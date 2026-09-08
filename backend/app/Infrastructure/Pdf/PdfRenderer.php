<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders Blade views to PDF.
 *
 * Remote resources are disabled in the renderer: a report card template must
 * never be able to fetch a URL, which would turn a school logo field into an
 * SSRF primitive. Images are inlined as data URIs instead, read through the
 * storage disk the document actually lives on.
 */
final class PdfRenderer
{
    /** @param array<string, mixed> $data */
    public function render(string $view, array $data, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        return Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',   // has the accents fr/ht need
            'chroot' => resource_path('views'),
        ])
            ->loadView($view, $data)
            ->setPaper($paper, $orientation)
            ->output();
    }

    /**
     * A stored image as a data URI, so templates never reference a URL.
     * Returns null when the file is missing or unreadable — a missing logo
     * must degrade to "no logo", not to a failed report card run.
     */
    public function inlineImage(?string $path, ?string $disk = null): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            $disk ??= (string) config('schoolflow.storage.disk', 's3');
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return null;
            }

            $contents = $storage->get($path);

            if ($contents === null) {
                return null;
            }

            $mime = $storage->mimeType($path) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (Throwable) {
            return null;
        }
    }
}
