<?php

namespace App\Console\Commands;

use App\Services\DatabaseContentDigest;
use App\Services\PrivateBackupFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class RestoreLocalBackup extends Command
{
    protected $signature = 'system:restore-local-backup {id} {--manifest-sha256=} {--create-isolated-target : Buat target tetap baru bila belum ada} {--recovery-drill : Paket dataset restore ke kkp_pinjaman_recovery_drill baru}';

    protected $description = 'Pulihkan ke database/folder uji baru; tidak menimpa atau menghapus target yang sudah ada';

    public function handle(PrivateBackupFiles $files): int
    {
        $operator = null;
        $identity = null;
        $sourceConfig = config('database.connections.mysql');
        $sourceDisk = config('filesystems.disks.local.root');
        $changed = false;
        $input = null;
        try {
            if (! $this->option('create-isolated-target') || Artisan::call('system:assert-local-demo-database') !== 0
                || Artisan::call('system:verify-local-backup', ['id' => $this->argument('id'), '--manifest-sha256' => $this->option('manifest-sha256')]) !== 0) {
                throw new \RuntimeException;
            }
            $backup = base_path('../.tools/local-backups/'.$this->argument('id'));
            $manifest = json_decode(file_get_contents($backup.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $drill = (bool) $this->option('recovery-drill');
            $expectedSource = $drill ? 'kkp_pinjaman_restore_test' : 'kkp_pinjaman';
            if ($manifest['source_tree_dirty'] !== false || ! isset($manifest['constraints'], $manifest['indexes']) || $manifest['source_database'] !== $expectedSource
                || ($drill && (! Str::isUuid($manifest['source_restore_id'] ?? '') || ! isset($manifest['logical_row_hashes'])
                    || ($manifest['table_counts']['application_submissions'] ?? 0) < 1 || ($manifest['table_counts']['submission_documents'] ?? 0) < 3
                    || ($manifest['table_counts']['approval_decisions'] ?? 0) < 2))) {
                throw new \RuntimeException;
            }
            $target = $drill ? 'kkp_pinjaman_recovery_drill' : 'kkp_pinjaman_restore_test';
            $operator = DB::connection()->getPdo();
            $existing = $operator->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE SCHEMA_NAME=?');
            $existing->execute([$target]);
            if ((int) $existing->fetchColumn() !== 0) {
                throw new \RuntimeException;
            }
            $existing->closeCursor();
            $mysql = 'Q:/maragoon/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe';
            if (! is_file($mysql)) {
                throw new \RuntimeException;
            }
            $sql = file_get_contents($backup.'/database.sql');
            if (preg_match('/^(?:USE\s|CREATE\s+DATABASE\s|DROP\s|TRUNCATE\s)/im', $sql) || str_contains($sql, '`'.$expectedSource.'`.')) {
                throw new \RuntimeException;
            }
            unset($sql);
            $run = (string) Str::uuid();
            $directory = base_path('../.tools/local-restores/'.$run);
            if (file_exists($directory) || ! mkdir($directory, 0700, true)) {
                throw new \RuntimeException;
            }
            file_put_contents($directory.'/INCOMPLETE', 'Isolated restore validation pending.');
            $files->copy($backup.'/private', $directory.'/private');
            mkdir($directory.'/artifact-root', 0700);
            foreach ($manifest['release_files'] as $path => $metadata) {
                if (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/') || str_contains($path, ':')) {
                    throw new \RuntimeException;
                }
                $dest = $directory.'/artifact-root/'.$path;
                if (! is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0700, true);
                }
                $index = array_search($path, array_keys($manifest['release_files']), true);
                $files->copyFile($backup.'/release/'.$index.'-'.basename($path), $dest);
            }
            // DDL is limited to a new explicitly named target; never DROP/TRUNCATE or IF NOT EXISTS.
            $operator->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $account = 'lendara_restore_'.bin2hex(random_bytes(6));
            $created = $operator->query("CREATE USER '$account'@'127.0.0.1' IDENTIFIED BY RANDOM PASSWORD");
            $identity = "'$account'@'127.0.0.1'";
            $row = array_change_key_case($created->fetch(\PDO::FETCH_ASSOC), CASE_LOWER);
            $created->closeCursor();
            $password = $row['generated password'];
            unset($row);
            $operator->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON `$target`.* TO $identity");
            $process = new Process([$mysql, '--no-defaults', '--protocol=TCP', '--host=127.0.0.1', '--port='.$sourceConfig['port'], '--user='.$account,
                '--database='.$target, '--binary-mode=1', '--local-infile=0'], base_path(), ['MYSQL_PWD' => $password], timeout: 120);
            $input = fopen($backup.'/database.sql', 'rb');
            $process->setInput($input);
            $process->disableOutput();
            if ($process->run() !== 0) {
                throw new \RuntimeException;
            }
            fclose($input);
            $input = null;
            $restoreConfig = array_replace($sourceConfig, ['url' => null, 'host' => '127.0.0.1', 'unix_socket' => '', 'database' => $target, 'username' => $account, 'password' => $password]);
            config(['database.connections.mysql' => $restoreConfig, 'filesystems.disks.local.root' => $directory.'/private']);
            DB::purge('mysql');
            Storage::forgetDisk('local');
            $changed = true;
            if (DB::selectOne('SELECT DATABASE() AS name')->name !== $target) {
                throw new \RuntimeException;
            }
            foreach ($manifest['table_counts'] as $table => $count) {
                if (! preg_match('/\A[a-z_]+\z/D', $table) || DB::table($table)->count() !== $count) {
                    throw new \RuntimeException;
                }
                if (isset($manifest['logical_row_hashes']) && (! isset($manifest['logical_row_hashes'][$table])
                    || ! hash_equals($manifest['logical_row_hashes'][$table], app(DatabaseContentDigest::class)->table($table)))) {
                    throw new \RuntimeException;
                }
            }
            $constraints = DB::select('SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS name, CONSTRAINT_TYPE AS type, ENFORCED AS enforced FROM information_schema.table_constraints WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME');
            $indexes = DB::select('SELECT TABLE_NAME AS table_name, INDEX_NAME AS name, SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
            if (json_encode($constraints) !== json_encode($manifest['constraints']) || json_encode($indexes) !== json_encode($manifest['indexes'])
                || DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.referential_constraints WHERE CONSTRAINT_SCHEMA=DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA<>DATABASE()')->total > 0
                || Artisan::call('documents:audit-integrity') !== 0) {
                throw new \RuntimeException;
            }
            foreach (DB::table('model_versions')->get() as $model) {
                if (str_starts_with($model->version_code, 'stub-')) {
                    continue;
                }
                $path = $model->artifact_path;
                if (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/') || str_contains($path, ':') || ! isset($manifest['model_artifacts'][$model->version_code])) {
                    throw new \RuntimeException;
                }
                $dest = $directory.'/artifact-root/'.$path;
                if (! is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0700, true);
                }
                $copied = $files->copyFile($backup.'/model/'.$model->artifact_hash.'.joblib', $dest);
                if ($copied['sha256'] !== $model->artifact_hash) {
                    throw new \RuntimeException;
                }
            }
            file_put_contents($directory.'/validation.json', json_encode(['database' => $target, 'tables' => count($manifest['table_counts']), 'row_counts_match' => true,
                'constraints_match' => true, 'indexes_match' => true, 'logical_content_match' => isset($manifest['logical_row_hashes']),
                'private_integrity_passed' => true, 'model_hashes_match' => true, 'browser_verified' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if (! rename($directory.'/INCOMPLETE', $directory.'/DATA_RESTORED_BROWSER_NOT_VERIFIED')) {
                throw new \RuntimeException;
            }
            $this->line(json_encode(['isolated_restore_data_verified' => true, 'database' => $target, 'restore_id' => $run, 'browser_verified' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Restore belum terverifikasi. Target harus baru/kosong dan pin valid. Paket/target parsial dipertahankan tanpa overwrite atau penghapusan; sumber tidak diubah.');

            return self::FAILURE;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if ($changed) {
                DB::purge('mysql');
                config(['database.connections.mysql' => $sourceConfig, 'filesystems.disks.local.root' => $sourceDisk]);
                Storage::forgetDisk('local');
            }
            if ($identity && $operator) {
                $operator->exec("DROP USER $identity"); // Only the temporary identity created above, never a database.
            }
        }
    }
}
