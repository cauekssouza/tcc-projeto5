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

/**
 * Classe responsável por controlar disponibilidade de serviços (circuit breaker).
 */
final class Ganesha
{
    /**
     * @var string
     */
    private const EVENT_TRIPPED = 'tripped';

    /**
     * @var string
     */
    private const EVENT_CALMED_DOWN = 'calmed_down';

    /**
     * @var string
     */
    private const EVENT_STORAGE_ERROR = 'storage_error';

    /**
     * the status between failure count 0 and trip.
     * @var int
     */
    private const STATUS_CALMED_DOWN = 1;

    /**
     * the status between trip and calm down.
     * @var int
     */
    private const STATUS_TRIPPED  = 2;

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
     * Registra falha.
     */
    public function failure(string $service): void
    {
        $service = $this->sanitizeServiceName($service);

        try {
            if ($this->strategy->recordFailure($service) === self::STATUS_TRIPPED) {
                $this->notify(self::EVENT_TRIPPED, $service, '');
            }
        } catch (StorageException $e) {
            // Não expor mensagem interna da exception diretamente
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to record failure'
            );
            // Aqui você pode logar internamente:
            // error_log('StorageException in failure: ' . $e->getMessage());
        }
    }

    /**
     * Registra sucesso.
     */
    public function success(string $service): void
    {
        $service = $this->sanitizeServiceName($service);

        try {
            if ($this->strategy->recordSuccess($service) === self::STATUS_CALMED_DOWN) {
                $this->notify(self::EVENT_CALMED_DOWN, $service, '');
            }
        } catch (StorageException $e) {
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to record success'
            );
            // error_log('StorageException in success: ' . $e->getMessage());
        }
    }

    public function isAvailable(string $service): bool
    {
        if (self::$disabled) {
            return true;
        }

        $service = $this->sanitizeServiceName($service);

        try {
            return $this->strategy->isAvailable($service);
        } catch (StorageException $e) {
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to check availability'
            );
            // fail-silent: considera disponível em caso de erro de storage
            return true;
        }
    }

    /**
     * @psalm-param callable(self::EVENT_*, string, string): void $callable
     */
    public function subscribe(callable $callable): void
    {
        // Opcional: validar tipo de callable mais estritamente se necessário
        $this->subscribers[] = $callable;
    }

    private function notify(string $event, string $service, string $message): void
    {
        foreach ($this->subscribers as $subscriber) {
            // Evita call_user_func_array; chama diretamente o callable
            $subscriber($event, $service, $message);
        }
    }

    /**
     * Desabilita o circuito (sempre disponível).
     */
    public static function disable(): void
    {
        self::$disabled = true;
    }

    /**
     * Habilita o circuito.
     */
    public static function enable(): void
    {
        self::$disabled = false;
    }

    /**
     * Reseta todos os contadores.
     */
    public function reset(): void
    {
        $this->strategy->reset();
    }

    /**
     * Sanitiza/valida o nome do serviço para evitar valores inesperados.
     */
    private function sanitizeServiceName(string $service): string
    {
        // Exemplo simples: trim e limite de tamanho.
        $service = trim($service);

        if ($service === '') {
            // Opcional: lançar exceção ou tratar de outra forma
            throw new \InvalidArgumentException('Service name cannot be empty.');
        }

        // Limita tamanho para evitar abusos (logs, storage, etc.)
        if (mb_strlen($service) > 255) {
            $service = mb_substr($service, 0, 255);
        }

        return $service;
    }
}
