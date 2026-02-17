<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация параметров листинга фото (пагинация + сортировка).
 */
class ListSeriesPhotosRequest extends FormRequest
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
            'sort_by' => ['nullable', 'string', 'in:id,created_at,original_name,size'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
