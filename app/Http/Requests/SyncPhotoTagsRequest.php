<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncPhotoTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tags' => ['required', 'array', 'min:1', 'max:50'],
            'tags.*' => ['required', 'string', 'max:50'],
        ];
    }
}
