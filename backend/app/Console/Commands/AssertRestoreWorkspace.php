<?php

namespace App\Console\Commands;

use App\Services\RestoreWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssertRestoreWorkspace extends Command
{
    protected $signature = 'system:assert-restore-workspace';

    protected $description = 'Tolak disk/DB/URL/cookie development pada profil restore';

    public function handle(): int
    {
        try {
            $root = RestoreWorkspace::root((string) env('LENDARA_RESTORE_ID'), (string) config('database.connections.mysql.database'), app()->environment());
            if (DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_restore_test'
                || realpath((string) config('filesystems.disks.local.root')) !== realpath($root.'/private')
                || config('app.url') !== 'http://127.0.0.1:8800' || config('services.ml.url') !== 'http://127.0.0.1:8801'
                || config('session.cookie') !== 'lendara_restore_session' || ! in_array(config('session.domain'), [null, ''], true)
                || config('cors.allowed_origins') !== ['http://127.0.0.1:5174']
                || config('sanctum.stateful') !== ['127.0.0.1:5174', '127.0.0.1:8800']) {
                throw new \RuntimeException;
            }
            $this->line('{"restore_workspace_verified":true,"development_disk_used":false,"development_database_used":false}');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Konfigurasi restore tercampur atau belum terverifikasi; tidak memakai disk/DB development sebagai fallback.');

            return self::FAILURE;
        }
    }
}
