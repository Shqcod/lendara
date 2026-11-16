<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuditOrphanDocuments extends Command
{
    protected $signature = 'documents:audit-orphans {--grace-hours=24 : Masa tenggang minimal 24 jam}';

    protected $description = 'Audit baca-saja berkas privat tanpa referensi; tidak menghapus atau menjadwalkan cleanup';

    public function handle(): int
    {
        $hours = (string) $this->option('grace-hours');
        if (! preg_match('/\A[0-9]{1,4}\z/D', $hours) || (int) $hours < 24 || (int) $hours > 8760) {
            $this->error('Masa tenggang harus integer 24 sampai 8760 jam.');

            return self::INVALID;
        }
        if (config('filesystems.disks.local.driver') !== 'local' || config('database.default') !== 'mysql') {
            $this->error('Audit memerlukan disk local privat dan koneksi MySQL.');

            return self::FAILURE;
        }
        $counts = ['scanned' => 0, 'referenced' => 0, 'within_grace' => 0, 'unreferenced_observations' => 0];
        $cutoff = now()->subHours((int) $hours)->timestamp;
        try {
            // Establish DB availability even for an empty disk; never infer orphans from a DB failure.
            DB::table('document_versions')->limit(1)->get(['id']);
            $disk = Storage::disk('local');
            foreach ($disk->allFiles('loan-documents') as $path) {
                if (! str_starts_with($path, 'loan-documents/') || str_contains($path, '..') || str_contains($path, '\\')) {
                    throw new \RuntimeException('Unsafe storage entry');
                }
                $counts['scanned']++;
                if (DB::table('document_versions')->where('storage_path', $path)->exists()) {
                    $counts['referenced']++;
                } elseif ($disk->lastModified($path) >= $cutoff) {
                    $counts['within_grace']++;
                } elseif (DB::table('document_versions')->where('storage_path', $path)->exists()) {
                    $counts['referenced']++;
                } else {
                    $counts['unreferenced_observations']++;
                }
            }
        } catch (Throwable) {
            // No paths, SQL, credentials, partial count or misleading success report on failure.
            $this->error('Audit tidak lengkap. Periksa akses database/disk privat; tidak ada berkas yang diubah.');

            return self::FAILURE;
        }
        $this->line(json_encode(['read_only' => true, 'grace_hours' => (int) $hours, 'counts' => $counts], JSON_THROW_ON_ERROR));
        $this->line('Observasi bukan izin hapus: transaksi belum commit atau perubahan sesudah audit masih mungkin.');

        return self::SUCCESS;
    }
}
