<?php

namespace App\Support\OfflineSync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Definición de un recurso sincronizable (entrada de `config/offline-sync.php` -> `resources`).
 */
final class SyncResource
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string<JsonResource>|null  $resource
     * @param  array<string, string|list<string>>  $permissions
     * @param  array<string, mixed>  $createRules
     * @param  array<string, mixed>  $updateRules
     */
    public function __construct(
        public readonly string $name,
        public readonly string $model,
        public readonly ?string $resource,
        public readonly array $permissions,
        public readonly array $createRules,
        public readonly array $updateRules,
        public readonly bool $requireIfMatch,
    ) {}
}
