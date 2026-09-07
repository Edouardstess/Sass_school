<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Document\Models\Document;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

/**
 * Files.
 *
 * Documents attached to a student inherit that student's access rules, so a
 * parent can fetch their own child's report card PDF but nothing else — the
 * check is delegated rather than duplicated.
 */
class DocumentPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('documents.view');
    }

    public function view(User $user, Document $document): bool
    {
        if (! $this->sameTenant($user, $document)) {
            return false;
        }

        if ($document->uploaded_by === $user->id) {
            return true;
        }

        if (! $user->hasPermission('documents.view')) {
            return false;
        }

        return $this->canReachSubject($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function upload(User $user): bool
    {
        return $user->hasPermission('documents.upload');
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->allows($user, $document, 'documents.delete');
    }

    /**
     * When a document hangs off a record, authorisation follows that record.
     * Anything else is staff-only.
     */
    private function canReachSubject(User $user, Document $document): bool
    {
        $subject = $document->documentable;

        if ($subject === null) {
            return true;
        }

        return match (true) {
            $subject instanceof \App\Domain\Student\Models\Student => $user->can('view', $subject),
            $subject instanceof \App\Domain\Academic\Models\ReportCard => $user->can('view', $subject),
            $subject instanceof \App\Domain\Finance\Models\Invoice => $user->can('view', $subject),
            $subject instanceof \App\Domain\Finance\Models\Receipt => $user->can('view', $subject->invoice),
            default => true,
        };
    }
}
