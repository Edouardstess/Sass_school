<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeding entry point.
 *
 * The first three seeders are *reference data* and are safe — expected, even —
 * to run on every deployment, including production: they converge the
 * permission catalogue, the plans and the default message templates onto
 * whatever the current release declares.
 *
 * DemoSeeder is different: it fabricates a school full of people. It refuses
 * to run outside local/testing, so `php artisan db:seed` on a production box
 * cannot invent thirty students.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            PlanSeeder::class,
            NotificationTemplateSeeder::class,
        ]);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoSeeder::class);
        }
    }
}
