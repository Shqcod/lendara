<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CreateReviewer extends Command
{
    protected $signature = 'users:create-reviewer';

    protected $description = 'Buat akun peninjau lokal secara interaktif tanpa kata sandi bawaan';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Bootstrap peninjau hanya untuk local/testing.');

            return 1;
        }
        $name = $this->ask('Nama peninjau fiktif');
        $email = $this->ask('Email peninjau lokal');
        $role = $this->choice('Peran peninjau', ['verifikator', 'penyetuju_1', 'penyetuju_2']);
        $password = $this->secret('Kata sandi baru');
        $confirmation = $this->secret('Ulangi kata sandi');
        $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation, 'role' => $role], [
            'name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'], 'role' => ['in:verifikator,penyetuju_1,penyetuju_2'],
        ]);
        if ($validator->fails()) {
            $this->error('Akun tidak dibuat. Periksa nama, format/keunikan email, peran, dan kecocokan kata sandi (8–128 karakter).');

            return 1;
        }
        try {
            DB::transaction(function () use ($name, $email, $role, $password) {
                $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
                $user->role = $role;
                $user->is_active = true;
                $user->save();
                DB::table('activity_logs')->insert(['actor_id' => null, 'action' => 'internal_user.created_cli', 'target_type' => 'user', 'target_id' => $user->id,
                    'metadata' => json_encode(['role' => $role, 'source' => 'local_bootstrap']), 'created_at' => now()]);
            });
            $this->info('Akun peninjau lokal dibuat. Masuk melalui halaman Lendara.');

            return 0;
        } catch (Throwable) {
            $this->error('Akun belum dibuat. Periksa koneksi atau konflik email tanpa membagikan kredensial.');

            return 1;
        }
    }
}
