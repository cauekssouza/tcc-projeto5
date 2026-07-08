<?php

/**
 * Interface de estratégia (suposição, ajuste conforme sua implementação real)
 */
interface StrategyInterface
{
    public function recordFailure(string $service): int;
    public function recordSuccess(string $service): int;
    public function isAvailable(string $service): bool;
    public function reset(): void;
}

/**
 * Exceção de storage (suposição, ajuste conforme sua implementação real)
 */
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
            // Não expor mensagem interna da exceção diretamente
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to record failure'
            );
            // Aqui você pode logar o erro internamente:
            // error_log($e->getMessage());
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
            // error_log($e->getMessage());
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
            // error_log($e->getMessage());

            // Aqui você pode decidir se falha-aberto (true) é realmente desejável.
            // Em muitos cenários de segurança, é melhor falhar-fechado (false).
            return false;
        }
    }

    /**
     * @psalm-param callable(self::EVENT_*, string, string): void $callable
     */
    public function subscribe(callable $callable): void
    {
        // Opcional: validar assinatura do callable via Reflection, se quiser ser mais rígido.
        $this->subscribers[] = $callable;
    }

    private function notify(string $event, string $service, string $message): void
    {
        foreach ($this->subscribers as $subscriber) {
            // Chamar diretamente o callable é mais simples e seguro
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
        try {
            $this->strategy->reset();
        } catch (StorageException $e) {
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                '',
                'failed to reset strategy'
            );
            // error_log($e->getMessage());
        }
    }
}
