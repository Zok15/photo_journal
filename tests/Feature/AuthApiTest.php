<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_token_and_user(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
        $response->assertJsonPath('user.locale', 'ru');
        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
            'locale' => 'ru',
        ]);
    }

    public function test_login_returns_token_for_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'bob@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_profile_requires_valid_token(): void
    {
        $response = $this->getJson('/api/v1/profile');

        $response->assertUnauthorized();
    }

    public function test_profile_alias_auth_me_is_equivalent_for_get(): void
    {
        $user = User::factory()->create([
            'name' => 'Alias Check',
            'journal_title' => 'Bird Notes',
        ]);

        Sanctum::actingAs($user);

        $profile = $this->getJson('/api/v1/profile');
        $authMe = $this->getJson('/api/v1/auth/me');

        $profile->assertOk();
        $authMe->assertOk();

        $this->assertSame($profile->json(), $authMe->json());
    }

    public function test_logout_revokes_current_token(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => 'password123',
        ]);

        $token = $register->json('token');
        $tokenModel = PersonalAccessToken::findToken($token);
        $this->assertNotNull($tokenModel);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $tokenStillExists = PersonalAccessToken::query()
            ->whereKey($tokenModel->id)
            ->exists();

        $this->assertFalse($tokenStillExists);
    }

    public function test_update_me_updates_name_and_journal_title(): void
    {
        $user = User::factory()->create([
            'name' => 'Before',
            'journal_title' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/profile', [
            'name' => 'After',
            'journal_title' => 'Bird Notes',
            'locale' => 'en',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'After');
        $response->assertJsonPath('data.journal_title', 'Bird Notes');
        $response->assertJsonPath('data.locale', 'en');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'After',
            'journal_title' => 'Bird Notes',
            'locale' => 'en',
        ]);
    }

    public function test_update_me_rejects_taken_email(): void
    {
        $current = User::factory()->create([
            'email' => 'current@example.com',
        ]);

        User::factory()->create([
            'email' => 'taken@example.com',
        ]);

        Sanctum::actingAs($current);

        $response = $this->patchJson('/api/v1/profile', [
            'email' => 'taken@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_profile_alias_auth_me_is_equivalent_for_patch(): void
    {
        $user = User::factory()->create([
            'name' => 'Before',
            'journal_title' => null,
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'name' => 'After Alias',
            'journal_title' => 'Alias Journal',
        ];

        $profile = $this->patchJson('/api/v1/profile', $payload);
        $authMe = $this->patchJson('/api/v1/auth/me', $payload);

        $profile->assertOk();
        $authMe->assertOk();

        $this->assertSame($profile->json(), $authMe->json());
    }

    public function test_forgot_password_sends_reset_notification_for_existing_user(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'reset@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'If the account exists, a reset link has been sent.');
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);
    }

    public function test_forgot_password_returns_generic_message_for_unknown_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'If the account exists, a reset link has been sent.');
        Notification::assertNothingSent();
    }

    public function test_forgot_password_returns_503_when_mail_transport_fails(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new \RuntimeException('Mail transport failed'));

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'reset@example.com',
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('message', 'Password reset email service is unavailable.');
    }

    public function test_reset_password_updates_password_and_allows_login_with_new_one(): void
    {
        $user = User::factory()->create([
            'email' => 'restore@example.com',
            'password' => 'old-password-123',
        ]);

        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Password has been reset.');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'old-password-123',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'new-password-123',
        ])->assertOk();
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'bad-token@example.com',
            'password' => 'old-password-123',
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertUnprocessable();
    }
}
