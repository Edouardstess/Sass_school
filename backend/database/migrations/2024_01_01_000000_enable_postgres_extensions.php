<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SchoolFlow relies on four PostgreSQL extensions. Creating them here rather
 * than only in the Docker init script keeps the application self-contained:
 * a fresh CI database, a managed cloud instance or a developer's local server
 * all converge on the same state from `php artisan migrate` alone.
 *
 * All four are "trusted" extensions on PostgreSQL 16, so the database owner
 * can install them without superuser rights.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $extensions = [
        'pgcrypto',   // gen_random_uuid()
        'citext',     // case-insensitive text
        'pg_trgm',    // trigram indexes for global search
        'unaccent',   // accent-insensitive matching (fr / ht names)
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->extensions as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }
    }

    public function down(): void
    {
        // Extensions are intentionally not dropped: other schemas in the same
        // database may depend on them, and dropping is not reversible-safe.
    }
};
