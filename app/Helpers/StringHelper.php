<?php

namespace App\Helpers;

use Illuminate\Support\Str;

/**
 * StringHelper
 * 
 * Helper estático para operaciones con strings:
 * slugs, truncamiento, conversión de casos, enmascaramiento.
 * 
 * @package App\Helpers
 */
class StringHelper
{
    /**
     * Generar slug desde string
     *
     * @param string $string String a convertir
     * @param string $separator Separador (default: '-')
     * @param string|null $language Idioma para transliteración (default: 'es')
     * @return string Slug generado
     */
    public static function slugify(string $string, string $separator = '-', ?string $language = 'es'): string
    {
        return Str::slug($string, $separator, $language);
    }

    /**
     * Truncar string a longitud específica
     *
     * @param string $string String a truncar
     * @param int $length Longitud máxima
     * @param string $suffix Sufijo cuando se trunca (default: '...')
     * @return string String truncado
     */
    public static function truncate(string $string, int $length, string $suffix = '...'): string
    {
        return Str::limit($string, $length, $suffix);
    }

    /**
     * Truncar string por palabras
     *
     * @param string $string String a truncar
     * @param int $words Número máximo de palabras
     * @param string $suffix Sufijo cuando se trunca (default: '...')
     * @return string String truncado por palabras
     */
    public static function truncateWords(string $string, int $words, string $suffix = '...'): string
    {
        return Str::words($string, $words, $suffix);
    }

    /**
     * Convertir a camelCase
     *
     * @param string $string String a convertir
     * @return string String en camelCase
     */
    public static function toCamelCase(string $string): string
    {
        return Str::camel($string);
    }

    /**
     * Convertir a snake_case
     *
     * @param string $string String a convertir
     * @return string String en snake_case
     */
    public static function toSnakeCase(string $string): string
    {
        return Str::snake($string);
    }

    /**
     * Convertir a PascalCase
     *
     * @param string $string String a convertir
     * @return string String en PascalCase
     */
    public static function toPascalCase(string $string): string
    {
        return Str::studly($string);
    }

    /**
     * Convertir a kebab-case
     *
     * @param string $string String a convertir
     * @return string String en kebab-case
     */
    public static function toKebabCase(string $string): string
    {
        return Str::kebab($string);
    }

    /**
     * Pluralizar palabra
     *
     * @param string $word Palabra a pluralizar
     * @param int $count Cantidad (opcional, para decidir si pluralizar)
     * @return string Palabra pluralizada
     */
    public static function pluralize(string $word, ?int $count = null): string
    {
        $plural = Str::plural($word, $count ?? 2);
        
        return $plural;
    }

    /**
     * Singularizar palabra
     *
     * @param string $word Palabra a singularizar
     * @return string Palabra singularizada
     */
    public static function singularize(string $word): string
    {
        return Str::singular($word);
    }

    /**
     * Enmascarar string para datos sensibles
     *
     * @param string $string String a enmascarar
     * @param int $visibleStart Caracteres visibles al inicio (default: 3)
     * @param int $visibleEnd Caracteres visibles al final (default: 3)
     * @param string $mask Carácter de enmascaramiento (default: '*')
     * @return string String enmascarado
     */
    public static function mask(
        string $string,
        int $visibleStart = 3,
        int $visibleEnd = 3,
        string $mask = '*'
    ): string {
        $length = strlen($string);

        if ($length <= ($visibleStart + $visibleEnd)) {
            return str_repeat($mask, $length);
        }

        $start = substr($string, 0, $visibleStart);
        $end = substr($string, -$visibleEnd);
        $middle = str_repeat($mask, $length - $visibleStart - $visibleEnd);

        return $start . $middle . $end;
    }

    /**
     * Enmascarar completamente un string
     *
     * @param string $string String a enmascarar
     * @param string $mask Carácter de enmascaramiento (default: '*')
     * @return string String completamente enmascarado
     */
    public static function maskAll(string $string, string $mask = '*'): string
    {
        return str_repeat($mask, strlen($string));
    }

    /**
     * Limpiar string de caracteres especiales
     *
     * @param string $string String a limpiar
     * @param bool $preserveSpaces Si preservar espacios (default: true)
     * @return string String limpio
     */
    public static function clean(string $string, bool $preserveSpaces = true): string
    {
        $pattern = $preserveSpaces ? '/[^a-zA-Z0-9\s]/' : '/[^a-zA-Z0-9]/';
        
        return preg_replace($pattern, '', $string);
    }

    /**
     * Remover espacios extra y normalizar
     *
     * @param string $string String a normalizar
     * @return string String normalizado
     */
    public static function normalizeWhitespace(string $string): string
    {
        return preg_replace('/\s+/', ' ', trim($string));
    }

    /**
     * Extraer números de un string
     *
     * @param string $string String del cual extraer números
     * @return string String con solo números
     */
    public static function extractNumbers(string $string): string
    {
        return preg_replace('/\D/', '', $string);
    }

    /**
     * Extraer letras de un string
     *
     * @param string $string String del cual extraer letras
     * @return string String con solo letras
     */
    public static function extractLetters(string $string): string
    {
        return preg_replace('/[^a-zA-Z]/', '', $string);
    }

    /**
     * Capitalizar primera letra de cada palabra
     *
     * @param string $string String a capitalizar
     * @return string String capitalizado
     */
    public static function capitalizeWords(string $string): string
    {
        return Str::title($string);
    }

    /**
     * Capitalizar solo la primera letra
     *
     * @param string $string String a capitalizar
     * @return string String con primera letra mayúscula
     */
    public static function capitalizeFirst(string $string): string
    {
        return Str::ucfirst($string);
    }

    /**
     * Convertir a minúsculas
     *
     * @param string $string String a convertir
     * @return string String en minúsculas
     */
    public static function toLower(string $string): string
    {
        return Str::lower($string);
    }

    /**
     * Convertir a mayúsculas
     *
     * @param string $string String a convertir
     * @return string String en mayúsculas
     */
    public static function toUpper(string $string): string
    {
        return Str::upper($string);
    }

    /**
     * Verificar si string contiene substring (case-insensitive)
     *
     * @param string $haystack String donde buscar
     * @param string $needle String a buscar
     * @return bool true si contiene el substring
     */
    public static function contains(string $haystack, string $needle): bool
    {
        return Str::contains($haystack, $needle);
    }

    /**
     * Verificar si string comienza con substring
     *
     * @param string $haystack String donde buscar
     * @param string $needle String a buscar
     * @return bool true si comienza con el substring
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        return Str::startsWith($haystack, $needle);
    }

    /**
     * Verificar si string termina con substring
     *
     * @param string $haystack String donde buscar
     * @param string $needle String a buscar
     * @return bool true si termina con el substring
     */
    public static function endsWith(string $haystack, string $needle): bool
    {
        return Str::endsWith($haystack, $needle);
    }

    /**
     * Reemplazar primera ocurrencia
     *
     * @param string $search String a buscar
     * @param string $replace String de reemplazo
     * @param string $subject String donde buscar
     * @return string String con reemplazo
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        return Str::replaceFirst($search, $replace, $subject);
    }

    /**
     * Reemplazar última ocurrencia
     *
     * @param string $search String a buscar
     * @param string $replace String de reemplazo
     * @param string $subject String donde buscar
     * @return string String con reemplazo
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        return Str::replaceLast($search, $replace, $subject);
    }

    /**
     * Generar string aleatorio
     *
     * @param int $length Longitud del string
     * @return string String aleatorio
     */
    public static function random(int $length = 16): string
    {
        return Str::random($length);
    }

    /**
     * Generar UUID v4
     *
     * @return string UUID generado
     */
    public static function uuid(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Verificar si string es UUID válido
     *
     * @param string $string String a verificar
     * @return bool true si es UUID válido
     */
    public static function isUuid(string $string): bool
    {
        return Str::isUuid($string);
    }

    /**
     * Obtener substring entre dos strings
     *
     * @param string $string String completo
     * @param string $start String inicial
     * @param string $end String final
     * @return string|null Substring encontrado o null
     */
    public static function between(string $string, string $start, string $end): ?string
    {
        $startPos = strpos($string, $start);
        
        if ($startPos === false) {
            return null;
        }

        $startPos += strlen($start);
        $endPos = strpos($string, $end, $startPos);

        if ($endPos === false) {
            return null;
        }

        return substr($string, $startPos, $endPos - $startPos);
    }

    /**
     * Remover acentos y caracteres especiales
     *
     * @param string $string String a limpiar
     * @return string String sin acentos
     */
    public static function removeAccents(string $string): string
    {
        return Str::ascii($string);
    }

    /**
     * Obtener palabras de un string
     *
     * @param string $string String del cual extraer palabras
     * @return array Array de palabras
     */
    public static function words(string $string): array
    {
        return Str::wordCount($string) > 0 ? explode(' ', $string) : [];
    }

    /**
     * Contar palabras en un string
     *
     * @param string $string String a contar
     * @return int Número de palabras
     */
    public static function wordCount(string $string): int
    {
        return Str::wordCount($string);
    }
}
