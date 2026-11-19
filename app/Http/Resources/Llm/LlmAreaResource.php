<?php

namespace App\Http\Resources\Llm;

use App\Http\Resources\BaseResource;
use App\Models\Llm\LlmArea;
use Illuminate\Http\Request;

/**
 * @mixin LlmArea
 */
class LlmAreaResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->getBaseFields(),
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'primary_provider' => $this->primary_provider,
            'primary_model' => $this->primary_model,
            'fallbacks' => $this->fallbacks ?? [],
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            // Cadena efectiva y si el área puede usarse: ahorra tener que
            // deducirlo en el cliente a partir de los campos anteriores.
            'chain' => $this->resource->chain(),
            'usable' => $this->resource->isUsable(),
        ];
    }
}
