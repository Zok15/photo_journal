<?php

namespace App\Http\Requests\Series;

use Illuminate\Foundation\Http\FormRequest;

class ListSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:new,old,taken_new,taken_old'],
            'status_only' => ['nullable', 'boolean'],
            'include_blocking_tags' => ['nullable', 'boolean'],
        ];
    }
}
