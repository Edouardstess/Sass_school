<?php

declare(strict_types=1);

use App\Domain\Academic\Models\Level;
use App\Domain\Finance\Models\FeeType;
use App\Domain\Identity\Models\Role;
use App\Domain\Shared\Exceptions\TenantMismatchException;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\TenantContext;

/**
 * The isolation guarantee, tested at every layer independently.
 *
 * A hole in any one of them must not be enough to leak another school's data,
 * so each layer is exercised on its own rather than only through the API.
 */
beforeEach(function (): void {
    $this->schoolA = $this->makeSchool(['name' => 'École A']);
    $this->schoolB = $this->makeSchool(['name' => 'École B']);

    $this->studentA = $this->withinTenant($this->schoolA, fn () => Student::factory()->create([
        'school_id' => $this->schoolA->id,
        'first_name' => 'Alpha',
    ]));

    $this->studentB = $this->withinTenant($this->schoolB, fn () => Student::factory()->create([
        'school_id' => $this->schoolB->id,
        'first_name' => 'Bravo',
    ]));
});

// ---------------------------------------------------------------- query scope

it('confines every query to the active tenant', function (): void {
    $this->withinTenant($this->schoolA, function (): void {
        $students = Student::query()->get();

        expect($students)->toHaveCount(1)
            ->and($students->first()->id)->toBe($this->studentA->id);
    });

    $this->withinTenant($this->schoolB, function (): void {
        expect(Student::query()->pluck('id')->all())->toBe([$this->studentB->id]);
    });
});

it('cannot fetch another tenant\'s record even by its exact primary key', function (): void {
    $this->withinTenant($this->schoolA, function (): void {
        expect(Student::query()->find($this->studentB->id))->toBeNull();
    });
});

it('counts and aggregates only within the tenant', function (): void {
    $this->withinTenant($this->schoolA, function (): void {
        expect(Student::query()->count())->toBe(1);
    });
});

// -------------------------------------------------------------------- writes

it('stamps the active tenant onto new records automatically', function (): void {
    $student = $this->withinTenant(
        $this->schoolA,
        fn () => Student::factory()->create(['school_id' => null, 'first_name' => 'Charlie'])
    );

    expect($student->school_id)->toBe($this->schoolA->id);
});

it('refuses to create a record owned by a different school', function (): void {
    $this->withinTenant($this->schoolA, function (): void {
        Student::factory()->create(['school_id' => $this->schoolB->id]);
    });
})->throws(TenantMismatchException::class);

it('refuses to move an existing record to another school', function (): void {
    $this->withinTenant($this->schoolA, function (): void {
        $this->studentA->school_id = $this->schoolB->id;
        $this->studentA->save();
    });
})->throws(TenantMismatchException::class);

// ------------------------------------------------------------------- the API

it('returns 404, not 403, for a record belonging to another tenant', function (): void {
    $admin = $this->makeUser($this->schoolA, Role::SCHOOL_ADMIN);

    // 403 would confirm the identifier exists somewhere, which is an oracle
    // for enumerating another school's records.
    $this->actingAsUser($admin)
        ->getJson("/api/v1/students/{$this->studentB->id}")
        ->assertNotFound();
});

it('lists only the caller\'s own students', function (): void {
    $admin = $this->makeUser($this->schoolA, Role::SCHOOL_ADMIN);

    $response = $this->actingAsUser($admin)->getJson('/api/v1/students');

    $response->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.first_name'))->toBe('Alpha');
});

it('refuses to update another tenant\'s record through the API', function (): void {
    $admin = $this->makeUser($this->schoolA, Role::SCHOOL_ADMIN);

    $this->actingAsUser($admin)
        ->putJson("/api/v1/students/{$this->studentB->id}", ['first_name' => 'Hacked'])
        ->assertNotFound();

    expect($this->studentB->fresh()->first_name)->toBe('Bravo');
});

it('refuses to delete another tenant\'s record through the API', function (): void {
    $admin = $this->makeUser($this->schoolA, Role::SCHOOL_ADMIN);

    $this->actingAsUser($admin)
        ->deleteJson("/api/v1/students/{$this->studentB->id}")
        ->assertNotFound();

    expect(Student::query()->withoutTenantScope()->find($this->studentB->id))->not->toBeNull();
});

it('ignores a school_id supplied in the request body', function (): void {
    $admin = $this->makeUser($this->schoolA, Role::SCHOOL_ADMIN);

    $level = $this->withinTenant($this->schoolA, fn () => Level::query()->create([
        'school_id' => $this->schoolA->id, 'name' => '6ème', 'sequence' => 1,
    ]));

    $response = $this->actingAsUser($admin)->postJson('/api/v1/students', [
        // A mass-assignment attempt: plant the record in another tenant.
        'school_id' => $this->schoolB->id,
        'first_name' => 'Mallory',
        'last_name' => 'Test',
        'gender' => 'female',
        'birth_date' => '2012-05-04',
    ]);

    $response->assertCreated();

    $created = Student::query()->withoutTenantScope()->where('first_name', 'Mallory')->firstOrFail();

    expect($created->school_id)->toBe($this->schoolA->id);
});

it('does not leak another tenant through a header a user controls', function (): void {
    $admin = $this->makeUser($this->schoolA, Role::SCHOOL_ADMIN);

    // Only platform admins may name a tenant; for everyone else the header
    // must be inert.
    $response = $this->actingAsUser($admin)
        ->withHeader('X-School-Id', $this->schoolB->id)
        ->getJson('/api/v1/students');

    $response->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.first_name'))->toBe('Alpha');
});

// ----------------------------------------------------------------- platform

it('requires the impersonation permission for a platform admin to enter a tenant', function (): void {
    $admin = $this->makePlatformAdmin();

    // The seeded platform role does hold platform.impersonate, so this
    // succeeds — and the context is flagged as impersonation for the audit
    // trail.
    $this->actingAsUser($admin)
        ->withHeader('X-School-Id', $this->schoolA->id)
        ->getJson('/api/v1/students')
        ->assertOk();

    expect(app(TenantContext::class)->isImpersonating())->toBeTrue();
});

it('refuses tenant endpoints to a platform admin who names no school', function (): void {
    $admin = $this->makePlatformAdmin();

    // Without this the global scope would be inactive and the request would
    // see every school at once.
    $this->actingAsUser($admin)
        ->getJson('/api/v1/students')
        ->assertStatus(400)
        ->assertJsonPath('code', 'tenant_context_required');
});

// ------------------------------------------------------------ related models

it('isolates finance data as strictly as student data', function (): void {
    $feeA = $this->withinTenant($this->schoolA, fn () => FeeType::query()->create([
        'school_id' => $this->schoolA->id,
        'name' => 'Scolarité A',
        'default_amount_minor' => 100_000,
        'currency' => 'HTG',
    ]));

    $this->withinTenant($this->schoolB, function () use ($feeA): void {
        expect(FeeType::query()->find($feeA->id))->toBeNull()
            ->and(FeeType::query()->count())->toBe(0);
    });
});

it('still sees everything when the scope is explicitly suspended for platform work', function (): void {
    $this->withinTenant($this->schoolA, function (): void {
        $all = app(TenantContext::class)->withoutScope(fn () => Student::query()->count());

        expect($all)->toBe(2);
    });
});
