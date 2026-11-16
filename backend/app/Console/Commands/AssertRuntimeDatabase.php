<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssertRuntimeDatabase extends Command
{
    protected $signature = 'system:assert-runtime-database {--restore : Akun runtime target restore terisolasi}';

    protected $description = 'Verifikasi identitas runtime lokal dan larangan tulis immutable tanpa mengubah baris';

    public function handle(): int
    {
        try {
            $testing = app()->environment('testing');
            $database = $this->option('restore') ? 'kkp_pinjaman_restore_test' : ($testing ? 'kkp_pinjaman_test' : 'kkp_pinjaman');
            $prefix = $this->option('restore') ? 'lendara_recover_' : ($testing ? 'lendara_dbtest_' : 'lendara_runtime_');
            if (! app()->environment('local', 'testing') || config('database.default') !== 'mysql'
                || config('database.connections.mysql.database') !== $database) {
                throw new \RuntimeException;
            }
            $identity = DB::selectOne('SELECT DATABASE() AS name, CURRENT_USER() AS account, CURRENT_ROLE() AS roles');
            if ($identity->name !== $database || ! preg_match('/\A'.$prefix.'[a-f0-9]{12}@127\.0\.0\.1\z/D', $identity->account) || $identity->roles !== 'NONE') {
                throw new \RuntimeException;
            }
            foreach (DB::select('SHOW GRANTS') as $row) {
                $grant = array_values((array) $row)[0];
                $permissions = explode(' ON ', $grant)[0];
                if (! str_contains($grant, ' ON ') || str_contains($grant, 'WITH GRANT OPTION')
                    || (str_contains($grant, ' ON *.* ') && ! str_starts_with($grant, 'GRANT USAGE ON '))
                    || preg_match('/ ON `[^`]+`\.\* /', $grant)
                    || preg_match('/\b(ALTER|CREATE|DROP|TRIGGER|INDEX|REFERENCES|EXECUTE|LOCK TABLES)\b/', $permissions)) {
                    throw new \RuntimeException;
                }
            }
            $queries = ['SELECT * FROM migrations WHERE 1=0', 'SELECT * FROM mysql.user WHERE 1=0'];
            foreach (['application_submissions', 'submission_documents', 'verification_results', 'approval_decisions', 'application_status_histories', 'activity_logs'] as $table) {
                $queries[] = "UPDATE `$table` SET id=id WHERE 1=0";
                $queries[] = "DELETE FROM `$table` WHERE 1=0";
            }
            foreach (['model_versions' => 'artifact_hash', 'assignments' => 'assigned_to', 'anomaly_checks' => 'input_features', 'document_versions' => 'storage_path', 'document_types' => 'is_active'] as $table => $column) {
                $queries[] = "UPDATE `$table` SET `$column`=`$column` WHERE 1=0";
            }
            foreach ($queries as $sql) {
                try {
                    DB::statement($sql);
                } catch (QueryException $error) {
                    if (in_array((int) ($error->errorInfo[1] ?? 0), [1142, 1143], true)) {
                        continue;
                    }
                    throw $error;
                }
                throw new \RuntimeException;
            }
            $this->line('{"runtime_identity_verified":true,"immutable_operations_denied":true}');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Runtime DB tidak terverifikasi; tidak memakai fallback operator. Tidak ada baris yang diubah.');

            return self::FAILURE;
        }
    }
}
