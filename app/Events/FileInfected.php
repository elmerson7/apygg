<?php

namespace App\Events;

use App\Models\File;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El antivirus detectó malware en un archivo subido; su contenido ya se borró (docs/files.md, "Escaneo antivirus").
 * Escúchalo para avisar al usuario o al equipo de seguridad. `$file->scan_result` lleva el motor y la firma.
 */
class FileInfected
{
    use Dispatchable, SerializesModels;

    public function __construct(public File $file) {}
}
