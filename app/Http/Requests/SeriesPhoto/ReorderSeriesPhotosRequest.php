<?php

namespace App\Http\Requests\SeriesPhoto;

use Illuminate\Foundation\Http\FormRequest;

class ReorderSeriesPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo_ids' => ['required', 'array', 'min:1'],
            'photo_ids.*' => ['required', 'integer', 'distinct', 'exists:photos,id'],
        ];
    }
}
