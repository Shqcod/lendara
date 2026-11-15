<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InternalAccounts
{
    public const ROLES = ['verifikator', 'penyetuju_1', 'penyetuju_2', 'admin'];

    public function create(int $actor, array $data): User
    {
        try {
            return DB::transaction(function () use ($actor, $data) {
                $admin = User::whereKey($actor)->lockForUpdate()->firstOrFail();
                abort_unless($admin->is_active && $admin->role === Role::Admin, 403);
                abort_unless(in_array($data['role'], self::ROLES, true), 403);
                $user = new User(collect($data)->only(['name', 'email', 'password'])->all());
                $user->role = $data['role'];
                $user->is_active = true;
                $user->save();
                DB::table('activity_logs')->insert(['actor_id' => $actor, 'action' => 'internal_user.created', 'target_type' => 'user', 'target_id' => $user->id,
                    'metadata' => json_encode(['role' => $data['role']]), 'created_at' => now()]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Email sudah digunakan.']);
        }
    }
}
