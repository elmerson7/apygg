<?php

namespace App\Integrations\Antivirus;

use App\Contracts\MalwareScannerInterface;
use App\Support\Files\ScanVerdict;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Consulta el SHA-256 del archivo en VirusTotal (API v3, `GET /files/{hash}`). NO sube el archivo: el contenido no
 * sale del servidor. Solo detecta malware ya conocido por VirusTotal; un hash desconocido (404) cuenta como limpio,
 * por eso es un complemento de ClamAV y no un sustituto. Infectado si al menos `min_detections` motores lo marcan
 * como malicioso. La clave gratuita es solo para uso no comercial y tiene límite de peticiones (429 = reintento).
 */
class VirusTotalHashScanner implements MalwareScannerInterface
{
    public function name(): string
    {
        return 'virustotal';
    }

    public function scan($stream, int $size): ScanVerdict
    {
        $key = (string) config('files.scanning.virustotal.key', '');

        if ($key === '') {
            return ScanVerdict::error('virustotal: falta VIRUSTOTAL_API_KEY', retryable: false);
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        $sha256 = hash_final($context);

        try {
            $response = Http::baseUrl((string) config('files.scanning.virustotal.url', 'https://www.virustotal.com/api/v3'))
                ->withHeaders(['x-apikey' => $key])
                ->acceptJson()
                ->timeout((int) config('files.scanning.virustotal.timeout', 10))
                ->get('/files/'.$sha256);
        } catch (ConnectionException) {
            return ScanVerdict::error('virustotal: sin conexión');
        }

        if ($response->status() === 404) {
            return ScanVerdict::clean('virustotal: hash desconocido');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ScanVerdict::error('virustotal: clave rechazada ('.$response->status().')', retryable: false);
        }

        if (! $response->successful()) {
            return ScanVerdict::error('virustotal: respuesta '.$response->status());
        }

        $malicious = (int) $response->json('data.attributes.last_analysis_stats.malicious', 0);
        $min = max(1, (int) config('files.scanning.virustotal.min_detections', 2));

        return $malicious >= $min
            ? ScanVerdict::infected("virustotal:{$malicious} motores")
            : ScanVerdict::clean();
    }
}
