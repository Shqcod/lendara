<?php

namespace App\Console\Commands;

use App\Services\ModelHandover;
use Illuminate\Console\Command;
use Throwable;

class HandoverModel extends Command
{
    protected $signature = 'ml:handover-model {version} {--expected-active= : Versi aktif yang sudah ditinjau, atau none}';

    protected $description = 'Handover model eksplisit setelah writer dihentikan dan runtime model siap';

    public function handle(ModelHandover $models): int
    {
        try {
            $models->switch((string) $this->argument('version'), (string) $this->option('expected-active'));
            $this->info('Model aktif sesuai tujuan. Metadata/hasil lama dipertahankan.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Handover ditolak. Periksa expected-active, mode/readiness/hash, artefak dan processing. Model aktif tidak diubah.');

            return self::FAILURE;
        }
    }
}
