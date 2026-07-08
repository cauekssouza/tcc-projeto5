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

    /**
     * Lightweight inter‑process lock file path.
     *
     * @var string
     */
    private static $lockFile = __DIR__ . '/ganesha.lock';

    /**
     * @var resource|null
     */
    private static $lockHandle;

    public function __construct(StrategyInterface $strategy)
    {
        $this->strategy = $strategy;
    }

    /**
     * Records failure
     */
    public function failure(string $service): void
    {
        if (!$this->acquireLock()) {
            // If we cannot safely coordinate counters, avoid mutating state
            $this->notify(self::EVENT_STORAGE_ERROR, $service, 'failed to acquire lock for failure');
            return;
        }

        try {
            $status = $this->strategy->recordFailure($service);
            if ($status === self::STATUS_TRIPPED) {
                $this->notify(self::EVENT_TRIPPED, $service, '');
            }
        } catch (StorageException $e) {
            $this->notify(self::EVENT_STORAGE_ERROR, $service, 'failed to record failure : ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Defensive catch for any unexpected adapter error
            $this->notify(self::EVENT_STORAGE_ERROR, $service, 'unexpected error on failure : ' . $e->getMessage());
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Records success
     */
    public function success(string $service): void
    {
        if (!$this->acquireLock()) {
            $this->notify(self::EVENT_STORAGE_ERROR, $service, 'failed to acquire lock for success');
            return;
        }

        try {
            $status = $this->strategy->recordSuccess($service);
            if ($status === self::STATUS_CALMED_DOWN) {
                $this->notify(self::EVENT_CALMED_DOWN, $service, '');
            }
        } catch (StorageException $e) {
            $this->notify(self::EVENT_STORAGE_ERROR, $service, 'failed to record success : ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->notify(self::EVENT_STORAGE_ERROR, $service, 'unexpected error on success : ' . $e->getMessage());
        } finally {
            $this->releaseLock();
        }
    }

    public function isAvailable(string $service): bool
    {
        if (self::$disabled) {
            return true;
        }

        if (!$this->acquireLock()) {
            // Conservative default: protect infrastructure when coordination fails
            $this->notify(self::EVENT_STORAGE_ERROR, $service, 'failed to acquire lock for isAvailable');
            return false;
        }

        try {
            return $this->strategy->isAvailable($service);
        } catch (StorageException $e) {
            $this->notify(self::EVENT_STORAGE_ERROR, $service, 'failed to isAvailable : ' . $e->getMessage());
            // Fail‑closed to avoid resource exhaustion when storage is unstable
            return false;
        } catch (\Throwable $e) {
            $this->notify(self::EVENT_STORAGE_ERROR, $service, 'unexpected error on isAvailable : ' . $e->getMessage());
            return false;
        } finally {
            $this->releaseLock();
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
            call_user_func_array($s, [$event, $service, $message]);
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
        if (!$this->acquireLock()) {
            $this->notify(self::EVENT_STORAGE_ERROR, 'global', 'failed to acquire lock for reset');
            return;
        }

        try {
            $this->strategy->reset();
        } catch (StorageException $e) {
            $this->notify(self::EVENT_STORAGE_ERROR, 'global', 'failed to reset : ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->notify(self::EVENT_STORAGE_ERROR, 'global', 'unexpected error on reset : ' . $e->getMessage());
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Acquire a simple cross‑process lock to mitigate race conditions
     * on counters and state transitions.
     */
    private function acquireLock(): bool
    {
        if (self::$lockHandle === null) {
            self::$lockHandle = @fopen(self::$lockFile, 'c');
            if (self::$lockHandle === false) {
                self::$lockHandle = null;
                return false;
            }
        }

        $start = microtime(true);
        $timeoutSeconds = 0.05; // small timeout to avoid threads stuck in retention loops

        do {
            if (@flock(self::$lockHandle, LOCK_EX | LOCK_NB)) {
                return true;
            }
            usleep(1000); // brief backoff to reduce contention
        } while ((microtime(true) - $start) < $timeoutSeconds);

        return false;
    }

    /**
     * Release the previously acquired lock.
     */
    private function releaseLock(): void
    {
        if (self::$lockHandle !== null) {
            @flock(self::$lockHandle, LOCK_UN);
        }
    }
}
