<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssertLocalDemoDatabase extends Command
{
    protected $signature = 'system:assert-local-demo-database';

    protected $description = 'Guard database development lokal sebelum menjalankan profil demo';

    public function handle(): int
    {
        try {
            if (! app()->environment('local') || config('database.default') !== 'mysql'
                || config('database.connections.mysql.database') !== 'kkp_pinjaman'
                || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman') {
                throw new \RuntimeException;
            }
            $this->info('PASS: database demo development lokal cocok.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Profil demo wajib local/MySQL/kkp_pinjaman. Tidak ada perubahan.');

            return self::FAILURE;
        }
    }
}
