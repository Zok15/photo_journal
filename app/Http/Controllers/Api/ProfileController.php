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
    private function makeProfilePayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'journal_title' => $user->journal_title,
            'locale' => $user->locale,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'can_moderate' => $user->hasAnyRole(['super_admin', 'moderator']),
        ];
    }

    public function show(Request $request): JsonResponse
    {
        // Возвращаем пользователя из текущего токена.
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->makeProfilePayload($user),
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

        $emailChanged = array_key_exists('email', $data) && (string) $data['email'] !== (string) $user->email;

        $user->fill($data);
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        /** @var User $fresh */
        $fresh = $user->fresh();

        return response()->json([
            'data' => $this->makeProfilePayload($fresh),
        ]);
    }
}
