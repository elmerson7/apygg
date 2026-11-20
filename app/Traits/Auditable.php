<?php

namespace App\Traits;

use App\Contracts\AuditServiceInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Auditable — auditoría de negocio opt-in por modelo (docs/audit.md).
 *
 * Registra crear, actualizar, borrar y restaurar con el DIFF antes/después, quién (usuario, API key o
 * sistema), el tenant, la IP y el `request_id`. Nunca guarda los `$hidden` del modelo, los de `$auditExclude`
 * ni `audit.exclude`, y los valores pasan por `Redactor` (secretos y datos personales).
 *
 * Opcional en el modelo:
 *   protected array $auditExclude = ['campo_ruidoso'];   // no se audita ese atributo
 *   protected array $auditOnly = ['status', 'plan'];     // solo se auditan estos
 *
 * No cubre las operaciones masivas de query (`Model::query()->update()`): usa el modelo o registra
 * a mano con `AuditServiceInterface::recordAction()`.
 *
 * @mixin Model
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(fn (self $model) => $model->auditEvent('created'));
        static::updated(fn (self $model) => $model->auditEvent('updated'));
        static::deleted(fn (self $model) => $model->auditEvent('deleted'));

        // Todos los modelos de la app heredan SoftDeletes del `Model` base; sin él no hay `restored`.
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent('restored', fn (self $model) => $model->auditEvent('restored'));
        }
    }

    /**
     * @internal lo llaman los eventos del modelo
     */
    public function auditEvent(string $event): void
    {
        $old = null;
        $new = null;

        if ($event === 'updated') {
            $keys = array_values(array_filter(array_keys($this->getChanges()), $this->isAudited(...)));

            if ($keys === []) {
                return;
            }

            $before = $this->auditCast($this->getOriginal());
            $after = $this->auditCast($this->getAttributes());
            $old = array_intersect_key($before, array_flip($keys));
            $new = array_intersect_key($after, array_flip($keys));
        } elseif ($event === 'created') {
            $new = $this->auditedAttributes();
        } else {
            // deleted / restored: el estado del registro en ese momento
            $old = $this->auditedAttributes();
        }

        app(AuditServiceInterface::class)->recordModelEvent($this, $event, $old, $new);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditedAttributes(): array
    {
        return array_filter(
            $this->auditCast($this->getAttributes()),
            fn (string $key): bool => $this->isAudited($key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Valores con los casts del modelo aplicados. Si un atributo JSON se asignó ya como array (sin codificar),
     * el cast lanzaría `TypeError`: la auditoría nunca debe romper el guardado, así que se usan los valores tal cual.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function auditCast(array $raw): array
    {
        try {
            return $this->newInstance([], true)->setRawAttributes($raw)->attributesToArray();
        } catch (\TypeError) {
            return $raw;
        }
    }

    private function isAudited(string $attribute): bool
    {
        if (in_array($attribute, $this->getHidden(), true)
            || in_array($attribute, (array) config('audit.exclude', []), true)
            || in_array($attribute, $this->auditList('auditExclude'), true)) {
            return false;
        }

        $only = $this->auditList('auditOnly');

        return $only === [] || in_array($attribute, $only, true);
    }

    /**
     * @return list<string>
     */
    private function auditList(string $property): array
    {
        return property_exists($this, $property) ? array_values((array) $this->{$property}) : [];
    }
}
