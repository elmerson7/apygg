<?php

namespace App\Traits;

use App\Helpers\Redactor;

/**
 * Redacta con App\Helpers\Redactor los atributos JSON de un modelo de log justo antes de
 * guardarlo, sea cual sea el camino de escritura. El modelo declara `$redactedAttributes`.
 */
trait RedactsLogData
{
    public static function bootRedactsLogData(): void
    {
        static::saving(function (self $model): void {
            foreach ($model->redactedAttributes as $attribute) {
                $value = $model->getAttribute($attribute);
                if (is_array($value)) {
                    $model->setAttribute($attribute, Redactor::redact($value));
                }
            }
        });
    }
}
