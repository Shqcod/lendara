<?php

namespace App\Services;

use App\Exceptions\DomainError;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReviewAssignments
{
    private function reviewer(int $id): User
    {
        $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
        if (! $user->is_active) {
            throw new DomainError('ACCOUNT_INACTIVE', 'Akun dinonaktifkan. Hubungi administrator.', 403);
        }
        app(ReviewScope::class)->context($user);

        return $user;
    }

    private function log(int $actor, string $action, Assignment $assignment): void
    {
        DB::table('activity_logs')->insert(['actor_id' => $actor, 'action' => $action, 'target_type' => 'assignment', 'target_id' => $assignment->id,
            'metadata' => json_encode(['application_id' => $assignment->application_id, 'submission_id' => $assignment->submission_id, 'stage' => $assignment->stage]),
            'created_at' => now()]);
    }

    public function claim(int $actor, int $id, int $expected): Assignment
    {
        try {
            return DB::transaction(function () use ($actor, $id, $expected) {
                $user = $this->reviewer($actor);
                $scope = app(ReviewScope::class);
                $application = $scope->applications($user)->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($application->current_submission_id !== $expected) {
                    throw new DomainError('SUBMISSION_VERSION_CONFLICT', 'Versi pengajuan berubah. Periksa data terbaru sebelum mengambil tugas.');
                }
                if (Assignment::where('application_id', $id)->whereNull('ended_at')->lockForUpdate()->first()) {
                    throw new DomainError('ASSIGNMENT_ALREADY_CLAIMED', 'Tugas sudah diambil. Periksa pemilik tugas terbaru.');
                }
                $assignment = Assignment::create(['application_id' => $id, 'submission_id' => $expected, 'assigned_to' => $actor,
                    'stage' => $scope->context($user)['stage'], 'claimed_at' => now()]);
                $this->log($actor, 'assignment.claimed', $assignment);

                return $assignment->setRelation('assignee', $user);
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainError('ASSIGNMENT_ALREADY_CLAIMED', 'Tugas sudah diambil. Periksa pemilik tugas terbaru.');
        }
    }

    public function release(int $actor, int $id, string $reason): Assignment
    {
        $context = Assignment::findOrFail($id);

        return DB::transaction(function () use ($actor, $id, $reason, $context) {
            $user = $this->reviewer($actor);
            $scope = app(ReviewScope::class);
            $application = $scope->applications($user)->whereKey($context->application_id)->lockForUpdate()->firstOrFail();
            $assignment = Assignment::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($assignment->assigned_to === $actor, 403);
            if ($assignment->ended_at !== null || $assignment->submission_id !== $application->current_submission_id
                || $assignment->stage !== $scope->context($user)['stage']) {
                throw new DomainError('ASSIGNMENT_NOT_ACTIVE', 'Penugasan ini tidak lagi aktif pada tahap dan versi terkini.');
            }
            $assignment->ended_at = now();
            $assignment->end_type = 'released';
            $assignment->end_reason = $reason;
            $assignment->ended_by = $actor;
            $assignment->save();
            $this->log($actor, 'assignment.released', $assignment);

            return $assignment->setRelation('assignee', $user);
        });
    }
}
