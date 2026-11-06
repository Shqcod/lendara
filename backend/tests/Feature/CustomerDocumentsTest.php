<?php

namespace Tests\Feature;

use App\Exceptions\DomainError;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\CustomerDocuments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Support\DomainFixtures;
use Tests\TestCase;

class CustomerDocumentsTest extends TestCase
{
    use DatabaseTransactions, DomainFixtures;

    private User $customer;

    private int $application;

    private int $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Accept' => 'application/json']);
        $this->customer = User::factory()->create();
        $this->application = $this->loan($this->customer->id);
        $this->type = DB::table('document_types')->insertGetId([
            'code' => 'T'.Str::upper(Str::random(8)), 'name' => 'Berkas Fiktif', 'is_required' => true, 'is_active' => true,
        ]);
        $this->authenticate($this->customer);
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function pdf(string $name = 'fiktif.pdf', ?int $size = null): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $size === null ? $content : str_pad($content, $size, ' '));
    }

    private function upload(?UploadedFile $file = null, array $extra = [])
    {
        return $this->post('/api/v1/applications/'.$this->application.'/documents', array_merge([
            'document_type_id' => $this->type, 'file' => $file ?? $this->pdf(),
        ], $extra));
    }

    public function test_uploads_are_versioned_private_and_latest_list_does_not_leak_storage_or_internal_fields(): void
    {
        $first = $this->upload()->assertCreated()->assertJsonPath('data.version_number', 1)->json('data');
        $firstRow = DocumentVersion::findOrFail($first['id']);
        $oldContents = Storage::disk('local')->get($firstRow->storage_path);
        $second = $this->upload($this->pdf('pengganti.pdf'))->assertCreated()->assertJsonPath('data.version_number', 2)->json('data');
        $this->assertNotSame($firstRow->storage_path, DocumentVersion::findOrFail($second['id'])->storage_path);
        $this->assertSame($oldContents, Storage::disk('local')->get($firstRow->storage_path));
        $this->assertSame(hash('sha256', $oldContents), $firstRow->file_hash);
        $this->assertSame(strlen($oldContents), $firstRow->file_size_bytes);
        $this->assertStringStartsWith('loan-documents/'.$this->application.'/', $firstRow->storage_path);
        $list = $this->getJson('/api/v1/applications/'.$this->application.'/documents')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second['id']);
        $this->assertEqualsCanonicalizing(['id', 'document_type_id', 'version_number', 'original_filename', 'mime_type', 'file_size_bytes', 'uploaded_at'], array_keys($list->json('data.0')));
        $download = $this->getJson('/api/v1/document-versions/'.$first['id'].'/download')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertSame($oldContents, $download->streamedContent());
        $this->assertSame(2, DB::table('activity_logs')->where('actor_id', $this->customer->id)->where('action', 'document_version.created')->count());
        $this->get('/storage/'.$firstRow->storage_path)->assertNotFound();
    }

    public function test_old_submission_keeps_its_document_after_revision_upload(): void
    {
        $old = $this->upload()->assertCreated()->json('data.id');
        $submission = $this->submission($this->application);
        DB::table('submission_documents')->insert(['submission_id' => $submission, 'document_type_id' => $this->type, 'document_version_id' => $old, 'created_at' => now()]);
        DB::table('loan_applications')->where('id', $this->application)->update(['status' => 'revision_required', 'current_submission_id' => $submission]);
        $this->upload()->assertCreated()->assertJsonPath('data.version_number', 2);
        $this->assertDatabaseHas('submission_documents', ['submission_id' => $submission, 'document_version_id' => $old]);
        $this->getJson('/api/v1/document-versions/'.$old.'/download')->assertOk();
    }

    public function test_cross_customer_guessing_returns_404_before_file_validation(): void
    {
        $document = $this->upload()->assertCreated()->json('data.id');
        $this->authenticate(User::factory()->create());
        $this->getJson('/api/v1/applications/'.$this->application.'/documents')->assertNotFound();
        $this->postJson('/api/v1/applications/'.$this->application.'/documents', [])->assertNotFound();
        $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertNotFound();
        $this->getJson('/api/v1/document-versions/0/download')->assertNotFound();
    }

    public function test_internal_roles_and_anonymous_cannot_access_customer_document_endpoints(): void
    {
        $document = $this->upload()->assertCreated()->json('data.id');
        foreach (['verifikator', 'penyetuju_1', 'penyetuju_2', 'admin'] as $role) {
            $this->authenticate(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/document-types')->assertForbidden();
            $this->getJson('/api/v1/applications/'.$this->application.'/documents')->assertForbidden();
            $this->postJson('/api/v1/applications/'.$this->application.'/documents', [])->assertForbidden();
            $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/document-types')->assertUnauthorized();
        $this->getJson('/api/v1/applications/'.$this->application.'/documents')->assertUnauthorized();
        $this->postJson('/api/v1/applications/'.$this->application.'/documents', [])->assertUnauthorized();
        $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertUnauthorized();
    }

    public function test_inactive_customer_cannot_download(): void
    {
        $document = $this->upload()->assertCreated()->json('data.id');
        $this->customer->is_active = false;
        $this->customer->save();
        $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_upload_rechecks_active_account_inside_transaction(): void
    {
        DB::table('users')->where('id', $this->customer->id)->update(['is_active' => false]);
        $this->expectException(DomainError::class);
        app(CustomerDocuments::class)->upload($this->customer->id, $this->application, $this->type, $this->pdf());
    }

    public function test_upload_is_rejected_in_all_non_editable_statuses_but_read_remains_available(): void
    {
        $document = $this->upload()->assertCreated()->json('data.id');
        foreach (['pending_verification', 'pending_approval_1', 'pending_approval_2', 'approved', 'rejected'] as $status) {
            DB::table('loan_applications')->where('id', $this->application)->update(['status' => $status]);
            $this->postJson('/api/v1/applications/'.$this->application.'/documents', [])->assertConflict()->assertJsonPath('code', 'APPLICATION_NOT_EDITABLE');
            $this->getJson('/api/v1/document-versions/'.$document.'/download')->assertOk();
        }
        $this->assertSame(1, DocumentVersion::where('application_id', $this->application)->count());
    }

    public function test_file_content_extension_and_exact_five_megabyte_boundary(): void
    {
        $this->upload($this->pdf('fiktif.PDF', 5 * 1024 * 1024))->assertCreated();
        $this->upload($this->pdf('terlalu-besar.pdf', 5 * 1024 * 1024 + 1))->assertUnprocessable()->assertJsonValidationErrors('file');
        foreach ([
            UploadedFile::fake()->createWithContent('bukan.pdf', 'Teks biasa meskipun bernama PDF'),
            $this->pdf('mismatch.png'), $this->pdf('script.exe'),
            UploadedFile::fake()->createWithContent('mismatch.jpg', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOhkAAAAASUVORK5CYII=')),
        ] as $file) {
            $this->upload($file)->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $png = UploadedFile::fake()->createWithContent('fiktif.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOhkAAAAASUVORK5CYII='));
        $this->upload($png)->assertCreated()->assertJsonPath('data.mime_type', 'image/png');
        foreach (['jpg', 'jpeg'] as $extension) {
            $jpeg = UploadedFile::fake()->createWithContent('fiktif.'.$extension, file_get_contents(__DIR__.'/../Fixtures/document-fiktif.jpg'));
            $this->upload($jpeg)->assertCreated()->assertJsonPath('data.mime_type', 'image/jpeg');
        }
        $this->assertSame(4, DocumentVersion::where('application_id', $this->application)->count());
    }

    public function test_document_types_are_explicit_and_inactive_type_and_server_fields_are_rejected(): void
    {
        $type = collect($this->getJson('/api/v1/document-types')->assertOk()->json('data'))->firstWhere('id', $this->type);
        $this->assertTrue($type['is_required']);
        $this->assertTrue($type['is_active']);
        $this->upload(extra: ['storage_path' => 'public/x.pdf', 'version_number' => 999, 'uploaded_by' => $this->customer->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['storage_path', 'version_number', 'uploaded_by']);
        DB::table('document_types')->where('id', $this->type)->update(['is_active' => false]);
        $this->upload()->assertUnprocessable()->assertJsonValidationErrors('document_type_id');
    }

    public function test_large_post_is_normalized_to_413_json(): void
    {
        // Local php.ini permits 2G; exceed it without allocating a large body.
        $this->call('POST', '/api/v1/applications/'.$this->application.'/documents', [], [], [], ['CONTENT_LENGTH' => 4 * 1024 * 1024 * 1024])
            ->assertStatus(413)->assertJsonPath('code', 'PAYLOAD_TOO_LARGE');
    }

    public function test_missing_file_is_404_and_version_instance_cannot_be_updated(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        $document = DocumentVersion::findOrFail($id);
        Storage::disk('local')->delete($document->storage_path);
        $this->getJson('/api/v1/document-versions/'.$id.'/download')->assertNotFound();
        $document->original_filename = 'changed.pdf';
        $this->expectException(LogicException::class);
        $document->save();
    }

    public function test_failed_upload_transaction_preserves_old_version_and_enqueues_compensating_cleanup(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `activity_logs`')) {
                throw new RuntimeException('Synthetic audit failure after file persistence.');
            }
        });
        $this->upload()->assertStatus(500)->assertJsonMissingPath('exception');
        $this->assertSame(1, DocumentVersion::where('application_id', $this->application)->count());
        Storage::disk('local')->assertExists(DocumentVersion::findOrFail($id)->storage_path);
        $job = DB::table('jobs')->where('queue', 'document-cleanup')->orderByDesc('id')->first();
        $this->assertNotNull($job);
        $command = unserialize(json_decode($job->payload, true)['data']['command']);
        $this->assertCount(2, Storage::disk('local')->allFiles('loan-documents/'.$this->application));
        $command->handle();
        $this->assertCount(1, Storage::disk('local')->allFiles('loan-documents/'.$this->application));
    }
}
