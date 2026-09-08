<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\School\Models\AcademicYear;
use App\Domain\School\Models\School;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reference data every test needs. Cheap enough to run per test, and
        // it keeps tests independent of seeding order.
        $this->seed(PermissionSeeder::class);

        // The permission registry memoises per request; a test process is one
        // long request, so it must be cleared between cases.
        app(PermissionRegistry::class)->flushLocal();
    }

    /**
     * Make a bearer-token request behave like a genuinely separate request.
     *
     * Laravel keeps one application instance for the whole test, so the auth
     * guard memoises the resolved user across calls. That makes revocation
     * tests pass for the wrong reason: a token deleted mid-test still
     * "authenticates" because the guard never re-reads it. Forgetting the
     * guards first forces the token to be looked up again, as it would be in
     * production where every request boots fresh.
     *
     * Scoped to requests that actually carry an Authorization header, so
     * `Sanctum::actingAs()` — which sets the user on the guard directly and
     * has no token to re-read — keeps working.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $carriesBearerToken = isset($server['HTTP_AUTHORIZATION'])
            || isset($this->defaultHeaders['Authorization']);

        if ($carriesBearerToken) {
            $this->app['auth']->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();

        parent::tearDown();
    }

    /** A tenant with an active academic year, ready to receive data. */
    public function makeSchool(array $attributes = []): School
    {
        $school = School::factory()->create($attributes);

        AcademicYear::query()->create([
            'school_id' => $school->id,
            'name' => now()->year.'-'.(now()->year + 1),
            'starts_on' => CarbonImmutable::create((int) now()->year, 9, 1),
            'ends_on' => CarbonImmutable::create((int) now()->year + 1, 6, 30),
            'status' => AcademicYear::STATUS_ACTIVE,
            'grading_scale_max' => 100,
            'passing_grade' => 50,
        ]);

        return $school;
    }

    /** A user in the given school holding one of the seeded system roles. */
    public function makeUser(School $school, string $role = Role::SCHOOL_ADMIN, array $attributes = []): User
    {
        $user = User::factory()->create(['school_id' => $school->id, ...$attributes]);

        $roleModel = Role::query()->where('name', $role)->availableTo($school->id)->firstOrFail();
        $user->roles()->attach($roleModel->id, ['assigned_at' => now()]);
        $user->bumpPermissionsVersion();

        return $user->fresh(['roles', 'school']);
    }

    public function makePlatformAdmin(): User
    {
        $user = User::factory()->create(['school_id' => null]);

        $role = Role::query()->where('name', Role::PLATFORM_SUPER_ADMIN)->whereNull('school_id')->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);
        $user->bumpPermissionsVersion();

        return $user->fresh(['roles']);
    }

    /**
     * Authenticate for API calls.
     *
     * Token abilities mirror the user's effective permissions, exactly as
     * AuthenticationService issues them, so ability checks are exercised
     * rather than bypassed.
     */
    public function actingAsUser(User $user): static
    {
        Sanctum::actingAs($user, $user->permissionNames());

        return $this;
    }

    /** Run a closure with a given school as the active tenant. */
    public function withinTenant(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->runFor($school, fn () => $callback());
    }

    public function seedPlans(): void
    {
        $this->seed(PlanSeeder::class);
    }
}
