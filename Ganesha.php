<?php

declare(strict_types=1);

interface StrategyInterface
{
    public function recordFailure(string $service): int;
    public function recordSuccess(string $service): int;
    public function isAvailable(string $service): bool;
    public function reset(): void;
}

class StorageException extends \RuntimeException
{
}

final class Ganesha
{
    /**
     * @var string
     */
    public const EVENT_TRIPPED = 'tripped';

    /**
     * @var string
     */
    public const EVENT_CALMED_DOWN = 'calmed_down';

    /**
     * @var string
     */
    public const EVENT_STORAGE_ERROR = 'storage_error';

    /**
     * the status between failure count 0 and trip.
     * @var int
     */
    public const STATUS_CALMED_DOWN = 1;

    /**
     * the status between trip and calm down.
     * @var int
     */
    public const STATUS_TRIPPED  = 2;

    /**
     * @var StrategyInterface
     */
    private StrategyInterface $strategy;

    /**
     * @var callable[]
     */
    private array $subscribers = [];

    /**
     * @var bool
     */
    private static bool $disabled = false;

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

            if ($status === self::STATUS_TRIPPED) {
                $this->notify(self::EVENT_TRIPPED, $service, '');
            }
        } catch (StorageException $e) {
            // Não expor detalhes internos da exceção
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to record failure'
            );
            // Opcional: logar detalhes em canal seguro (logger, syslog etc.)
        }
    }

    /**
     * Records success
     */
    public function success(string $service): void
    {
        try {
            $status = $this->strategy->recordSuccess($service);

            if ($status === self::STATUS_CALMED_DOWN) {
                $this->notify(self::EVENT_CALMED_DOWN, $service, '');
            }
        } catch (StorageException $e) {
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to record success'
            );
            // Opcional: logar detalhes internos em canal seguro
        }
    }

    public function isAvailable(string $service): bool
    {
        if (self::$disabled) {
            return true;
        }

        try {
            return $this->strategy->isAvailable($service);
        } catch (StorageException $e) {
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to check availability'
            );
            // fail-silent: não derruba o sistema por erro de storage
            return true;
        }
    }

    /**
     * @psalm-param callable(self::EVENT_*, string, string): void $callable
     */
    public function subscribe(callable $callable): void
    {
        // Opcional: validar que o callable é realmente chamável
        if (!\is_callable($callable)) {
            throw new \InvalidArgumentException('Subscriber must be callable.');
        }

        $this->subscribers[] = $callable;
    }

    private function notify(string $event, string $service, string $message): void
    {
        // Validar evento para evitar uso indevido
        $allowedEvents = [
            self::EVENT_TRIPPED,
            self::EVENT_CALMED_DOWN,
            self::EVENT_STORAGE_ERROR,
        ];

        if (!\in_array($event, $allowedEvents, true)) {
            // Ignora eventos inválidos ou lança exceção, dependendo da política
            return;
        }

        foreach ($this->subscribers as $subscriber) {
            // Chamada direta é mais segura e performática que call_user_func_array
            $subscriber($event, $service, $message);
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
        $this->strategy->reset();
    }
}
