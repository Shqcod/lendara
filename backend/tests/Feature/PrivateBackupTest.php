<?php

namespace Tests\Feature;

use App\Services\PrivateBackupFiles;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PrivateBackupTest extends TestCase
{
    public function test_copies_nested_files_with_hashes_without_mutating_source(): void
    {
        $root = storage_path('framework/testing/backup-files/'.Str::uuid());
        mkdir($root.'/source/nested', 0777, true);
        $bytes = str_repeat("fiktif\x00", 20000);
        file_put_contents($root.'/source/nested/test.bin', $bytes);
        $manifest = app(PrivateBackupFiles::class)->copy($root.'/source', $root.'/copy');
        $this->assertSame(strlen($bytes), $manifest['nested/test.bin']['bytes']);
        $this->assertSame(hash('sha256', $bytes), $manifest['nested/test.bin']['sha256']);
        $this->assertSame($bytes, file_get_contents($root.'/copy/nested/test.bin'));
        $this->assertSame($bytes, file_get_contents($root.'/source/nested/test.bin'));
        try {
            app(PrivateBackupFiles::class)->copy($root.'/source', $root.'/copy');
            $this->fail('Existing backup must never be overwritten');
        } catch (RuntimeException) {
            $this->assertSame($bytes, file_get_contents($root.'/copy/nested/test.bin'));
        }
    }

    public function test_refuses_recursive_destination_and_single_file_overwrite(): void
    {
        $root = storage_path('framework/testing/backup-files/'.Str::uuid());
        mkdir($root.'/source', 0777, true);
        file_put_contents($root.'/source/keep.txt', 'keep');
        try {
            app(PrivateBackupFiles::class)->copy($root.'/source', $root.'/source/copy');
            $this->fail('Recursive backup accepted');
        } catch (RuntimeException) {
            $this->assertFalse(is_dir($root.'/source/copy'));
        }
        try {
            app(PrivateBackupFiles::class)->copyFile($root.'/source/keep.txt', $root.'/source/keep.txt');
            $this->fail('Overwrite accepted');
        } catch (RuntimeException) {
            $this->assertSame('keep', file_get_contents($root.'/source/keep.txt'));
        }
    }

    public function test_export_refuses_missing_maintenance_acknowledgement_and_testing_database(): void
    {
        $this->artisan('system:backup-local-demo')->expectsOutputToContain('Backup belum lengkap')->assertExitCode(1);
        $this->artisan('system:backup-local-demo', ['--writers-stopped' => true])->assertExitCode(1);
        $this->artisan('system:backup-local-demo', ['--writers-stopped' => true, '--restore-workspace' => (string) Str::uuid()])->assertExitCode(1);
        $this->artisan('system:restore-local-backup', ['id' => (string) Str::uuid()])->assertExitCode(1);
        $this->artisan('system:restore-local-backup', ['id' => (string) Str::uuid(), '--create-isolated-target' => true])->assertExitCode(1);
        $this->artisan('system:restore-local-backup', ['id' => (string) Str::uuid(), '--create-isolated-target' => true, '--recovery-drill' => true])->assertExitCode(1);
    }

    public function test_verifier_detects_tampered_content_and_requires_independent_manifest_pin(): void
    {
        $id = (string) Str::uuid();
        $root = base_path('../.tools/local-backups/'.$id);
        mkdir($root, 0700, true);
        file_put_contents($root.'/database.sql', 'fictional fixture');
        file_put_contents($root.'/EXPORT_COMPLETE_NOT_RESTORE_VERIFIED', 'fixture');
        $manifest = ['schema_version' => 1, 'source_tree_dirty' => true, 'database_sql' => ['bytes' => 17, 'sha256' => hash('sha256', 'fictional fixture')],
            'private_files' => [], 'model_artifacts' => [], 'release_files' => []];
        file_put_contents($root.'/manifest.json', json_encode($manifest));
        $pin = hash_file('sha256', $root.'/manifest.json');
        $this->artisan('system:verify-local-backup', ['id' => $id, '--manifest-sha256' => $pin])->expectsOutputToContain('"backup_hashes_verified":true')->assertExitCode(0);
        $this->artisan('system:verify-local-backup', ['id' => $id])->assertExitCode(1);
        file_put_contents($root.'/database.sql', 'tampered fixture!');
        $this->artisan('system:verify-local-backup', ['id' => $id, '--manifest-sha256' => $pin])->assertExitCode(1);
        $this->assertSame('tampered fixture!', file_get_contents($root.'/database.sql'));
    }
}
