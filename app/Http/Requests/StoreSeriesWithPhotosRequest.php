<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация создания серии с пакетной загрузкой фото.
 */
class StoreSeriesWithPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'photos' => ['required', 'array', 'min:1', 'max:50'],
            'photos.*' => [
                'required',
                'file',
                'image',
                'max:20480',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
        ];
    }
}
