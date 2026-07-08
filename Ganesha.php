<?php

declare(strict_types=1);

final class Ganesha
{
    /**
     * @var string
     */
    public const EVENT_TRIPPED        = 'tripped';
    public const EVENT_CALMED_DOWN    = 'calmed_down';
    public const EVENT_STORAGE_ERROR  = 'storage_error';

    /**
     * the status between failure count 0 and trip.
     * @var int
     */
    public const STATUS_CALMED_DOWN = 1;

    /**
     * the status between trip and calm down.
     * @var int
     */
    public const STATUS_TRIPPED = 2;

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
            // Não expor detalhes internos da exceção diretamente
            $safeMessage = 'failed to record failure';
            $this->notify(self::EVENT_STORAGE_ERROR, $service, $safeMessage);

            // Opcional: logar detalhes técnicos em canal seguro
            // error_log('StorageException in failure(): ' . $e->getMessage());
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
            $safeMessage = 'failed to record success';
            $this->notify(self::EVENT_STORAGE_ERROR, $service, $safeMessage);

            // Opcional: logar detalhes técnicos
            // error_log('StorageException in success(): ' . $e->getMessage());
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
            $safeMessage = 'failed to check availability';
            $this->notify(self::EVENT_STORAGE_ERROR, $service, $safeMessage);

            // fail-silent: assume disponível, mas loga internamente
            // error_log('StorageException in isAvailable(): ' . $e->getMessage());

            return true;
        }
    }

    /**
     * @psalm-param callable(self::EVENT_*, string, string): void $callable
     */
    public function subscribe(callable $callable): void
    {
        // Opcional: validar assinatura do callable via reflexão ou wrapper
        $this->subscribers[] = $callable;
    }

    private function notify(string $event, string $service, string $message): void
    {
        foreach ($this->subscribers as $subscriber) {
            // Evitar call_user_func_array por questões de performance e legibilidade
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
        // Se reset puder lançar StorageException, considerar try/catch aqui também
        $this->strategy->reset();
    }
}
