<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Контроллер профиля текущего пользователя.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // Возвращаем пользователя из текущего токена.
        return response()->json([
            'data' => $request->user(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Частичное обновление профиля (PATCH).
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'journal_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'required', 'in:ru,en'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->update($data);

        return response()->json([
            'data' => $user->fresh(),
        ]);
    }
}
