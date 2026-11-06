<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CleanupDraftDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public array $paths) {}

    public function handle(): void
    {
        $disk = Storage::disk('local');
        foreach ($this->paths as $path) {
            if (! is_string($path) || ! preg_match('~\Aloan-documents/[a-zA-Z0-9/_\.\-]+\z~', $path)
                || array_intersect(explode('/', $path), ['..', '.', ''])) {
                throw new RuntimeException('Path pembersihan dokumen tidak valid.');
            }
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException('Pembersihan dokumen privat belum berhasil.');
            }
        }
    }
}
