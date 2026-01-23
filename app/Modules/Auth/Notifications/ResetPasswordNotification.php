<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Token de reset de contraseña
     *
     * @var string
     */
    protected string $token;

    /**
     * URL base del frontend para el reset (opcional)
     *
     * @var string|null
     */
    protected ?string $resetUrl;

    /**
     * Create a new notification instance.
     *
     * @param string $token Token de reset
     * @param string|null $resetUrl URL base del frontend (opcional)
     */
    public function __construct(string $token, ?string $resetUrl = null)
    {
        $this->token = $token;
        $this->resetUrl = $resetUrl;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Construir URL de reset
        $url = $this->buildResetUrl($notifiable->email);

        return (new MailMessage)
            ->subject('Recuperación de Contraseña')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Recibiste este email porque solicitaste recuperar tu contraseña.')
            ->action('Restablecer Contraseña', $url)
            ->line('Este enlace expirará en ' . config('auth.passwords.users.expire', 60) . ' minutos.')
            ->line('Si no solicitaste este cambio, ignora este email.')
            ->salutation('Saludos, ' . config('app.name'));
    }

    /**
     * Construir URL de reset de contraseña
     *
     * @param string $email Email del usuario
     * @return string URL completa de reset
     */
    protected function buildResetUrl(string $email): string
    {
        $queryParams = http_build_query([
            'email' => $email,
            'token' => $this->token,
        ]);

        // Si se proporcionó una URL personalizada, validarla contra whitelist
        if ($this->resetUrl && $this->isAllowedOrigin($this->resetUrl)) {
            // Asegurar que la URL termine con / o tenga el path correcto
            $baseUrl = rtrim($this->resetUrl, '/');
            $path = str_contains($this->resetUrl, '/reset-password') ? '' : '/reset-password';
            return $baseUrl . $path . '?' . $queryParams;
        }

        // Fallback: usar URL genérica o configurada
        $fallbackUrl = config('app.url');
        return rtrim($fallbackUrl, '/') . '/reset-password?' . $queryParams;
    }

    /**
     * Validar que la URL de reset esté en la whitelist de orígenes permitidos
     *
     * @param string $url URL a validar
     * @return bool True si la URL está permitida
     */
    protected function isAllowedOrigin(string $url): bool
    {
        $allowedOrigins = config('app.allowed_origins', []);

        if (empty($allowedOrigins)) {
            // Si no hay whitelist configurada, permitir en desarrollo
            return config('app.env') === 'local';
        }

        $parsedUrl = parse_url($url);

        if (!$parsedUrl || !isset($parsedUrl['host'])) {
            return false;
        }

        $urlHost = $parsedUrl['host'];
        $urlPort = $parsedUrl['port'] ?? null;
        $urlScheme = $parsedUrl['scheme'] ?? 'http';

        // Construir host completo con puerto si existe
        $fullHost = $urlPort ? "{$urlHost}:{$urlPort}" : $urlHost;
        $fullUrl = $urlPort ? "{$urlScheme}://{$urlHost}:{$urlPort}" : "{$urlScheme}://{$urlHost}";

        // Verificar cada origen permitido
        foreach ($allowedOrigins as $allowed) {
            $allowed = trim($allowed);

            // Si es una URL completa, comparar completa
            if (str_starts_with($allowed, 'http://') || str_starts_with($allowed, 'https://')) {
                $allowedParsed = parse_url($allowed);
                if ($allowedParsed) {
                    $allowedHost = $allowedParsed['host'] ?? '';
                    $allowedPort = $allowedParsed['port'] ?? null;
                    $allowedFullHost = $allowedPort ? "{$allowedHost}:{$allowedPort}" : $allowedHost;

                    // Comparar host y puerto
                    if ($urlHost === $allowedHost && $urlPort === $allowedPort) {
                        return true;
                    }
                }
            } else {
                // Si es solo un host, comparar host y puerto
                // Soporta formato "host:port" o solo "host"
                if (str_contains($allowed, ':')) {
                    // Formato "host:port"
                    if ($fullHost === $allowed) {
                        return true;
                    }
                } else {
                    // Solo host, comparar sin puerto
                    if ($urlHost === $allowed) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'token' => $this->token,
            'email' => $notifiable->email,
            'reset_url' => $this->resetUrl,
        ];
    }
}
