<?php
declare(strict_types=1);

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
            if ($this->strategy->recordFailure($service) === self::STATUS_TRIPPED) {
                $this->notify(self::EVENT_TRIPPED, $service, '');
            }
        } catch (StorageException $e) {
            // Não expor mensagem interna da exceção
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to record failure'
            );
        }
    }

    /**
     * Records success
     */
    public function success(string $service): void
    {
        try {
            if ($this->strategy->recordSuccess($service) === self::STATUS_CALMED_DOWN) {
                $this->notify(self::EVENT_CALMED_DOWN, $service, '');
            }
        } catch (StorageException $e) {
            // Não expor mensagem interna da exceção
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to record success'
            );
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
            // Não expor detalhes internos
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                $service,
                'failed to check availability'
            );
            // fail-silent
            return true;
        }
    }

    /**
     * @psalm-param callable(self::EVENT_*, string, string): void $callable
     */
    public function subscribe(callable $callable): void
    {
        // Opcional: validar tipo de callable se necessário
        $this->subscribers[] = $callable;
    }

    private function notify(string $event, string $service, string $message): void
    {
        foreach ($this->subscribers as $subscriber) {
            try {
                // Chamada direta é mais segura e clara que call_user_func_array
                $subscriber($event, $service, $message);
            } catch (\Throwable $t) {
                // Evita que um subscriber mal comportado quebre o fluxo
                // Aqui você poderia logar internamente sem expor nada ao usuário
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
        try {
            $this->strategy->reset();
        } catch (StorageException $e) {
            $this->notify(
                self::EVENT_STORAGE_ERROR,
                '',
                'failed to reset'
            );
        }
    }
}
