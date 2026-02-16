<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z ]+$/',
                'unique:tags,name',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = (string) $this->input('name', '');
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');

        $this->merge([
            'name' => $normalized,
        ]);
    }
}
