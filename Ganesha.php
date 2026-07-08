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
        $this->withSafeStorage(
            $service,
            'failed to record failure',
            function () use ($service): ?int {
                return $this->strategy->recordFailure($service);
            },
            function (?int $status) use ($service): void {
                if ($status === self::STATUS_TRIPPED) {
                    $this->notify(self::EVENT_TRIPPED, $service, '');
                }
            }
        );
    }

    /**
     * Records success
     */
    public function success(string $service): void
    {
        $this->withSafeStorage(
            $service,
            'failed to record success',
            function () use ($service): ?int {
                return $this->strategy->recordSuccess($service);
            },
            function (?int $status) use ($service): void {
                if ($status === self::STATUS_CALMED_DOWN) {
                    $this->notify(self::EVENT_CALMED_DOWN, $service, '');
                }
            }
        );
    }

    public function isAvailable(string $service): bool
    {
        if (self::$disabled) {
            return true;
        }

        return $this->withSafeStorage(
            $service,
            'failed to isAvailable',
            function () use ($service): bool {
                return $this->strategy->isAvailable($service);
            },
            null,
            true // fail-open default
        );
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
                call_user_func_array($s, [$event, $service, $message]);
            } catch (\Throwable $e) {
                // Protect availability: subscriber failures must not impact circuit breaker behavior
                // Optionally, could route this to a dedicated logging subscriber if configured
            }
        }
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
        $this->withSafeStorage(
            '',
            'failed to reset strategy',
            function (): void {
                $this->strategy->reset();
            }
        );
    }

    /**
     * Centralized, robust storage adapter protection.
     *
     * @template T
     * @param string        $service
     * @param string        $contextMessage
     * @param callable      $operation   fn(): T
     * @param callable|null $onResult    fn(T|null): void
     * @param mixed         $default     default value when storage fails (for availability, fail-open)
     * @return mixed
     */
    private function withSafeStorage(
        string $service,
        string $contextMessage,
        callable $operation,
        ?callable $onResult = null,
        $default = null
    ) {
        try {
            // Single, atomic call into the strategy to avoid userland race conditions.
            $result = $operation();

            if ($onResult !== null) {
                $onResult($result);
            }

            return $result ?? $default;
        } catch (StorageException $e) {
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                $contextMessage . ' : ' . $e->getMessage()
            );
            return $default;
        } catch (\Throwable $e) {
            // Catch-all to prevent adapter or unexpected errors from crashing the main application.
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                $contextMessage . ' (unexpected error) : ' . $e->getMessage()
            );
            return $default;
        }
    }
}
