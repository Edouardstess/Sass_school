<?php

declare(strict_types=1);

use App\Domain\Identity\Models\LoginAttempt;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->school = $this->makeSchool();
    $this->user = $this->makeUser($this->school, Role::SCHOOL_ADMIN, [
        'email' => 'admin@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);
});

it('issues a token on valid credentials', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery-staple',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id', 'email']]]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

it('accepts the address case-insensitively', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'ADMIN@Example.TEST',
        'password' => 'correct-horse-battery-staple',
    ])->assertOk();
});

it('rejects a wrong password with the same message as an unknown address', function (): void {
    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'wrong',
    ]);

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.test',
        'password' => 'wrong',
    ]);

    // Distinguishable responses would let an attacker enumerate accounts.
    $wrongPassword->assertStatus(422);
    $unknownEmail->assertStatus(422);

    expect($wrongPassword->json('errors.email'))->toBe($unknownEmail->json('errors.email'));
});

it('records every attempt, successful or not', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.test', 'password' => 'nope']);
    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.test', 'password' => 'correct-horse-battery-staple']);

    expect(LoginAttempt::query()->where('successful', false)->count())->toBe(1)
        ->and(LoginAttempt::query()->where('successful', true)->count())->toBe(1);
});

it('throttles after the configured number of failures', function (): void {
    config(['schoolflow.security.max_login_attempts' => 3]);

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.test', 'password' => 'nope']);
    }

    // The correct password is now refused too: the lockout is on the
    // (email, IP) pair, not on whether this particular guess was right.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertStatus(422);

    expect(LoginAttempt::query()->where('failure_reason', LoginAttempt::REASON_THROTTLED)->exists())->toBeTrue();
});

it('refuses a disabled account', function (): void {
    $this->user->forceFill(['status' => User::STATUS_DISABLED])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertStatus(422);

    expect(LoginAttempt::query()->where('failure_reason', LoginAttempt::REASON_ACCOUNT_DISABLED)->exists())->toBeTrue();
});

it('locks out a whole school when its tenant is suspended', function (): void {
    $this->school->forceFill(['status' => School::STATUS_SUSPENDED])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertStatus(422);
});

it('still lets a platform admin in while a tenant is suspended', function (): void {
    $this->school->forceFill(['status' => School::STATUS_SUSPENDED])->save();

    $platform = $this->makePlatformAdmin();
    $platform->forceFill(['password' => Hash::make('platform-pass-123')])->save();

    // Platform staff must be able to sign in precisely when a school is in
    // trouble — that is when they are needed.
    $this->postJson('/api/v1/auth/login', [
        'email' => $platform->email,
        'password' => 'platform-pass-123',
    ])->assertOk();
});

it('returns the caller profile with their effective permissions', function (): void {
    $response = $this->actingAsUser($this->user)->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonPath('data.user.email', 'admin@example.test')
        ->assertJsonPath('data.school.id', $this->school->id);

    expect($response->json('data.permissions'))->toContain('students.view')
        ->and($response->json('data.roles'))->toContain(Role::SCHOOL_ADMIN);
});

it('rejects unauthenticated access to protected endpoints', function (): void {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->getJson('/api/v1/students')->assertUnauthorized();
});

it('revokes the token on logout', function (): void {
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('revokes every token when the password changes', function (): void {
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/password', [
            'current_password' => 'correct-horse-battery-staple',
            'password' => 'An0ther-Strong-Secret',
            'password_confirmation' => 'An0ther-Strong-Secret',
        ])->assertOk();

    // A password change is usually a response to compromise; leaving old
    // sessions alive would defeat the point.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('refuses a password change without the current password', function (): void {
    $this->actingAsUser($this->user)
        ->postJson('/api/v1/auth/password', [
            'current_password' => 'not-the-password',
            'password' => 'An0ther-Strong-Secret',
            'password_confirmation' => 'An0ther-Strong-Secret',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.current_password.0', __('auth.current_password_invalid'));
});

it('enforces password strength on change', function (): void {
    $this->actingAsUser($this->user)
        ->postJson('/api/v1/auth/password', [
            'current_password' => 'correct-horse-battery-staple',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('never returns the password hash or 2FA secret', function (): void {
    $response = $this->actingAsUser($this->user)->getJson('/api/v1/auth/me');

    $body = $response->getContent();

    expect($body)->not->toContain('password')
        ->and($body)->not->toContain('two_factor_secret');
});
