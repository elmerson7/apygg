<?php

namespace App\Support\Privacy\Contributors;

use App\Contracts\FileServiceInterface;
use App\Contracts\Privacy\PersonalDataContributor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Archivos subidos por el usuario (metadatos; nunca el contenido ni URLs). `erase()` según `privacy.erase.files`:
 * `delete` (borra fila y objeto) o `detach` (conserva el archivo del tenant sin autor).
 */
class FilesContributor implements PersonalDataContributor
{
    public function __construct(private readonly FileServiceInterface $files) {}

    public function key(): string
    {
        return 'files';
    }

    public function export(User $user): array
    {
        return ['files' => DB::table('files')->where('user_id', $user->getKey())->orderBy('id')
            ->get(['tenant_id', 'name', 'filename', 'mime_type', 'size', 'category', 'description', 'is_public', 'created_at', 'deleted_at'])->all()];
    }

    public function erase(User $user): void
    {
        // Subidas directas sin completar: se cancelan (liberan su reserva de cuota) y su objeto huérfano se borra.
        $intents = DB::table('file_upload_intents')->where('user_id', $user->getKey());
        $orphans = (clone $intents)->where('status', 'pending')->get(['path', 'disk']);
        $intents->delete();

        $query = DB::table('files')->where('user_id', $user->getKey());

        if (config('privacy.erase.files', 'delete') === 'detach') {
            $query->update(['user_id' => null, 'deleted_by' => null]);
            $objects = $orphans;
        } else {
            $objects = (clone $query)->get(['path', 'disk'])->concat($orphans);
            $query->delete();
        }

        // El objeto se borra solo si la transacción se confirma; un fallo de disco no deshace el borrado de la cuenta.
        DB::afterCommit(function () use ($objects): void {
            foreach ($objects as $object) {
                try {
                    $this->files->delete((string) $object->path, (string) $object->disk);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
