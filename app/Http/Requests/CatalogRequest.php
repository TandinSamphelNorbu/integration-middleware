<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => [
                'required',
                'string',
                'max:20',
            ],

            'type' => [
                'required',
                'string',
                'in:prepaid,postpaid',
            ],
        ];
    }
}
