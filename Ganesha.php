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
        self::withServiceLock($service, function () use ($service): void {
            try {
                $status = $this->strategy->recordFailure($service);
                if ($status === self::STATUS_TRIPPED) {
                    $this->notify(self::EVENT_TRIPPED, $service, '');
                }
            } catch (StorageException $e) {
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'failed to record failure : ' . $e->getMessage()
                );
            } catch (\Throwable $e) {
                // Defensive catch to avoid crashes from unexpected adapter errors
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'unexpected error while recording failure : ' . $e->getMessage()
                );
            }
        });
    }

    /**
     * Records success
     */
    public function success(string $service): void
    {
        self::withServiceLock($service, function () use ($service): void {
            try {
                $status = $this->strategy->recordSuccess($service);
                if ($status === self::STATUS_CALMED_DOWN) {
                    $this->notify(self::EVENT_CALMED_DOWN, $service, '');
                }
            } catch (StorageException $e) {
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'failed to record success : ' . $e->getMessage()
                );
            } catch (\Throwable $e) {
                // Defensive catch to avoid crashes from unexpected adapter errors
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'unexpected error while recording success : ' . $e->getMessage()
                );
            }
        });
    }

    public function isAvailable(string $service): bool
    {
        if (self::$disabled) {
            return true;
        }

        return (bool) self::withServiceLock($service, function () use ($service): bool {
            try {
                return $this->strategy->isAvailable($service);
            } catch (StorageException $e) {
                // Storage failure: protect infrastructure by treating service as unavailable
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'failed to isAvailable : ' . $e->getMessage()
                );
                $this->notify(self::EVENT_TRIPPED, $service, 'tripped due to storage error');
                return false;
            } catch (\Throwable $e) {
                // Any unexpected adapter error should not crash the app
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'unexpected error in isAvailable : ' . $e->getMessage()
                );
                $this->notify(self::EVENT_TRIPPED, $service, 'tripped due to unexpected storage error');
                return false;
            }
        });
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
                // Never let a subscriber crash the main flow; best-effort notification only
                // Optionally, one could route this to a dedicated logging subscriber.
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
        self::withGlobalLock(function (): void {
            try {
                $this->strategy->reset();
            } catch (StorageException $e) {
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    '',
                    'failed to reset : ' . $e->getMessage()
                );
            } catch (\Throwable $e) {
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    '',
                    'unexpected error while resetting : ' . $e->getMessage()
                );
            }
        });
    }

    /**
     * Lightweight per-service lock to mitigate race conditions on shared counters.
     * Uses non-blocking flock with a small timeout to avoid threads being stuck.
     *
     * @param callable $callback
     * @return mixed
     */
    private static function withServiceLock(string $service, callable $callback)
    {
        $lockFile = sys_get_temp_dir() . '/ganesha_' . md5($service) . '.lock';
        $fh = @fopen($lockFile, 'c');

        if ($fh === false) {
            // If we cannot create/open a lock file, proceed without locking to preserve availability.
            return $callback();
        }

        $start   = microtime(true);
        $locked  = false;
        $timeout = 0.05; // 50ms max wait to avoid resource exhaustion

        while (!($locked = @flock($fh, LOCK_EX | LOCK_NB))) {
            if ((microtime(true) - $start) > $timeout) {
                // Give up on locking to avoid threads stuck in retention loops
                break;
            }
            usleep(1000);
        }

        try {
            return $callback();
        } finally {
            if ($locked) {
                @flock($fh, LOCK_UN);
            }
            @fclose($fh);
        }
    }

    /**
     * Global lock for operations that affect all services (e.g., reset).
     *
     * @param callable $callback
     * @return mixed
     */
    private static function withGlobalLock(callable $callback)
    {
        $lockFile = sys_get_temp_dir() . '/ganesha_global.lock';
        $fh = @fopen($lockFile, 'c');

        if ($fh === false) {
            return $callback();
        }

        $start   = microtime(true);
        $locked  = false;
        $timeout = 0.05;

        while (!($locked = @flock($fh, LOCK_EX | LOCK_NB))) {
            if ((microtime(true) - $start) > $timeout) {
                break;
            }
            usleep(1000);
        }

        try {
            return $callback();
        } finally {
            if ($locked) {
                @flock($fh, LOCK_UN);
            }
            @fclose($fh);
        }
    }
}
