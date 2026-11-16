<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\DomainError;
use App\Jobs\CleanupDraftDocuments;
use App\Models\DocumentVersion;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CustomerDocuments
{
    public function upload(int $actor, int $id, int $type, UploadedFile $file): DocumentVersion
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $id, $type, $file, &$path) {
                $user = User::whereKey($actor)->lockForUpdate()->firstOrFail();
                if (! $user->is_active) {
                    throw new DomainError('ACCOUNT_INACTIVE', 'Akun dinonaktifkan. Hubungi administrator.', 403);
                }
                abort_unless($user->role === Role::Customer, 403);
                $application = LoanApplication::where('customer_id', $actor)->whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $application->status->editable()) {
                    throw new DomainError('APPLICATION_NOT_EDITABLE', 'Dokumen hanya dapat diunggah saat draf atau diminta revisi.');
                }
                if (! DB::table('document_types')->where('id', $type)->where('is_active', true)->sharedLock()->exists()) {
                    throw ValidationException::withMessages(['document_type_id' => ['Jenis dokumen tidak tersedia untuk unggahan.']]);
                }
                // A locking read observes the latest committed version even under MySQL REPEATABLE READ.
                $latest = DocumentVersion::where('application_id', $id)->where('document_type_id', $type)
                    ->orderByDesc('version_number')->lockForUpdate()->first();
                $path = 'loan-documents/'.$id.'/'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
                $stream = fopen($file->getRealPath(), 'rb');
                try {
                    if (! Storage::disk('local')->put($path, $stream)) {
                        throw new RuntimeException('Penyimpanan dokumen gagal.');
                    }
                } finally {
                    fclose($stream);
                }
                $document = new DocumentVersion([
                    'application_id' => $id, 'document_type_id' => $type, 'version_number' => ($latest?->version_number ?? 0) + 1,
                    'original_filename' => $file->getClientOriginalName(), 'storage_path' => $path,
                    'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()), 'file_size_bytes' => $file->getSize(),
                    'file_hash' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $actor, 'uploaded_at' => now(),
                ]);
                $document->save();
                DB::table('activity_logs')->insert([
                    'actor_id' => $actor, 'action' => 'document_version.created', 'target_type' => 'document_version',
                    'target_id' => $document->id, 'metadata' => json_encode(['application_id' => $id, 'document_type_id' => $type, 'version_number' => $document->version_number]),
                    'created_at' => now(),
                ]);

                return $document;
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                try {
                    // Never compensate a commit whose acknowledgement may have been lost.
                    if (! DB::table('document_versions')->where('storage_path', $path)->exists()) {
                        CleanupDraftDocuments::dispatch([$path])->onConnection('database')->onQueue('document-cleanup')->beforeCommit();
                    }
                } catch (Throwable) {
                    Log::error('Pembersihan unggahan gagal dijadwalkan; perlu rekonsiliasi berkas privat.', ['application_id' => $id]);
                }
            }
            throw $error;
        }
    }
}
