<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SubmissionFixtures;
use Tests\TestCase;

class OrphanDocumentAuditTest extends TestCase
{
    use DatabaseTransactions, SubmissionFixtures;

    public function test_audit_preserves_referenced_old_unreferenced_and_recent_files_and_jobs(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $type = DB::table('document_types')->where('code', 'D01')->value('id');
        $document = $this->storedDocument($this->loan(), $type);
        $referenced = DB::table('document_versions')->where('id', $document)->value('storage_path');
        touch($disk->path($referenced), time() - 172800);
        $disk->put('loan-documents/fiktif/orphan.pdf', 'fiktif');
        touch($disk->path('loan-documents/fiktif/orphan.pdf'), time() - 172800);
        $disk->put('loan-documents/fiktif/recent.pdf', 'fiktif');
        $disk->put('unrelated/old.txt', 'outside scope');
        $jobs = DB::table('jobs')->count();
        $rows = DB::table('document_versions')->count();
        $this->artisan('documents:audit-orphans')->expectsOutputToContain('"scanned":3,"referenced":1,"within_grace":1,"unreferenced_observations":1')->assertExitCode(0);
        $this->assertCount(4, $disk->allFiles());
        $this->assertSame('fiktif', $disk->get('loan-documents/fiktif/orphan.pdf'));
        $this->assertSame($jobs, DB::table('jobs')->count());
        $this->assertSame($rows, DB::table('document_versions')->count());
    }

    public function test_grace_boundary_is_retained_and_invalid_grace_options_are_rejected(): void
    {
        Storage::fake('local');
        $this->freezeTime();
        Storage::disk('local')->put('loan-documents/boundary.pdf', 'fiktif');
        touch(Storage::disk('local')->path('loan-documents/boundary.pdf'), now()->subHours(24)->timestamp);
        $this->artisan('documents:audit-orphans')->expectsOutputToContain('"within_grace":1,"unreferenced_observations":0')->assertExitCode(0);
        foreach (['0', '23', '-1', '1.5', '8761'] as $hours) {
            $this->artisan('documents:audit-orphans', ['--grace-hours' => $hours])->assertExitCode(2);
        }
        $this->assertTrue(Storage::disk('local')->exists('loan-documents/boundary.pdf'));
    }

    public function test_database_failure_is_not_reported_as_success_or_as_orphans(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('loan-documents/retained.pdf', 'fiktif');
        DB::shouldReceive('table')->once()->with('document_versions')->andThrow(new \RuntimeException('sensitive connection details'));
        $this->artisan('documents:audit-orphans')->expectsOutputToContain('Audit tidak lengkap.')->assertExitCode(1);
        $this->assertTrue(Storage::disk('local')->exists('loan-documents/retained.pdf'));
    }
}
