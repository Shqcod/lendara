<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\DomainError;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountManagement
{
    public function change(int $actor, int $target, string $action, array $data): User
    {
        return DB::transaction(function () use ($actor, $target, $action, $data) {
            $users = User::whereIn('id', [$actor, $target])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $admin = $users->get($actor);
            $user = $users->get($target);
            abort_unless($admin && $admin->is_active && $admin->role === Role::Admin, 403);
            abort_unless($user && in_array($user->role->value, InternalAccounts::ROLES, true), 404);
            if ($actor === $target) {
                throw new DomainError('SELF_ACCOUNT_CHANGE_FORBIDDEN', 'Perubahan akun sendiri tidak diizinkan.');
            }
            if ($user->role->value !== $data['expected_role'] || $user->is_active !== $data['expected_is_active']) {
                throw new DomainError('ACCOUNT_VERSION_CONFLICT', 'Data akun berubah. Periksa data terbaru.');
            }
            $nextRole = $action === 'role' ? $data['role'] : $user->role->value;
            $nextActive = $action === 'role' ? $user->is_active : $action === 'activate';
            if ($nextRole === $user->role->value && $nextActive === $user->is_active) {
                throw new DomainError('ACCOUNT_VERSION_CONFLICT', 'Akun sudah berada pada keadaan tersebut.');
            }
            if ($user->role === Role::Admin && $user->is_active && ($nextRole !== 'admin' || ! $nextActive)
                && User::where('role', 'admin')->where('is_active', true)->count() <= 1) {
                throw new DomainError('LAST_ACTIVE_ADMIN', 'Admin aktif terakhir harus dipertahankan.');
            }
            // All user locks precede application/assignment locks, matching reviewer mutations.
            $applicationIds = DB::table('assignments')->where('assigned_to', $target)->whereNull('ended_at')->orderBy('application_id')->pluck('application_id');
            DB::table('loan_applications')->whereIn('id', $applicationIds)->orderBy('id')->lockForUpdate()->get();
            $tasks = DB::table('assignments')->where('assigned_to', $target)->whereNull('ended_at')->orderBy('id')->lockForUpdate()->get();
            if ($action === 'role' && $tasks->isNotEmpty()) {
                throw new DomainError('ACCOUNT_HAS_ACTIVE_ASSIGNMENTS', 'Selesaikan atau lepas tugas aktif sebelum mengubah peran.');
            }
            if (config('session.driver') !== 'database' || (config('session.connection') && config('session.connection') !== config('database.default'))) {
                throw new DomainError('SERVICE_NOT_READY', 'Pengelolaan akun memerlukan sesi database pada koneksi yang sama.', 503);
            }
            $at = now();
            if ($action === 'deactivate') {
                foreach ($tasks as $task) {
                    DB::table('assignments')->where('id', $task->id)->update(['ended_at' => $at, 'end_type' => 'released', 'end_reason' => $data['reason'], 'ended_by' => $actor]);
                    DB::table('activity_logs')->insert(['actor_id' => $actor, 'action' => 'assignment.released_admin', 'target_type' => 'assignment', 'target_id' => $task->id, 'metadata' => json_encode(['application_id' => $task->application_id, 'submission_id' => $task->submission_id]), 'created_at' => $at]);
                }
            }
            $oldRole = $user->role->value;
            $oldActive = $user->is_active;
            $user->role = $nextRole;
            $user->is_active = $nextActive;
            $user->remember_token = null;
            $user->save();
            DB::table(config('session.table', 'sessions'))->where('user_id', $target)->delete();
            DB::table('activity_logs')->insert(['actor_id' => $actor, 'action' => 'user.access_changed', 'target_type' => 'user', 'target_id' => $target,
                'metadata' => json_encode(['operation' => $action, 'from_role' => $oldRole, 'to_role' => $nextRole, 'from_active' => $oldActive, 'to_active' => $nextActive]), 'description' => $data['reason'], 'created_at' => $at]);

            return $user;
        }, 3);
    }
}
