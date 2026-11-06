<?php

use App\Exceptions\DomainError;
use App\Services\CustomerDocuments;
use App\Services\CustomerSubmissions;
use App\Services\CustomerWorkspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// CLI-only worker for real, overlapping MySQL transactions. No credentials in argv/output.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_test') {
    fwrite(STDERR, 'Unsafe test database.');
    exit(1);
}
[$script, $mode, $actor, $application, $submission, $directory, $worker] = $argv;
$connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = static function (string $file): void {
    $until = microtime(true) + 15;
    while (! is_file($file)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Barrier timed out.');
        }
        usleep(10000);
        clearstatcache(true, $file);
    }
};

try {
    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    DB::beginTransaction();
    file_put_contents($directory.'/'.$worker.'.ready', (string) $connection);
    $wait($directory.'/start');
    if ($worker === 'b') {
        $wait($directory.'/a.inserted');
        file_put_contents($directory.'/b.attempting', 'transaction-active');
    }
    if ($mode === 'draft') {
        app(CustomerWorkspace::class)->createDraft((int) $actor, []);
    } elseif ($mode === 'submission') {
        app(CustomerSubmissions::class)->submit((int) $actor, (int) $application, null);
    } elseif ($mode === 'document') {
        $file = new UploadedFile($directory.'/fiktif.pdf', 'fiktif.pdf', 'application/pdf', null, true);
        app(CustomerDocuments::class)->upload((int) $actor, (int) $application, (int) $submission, $file);
    } elseif ($mode === 'assignment') {
        DB::table('assignments')->insert([
            'application_id' => (int) $application, 'submission_id' => (int) $submission,
            'assigned_to' => (int) $actor, 'stage' => 'verification', 'claimed_at' => now(),
        ]);
    } else {
        throw new RuntimeException('Unknown test mode.');
    }
    if ($worker === 'a') {
        file_put_contents($directory.'/a.inserted', 'uncommitted');
        $wait($directory.'/release');
    }
    DB::commit();
    echo json_encode(['connection' => $connection, 'result' => 'created'], JSON_THROW_ON_ERROR);
} catch (DomainError $error) {
    DB::rollBack();
    echo json_encode(['connection' => $connection, 'result' => $error->domainCode], JSON_THROW_ON_ERROR);
} catch (UniqueConstraintViolationException $error) {
    DB::rollBack();
    echo json_encode(['connection' => $connection, 'result' => 'unique_conflict', 'mysql_code' => $error->errorInfo[1]], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, 'Concurrency worker failed: '.$error::class);
    exit(2);
} finally {
    DB::disconnect();
}
