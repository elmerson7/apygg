<?php

namespace App\Services\Notifications;

use App\Contracts\LocaleResolverInterface;
use App\Contracts\NotificationPreferenceServiceInterface;
use App\Exceptions\BusinessLogicException;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\NotificationType;
use Carbon\CarbonInterface;

class NotificationPreferenceService implements NotificationPreferenceServiceInterface
{
    public function __construct(private readonly LocaleResolverInterface $locale) {}

    public function forUser(User $user): array
    {
        $stored = NotificationPreference::query()->where('user_id', $user->getKey())->get()
            ->mapWithKeys(fn (NotificationPreference $p) => ["{$p->type}|{$p->channel}" => $p->enabled]);

        $types = [];

        foreach (NotificationType::all() as $type) {
            $channels = [];

            foreach ($type->channels as $channel) {
                $channels[$channel] = $type->mandatory || (bool) $stored->get("{$type->key}|{$channel}", true);
            }

            $types[] = ['type' => $type->key, 'mandatory' => $type->mandatory, 'channels' => $channels];
        }

        $settings = NotificationSetting::query()->where('user_id', $user->getKey())->first();

        return [
            'types' => $types,
            'quiet_hours' => [
                'enabled' => (bool) $settings?->quiet_hours_enabled,
                'start' => $settings?->quiet_start,
                'end' => $settings?->quiet_end,
                'timezone' => $this->locale->timezoneFor($user),
            ],
        ];
    }

    public function update(User $user, array $preferences = [], ?array $quietHours = null): void
    {
        foreach ($preferences as $preference) {
            $type = NotificationType::find($preference['type']);

            if (! in_array($preference['channel'], $type->channels, true)) {
                throw new BusinessLogicException("El tipo {$type->key} no se envía por {$preference['channel']}", 'notification_channel_unknown');
            }

            if ($type->mandatory && ! $preference['enabled']) {
                throw new BusinessLogicException("La notificación {$type->key} es obligatoria y no se puede desactivar", 'notification_mandatory');
            }
        }

        foreach ($preferences as $preference) {
            NotificationPreference::query()->upsert(
                [[
                    'user_id' => $user->getKey(),
                    'type' => $preference['type'],
                    'channel' => $preference['channel'],
                    'enabled' => $preference['enabled'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['user_id', 'type', 'channel'],
                ['enabled', 'updated_at'],
            );
        }

        if ($quietHours !== null) {
            $this->updateQuietHours($user, $quietHours);
        }
    }

    public function isEnabled(User $user, NotificationType $type, string $channel): bool
    {
        if ($type->mandatory) {
            return true;
        }

        $enabled = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type->key)
            ->where('channel', $channel)
            ->value('enabled');

        return $enabled === null ? true : (bool) $enabled;
    }

    public function inQuietHours(User $user, ?CarbonInterface $at = null): bool
    {
        $settings = NotificationSetting::query()->where('user_id', $user->getKey())->first();

        if ($settings === null || ! $settings->quiet_hours_enabled || ! $settings->quiet_start || ! $settings->quiet_end) {
            return false;
        }

        $now = ($at ?? now())->copy()->setTimezone($this->locale->timezoneFor($user))->format('H:i');
        [$start, $end] = [$settings->quiet_start, $settings->quiet_end];

        return match (true) {
            $start === $end => false,
            $start < $end => $now >= $start && $now < $end,
            default => $now >= $start || $now < $end,   // cruza la medianoche (22:00-07:00)
        };
    }

    /**
     * @param  array{enabled?: bool, start?: string|null, end?: string|null}  $quietHours
     */
    private function updateQuietHours(User $user, array $quietHours): void
    {
        $current = NotificationSetting::query()->where('user_id', $user->getKey())->first();

        $enabled = (bool) ($quietHours['enabled'] ?? ($current !== null && $current->quiet_hours_enabled));
        $start = array_key_exists('start', $quietHours) ? $quietHours['start'] : $current?->quiet_start;
        $end = array_key_exists('end', $quietHours) ? $quietHours['end'] : $current?->quiet_end;

        if ($enabled && (! $start || ! $end)) {
            throw new BusinessLogicException('Las horas de silencio necesitan inicio y fin', 'quiet_hours_incomplete');
        }

        NotificationSetting::query()->upsert(
            [[
                'user_id' => $user->getKey(),
                'quiet_hours_enabled' => $enabled,
                'quiet_start' => $start,
                'quiet_end' => $end,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id'],
            ['quiet_hours_enabled', 'quiet_start', 'quiet_end', 'updated_at'],
        );
    }
}
