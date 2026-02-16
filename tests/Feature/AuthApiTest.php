<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
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
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'After');
        $response->assertJsonPath('data.journal_title', 'Bird Notes');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'After',
            'journal_title' => 'Bird Notes',
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
}
