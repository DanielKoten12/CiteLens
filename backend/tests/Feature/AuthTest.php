<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('registers a user and issues a bearer token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Daniel Belawa Koten',
        'email' => 'daniel@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('message', 'Registrasi berhasil.')
        ->assertJsonPath('data.user.name', 'Daniel Belawa Koten')
        ->assertJsonPath('data.user.email', 'daniel@example.com')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure([
            'data' => [
                'user' => ['id', 'name', 'email', 'created_at'],
                'token',
                'token_type',
            ],
            'message',
        ]);

    expect($response->json('data.user.id'))->toBeUuid()
        ->and($response->json('data.token'))->toBeString()->not->toBeEmpty();

    $user = User::query()->where('email', 'daniel@example.com')->firstOrFail();

    expect(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('password123');

    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('rejects registration with a duplicate email with 422', function () {
    User::factory()->create(['email' => 'daniel@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Daniel Belawa Koten',
        'email' => 'daniel@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.message', 'The given data was invalid.')
        ->assertJsonPath('error.details.email.0', 'The email has already been taken.');

    $this->assertDatabaseCount('users', 1);
});

it('rejects registration with a password mismatch with 422', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Daniel Belawa Koten',
        'email' => 'daniel@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password124',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.details.password.0', 'The password field confirmation does not match.');

    $this->assertDatabaseCount('users', 0);
});

it('rejects registration with a password shorter than 8 characters with 422', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Daniel Belawa Koten',
        'email' => 'daniel@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['details' => ['password']]]);

    $this->assertDatabaseCount('users', 0);
});

it('rejects registration when required fields are missing with 422', function () {
    $this->postJson('/api/v1/auth/register', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['details' => ['name', 'email', 'password']]]);

    $this->assertDatabaseCount('users', 0);
});

it('logs in with valid credentials and issues a bearer token', function () {
    $user = User::factory()->create([
        'name' => 'Daniel Belawa Koten',
        'email' => 'daniel@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'daniel@example.com',
        'password' => 'password123',
    ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'daniel@example.com')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token', 'token_type']]);

    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('rejects login with a wrong password with 422', function () {
    User::factory()->create([
        'email' => 'daniel@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'daniel@example.com',
        'password' => 'wrong-password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.message', 'Kredensial tidak valid.')
        ->assertJsonPath('error.details.email.0', 'These credentials do not match our records.');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('rejects login with an unknown email with the same response as a wrong password', function () {
    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'password123',
    ]);

    User::factory()->create(['email' => 'daniel@example.com', 'password' => 'password123']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'daniel@example.com',
        'password' => 'wrong-password',
    ]);

    $unknownEmail->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.message', 'Kredensial tidak valid.')
        ->assertJsonPath('error.details.email.0', 'These credentials do not match our records.');

    expect($unknownEmail->json('error'))->toBe($wrongPassword->json('error'));
});

it('returns the authenticated user from /auth/me', function () {
    $user = User::factory()->create([
        'name' => 'Daniel Belawa Koten',
        'email' => 'daniel@example.com',
    ]);
    $token = $user->createToken('auth-token')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'Daniel Belawa Koten')
        ->assertJsonPath('data.email', 'daniel@example.com')
        ->assertJsonStructure(['data' => ['id', 'name', 'email', 'created_at']])
        ->assertJsonMissingPath('data.token');
});

it('returns 401 from /auth/me without a token', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED')
        ->assertJsonPath('error.message', 'Unauthenticated.');
});

it('revokes the current token on logout and rejects it afterwards', function () {
    $user = User::factory()->create();
    $token = $user->createToken('auth-token')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('message', 'Logout berhasil.');

    $this->assertDatabaseCount('personal_access_tokens', 0);

    // The auth guard caches the resolved user within a single test process;
    // forget it so the next request is authenticated from scratch.
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('returns 401 when logging out without a token', function () {
    $this->postJson('/api/v1/auth/logout')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('throttles login after 5 attempts per minute with 429', function () {
    User::factory()->create(['email' => 'daniel@example.com', 'password' => 'password123']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'daniel@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'daniel@example.com',
        'password' => 'wrong-password',
    ])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertJsonPath('error.message', 'Too many attempts. Please try again later.');
});
