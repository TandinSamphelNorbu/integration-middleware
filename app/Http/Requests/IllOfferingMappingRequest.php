<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IllOfferingMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'base_plan_name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
