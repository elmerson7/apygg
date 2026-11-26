<?php

namespace App\Integrations\Antivirus;

use App\Contracts\MalwareScannerInterface;
use App\Support\Files\ScanVerdict;

/**
 * ClamAV por su demonio `clamd` (contenedor opcional `clamav`, perfil de Compose del mismo nombre). Envía el contenido
 * con el comando INSTREAM por TCP (`CLAMAV_HOST`:`CLAMAV_PORT`): no hace falta compartir disco con clamd.
 * Respuestas: `stream: OK` (limpio), `stream: <firma> FOUND` (infectado), `... ERROR` (fallo).
 */
class ClamAvScanner implements MalwareScannerInterface
{
    private const CHUNK_BYTES = 65536;

    /**
     * @param  (\Closure(): (resource|false))|null  $connect  abre la conexión con clamd (los tests inyectan un socketpair)
     */
    public function __construct(private readonly ?\Closure $connect = null) {}

    public function name(): string
    {
        return 'clamav';
    }

    public function scan($stream, int $size): ScanVerdict
    {
        $socket = $this->open();

        if (! is_resource($socket)) {
            return ScanVerdict::error('clamav: no se pudo conectar con clamd');
        }

        try {
            stream_set_timeout($socket, (int) config('files.scanning.clamav.timeout', 60));

            // Si clamd corta la conexión (p. ej. al superar StreamMaxLength), su respuesta dice por qué.
            if (! $this->write($socket, "zINSTREAM\0")) {
                return $this->parse($this->readReply($socket));
            }

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if ($chunk === false) {
                    return ScanVerdict::error('clamav: no se pudo leer el archivo');
                }

                if ($chunk === '') {
                    continue;
                }

                if (! $this->write($socket, pack('N', strlen($chunk)).$chunk)) {
                    return $this->parse($this->readReply($socket));
                }
            }

            if (! $this->write($socket, pack('N', 0))) {
                return $this->parse($this->readReply($socket));
            }

            return $this->parse($this->readReply($socket));
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource|false
     */
    private function open()
    {
        if ($this->connect !== null) {
            return ($this->connect)();
        }

        $host = (string) config('files.scanning.clamav.host', 'clamav');
        $port = (int) config('files.scanning.clamav.port', 3310);
        $timeout = (float) config('files.scanning.clamav.connect_timeout', 5);

        return @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    }

    /**
     * @param  resource  $socket
     */
    private function write($socket, string $data): bool
    {
        $length = strlen($data);

        for ($written = 0; $written < $length; $written += $bytes) {
            $bytes = @fwrite($socket, substr($data, $written));

            if ($bytes === false || $bytes === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  resource  $socket
     */
    private function readReply($socket): string
    {
        $reply = '';

        while (! feof($socket)) {
            $chunk = fread($socket, 4096);

            if ($chunk === false || ($chunk === '' && stream_get_meta_data($socket)['timed_out'])) {
                break;
            }

            $reply .= $chunk;

            if (str_contains($reply, "\0")) {
                break;
            }
        }

        return trim(strstr($reply, "\0", true) ?: $reply);
    }

    private function parse(string $reply): ScanVerdict
    {
        // Formato: "stream: OK", "stream: Eicar-Signature FOUND", "INSTREAM size limit exceeded. ERROR".
        $body = preg_replace('/^stream:\s*/', '', $reply) ?? $reply;

        if ($body === 'OK') {
            return ScanVerdict::clean();
        }

        if (str_ends_with($body, ' FOUND')) {
            return ScanVerdict::infected('clamav:'.mb_substr(trim(substr($body, 0, -6)), 0, 200));
        }

        if (str_contains($body, 'size limit exceeded')) {
            return ScanVerdict::error('clamav: el archivo supera StreamMaxLength de clamd', retryable: false);
        }

        return ScanVerdict::error('clamav: respuesta inesperada: '.mb_substr($body === '' ? '(vacía)' : $body, 0, 150));
    }
}
