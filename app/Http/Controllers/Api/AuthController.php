<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Контроллер аутентификации API.
 * Отвечает за регистрацию, вход и выход пользователя по токену Sanctum.
 */
class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        // Валидируем входные данные перед созданием пользователя.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'locale' => ['nullable', 'in:ru,en'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'locale' => $data['locale'] ?? 'ru',
        ]);

        // Выдаем персональный API-токен для работы SPA/клиента.
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ], Response::HTTP_CREATED);
    }

    public function login(Request $request): JsonResponse
    {
        // Валидируем форму входа.
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        // Отдаем 422, чтобы фронтенд показал корректную ошибку формы.
        if ($user === null || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        // Удаляем только текущий токен, остальные сессии пользователя не трогаем.
        $user->currentAccessToken()?->delete();

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        try {
            $status = Password::sendResetLink([
                'email' => $data['email'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Password reset email dispatch failed.', [
                'email' => $data['email'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Password reset email service is unavailable.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Do not reveal whether the email exists in the system.
        if ($status === Password::RESET_LINK_SENT || $status === Password::INVALID_USER) {
            return response()->json([
                'message' => 'If the account exists, a reset link has been sent.',
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Force re-login on all devices after password reset.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Password has been reset.',
        ]);
    }
}
