<?php

namespace App\Contracts;

/**
 * Único servicio de PDF del boilerplate (implementación: dompdf, PHP puro, sin binarios externos).
 */
interface PdfRendererInterface
{
    /**
     * @param  array{paper?: string, orientation?: string}  $options
     * @return string Contenido binario del PDF
     */
    public function render(string $html, array $options = []): string;

    /**
     * @param  array<string, mixed>  $data
     * @param  array{paper?: string, orientation?: string}  $options
     */
    public function renderView(string $view, array $data = [], array $options = []): string;
}
