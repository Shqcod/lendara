<?php

namespace Tests\Feature;

use App\Services\RestoreWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RestoreWorkspaceTest extends TestCase
{
    public function test_requires_restore_database_and_valid_identifier(): void
    {
        foreach ([['../secret', 'kkp_pinjaman_restore_test', 'local'], [(string) Str::uuid(), 'kkp_pinjaman', 'local'], [(string) Str::uuid(), 'kkp_pinjaman_restore_test', 'production']] as $args) {
            try {
                RestoreWorkspace::root(...$args);
                $this->fail('Mixed context accepted');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->artisan('system:assert-runtime-database', ['--restore' => true])->assertExitCode(1);
        $this->artisan('system:assert-restore-workspace')->assertExitCode(1);
    }

    public function test_requires_verified_marker_and_reads_only_the_isolated_private_root(): void
    {
        $id = (string) Str::uuid();
        $root = base_path('../.tools/local-restores/'.$id);
        mkdir($root.'/private', 0700, true);
        mkdir($root.'/artifact-root', 0700);
        try {
            RestoreWorkspace::root($id, 'kkp_pinjaman_restore_test', 'testing');
            $this->fail('Unverified workspace accepted');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        file_put_contents($root.'/DATA_RESTORED_BROWSER_NOT_VERIFIED', 'fixture');
        file_put_contents($root.'/validation.json', json_encode(['database' => 'kkp_pinjaman_restore_test', 'row_counts_match' => true, 'constraints_match' => true, 'indexes_match' => true, 'private_integrity_passed' => true, 'model_hashes_match' => true]));
        $this->assertSame(realpath($root), RestoreWorkspace::root($id, 'kkp_pinjaman_restore_test', 'testing'));
    }

    public function test_restore_database_without_workspace_id_cannot_boot_with_source_disk(): void
    {
        $code = 'require "vendor/autoload.php"; try { $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); } catch (Throwable $e) { exit(1); }';
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'local', 'DB_DATABASE' => 'kkp_pinjaman_restore_test', 'LENDARA_RESTORE_ID' => ''], timeout: 20);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
    }

    public function test_browser_probe_rejects_regular_test_context_before_creating_reviewers(): void
    {
        $before = DB::table('users')->count();
        $process = new Process([PHP_BINARY, base_path('../scripts/php/restore-browser.php')], base_path(), ['APP_ENV' => 'testing', 'RESTORE_BROWSER_TEST' => '1'], timeout: 20);
        $process->setInput(json_encode(['op' => 'reviewers', 'run_id' => (string) Str::uuid(), 'password' => Str::random(32)]));
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Restore browser probe rejected', $process->getErrorOutput());
        $this->assertSame($before, DB::table('users')->count());
    }
}
