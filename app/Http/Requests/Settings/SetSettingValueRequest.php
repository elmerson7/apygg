<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\BaseFormRequest;

/**
 * Valor de un ajuste declarado. El TIPO (bool/int/string/enum/json) lo valida el registro
 * (422 `setting_invalid_value`); aquí solo se exige que venga.
 */
class SetSettingValueRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['value' => ['present']];
    }
}
