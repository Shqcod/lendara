<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AuditDocumentIntegrity extends Command
{
    protected $signature = 'documents:audit-integrity';

    protected $description = 'Baca ukuran/hash semua versi dokumen privat tanpa mengubah atau menampilkan path';

    public function handle(): int
    {
        $counts = ['checked' => 0, 'verified' => 0, 'unavailable' => 0, 'size_mismatch' => 0, 'hash_mismatch' => 0];
        try {
            if (config('database.default') !== 'mysql' || config('filesystems.disks.local.driver') !== 'local') {
                throw new RuntimeException;
            }
            $disk = Storage::disk('local');
            $root = realpath($disk->path(''));
            if ($root === false) {
                throw new RuntimeException;
            }
            $normalize = fn ($path) => PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
            $prefix = $normalize(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
            DB::table('document_versions')->select(['id', 'storage_path', 'file_size_bytes', 'file_hash'])->chunkById(100, function ($rows) use ($disk, $prefix, $normalize, &$counts) {
                foreach ($rows as $row) {
                    $path = $row->storage_path;
                    if (! str_starts_with($path, 'loan-documents/') || str_contains($path, '\\') || array_intersect(explode('/', $path), ['.', '..', ''])) {
                        throw new RuntimeException;
                    }
                    $counts['checked']++;
                    if (! $disk->exists($path)) {
                        $counts['unavailable']++;

                        continue;
                    }
                    $absolute = realpath($disk->path($path));
                    if ($absolute === false || ! str_starts_with($normalize($absolute), $prefix) || ! is_file($absolute)) {
                        throw new RuntimeException;
                    }
                    $stream = $disk->readStream($path);
                    if (! is_resource($stream)) {
                        throw new RuntimeException;
                    }
                    $hash = hash_init('sha256');
                    $bytes = 0;
                    try {
                        while (! feof($stream)) {
                            $part = fread($stream, 65536);
                            if ($part === false) {
                                throw new RuntimeException;
                            }
                            $bytes += strlen($part);
                            hash_update($hash, $part);
                        }
                    } finally {
                        fclose($stream);
                    }
                    $sizeMatches = $bytes === (int) $row->file_size_bytes;
                    $hashMatches = hash_equals(strtolower($row->file_hash), hash_final($hash));
                    $counts['size_mismatch'] += $sizeMatches ? 0 : 1;
                    $counts['hash_mismatch'] += $hashMatches ? 0 : 1;
                    $counts['verified'] += $sizeMatches && $hashMatches ? 1 : 0;
                }
            });
            $ok = $counts['checked'] === $counts['verified'];
            $this->line(json_encode(['integrity_observed' => $ok, 'read_only' => true, 'counts' => $counts], JSON_THROW_ON_ERROR));
            $this->line('Observasi bukan backup/restore atau snapshot atomik. Hentikan writer sebelum salinan konsisten.');

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Audit integritas tidak lengkap; periksa DB/disk/referensi privat. Tidak ada file atau metadata yang diubah.');

            return self::FAILURE;
        }
    }
}
