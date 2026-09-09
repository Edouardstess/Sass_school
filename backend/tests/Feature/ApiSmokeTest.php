<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PlanSeeder;

/**
 * Every list endpoint, called for real.
 *
 * This exists because two whole classes of bug only appear when a controller
 * actually runs: a column-restricted eager load that starves a resource of an
 * attribute it renders (strict attribute access turns that into a 500), and a
 * query scope used on a model that does not carry the trait providing it.
 * Unit tests on services cannot see either. This can.
 */
beforeEach(function (): void {
    $this->seed(PlanSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);

    $this->school = $this->makeSchool();
    $this->owner = $this->makeUser($this->school, Role::SCHOOL_OWNER);
});

/** @return list<array{0: string}> */
dataset('index endpoints', [
    'dashboard' => ['/api/v1/dashboard'],
    'school' => ['/api/v1/school'],
    'school settings' => ['/api/v1/school/settings'],
    'subscription' => ['/api/v1/school/subscription'],
    'academic years' => ['/api/v1/academic-years'],
    'levels' => ['/api/v1/levels'],
    'subjects' => ['/api/v1/subjects'],
    'rooms' => ['/api/v1/rooms'],
    'classes' => ['/api/v1/classes'],
    'students' => ['/api/v1/students'],
    'guardians' => ['/api/v1/guardians'],
    'teachers' => ['/api/v1/teachers'],
    'admissions' => ['/api/v1/admissions'],
    'timetable' => ['/api/v1/timetable'],
    'assessments' => ['/api/v1/assessments'],
    'report cards' => ['/api/v1/report-cards'],
    'attendance' => ['/api/v1/attendance'],
    'fee types' => ['/api/v1/fee-types'],
    'invoices' => ['/api/v1/invoices'],
    'invoice summary' => ['/api/v1/invoices/summary'],
    'payments' => ['/api/v1/payments'],
    'payment methods' => ['/api/v1/payments/methods'],
    'documents' => ['/api/v1/documents'],
    'certificates' => ['/api/v1/certificates'],
    'notifications' => ['/api/v1/notifications'],
    'notification preferences' => ['/api/v1/notifications/preferences'],
    'assistant capabilities' => ['/api/v1/assistant/capabilities'],
    'plans' => ['/api/v1/plans'],
]);

it('serves every index endpoint', function (string $endpoint): void {
    $response = $this->actingAsUser($this->owner)->getJson($endpoint);

    expect($response->status())->toBe(200, "GET {$endpoint} returned {$response->status()}: ".$response->getContent());

    $response->assertJsonPath('success', true);
})->with('index endpoints');

it('honours search and sort on every searchable collection', function (): void {
    // The parameters that reach `applySearch` / `applySort`, which are the
    // scopes a missing Filterable trait would take out.
    $searchable = [
        '/api/v1/students?search=a&sort=-created_at',
        '/api/v1/teachers?search=a&sort=last_name',
        '/api/v1/guardians?search=a&sort=last_name',
        '/api/v1/classes?search=a&sort=name',
        '/api/v1/invoices?search=INV&sort=-due_on',
        '/api/v1/payments?search=PAY&sort=-paid_at',
        '/api/v1/assessments?sort=-assessed_on',
        '/api/v1/admissions?search=x',
    ];

    foreach ($searchable as $endpoint) {
        $response = $this->actingAsUser($this->owner)->getJson($endpoint);

        expect($response->status())->toBe(200, "GET {$endpoint} returned {$response->status()}: ".$response->getContent());
    }
});

it('caps page size at the configured maximum', function (): void {
    // Otherwise ?per_page=100000 is a way to pull an entire tenant into one
    // response, which is the thing the pagination rule exists to prevent.
    $response = $this->actingAsUser($this->owner)->getJson('/api/v1/students?per_page=100000');

    $response->assertOk();

    expect($response->json('meta.per_page'))
        ->toBe((int) config('schoolflow.pagination.max_per_page'));
});

it('rejects an unauthenticated request to every tenant endpoint', function (string $endpoint): void {
    $this->getJson($endpoint)->assertUnauthorized();
})->with('index endpoints');
