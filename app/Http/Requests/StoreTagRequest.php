<?php

namespace App\Http\Requests;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация ручного создания тега.
 */
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
                'regex:/^[A-Za-z0-9 ]+$/',
                'unique:tags,name',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = (string) $this->input('name', '');
        $collapsed = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        // Приводим тег к каноничному формату до валидации unique.
        if ($collapsed === '') {
            $normalized = '';
        } elseif (preg_match('/^[A-Za-z0-9 ]+$/', $collapsed) !== 1) {
            $normalized = $collapsed;
        } else {
            $normalized = Tag::normalizeTagName($collapsed);
        }

        $this->merge([
            'name' => $normalized,
        ]);
    }
}
