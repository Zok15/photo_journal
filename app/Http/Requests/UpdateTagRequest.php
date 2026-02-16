<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tag = $this->route('tag');

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z ]+$/',
                Rule::unique('tags', 'name')->ignore($tag),
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
