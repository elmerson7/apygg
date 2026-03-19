<?php

namespace App\Enums;

/**
 * FileTypeEnum
 *
 * Enumeración de tipos de archivos posibles en el sistema.
 */
enum FileTypeEnum: string
{
    // Imágenes
    case jpeg = 'image/jpeg';
    case png = 'image/png';
    case gif = 'image/gif';
    case webp = 'image/webp';
    case svg = 'image/svg+xml';

    // Documentos
    case pdf = 'application/pdf';
    case doc = 'application/msword';
    case docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    case xls = 'application/vnd.ms-excel';
    case xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case ppt = 'application/vnd.ms-powerpoint';
    case pptx = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    case txt = 'text/plain';
    case csv = 'text/csv';

    // Archivos comprimidos
    case zip = 'application/zip';
    case rar = 'application/vnd.rar';
    case tar = 'application/x-tar';
    case gz = 'application/gzip';

    // Otros
    case json = 'application/json';
    case xml = 'application/xml';
}