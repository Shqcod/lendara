<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Exceptions\DomainError;
use App\Jobs\CleanupDraftDocuments;
use App\Models\CustomerProfile;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CustomerWorkspace
{
    private function lockedCustomer(int $id): User
    {
        $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
        if (! $user->is_active) {
            throw new DomainError('ACCOUNT_INACTIVE', 'Akun dinonaktifkan. Hubungi administrator.', 403);
        }
        abort_unless($user->role === Role::Customer, 403);

        return $user;
    }

    private function log(int $actor, string $action, string $target, int $id, array $metadata = []): void
    {
        DB::table('activity_logs')->insert([
            'actor_id' => $actor, 'action' => $action, 'target_type' => $target,
            'target_id' => $id, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function updateProfile(int $actor, array $data): void
    {
        try {
            DB::transaction(function () use ($actor, $data) {
                $user = $this->lockedCustomer($actor);
                if (array_key_exists('name', $data)) {
                    $user->name = $data['name'];
                    $user->save();
                }
                $profile = CustomerProfile::where('user_id', $actor)->lockForUpdate()->first() ?? new CustomerProfile;
                $profile->user_id = $actor;
                $profile->fill(Arr::except($data, ['name']));
                $profile->save();
                $this->log($actor, 'customer_profile.updated', 'customer_profile', $profile->id, ['fields' => array_keys($data)]);
            });
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['identity_number' => ['NIK sudah digunakan.']]);
        }
    }

    public function createDraft(int $actor, array $data): LoanApplication
    {
        try {
            return DB::transaction(function () use ($actor, $data) {
                $this->lockedCustomer($actor);
                if (LoanApplication::where('customer_id', $actor)->whereNotIn('status', ['approved', 'rejected'])->exists()) {
                    throw new DomainError('ACTIVE_APPLICATION_EXISTS', 'Anda masih memiliki pengajuan aktif. Lanjutkan pengajuan tersebut.');
                }
                $application = new LoanApplication($data);
                $application->customer_id = $actor;
                $application->application_number = 'LND-'.Str::ulid();
                $application->status = ApplicationStatus::Draft;
                $application->save();
                $this->log($actor, 'loan_application.created', 'loan_application', $application->id);

                return $application->refresh();
            });
        } catch (UniqueConstraintViolationException $error) {
            throw new DomainError('ACTIVE_APPLICATION_EXISTS', 'Anda masih memiliki pengajuan aktif. Muat ulang daftar pengajuan.');
        }
    }

    public function updateDraft(int $actor, int $id, array $data): LoanApplication
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $this->lockedCustomer($actor);
            $application = LoanApplication::where('customer_id', $actor)->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $application->status->editable()) {
                throw new DomainError('APPLICATION_NOT_EDITABLE', 'Pengajuan hanya dapat diubah saat draf atau diminta revisi.');
            }
            $application->fill($data)->save();
            $this->log($actor, 'loan_application.updated', 'loan_application', $id, ['fields' => array_keys($data)]);

            return $application->refresh();
        });
    }

    public function deleteDraft(int $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $this->lockedCustomer($actor);
            $application = LoanApplication::where('customer_id', $actor)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($application->status !== ApplicationStatus::Draft || $application->current_submission_id !== null
                || DB::table('application_submissions')->where('application_id', $id)->exists()) {
                throw new DomainError('DRAFT_ALREADY_SUBMITTED', 'Hanya draf yang belum pernah dikirim dapat dihapus.');
            }
            $documents = DB::table('document_versions')->where('application_id', $id)->lockForUpdate()->get(['id', 'storage_path']);
            if (DB::table('submission_documents')->whereIn('document_version_id', $documents->pluck('id'))->exists()) {
                throw new DomainError('DRAFT_ALREADY_SUBMITTED', 'Dokumen sudah menjadi bagian snapshot pengiriman.');
            }
            if ($documents->isNotEmpty()) {
                $queueDatabase = config('queue.connections.database.connection') ?: config('database.default');
                if ($queueDatabase !== DB::connection()->getName()) {
                    throw new RuntimeException('Queue pembersihan wajib memakai koneksi transaksi yang sama.');
                }
                CleanupDraftDocuments::dispatch($documents->pluck('storage_path')->all())
                    ->onConnection('database')->onQueue('document-cleanup')->beforeCommit();
            }
            DB::table('document_versions')->where('application_id', $id)->delete();
            $application->delete();
            $this->log($actor, 'loan_application.deleted', 'loan_application', $id);
        });
    }
}
