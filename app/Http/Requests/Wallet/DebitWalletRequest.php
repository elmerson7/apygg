<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\BaseFormRequest;

class DebitWalletRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'reference_type' => ['nullable', 'string', 'max:64'],
            'reference_id' => ['nullable', 'uuid'],
        ];
    }

    protected function getCustomAttributes(): array
    {
        return [
            'amount' => 'monto',
            'idempotency_key' => 'clave de idempotencia',
        ];
    }
}
