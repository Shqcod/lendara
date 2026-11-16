<?php

namespace App\Console\Commands;

use App\Services\ModelRegistration;
use Illuminate\Console\Command;
use Throwable;

class RegisterModel extends Command
{
    protected $signature = 'ml:register-model {metadata} {--sha256= : SHA256 metadata tepercaya} {--activate : Aktifkan hanya bila tidak ada model aktif lain}';

    protected $description = 'Daftarkan model terlatih dari metadata/hash operator, tanpa menimpa versi immutable';

    public function handle(ModelRegistration $models): int
    {
        try {
            $version = $models->register((string) $this->argument('metadata'), (string) $this->option('sha256'), (bool) $this->option('activate'));
            $this->info('Metadata model '.$version.' tersedia'.($this->option('activate') ? ' dan aktif.' : ' (tidak diaktifkan otomatis).'));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Registrasi model ditolak. Periksa mode model, hash/metadata/artefak, readiness dan model aktif yang ada. Tidak menimpa versi lama.');

            return self::FAILURE;
        }
    }
}
