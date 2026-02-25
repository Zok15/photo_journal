<?php

namespace App\Http\Requests\Series;

use Illuminate\Foundation\Http\FormRequest;

class ListPublicSeriesRequest extends FormRequest
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
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'date_field' => ['nullable', 'in:added,taken'],
            'sort' => ['nullable', 'in:new,old,taken_new,taken_old'],
        ];
    }
}
