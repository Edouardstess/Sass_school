<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Document\Models\Document;
use App\Domain\Document\Models\ExportJob;
use App\Domain\Document\Services\DocumentStorage;
use App\Domain\School\Models\School;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Removes documents past their expiry — chiefly generated exports, which are
 * a copy of data that still exists elsewhere and should not accumulate in
 * object storage indefinitely.
 *
 * Only documents with an explicit `expires_at` are touched. Report cards,
 * receipts and certificates never carry one, so this can never delete a record
 * a school is obliged to keep.
 */
class CleanupExpiredFiles extends Command
{
    protected $signature = 'schoolflow:cleanup-expired-files {--dry-run}';

    protected $description = 'Delete expired generated documents and stale export jobs';

    public function handle(TenantContext $tenant, DocumentStorage $storage): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;

        School::query()->cursor()->each(function (School $school) use ($tenant, $storage, $dryRun, &$deleted): void {
            $tenant->runFor($school, function () use ($storage, $dryRun, &$deleted): void {
                Document::query()
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now())
                    ->chunkById(100, function ($documents) use ($storage, $dryRun, &$deleted): void {
                        foreach ($documents as $document) {
                            if (! $dryRun) {
                                $storage->delete($document);
                            }

                            $deleted++;
                        }
                    });

                // An export whose file is gone is no longer downloadable, so
                // the job row is noise on the user's exports screen.
                ExportJob::query()
                    ->where('status', ExportJob::STATUS_COMPLETED)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now())
                    ->when(! $dryRun, fn ($q) => $q->delete());
            });
        });

        $this->info(sprintf('%s %d expired document(s).', $dryRun ? 'Would delete' : 'Deleted', $deleted));

        return self::SUCCESS;
    }
}
