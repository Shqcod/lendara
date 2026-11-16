<?php

namespace App\Console\Commands;

use App\Enums\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class LocalDemoAccounts extends Command
{
    protected $signature = 'system:local-demo-accounts';

    protected $description = 'Ringkasan kelengkapan akun aktif per peran tanpa identitas atau perubahan akun';

    public function handle(): int
    {
        try {
            if (! app()->environment('local', 'testing') || config('database.default') !== 'mysql') {
                throw new \RuntimeException;
            }
            $counts = DB::table('users')->where('is_active', true)->selectRaw('role, COUNT(*) AS total')->groupBy('role')->pluck('total', 'role');
            $roles = [];
            foreach (Role::cases() as $role) {
                $roles[$role->value] = (int) ($counts[$role->value] ?? 0) > 0;
            }
            $ready = ! in_array(false, $roles, true);
            $this->line(json_encode(['accounts_ready' => $ready, 'active_role_present' => $roles, 'read_only' => true], JSON_THROW_ON_ERROR));
            $this->line('Bukan bukti login/otorisasi. Bootstrap admin/reviewer melalui secret prompt; nasabah melalui UI.');

            return $ready ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Pemeriksaan akun tidak lengkap; periksa environment/koneksi lokal. Tidak ada akun yang diubah.');

            return self::FAILURE;
        }
    }
}
