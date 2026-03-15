<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class SocialCallbackRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'         => ['required', 'string'],
            'redirect_uri' => ['required', 'url'],
        ];
    }
}
