<?php

use App\Services\RuntimeDatabaseGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--pipe' || stream_isatty(STDOUT)
    || ! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'kkp_pinjaman_test'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_test'
    || ! preg_match('/\A[a-f0-9-]{36}\z/D', getenv('MODEL_BROWSER_RUN') ?: '')) {
    throw new RuntimeException('Runtime account broker requires guarded test runner and private pipe.');
}
$pdo = DB::connection()->getPdo();
$account = 'lendara_browser_'.bin2hex(random_bytes(6));
$identity = "'$account'@'127.0.0.1'";
$created = false;
$exit = 0;
try {
    $row = $pdo->query("CREATE USER $identity IDENTIFIED BY RANDOM PASSWORD")->fetch(PDO::FETCH_ASSOC);
    $created = true;
    $row = array_change_key_case($row, CASE_LOWER);
    if (! is_string($row['generated password'] ?? null)) {
        throw new RuntimeException('Generated secret unavailable');
    }
    foreach ((new RuntimeDatabaseGrants)->statements('kkp_pinjaman_test', $account, '127.0.0.1') as $sql) {
        $pdo->exec($sql);
    }
    // Private stdout pipe is consumed only by the runner, never forwarded to logs/tools.
    echo json_encode(['username' => $account, 'password' => $row['generated password'], 'port' => config('database.connections.mysql.port', 3306)])."\n";
    flush();
    unset($row);
    $request = fgets(STDIN);
    if (rtrim($request ?: '', "\r\n") !== 'cleanup') {
        $exit = 1;
    }
} catch (Throwable) {
    fwrite(STDERR, "Runtime account broker failed.\n");
    $exit = 1;
} finally {
    if ($created) {
        try {
            $pdo->exec("DROP USER $identity");
        } catch (Throwable) {
            fwrite(STDERR, "Cleanup failed for temporary identity $identity\n");
            $exit = 1;
        }
    }
}
exit($exit);
