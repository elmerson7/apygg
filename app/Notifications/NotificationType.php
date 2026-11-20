<?php

namespace App\Notifications;

use App\Exceptions\BusinessLogicException;

/**
 * Un tipo de notificación declarado en `config/notifications.php`.
 */
final readonly class NotificationType
{
    /**
     * @param  list<string>  $channels  canales por defecto
     */
    public function __construct(
        public string $key,
        public array $channels,
        public bool $mandatory = false,
    ) {}

    /**
     * @throws BusinessLogicException
     */
    public static function find(string $key): self
    {
        $config = config("notifications.types.{$key}");

        if (! is_array($config)) {
            throw new BusinessLogicException("Tipo de notificación desconocido: {$key}", 'notification_type_unknown');
        }

        $known = (array) config('notifications.channels', []);

        return new self(
            $key,
            array_values(array_filter((array) ($config['channels'] ?? []), fn ($c) => in_array($c, $known, true))),
            (bool) ($config['mandatory'] ?? false),
        );
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        $types = [];

        foreach (array_keys((array) config('notifications.types', [])) as $key) {
            $types[(string) $key] = self::find((string) $key);
        }

        return $types;
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function title(string $locale, array $params = []): string
    {
        return $this->text('title', $locale, $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function body(string $locale, array $params = []): string
    {
        return $this->text('body', $locale, $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private function text(string $part, string $locale, array $params): string
    {
        $replace = array_map(fn ($v) => (string) $v, $params);

        return (string) trans("notifications.types.{$this->key}.{$part}", $replace, $locale);
    }
}
