<?php

namespace App\Console\Commands;

use App\Services\RuntimeDatabaseGrants;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RuntimeDatabaseGrantPlan extends Command
{
    protected $signature = 'db:runtime-grants {database} {account} {host=127.0.0.1}';

    protected $description = 'Cetak rencana grant minimum tanpa menghubungi atau mengubah database';

    public function handle(RuntimeDatabaseGrants $grants): int
    {
        try {
            $statements = $grants->statements($this->argument('database'), $this->argument('account'), $this->argument('host'));
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->line('-- RENCANA SAJA. Tinjau docs/DATABASE_RUNTIME.md sebelum menerapkan.');
        $this->line('-- Hanya untuk akun baru tanpa grant/role lain. GRANT bersifat menambah izin.');
        foreach ($statements as $statement) {
            $this->line($statement);
        }

        return self::SUCCESS;
    }
}
