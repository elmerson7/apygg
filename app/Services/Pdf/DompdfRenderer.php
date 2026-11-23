<?php

namespace App\Services\Pdf;

use App\Contracts\PdfRendererInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;

/**
 * PDF con dompdf. Seguro por defecto: sin PHP embebido ni recursos remotos (SSRF) y con lectura de
 * archivos locales limitada a `export.pdf.chroot`. Sin estado entre llamadas (Octane).
 */
class DompdfRenderer implements PdfRendererInterface
{
    private const PAPERS = ['a3', 'a4', 'a5', 'letter', 'legal'];

    public function render(string $html, array $options = []): string
    {
        $paper = strtolower((string) ($options['paper'] ?? config('export.pdf.paper', 'a4')));
        $orientation = strtolower((string) ($options['orientation'] ?? config('export.pdf.orientation', 'portrait')));

        if (! in_array($paper, self::PAPERS, true) || ! in_array($orientation, ['portrait', 'landscape'], true)) {
            throw new InvalidArgumentException('Tamaño u orientación de papel no válidos.');
        }

        $cache = storage_path('framework/cache/dompdf');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $dompdfOptions = new Options;
        $dompdfOptions->set('isRemoteEnabled', false);
        $dompdfOptions->set('isPhpEnabled', false);
        $dompdfOptions->set('isJavascriptEnabled', false);
        $dompdfOptions->set('chroot', (string) config('export.pdf.chroot', resource_path()));
        $dompdfOptions->set('tempDir', sys_get_temp_dir());
        $dompdfOptions->set('fontCache', $cache);
        $dompdfOptions->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function renderView(string $view, array $data = [], array $options = []): string
    {
        /** @var view-string $viewName */
        $viewName = $view;

        return $this->render(view($viewName, $data)->render(), $options);
    }
}
