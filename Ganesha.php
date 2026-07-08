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
        $this->withServiceLock($service, function () use ($service): void {
            try {
                if ($this->strategy->recordFailure($service) === self::STATUS_TRIPPED) {
                    $this->notify(self::EVENT_TRIPPED, $service, '');
                }
            } catch (StorageException $e) {
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'failed to record failure : ' . $e->getMessage()
                );
            } catch (\Throwable $e) {
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
        $this->withServiceLock($service, function () use ($service): void {
            try {
                if ($this->strategy->recordSuccess($service) === self::STATUS_CALMED_DOWN) {
                    $this->notify(self::EVENT_CALMED_DOWN, $service, '');
                }
            } catch (StorageException $e) {
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'failed to record success : ' . $e->getMessage()
                );
            } catch (\Throwable $e) {
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

        return $this->withServiceLock($service, function () use ($service): bool {
            try {
                return $this->strategy->isAvailable($service);
            } catch (StorageException $e) {
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'failed to isAvailable : ' . $e->getMessage()
                );
                // fail-safe: protect infrastructure by treating service as unavailable
                return false;
            } catch (\Throwable $e) {
                $this->notify(
                    self::EVENT_STORAGE_ERROR,
                    $service,
                    'unexpected error in isAvailable : ' . $e->getMessage()
                );
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
                // prevent subscriber errors from impacting circuit breaker availability
                // optionally could log via a dedicated subscriber or external logger
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
        // global lock to avoid concurrent reset vs record operations
        $this->withGlobalLock(function (): void {
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
     * Provides a per-service mutual exclusion to mitigate race conditions
     *
     * @param callable $callback
     * @return mixed
     */
    private function withServiceLock(string $service, callable $callback)
    {
        $lockFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ganesha_' . md5($service) . '.lock';
        $fp = @fopen($lockFilePath, 'c');

        if ($fp === false) {
            // If lock file cannot be opened, execute without lock but still protect with try/catch in caller
            return $callback();
        }

        $result = null;

        try {
            if (@flock($fp, LOCK_EX)) {
                $result = $callback();
                @flock($fp, LOCK_UN);
            } else {
                // If lock cannot be acquired, still execute to avoid blocking indefinitely
                $result = $callback();
            }
        } finally {
            @fclose($fp);
        }

        return $result;
    }

    /**
     * Provides a global mutual exclusion for operations affecting all services (e.g., reset)
     *
     * @param callable $callback
     * @return mixed
     */
    private function withGlobalLock(callable $callback)
    {
        $lockFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ganesha_global.lock';
        $fp = @fopen($lockFilePath, 'c');

        if ($fp === false) {
            return $callback();
        }

        $result = null;

        try {
            if (@flock($fp, LOCK_EX)) {
                $result = $callback();
                @flock($fp, LOCK_UN);
            } else {
                $result = $callback();
            }
        } finally {
            @fclose($fp);
        }

        return $result;
    }
}
