<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Subscription\Models\Plan;
use App\Domain\Subscription\Models\PlanFeature;
use Illuminate\Database\Seeder;

/**
 * The commercial plans.
 *
 * Every limit is a `plan_features` row rather than a constant, which is what
 * lets a limit be raised for one tenant, or a plan re-priced, without a
 * deployment.
 */
class PlanSeeder extends Seeder
{
    /** @var list<array{code: string, name: string, monthly: int, yearly: int, students: int|null, users: int|null, storage: int|null, features: array<string, bool>, public: bool, order: int}> */
    private array $plans = [
        [
            'code' => Plan::STARTER,
            'name' => 'Starter',
            'monthly' => 2900,      // USD 29.00, in minor units
            'yearly' => 29000,      // two months free
            'students' => 100,
            'users' => 10,
            'storage' => 2048,
            'features' => [
                'sms' => false, 'whatsapp' => false, 'online_payments' => true,
                'advanced_reports' => false, 'ai_assistant' => false, 'public_api' => false,
                'admissions_portal' => true,
            ],
            'public' => true,
            'order' => 1,
        ],
        [
            'code' => Plan::STANDARD,
            'name' => 'Standard',
            'monthly' => 5900,
            'yearly' => 59000,
            'students' => 300,
            'users' => 30,
            'storage' => 10240,
            'features' => [
                'sms' => true, 'whatsapp' => false, 'online_payments' => true,
                'advanced_reports' => true, 'ai_assistant' => false, 'public_api' => false,
                'admissions_portal' => true,
            ],
            'public' => true,
            'order' => 2,
        ],
        [
            'code' => Plan::PROFESSIONAL,
            'name' => 'Professional',
            'monthly' => 9900,
            'yearly' => 99000,
            'students' => 700,
            'users' => 80,
            'storage' => 51200,
            'features' => [
                'sms' => true, 'whatsapp' => true, 'online_payments' => true,
                'advanced_reports' => true, 'ai_assistant' => true, 'public_api' => true,
                'admissions_portal' => true,
            ],
            'public' => true,
            'order' => 3,
        ],
        [
            'code' => Plan::ENTERPRISE,
            'name' => 'Enterprise',
            'monthly' => 0,         // quoted, not self-served
            'yearly' => 0,
            'students' => null,     // null = unlimited; per-tenant overrides apply
            'users' => null,
            'storage' => null,
            'features' => [
                'sms' => true, 'whatsapp' => true, 'online_payments' => true,
                'advanced_reports' => true, 'ai_assistant' => true, 'public_api' => true,
                'admissions_portal' => true,
            ],
            'public' => false,
            'order' => 4,
        ],
    ];

    public function run(): void
    {
        foreach ($this->plans as $definition) {
            $plan = Plan::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'price_monthly_minor' => $definition['monthly'],
                    'price_yearly_minor' => $definition['yearly'],
                    'currency' => 'USD',
                    'trial_days' => 14,
                    'sort_order' => $definition['order'],
                    'is_active' => true,
                    'is_public' => $definition['public'],
                ],
            );

            $limits = [
                PlanFeature::MAX_STUDENTS => $definition['students'],
                PlanFeature::MAX_USERS => $definition['users'],
                PlanFeature::MAX_STORAGE_MB => $definition['storage'],
            ];

            foreach ($limits as $key => $value) {
                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'key' => $key],
                    ['type' => PlanFeature::TYPE_LIMIT, 'limit_value' => $value, 'enabled' => true],
                );
            }

            foreach ($definition['features'] as $key => $enabled) {
                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'key' => $key],
                    ['type' => PlanFeature::TYPE_BOOLEAN, 'limit_value' => null, 'enabled' => $enabled],
                );
            }
        }
    }
}
