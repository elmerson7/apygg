<?php

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;

class QrCodeService
{
    protected QROptions $options;

    public function __construct()
    {
        $this->options = new QROptions([
            'version' => 7,
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => QRCode::ECC_H,
            'scale' => 10,
            'imageBase64' => false,
            'imageTransparent' => false,
        ]);
    }

    /**
     * Generate QR code and save to storage
     *
     * @param  string  $data  URL or data to encode
     * @param  string  $filename  Filename without extension
     * @return string Path to saved QR code
     */
    public function generate(string $data, string $filename): string
    {
        $qrcode = new QRCode($this->options);
        $qrImage = $qrcode->render($data);

        $path = "certificates/qr/{$filename}.png";
        Storage::disk('public')->put($path, $qrImage);

        return $path;
    }

    /**
     * Generate QR code with logo (optional)
     * Note: Logo support requires different approach in chillerlan/php-qrcode v5
     */
    public function generateWithLogo(string $data, string $filename, ?string $logoPath = null): string
    {
        // Logo embedding not supported in v5 without additional processing
        // Generate simple QR code for now
        return $this->generate($data, $filename);
    }

    /**
     * Delete QR code from storage
     */
    public function delete(string $path): bool
    {
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->delete($path);
        }

        return false;
    }

    /**
     * Get full URL of QR code
     */
    public function getUrl(string $path): string
    {
        $disk = Storage::disk('public');
        $url = $disk->url($path);

        // If URL is empty or invalid, construct manually
        if (empty($url) || $url === '/') {
            $baseUrl = config('app.url', 'http://localhost');
            $url = rtrim($baseUrl, '/').'/storage/'.$path;
        }

        return $url;
    }
}
