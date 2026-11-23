<?php

use App\Contracts\BackupServiceInterface;
use Illuminate\Support\Facades\Storage;

test('restaurar un backup local no consulta el disco remoto', function () {
    // Si se consultara S3 (caído o sin configurar) la restauración local fallaría con "Unable to check existence".
    Storage::shouldReceive('disk')->never();

    $archivo = tempnam(sys_get_temp_dir(), 'backup_').'.sql.gz';
    file_put_contents($archivo, 'no es gzip');

    try {
        expect(fn () => app(BackupServiceInterface::class)->restoreDatabaseBackup($archivo, false))
            ->toThrow(RuntimeException::class, 'Error al descomprimir backup');
    } finally {
        @unlink($archivo);
    }
});

test('un backup que no existe ni en local ni en remoto se rechaza', function () {
    Storage::fake('backups-remoto');
    config(['backups.remote.disk' => 'backups-remoto']);

    expect(fn () => app(BackupServiceInterface::class)->restoreDatabaseBackup('/no/existe.sql.gz', false))
        ->toThrow(RuntimeException::class, 'Backup no encontrado');
});
