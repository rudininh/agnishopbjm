<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_success()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['uuid', 'name', 'email'],
            'token'
        ]);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_login_success()
    {
        $user = User::factory()->create([
            'password' => bcrypt('Password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['uuid', 'name', 'email'],
            'token'
        ]);
    }

    public function test_login_invalid_credentials()
    {
        User::factory()->create(['password' => bcrypt('correct')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'Wrong123',
        ]);

        $response->assertStatus(401);
    }

    public function test_logout_success()
    {
        $user = User::factory()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/auth/logout');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Logout sukses']);
    }
}
