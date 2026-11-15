<?php

use App\Exceptions\DomainError;
use App\Models\User;
use App\Services\CustomerDocuments;
use App\Services\CustomerSubmissions;
use App\Services\CustomerWorkspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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
    } elseif ($mode === 'claim_http') {
        Auth::guard('web')->setUser(User::findOrFail($actor));
        $request = Request::create('/api/v1/internal/applications/'.$application.'/assignments', 'POST', [], [], [],
            ['HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['expected_current_submission_id' => (int) $submission]));
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
        $httpStatus = $response->getStatusCode();
        if (! in_array($httpStatus, [201, 409], true)) {
            throw new RuntimeException('Unexpected claim status '.$httpStatus);
        }
        $claimResult = $httpStatus === 201 ? 'created' : json_decode($response->getContent(), true)['code'];
    } elseif (in_array($mode, ['verification_http', 'retry_http', 'approval_http'], true)) {
        Auth::guard('web')->setUser(User::findOrFail($actor));
        $request = Request::create('/api/v1/internal/submissions/'.$submission.($mode === 'retry_http' ? '/anomaly-checks' : ($mode === 'approval_http' ? '/approval-decisions' : '/verification-results')), 'POST', [], [], [],
            ['HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode($mode === 'retry_http' ? new stdClass : ($mode === 'approval_http' ? ['decision' => 'approved'] : ['result_type' => 'request_revision', 'notes' => 'Revisi fiktif bersamaan', 'document_checklist' => []])));
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
        $httpStatus = $response->getStatusCode();
        if (! in_array($httpStatus, [201, 202, 404, 409], true)) {
            throw new RuntimeException('Unexpected verification status '.$httpStatus);
        }
        $claimResult = $httpStatus === 202 ? 'created' : ($httpStatus === 201 ? 'created' : ($httpStatus === 409 ? 'retry_conflict' : 'out_of_scope'));
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
    echo json_encode(['connection' => $connection, 'result' => $claimResult ?? 'created', 'http_status' => $httpStatus ?? null], JSON_THROW_ON_ERROR);
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
