<?php

namespace App\Console\Commands;

use App\Services\DatabaseContentDigest;
use App\Services\PrivateBackupFiles;
use App\Services\RestoreWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class BackupLocalDemo extends Command
{
    protected $signature = 'system:backup-local-demo {--writers-stopped : Operator sudah menghentikan writer/worker/scheduler} {--restore-workspace= : Ekspor dataset dari workspace restore tervalidasi melalui operator lokal}';

    protected $description = 'Ekspor backup privat ke direktori baru; tidak restore, reset, atau menimpa file';

    public function handle(PrivateBackupFiles $files): int
    {
        $locked = false;
        $directory = null;
        $originalConfig = config('database.connections.mysql');
        $originalDisk = config('filesystems.disks.local.root');
        $changed = false;
        try {
            if (! $this->option('writers-stopped') || Artisan::call('system:assert-local-demo-database') !== 0
                || config('filesystems.disks.local.driver') !== 'local') {
                throw new \RuntimeException;
            }
            $sourceDatabase = 'kkp_pinjaman';
            $project = realpath(base_path('..')).DIRECTORY_SEPARATOR;
            $artifactRoot = $project;
            if ($this->option('restore-workspace') !== null) {
                $workspace = RestoreWorkspace::root((string) $this->option('restore-workspace'), 'kkp_pinjaman_restore_test', 'local');
                $sourceDatabase = 'kkp_pinjaman_restore_test';
                $artifactRoot = realpath($workspace.'/artifact-root').DIRECTORY_SEPARATOR;
                config(['database.connections.mysql' => array_replace($originalConfig, ['url' => null, 'host' => '127.0.0.1', 'unix_socket' => '', 'database' => $sourceDatabase]),
                    'filesystems.disks.local.root' => $workspace.'/private']);
                DB::purge('mysql');
                Storage::forgetDisk('local');
                $changed = true;
                if (DB::selectOne('SELECT DATABASE() AS name')->name !== $sourceDatabase
                    || ! DB::table('loan_applications')->where('status', 'approved')->exists()) {
                    throw new \RuntimeException;
                }
            }
            $mysql = 'Q:/maragoon/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe';
            if (! is_file($mysql)) {
                throw new \RuntimeException;
            }
            $tables = DB::select('SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
            $names = [];
            foreach ($tables as $table) {
                if ($table->type !== 'BASE TABLE' || $table->engine !== 'InnoDB' || ! preg_match('/\A[a-z_]+\z/D', $table->name)) {
                    throw new \RuntimeException;
                }
                $names[] = $table->name;
            }
            if (count($names) !== 22 || ! in_array('migrations', $names, true)) {
                throw new \RuntimeException;
            }
            foreach (['routines' => 'ROUTINE_SCHEMA', 'events' => 'EVENT_SCHEMA', 'triggers' => 'TRIGGER_SCHEMA'] as $table => $column) {
                if (DB::selectOne("SELECT COUNT(*) AS total FROM information_schema.$table WHERE $column = DATABASE()")->total > 0) {
                    throw new \RuntimeException;
                }
            }
            $constraints = DB::select('SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS name, CONSTRAINT_TYPE AS type, ENFORCED AS enforced FROM information_schema.table_constraints WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME');
            $indexes = DB::select('SELECT TABLE_NAME AS table_name, INDEX_NAME AS name, SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
            // Database-scoped table locks only; never FLUSH TABLES WITH READ LOCK globally.
            DB::statement('SET SESSION lock_wait_timeout = 5');
            DB::statement('LOCK TABLES '.implode(', ', array_map(fn ($name) => "`$name` READ", $names)));
            $locked = true;
            if (DB::table('jobs')->exists() || DB::table('failed_jobs')->exists() || DB::table('anomaly_checks')->where('status', 'processing')->exists()
                || Artisan::call('documents:audit-integrity') !== 0) {
                throw new \RuntimeException;
            }
            $id = (string) Str::uuid();
            $directory = base_path('../.tools/local-backups/'.$id);
            if (file_exists($directory) || ! mkdir($directory, 0700, true)) {
                throw new \RuntimeException;
            }
            file_put_contents($directory.'/INCOMPLETE', 'Do not restore until manifest and hashes verify.');
            $config = config('database.connections.mysql');
            $dump = new Process(array_merge([$mysql, '--no-defaults', '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'],
                '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--skip-column-statistics', '--set-gtid-purged=OFF',
                '--skip-add-drop-table', '--skip-add-locks', '--skip-disable-keys', '--hex-blob', '--default-character-set=utf8mb4',
                '--result-file='.$directory.'/database.sql', $sourceDatabase], $names), base_path(), ['MYSQL_PWD' => (string) $config['password']], timeout: 120);
            $dump->disableOutput();
            if ($dump->run() !== 0) {
                throw new \RuntimeException;
            }
            $manifest = ['schema_version' => 1, 'source_database' => $sourceDatabase, 'source_restore_id' => $this->option('restore-workspace'), 'created_at_utc' => now()->utc()->toIso8601String(),
                'constraints' => $constraints, 'indexes' => $indexes,
                'table_counts' => [], 'database_sql' => ['sha256' => hash_file('sha256', $directory.'/database.sql'), 'bytes' => filesize($directory.'/database.sql')]];
            foreach ($names as $name) {
                $manifest['table_counts'][$name] = DB::table($name)->count();
                $manifest['logical_row_hashes'][$name] = app(DatabaseContentDigest::class)->table($name);
            }
            $manifest['private_files'] = $files->copy(Storage::disk('local')->path(''), $directory.'/private');
            mkdir($directory.'/model', 0700);
            $manifest['model_artifacts'] = [];
            foreach (DB::table('model_versions')->get() as $model) {
                if (str_starts_with($model->version_code, 'stub-')) {
                    continue;
                }
                $source = realpath($artifactRoot.$model->artifact_path);
                if (! $source || ! str_starts_with(strtolower($source), strtolower($artifactRoot)) || ! hash_equals($model->artifact_hash, hash_file('sha256', $source))) {
                    throw new \RuntimeException;
                }
                $manifest['model_artifacts'][$model->version_code] = $files->copyFile($source, $directory.'/model/'.$model->artifact_hash.'.joblib');
            }
            mkdir($directory.'/release', 0700);
            foreach (['docs/validation/ml-runtime-registry-v1.json', 'docs/validation/ml-model-v1-metadata.json', 'docs/ML_EXPERIMENT_PLAN.md',
                'ml-service/experiments/LOCK.json', 'ml-service/requirements-model.lock.txt', 'ml-service/app/model_pipeline.py', 'backend/composer.lock', 'frontend/package-lock.json'] as $index => $path) {
                $manifest['release_files'][$path] = $files->copyFile($artifactRoot.$path, $directory.'/release/'.$index.'-'.basename($path));
            }
            $commit = new Process(['git', 'rev-parse', 'HEAD'], base_path());
            $commit->mustRun();
            $manifest['commit'] = trim($commit->getOutput());
            $status = new Process(['git', 'status', '--porcelain'], base_path());
            $status->mustRun();
            $manifest['source_tree_dirty'] = trim($status->getOutput()) !== '';
            if (file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false
                || ! rename($directory.'/INCOMPLETE', $directory.'/EXPORT_COMPLETE_NOT_RESTORE_VERIFIED')) {
                throw new \RuntimeException;
            }
            $this->line(json_encode(['backup_exported' => true, 'backup_id' => $id, 'manifest_sha256' => hash_file('sha256', $directory.'/manifest.json'), 'tables' => count($names), 'private_file_count' => count($manifest['private_files']), 'restore_verified' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Backup belum lengkap. Periksa maintenance, hak operator, DB/disk/model. Paket parsial dipertahankan dan tidak boleh di-restore. Tidak ada data sumber yang dihapus.');

            return self::FAILURE;
        } finally {
            if ($locked) {
                try {
                    DB::statement('UNLOCK TABLES');
                } catch (Throwable) {
                    DB::disconnect(); // Closing the owned connection also releases its locks.
                }
            }
            if ($changed) {
                DB::purge('mysql');
                config(['database.connections.mysql' => $originalConfig, 'filesystems.disks.local.root' => $originalDisk]);
                Storage::forgetDisk('local');
            }
        }
    }
}
