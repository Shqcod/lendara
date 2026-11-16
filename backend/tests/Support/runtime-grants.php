<?php

use App\Services\RuntimeDatabaseGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--probe' || ! $app->environment('testing')
    || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'kkp_pinjaman_test'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_test') {
    throw new RuntimeException('Probe wajib CLI --probe pada MySQL kkp_pinjaman_test.');
}
$admin = DB::connection()->getPdo();
$version = $admin->query('SELECT VERSION()')->fetchColumn();
if (str_contains($version, 'MariaDB') || version_compare($version, '8.0.22', '<')) {
    throw new RuntimeException('Probe memerlukan MySQL >=8.0.22.');
}
$account = 'lendara_probe_'.bin2hex(random_bytes(6));
$identity = "'$account'@'127.0.0.1'";
$created = false;
$runtime = null;
$checks = 0;
$phase = 'create-temporary-account';
$failed = false;
try {
    // Server-generated secret stays in memory; it is never written to argv, files or output.
    $row = $admin->query("CREATE USER $identity IDENTIFIED BY RANDOM PASSWORD")->fetch(PDO::FETCH_ASSOC);
    $created = true;
    $row = array_change_key_case($row, CASE_LOWER);
    if (! is_string($row['generated password'] ?? null)) {
        throw new RuntimeException('Generated secret unavailable');
    }
    $password = $row['generated password'];
    foreach ((new RuntimeDatabaseGrants)->statements('kkp_pinjaman_test', $account, '127.0.0.1') as $sql) {
        $admin->exec($sql);
    }
    $phase = 'connect-runtime';
    $port = (int) config('database.connections.mysql.port', 3306);
    $runtime = new PDO("mysql:host=127.0.0.1;port=$port;dbname=kkp_pinjaman_test;charset=utf8mb4", $account, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($password, $row);
    $runtime->beginTransaction();
    $allow = function (string $sql) use ($runtime, &$checks) {
        $runtime->query($sql)->closeCursor();
        $checks++;
    };
    $deny = function (string $sql) use ($runtime, &$checks) {
        try {
            $runtime->query($sql)->closeCursor();
        } catch (PDOException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1142, 1143], true)) {
                $checks++;

                return;
            }
            throw $error;
        }
        throw new RuntimeException('Forbidden operation unexpectedly allowed');
    };
    $phase = 'append-only';
    foreach (['application_submissions', 'submission_documents', 'verification_results', 'approval_decisions', 'application_status_histories', 'activity_logs'] as $table) {
        $allow("SELECT * FROM `$table` WHERE 1=0 LOCK IN SHARE MODE");
        $deny("UPDATE `$table` SET id=id WHERE 1=0");
        $deny("DELETE FROM `$table` WHERE 1=0");
        // Explicit columns avoid attempting to insert generated columns, even with zero rows.
        $columns = $admin->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema='kkp_pinjaman_test' AND table_name='$table' AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        $fields = implode(',', array_map(fn ($column) => "`$column`", $columns));
        $allow("INSERT INTO `$table` ($fields) SELECT $fields FROM `$table` WHERE 1=0");
    }
    $phase = 'column-grants-and-locking';
    foreach (['anomaly_checks' => 'status', 'assignments' => 'ended_at', 'model_versions' => 'is_active'] as $table => $column) {
        $allow("SELECT * FROM `$table` WHERE 1=0 FOR UPDATE");
        $allow("UPDATE `$table` SET `$column`=`$column` WHERE 1=0");
        $deny("UPDATE `$table` SET id=id WHERE 1=0");
        $deny("DELETE FROM `$table` WHERE 1=0");
    }
    foreach (['anomaly_checks' => 'input_features', 'assignments' => 'assigned_to', 'model_versions' => 'artifact_hash', 'document_versions' => 'storage_path', 'document_types' => 'is_active'] as $table => $column) {
        $deny("UPDATE `$table` SET `$column`=`$column` WHERE 1=0");
    }
    $allow('SELECT * FROM document_types WHERE 1=0 LOCK IN SHARE MODE');
    foreach (['users', 'customer_profiles', 'loan_applications', 'document_versions'] as $table) {
        $allow("SELECT * FROM `$table` WHERE 1=0 FOR UPDATE");
    }
    $deny('DELETE FROM users WHERE 1=0');
    $deny('DELETE FROM customer_profiles WHERE 1=0');
    $deny('SELECT * FROM migrations WHERE 1=0');
    $deny('SELECT * FROM password_reset_tokens WHERE 1=0');
    $deny('SELECT * FROM mysql.user WHERE 1=0');
    $phase = 'supporting-crud';
    foreach (['sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'] as $table) {
        $allow("SELECT * FROM `$table` WHERE 1=0 FOR UPDATE");
        $key = in_array($table, ['cache', 'cache_locks'], true) ? 'key' : 'id';
        $allow("UPDATE `$table` SET `$key`=`$key` WHERE 1=0");
        $allow("DELETE FROM `$table` WHERE 1=0");
        $allow("INSERT INTO `$table` SELECT * FROM `$table` WHERE 1=0");
    }
    $phase = 'transactional-writes';
    $key = 'runtime-probe-'.bin2hex(random_bytes(8));
    $statement = $runtime->prepare('INSERT INTO cache (`key`, value, expiration) VALUES (?, ?, ?)');
    $statement->execute([$key, 'fixture', 1]);
    $statement = $runtime->prepare('UPDATE cache SET value=? WHERE `key`=?');
    $statement->execute(['updated', $key]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Probe cache update failed');
    }
    $statement = $runtime->prepare('DELETE FROM cache WHERE `key`=?');
    $statement->execute([$key]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Probe cache delete failed');
    }
    $allow("INSERT INTO activity_logs (action, target_type, metadata, created_at) VALUES ('runtime.probe', 'fixture', '{}', UTC_TIMESTAMP())");
    $runtime->rollBack();
    $checks += 3;
} catch (Throwable $error) {
    // Do not print database exceptions, connection details, generated credentials or queries.
    fwrite(STDERR, "Runtime grant probe failed at $phase (".$error::class.', code '.(int) ($error instanceof PDOException ? ($error->errorInfo[1] ?? 0) : 0).").\n");
    $failed = true;
} finally {
    try {
        if ($runtime?->inTransaction()) {
            $runtime->rollBack();
        }
    } catch (Throwable) {
        $failed = true;
        fwrite(STDERR, "Runtime probe rollback failed; connection closed.\n");
    } finally {
        $runtime = null;
        if ($created) {
            // Only the uniquely named identity created by this invocation is removed.
            try {
                $admin->exec("DROP USER $identity");
            } catch (Throwable) {
                $failed = true;
                fwrite(STDERR, "Temporary account cleanup failed: $identity\n");
            }
        }
    }
}
if ($failed) {
    exit(1);
}
echo json_encode(['database' => 'kkp_pinjaman_test', 'mysql' => $version, 'checks' => $checks, 'transaction' => 'rolled_back', 'temporary_account' => 'removed'], JSON_PRETTY_PRINT).PHP_EOL;
