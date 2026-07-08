<?php

class Ganesha
{
    /**
     * @var string
     */
    const EVENT_TRIPPED = 'tripped';

    /**
     * @var string
     */
    const EVENT_CALMED_DOWN = 'calmed_down';

    /**
     * @var string
     */
    const EVENT_STORAGE_ERROR = 'storage_error';

    /**
     * the status between failure count 0 and trip.
     * @var int
     */
    const STATUS_CALMED_DOWN = 1;

    /**
     * the status between trip and calm down.
     * @var int
     */
    const STATUS_TRIPPED  = 2;

    /**
     * @var StrategyInterface
     */
    private $strategy;

    /**
     * @var callable[]
     */
    private $subscribers = [];

    /**
     * @var bool
     */
    private static $disabled = false;

    public function __construct(StrategyInterface $strategy)
    {
        $this->strategy = $strategy;
    }

    /**
     * Records failure
     */
    public function failure(string $service): void
    {
        try {
            $status = $this->strategy->recordFailure($service);

            // single, atomic decision based on strategy result
            if ($status === self::STATUS_TRIPPED) {
                $this->notify(self::EVENT_TRIPPED, $service, '');
            }
        } catch (StorageException $e) {
            $this->handleStorageError($service, 'failed to record failure : ' . $e->getMessage());
        } catch (\Throwable $t) {
            // defensive catch to avoid crashes due to unexpected adapter errors
            $this->handleStorageError($service, 'unexpected error on failure : ' . $t->getMessage());
        }
    }

    /**
     * Records success
     */
    public function success(string $service): void
    {
        try {
            $status = $this->strategy->recordSuccess($service);

            // single, atomic decision based on strategy result
            if ($status === self::STATUS_CALMED_DOWN) {
                $this->notify(self::EVENT_CALMED_DOWN, $service, '');
            }
        } catch (StorageException $e) {
            $this->handleStorageError($service, 'failed to record success : ' . $e->getMessage());
        } catch (\Throwable $t) {
            // defensive catch to avoid crashes due to unexpected adapter errors
            $this->handleStorageError($service, 'unexpected error on success : ' . $t->getMessage());
        }
    }

    public function isAvailable(string $service): bool
    {
        if (self::$disabled) {
            return true;
        }

        try {
            // single call to strategy to avoid race-prone multi-step logic
            return $this->strategy->isAvailable($service);
        } catch (StorageException $e) {
            $this->handleStorageError($service, 'failed to isAvailable : ' . $e->getMessage());
            // conservative fallback: treat as unavailable to avoid hammering a degraded backend
            return false;
        } catch (\Throwable $t) {
            // any unexpected adapter error is treated as storage-related for availability purposes
            $this->handleStorageError($service, 'unexpected error on isAvailable : ' . $t->getMessage());
            return false;
        }
    }

    /**
     * @psalm-param callable(self::EVENT_*, string, string): void $callable
     */
    public function subscribe(callable $callable): void
    {
        $this->subscribers[] = $callable;
    }

    private function notify(string $event, string $service, string $message): void
    {
        foreach ($this->subscribers as $s) {
            try {
                // notification is best-effort; subscriber errors must not impact circuit logic
                call_user_func_array($s, [$event, $service, $message]);
            } catch (\Throwable $t) {
                // swallow subscriber errors to avoid cascading failures
                // optionally, this could be logged by an external logger injected via subscribers
            }
        }
    }

    /**
     * Centralized storage error handling to avoid silent failures and crashes.
     */
    private function handleStorageError(string $service, string $message): void
    {
        // single notification point; no rethrow to keep main application flow alive
        $this->notify(self::EVENT_STORAGE_ERROR, $service, $message);
    }

    /**
     * Disable
     */
    public static function disable(): void
    {
        self::$disabled = true;
    }

    /**
     * Enable
     */
    public static function enable(): void
    {
        self::$disabled = false;
    }

    /**
     * Resets all counts
     */
    public function reset(): void
    {
        try {
            $this->strategy->reset();
        } catch (StorageException $e) {
            $this->handleStorageError('', 'failed to reset : ' . $e->getMessage());
        } catch (\Throwable $t) {
            $this->handleStorageError('', 'unexpected error on reset : ' . $t->getMessage());
        }
    }
}
