<?php

namespace App\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regla de validación para imagen en Base64
 * 
 * Valida que el valor sea una imagen válida codificada en Base64.
 */
class ValidBase64Image implements ValidationRule
{
    /**
     * Tipos MIME permitidos
     */
    protected array $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Tamaño máximo en bytes (por defecto 5MB)
     */
    protected int $maxSize = 5242880;

    /**
     * Crear instancia con tipos MIME específicos
     */
    public static function mimes(array $mimes): self
    {
        $rule = new self();
        $rule->allowedMimes = $mimes;
        return $rule;
    }

    /**
     * Crear instancia con tamaño máximo específico
     */
    public static function maxSize(int $bytes): self
    {
        $rule = new self();
        $rule->maxSize = $bytes;
        return $rule;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('El campo :attribute debe ser una imagen codificada en Base64.');
            return;
        }

        // Verificar formato Base64
        if (!preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/', $value)) {
            $fail('El campo :attribute debe tener el formato: data:image/[tipo];base64,[datos].');
            return;
        }

        // Extraer datos Base64
        $base64Data = preg_replace('/^data:image\/[^;]+;base64,/', '', $value);
        
        // Validar que sea Base64 válido
        if (!base64_decode($base64Data, true)) {
            $fail('El campo :attribute contiene datos Base64 inválidos.');
            return;
        }

        // Validar tamaño
        $size = strlen(base64_decode($base64Data));
        if ($size > $this->maxSize) {
            $maxSizeMB = round($this->maxSize / 1048576, 2);
            $fail("El campo :attribute no puede ser mayor a {$maxSizeMB}MB.");
            return;
        }

        // Validar que sea una imagen válida
        $imageData = base64_decode($base64Data);
        $imageInfo = @getimagesizefromstring($imageData);

        if ($imageInfo === false) {
            $fail('El campo :attribute debe ser una imagen válida.');
            return;
        }

        // Validar tipo MIME
        $mimeType = $imageInfo['mime'];
        if (!in_array($mimeType, $this->allowedMimes)) {
            $allowedTypes = implode(', ', $this->allowedMimes);
            $fail("El campo :attribute debe ser uno de los siguientes tipos: {$allowedTypes}.");
        }
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'El campo :attribute debe ser una imagen válida codificada en Base64.';
    }
}
