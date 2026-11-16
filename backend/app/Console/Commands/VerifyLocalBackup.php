<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VerifyLocalBackup extends Command
{
    protected $signature = 'system:verify-local-backup {id} {--manifest-sha256= : Pin manifest dari keluaran exporter tepercaya}';

    protected $description = 'Verifikasi byte backup privat tanpa koneksi DB, restore, atau penulisan file';

    public function handle(): int
    {
        try {
            $id = (string) $this->argument('id');
            $pin = (string) $this->option('manifest-sha256');
            if (! Str::isUuid($id) || ! preg_match('/\A[a-f0-9]{64}\z/D', $pin)) {
                throw new RuntimeException;
            }
            $root = realpath(base_path('../.tools/local-backups/'.$id));
            if (! $root || is_file($root.'/INCOMPLETE') || ! is_file($root.'/EXPORT_COMPLETE_NOT_RESTORE_VERIFIED')
                || ! hash_equals($pin, hash_file('sha256', $root.'/manifest.json'))) {
                throw new RuntimeException;
            }
            $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            if (($manifest['schema_version'] ?? null) !== 1 || ! is_bool($manifest['source_tree_dirty'] ?? null)) {
                throw new RuntimeException;
            }
            $expected = ['database.sql' => $manifest['database_sql']];
            foreach ($manifest['private_files'] as $path => $metadata) {
                $expected['private/'.$path] = $metadata;
            }
            foreach ($manifest['model_artifacts'] as $metadata) {
                $expected['model/'.$metadata['sha256'].'.joblib'] = $metadata;
            }
            $index = 0;
            foreach ($manifest['release_files'] as $path => $metadata) {
                $expected['release/'.$index++.'-'.basename($path)] = $metadata;
            }
            $normalize = fn ($path) => PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
            foreach ($expected as $path => $metadata) {
                $file = realpath($root.'/'.$path);
                if (array_intersect(explode('/', $path), ['.', '..', '']) || str_contains($path, '\\')
                    || ! $file || ! str_starts_with($normalize($file), $normalize($root.DIRECTORY_SEPARATOR)) || ! is_file($file)
                    || ! is_int($metadata['bytes']) || $metadata['bytes'] < 0 || ! preg_match('/\A[a-f0-9]{64}\z/D', $metadata['sha256'])
                    || filesize($file) !== $metadata['bytes'] || ! hash_equals($metadata['sha256'], hash_file('sha256', $file))) {
                    throw new RuntimeException;
                }
            }
            $this->line(json_encode(['backup_hashes_verified' => true, 'files_checked' => count($expected), 'source_tree_dirty' => $manifest['source_tree_dirty'], 'restore_verified' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Backup tidak terverifikasi. Periksa pin manifest, penanda lengkap dan byte file secara privat; tidak ada restore atau perubahan.');

            return self::FAILURE;
        }
    }
}
