<?php

namespace App\Http\Requests\Series;

use Illuminate\Foundation\Http\FormRequest;

class ShowSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'include_photos' => ['nullable', 'boolean'],
            'photos_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status_only' => ['nullable', 'boolean'],
            'include_blocking_tags' => ['nullable', 'boolean'],
        ];
    }
}
