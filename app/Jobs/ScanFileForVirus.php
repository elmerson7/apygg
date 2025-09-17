<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\FileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScanFileForVirus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public File $file
    ) {
        $this->onQueue('file-processing');
    }

    /**
     * Ejecuta el escaneo de virus
     */
    public function handle(FileService $fileService): void
    {
        try {
            Log::info('Starting virus scan for file', [
                'file_id' => $this->file->id,
                'path' => $this->file->path,
                'mime_type' => $this->file->mime_type,
            ]);

            // Verificar que el archivo existe y está en estado scanning
            if ($this->file->status !== File::STATUS_SCANNING) {
                Log::warning('File is not in scanning status', [
                    'file_id' => $this->file->id,
                    'status' => $this->file->status,
                ]);
                return;
            }

            if (!$this->file->existsInStorage()) {
                Log::error('File not found in storage during scan', [
                    'file_id' => $this->file->id,
                    'path' => $this->file->path,
                ]);
                $this->file->markAsFailed();
                return;
            }

            // Realizar escaneo según la configuración
            $scanResult = $this->performVirusScan();

            if ($scanResult['infected']) {
                Log::warning('Virus detected in file', [
                    'file_id' => $this->file->id,
                    'threat' => $scanResult['threat'] ?? 'Unknown',
                    'scanner' => $scanResult['scanner'] ?? 'Unknown',
                ]);

                // Mover a cuarentena
                $quarantined = $fileService->quarantineFile($this->file);
                
                if (!$quarantined) {
                    Log::error('Failed to quarantine infected file', [
                        'file_id' => $this->file->id,
                    ]);
                }

                // Actualizar metadata con información del escaneo
                $this->file->update([
                    'meta' => array_merge($this->file->meta ?? [], [
                        'scan_result' => $scanResult,
                        'scanned_at' => now()->toISOString(),
                    ]),
                ]);

            } else {
                Log::info('File scan completed - no threats detected', [
                    'file_id' => $this->file->id,
                    'scanner' => $scanResult['scanner'] ?? 'Unknown',
                ]);

                // Marcar como verificado
                $this->file->markAsVerified();
                
                // Actualizar metadata
                $this->file->update([
                    'meta' => array_merge($this->file->meta ?? [], [
                        'scan_result' => $scanResult,
                        'scanned_at' => now()->toISOString(),
                    ]),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error scanning file for virus', [
                'file_id' => $this->file->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->file->markAsFailed();
            throw $e; // Re-throw para que el job se marque como fallido
        }
    }

    /**
     * Realiza el escaneo de virus usando el método configurado
     */
    private function performVirusScan(): array
    {
        // Determinar método de escaneo basado en configuración
        $scannerType = config('files.antivirus_scanner', 'clamav');

        return match ($scannerType) {
            'clamav' => $this->scanWithClamAV(),
            'virustotal' => $this->scanWithVirusTotal(),
            'mock' => $this->mockScan(),
            default => $this->mockScan(),
        };
    }

    /**
     * Escaneo con ClamAV (requiere clamav instalado en el sistema)
     */
    private function scanWithClamAV(): array
    {
        try {
            // Descargar archivo temporalmente para escaneo
            $tempFile = $this->downloadFileForScanning();
            
            if (!$tempFile) {
                return [
                    'infected' => false,
                    'scanner' => 'clamav',
                    'error' => 'Could not download file for scanning',
                ];
            }

            // Ejecutar clamav
            $command = "clamscan --no-summary --infected {$tempFile}";
            $output = [];
            $returnCode = 0;
            
            exec($command, $output, $returnCode);
            
            // Limpiar archivo temporal
            unlink($tempFile);

            // Interpretar resultado
            // Return code 0: No virus, 1: Virus found, 2: Error
            if ($returnCode === 1) {
                $threat = $this->extractThreatFromClamAVOutput($output);
                return [
                    'infected' => true,
                    'scanner' => 'clamav',
                    'threat' => $threat,
                    'raw_output' => implode("\n", $output),
                ];
            }

            return [
                'infected' => false,
                'scanner' => 'clamav',
                'raw_output' => implode("\n", $output),
            ];

        } catch (\Exception $e) {
            Log::error('ClamAV scan failed', [
                'file_id' => $this->file->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'infected' => false,
                'scanner' => 'clamav',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Escaneo con VirusTotal API
     */
    private function scanWithVirusTotal(): array
    {
        // Implementación simplificada - en producción usarías la API de VirusTotal
        // Esta implementación es básica y requiere configuración adicional
        
        $apiKey = config('files.virustotal_api_key');
        if (!$apiKey) {
            return [
                'infected' => false,
                'scanner' => 'virustotal',
                'error' => 'VirusTotal API key not configured',
            ];
        }

        try {
            // Por simplicidad, verificamos primero por hash
            $report = $this->getVirusTotalReport($this->file->checksum);
            
            if ($report && isset($report['positives']) && $report['positives'] > 0) {
                return [
                    'infected' => true,
                    'scanner' => 'virustotal',
                    'threat' => 'Multiple detections',
                    'positives' => $report['positives'],
                    'total' => $report['total'],
                ];
            }

            return [
                'infected' => false,
                'scanner' => 'virustotal',
                'positives' => $report['positives'] ?? 0,
                'total' => $report['total'] ?? 0,
            ];

        } catch (\Exception $e) {
            Log::error('VirusTotal scan failed', [
                'file_id' => $this->file->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'infected' => false,
                'scanner' => 'virustotal',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Escaneo simulado para testing/desarrollo
     */
    private function mockScan(): array
    {
        // Simular infección si el nombre contiene "virus" o "infected"
        $infected = str_contains(strtolower($this->file->original_name), 'virus') ||
                   str_contains(strtolower($this->file->original_name), 'infected');

        return [
            'infected' => $infected,
            'scanner' => 'mock',
            'threat' => $infected ? 'Test.Virus.MockThreat' : null,
        ];
    }

    /**
     * Descarga archivo para escaneo local
     */
    private function downloadFileForScanning(): ?string
    {
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'file_scan_');
            $content = Storage::disk($this->file->disk)->get($this->file->path);
            
            if (file_put_contents($tempFile, $content) === false) {
                return null;
            }

            return $tempFile;
        } catch (\Exception $e) {
            Log::error('Failed to download file for scanning', [
                'file_id' => $this->file->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrae el nombre de la amenaza del output de ClamAV
     */
    private function extractThreatFromClamAVOutput(array $output): ?string
    {
        foreach ($output as $line) {
            if (str_contains($line, 'FOUND')) {
                $parts = explode(':', $line);
                if (count($parts) >= 2) {
                    return trim(str_replace('FOUND', '', $parts[1]));
                }
            }
        }
        return null;
    }

    /**
     * Obtiene reporte de VirusTotal por hash
     */
    private function getVirusTotalReport(string $hash): ?array
    {
        // Implementación simplificada - en producción harías llamada HTTP real
        // a la API de VirusTotal
        return null;
    }

    /**
     * Número máximo de intentos
     */
    public $tries = 3;

    /**
     * Tiempo límite del job en segundos
     */
    public $timeout = 300; // 5 minutos

    /**
     * Manejar fallo del job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Virus scan job failed after all retries', [
            'file_id' => $this->file->id,
            'error' => $exception->getMessage(),
        ]);

        $this->file->markAsFailed();
    }
}
