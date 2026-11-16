<?php

use App\Services\RuntimeDatabaseGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../backend/vendor/autoload.php';
$app = require __DIR__.'/../../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$testing = ($argv[1] ?? '') === '--testing-pipe';
$restore = ($argv[1] ?? '') === '--restore-pipe';
$database = $restore ? 'kkp_pinjaman_restore_test' : ($testing ? 'kkp_pinjaman_test' : 'kkp_pinjaman');
$connectionDatabase = $restore ? 'kkp_pinjaman' : $database;
if (PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '', ['--local-pipe', '--testing-pipe', '--restore-pipe'], true) || stream_isatty(STDOUT)
    || ! $app->environment($testing ? 'testing' : 'local') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== $connectionDatabase || DB::selectOne('SELECT DATABASE() AS name')->name !== $connectionDatabase) {
    fwrite(STDERR, "Unsafe provisioning context.\n");
    exit(1);
}
if ($restore) {
    \App\Services\RestoreWorkspace::root(getenv('RESTORE_WORKSPACE_ID') ?: '', $database, 'local');
    if ((int) DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.tables WHERE TABLE_SCHEMA=?', [$database])->total !== 22) {
        exit(1);
    }
}
$pdo = DB::connection()->getPdo();
$account = ($restore ? 'lendara_recover_' : ($testing ? 'lendara_dbtest_' : 'lendara_runtime_')).bin2hex(random_bytes(6));
$identity = "'$account'@'127.0.0.1'";
$created = false;
$retained = false;
$exit = 0;
try {
    $statement = $pdo->query("CREATE USER $identity IDENTIFIED BY RANDOM PASSWORD");
    $created = true;
    $row = array_change_key_case($statement->fetch(PDO::FETCH_ASSOC), CASE_LOWER);
    $statement->closeCursor();
    if (! is_string($row['generated password'] ?? null)) {
        throw new RuntimeException;
    }
    foreach ((new RuntimeDatabaseGrants)->statements($database, $account, '127.0.0.1') as $sql) {
        $pdo->exec($sql);
    }
    // stdout is a private redirected pipe to the Windows provisioner, never a user log.
    echo json_encode(['username' => $account, 'password' => $row['generated password'], 'port' => config('database.connections.mysql.port', 3306)])."\n";
    flush();
    unset($row);
    $request = trim(fgets(STDIN) ?: '');
    $retained = ! $testing && $request === 'persisted';
    if (! $retained && $request !== 'cleanup') {
        $exit = 1;
    }
} catch (Throwable) {
    fwrite(STDERR, "Local runtime provisioning failed.\n");
    $exit = 1;
} finally {
    if ($created && ! $retained) {
        try {
            $pdo->exec("DROP USER $identity");
        } catch (Throwable) {
            fwrite(STDERR, "Owned provisioning account cleanup failed: $identity\n");
            $exit = 1;
        }
    }
}
exit($exit);
