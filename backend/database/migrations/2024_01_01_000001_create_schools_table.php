<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `schools` table is the tenant root. Every other business table carries a
 * `school_id` pointing here, and the tenant scope filters on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Stable, human-usable tenant handle used in URLs and support tickets.
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();

            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('country', 2)->default('HT');
            $table->string('postal_code', 20)->nullable();

            $table->string('logo_path')->nullable();

            // Defaults inherited by every record created inside the tenant.
            $table->string('locale', 8)->default('fr');
            $table->string('timezone', 64)->default('America/Port-au-Prince');
            $table->char('currency', 3)->default('HTG');

            // active | trial | suspended | cancelled — controls whether the
            // tenant's users may sign in at all.
            $table->string('status', 24)->default('trial');
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
