<?php

namespace App\Services\Privacy;

use App\Contracts\AuditServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\PersonalDataExportServiceInterface;
use App\Exceptions\PrivacyException;
use App\Jobs\GenerateDataExportJob;
use App\Models\PersonalDataExport;
use App\Models\User;
use App\Support\Privacy\PersonalDataRegistry;
use App\Support\Privacy\SecretStripper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Exportación de datos personales: reúne lo que aporta cada contribuidor, lo pasa por `SecretStripper` y lo
 * empaqueta en un ZIP del disco privado que caduca. Solo lee datos del usuario que la pidió.
 */
class PersonalDataExportService implements PersonalDataExportServiceInterface
{
    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly AuditServiceInterface $audit,
        private readonly NotificationServiceInterface $notifications,
    ) {}

    public function request(User $user): PersonalDataExport
    {
        $export = DB::transaction(function () use ($user): PersonalDataExport {
            // Serializa las peticiones del mismo usuario: el límite de frecuencia no se salta con dos a la vez.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $open = PersonalDataExport::query()->where('user_id', $user->getKey())
                ->whereIn('status', [PersonalDataExport::PENDING, PersonalDataExport::PROCESSING])->latest('id')->first();
            if ($open instanceof PersonalDataExport) {
                return $open;
            }

            $hours = (int) config('privacy.export.min_interval_hours', 24);
            $last = PersonalDataExport::query()->where('user_id', $user->getKey())->where('status', '!=', PersonalDataExport::FAILED)->latest('id')->first();
            if ($hours > 0 && $last instanceof PersonalDataExport && $last->created_at->gt(now()->subHours($hours))) {
                throw PrivacyException::exportRateLimited((int) max(1, now()->diffInSeconds($last->created_at->copy()->addHours($hours), false)));
            }

            $created = PersonalDataExport::query()->create(['user_id' => $user->getKey(), 'status' => PersonalDataExport::PENDING]);
            $this->audit->recordAction('privacy.data-export-requested', $user, null, ['export_id' => $created->id]);
            GenerateDataExportJob::dispatch($created->id);

            return $created;
        });

        return $export;
    }

    public function generate(int $exportId): void
    {
        $claimed = PersonalDataExport::query()->whereKey($exportId)->where('status', PersonalDataExport::PENDING)
            ->update(['status' => PersonalDataExport::PROCESSING, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $export = PersonalDataExport::query()->findOrFail($exportId);
        $user = User::withTrashed()->findOrFail($export->user_id);
        $tmp = tempnam(sys_get_temp_dir(), 'privacy-export-');

        try {
            $zip = new ZipArchive;
            if ($zip->open((string) $tmp, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('No se pudo crear el ZIP');
            }

            $keys = [];
            foreach ($this->registry->all() as $contributor) {
                $keys[] = $contributor->key();
                $zip->addFromString($contributor->key().'.json', $this->json(SecretStripper::strip($contributor->export($user))));
            }

            $zip->addFromString('manifest.json', $this->json([
                'generated_at' => now()->toIso8601String(),
                'user_id' => $user->getKey(),
                'format' => config('app.slug').'-personal-data-v1',
                'files' => array_map(fn (string $k) => "{$k}.json", $keys),
            ]));
            $zip->close();

            $disk = (string) config('privacy.export.disk');
            $path = trim((string) config('privacy.export.directory', 'privacy-exports'), '/').'/'.$user->getKey().'/'.Str::ulid().'.zip';
            $stream = fopen((string) $tmp, 'rb');
            Storage::disk($disk)->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $export->forceFill([
                'status' => PersonalDataExport::READY,
                'disk' => $disk,
                'path' => $path,
                'size' => (int) filesize((string) $tmp),
                'ready_at' => now(),
                'expires_at' => now()->addHours((int) config('privacy.export.ttl_hours', 72)),
                'error' => null,
            ])->save();
        } catch (\Throwable $e) {
            // Vuelve a «pendiente» para que el reintento del job la reclame.
            PersonalDataExport::query()->whereKey($exportId)->where('status', PersonalDataExport::PROCESSING)->update(['status' => PersonalDataExport::PENDING]);

            throw $e;
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $this->notifications->notifyNow($user, 'data_export_ready', [
            'action_url' => $this->signedUrl($export->refresh()),
            'hours' => (int) config('privacy.export.ttl_hours', 72),
        ]);
    }

    public function markFailed(int $exportId, string $error): void
    {
        PersonalDataExport::query()->whereKey($exportId)->whereIn('status', [PersonalDataExport::PENDING, PersonalDataExport::PROCESSING])
            ->update(['status' => PersonalDataExport::FAILED, 'error' => mb_substr($error, 0, 255), 'updated_at' => now()]);
    }

    public function list(User $user): Collection
    {
        return PersonalDataExport::query()->where('user_id', $user->getKey())->orderByDesc('id')->limit(20)->get();
    }

    public function downloadUrl(User $user, int $exportId): string
    {
        $export = PersonalDataExport::query()->where('user_id', $user->getKey())->findOrFail($exportId);

        return $this->signedUrl($this->assertDownloadable($export));
    }

    public function download(int $exportId, int $userId): StreamedResponse
    {
        $export = $this->assertDownloadable(PersonalDataExport::query()->where('user_id', $userId)->findOrFail($exportId));
        $disk = Storage::disk((string) $export->disk);
        $name = 'datos-personales-'.$export->created_at->format('Ymd').'.zip';

        return $disk->download((string) $export->path, $name, ['Content-Type' => 'application/zip']);
    }

    public function expireDue(): int
    {
        $count = 0;

        PersonalDataExport::query()->where('status', PersonalDataExport::READY)->where('expires_at', '<=', now())->orderBy('id')
            ->each(function (PersonalDataExport $export) use (&$count): void {
                $this->deleteFile($export);
                $export->forceFill(['status' => PersonalDataExport::EXPIRED, 'path' => null, 'disk' => null])->save();
                $count++;
            });

        $days = (int) config('privacy.retention.export_records_days', 90);
        if ($days > 0) {
            PersonalDataExport::query()->whereIn('status', [PersonalDataExport::EXPIRED, PersonalDataExport::FAILED])
                ->where('updated_at', '<', now()->subDays($days))->delete();
        }

        return $count;
    }

    private function assertDownloadable(PersonalDataExport $export): PersonalDataExport
    {
        if ($export->status === PersonalDataExport::EXPIRED || ($export->status === PersonalDataExport::READY && $export->expires_at !== null && $export->expires_at->isPast())) {
            throw PrivacyException::exportExpired();
        }

        if ($export->status !== PersonalDataExport::READY || $export->path === null) {
            throw PrivacyException::exportNotReady();
        }

        return $export;
    }

    private function signedUrl(PersonalDataExport $export): string
    {
        return URL::temporarySignedRoute(
            'privacy.data-export.download',
            $export->expires_at ?? now()->addHours(1),
            ['id' => $export->id, 'user' => $export->user_id],
        );
    }

    private function deleteFile(PersonalDataExport $export): void
    {
        if ($export->path !== null && $export->disk !== null) {
            try {
                Storage::disk($export->disk)->delete($export->path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function json(mixed $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
