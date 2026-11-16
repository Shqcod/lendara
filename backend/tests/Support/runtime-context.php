<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $identity = DB::selectOne('SELECT DATABASE() AS name, CURRENT_USER() AS account, CURRENT_ROLE() AS roles');
    if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'kkp_pinjaman_test' || $identity->name !== 'kkp_pinjaman_test'
        || $identity->account !== (getenv('RUNTIME_DB_USERNAME') ?: '!').'@127.0.0.1' || $identity->roles !== 'NONE'
        || ! str_starts_with($identity->account, 'lendara_browser_')) {
        throw new RuntimeException('Not the dedicated restricted test account');
    }
    if (config('cache.default') !== 'database' || config('session.driver') !== 'database' || config('queue.default') !== 'database') {
        throw new RuntimeException('Runtime proof requires database cache, sessions and queue');
    }
    foreach (['cache.stores.database.connection', 'cache.stores.database.lock_connection', 'session.connection', 'queue.connections.database.connection'] as $setting) {
        if (! in_array(config($setting), [null, 'mysql'], true)) {
            throw new RuntimeException('Runtime proof refuses an alternate supporting connection');
        }
    }
    try {
        DB::update('UPDATE application_submissions SET id=id WHERE 1=0');
        throw new RuntimeException('Runtime account unexpectedly permits snapshot updates');
    } catch (QueryException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1142) {
            throw $error;
        }
    }
    $result = ['restricted_identity_verified' => true, 'database' => $identity->name, 'roles' => $identity->roles, 'database_supporting_connections_verified' => true];
    if (($argv[1] ?? '') === 'scheduler') {
        if (config('cache.default') !== 'database') {
            throw new RuntimeException('Scheduler must use database cache');
        }
        // A dedicated fictional lost-enqueue fixture, inserted with the permitted runtime rights.
        // No deadline of an existing application/check is changed to force the result.
        [$checkId, $applicationId, $submissionId] = DB::transaction(function () {
            $user = User::factory()->create(['name' => 'Scheduler Fiktif', 'email' => 'scheduler-'.Str::uuid().'@example.test']);
            $application = DB::table('loan_applications')->insertGetId([
                'application_number' => 'SCHED-'.Str::ulid(), 'customer_id' => $user->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $submission = DB::table('application_submissions')->insertGetId([
                'application_id' => $application, 'version_number' => 1, 'customer_snapshot' => json_encode(['name' => 'Scheduler Fiktif']),
                'loan_amount' => '20000000.00', 'tenor_months' => 12, 'purpose' => 'Fixture expiry fiktif',
                'monthly_income' => '8000000.00', 'monthly_expenses' => '3000000.00', 'existing_installments' => '0.00',
                'submitted_by' => $user->id, 'submitted_at' => now()->subMinutes(3),
            ]);
            $model = DB::table('model_versions')->where('is_active', true)->sole();
            $check = DB::table('anomaly_checks')->insertGetId([
                'submission_id' => $submission, 'model_version_id' => $model->id, 'attempt_number' => 1, 'status' => 'processing',
                'input_features' => json_encode(['monthly_income' => 8000000, 'monthly_expenses' => 3000000, 'existing_installments' => 0, 'loan_amount' => 20000000, 'tenor_months' => 12]),
                'started_at' => now()->subMinutes(3), 'deadline_at' => now()->subMinute(), 'requested_by' => $user->id,
            ]);
            DB::table('loan_applications')->where('id', $application)->update(['current_submission_id' => $submission, 'status' => 'pending_verification']);

            return [$check, $application, $submission];
        });
        $snapshotBefore = json_encode(DB::table('application_submissions')->where('id', $submissionId)->sole());
        if (Artisan::call('schedule:run') !== 0 || ! str_contains(Artisan::output(), 'anomalies:expire')) {
            throw new RuntimeException('Scheduler expiry task did not run');
        }
        $heartbeat = Cache::get('system.scheduler.last_run');
        if (! is_string($heartbeat) || abs(time() - strtotime($heartbeat)) > 15) {
            throw new RuntimeException('Scheduler heartbeat not fresh');
        }
        $result['scheduler_database_heartbeat'] = true;
        $result['scheduler_expiry_command'] = true;
        $check = DB::table('anomaly_checks')->where('id', $checkId)->sole();
        if ($check->status !== 'failed' || $check->failure_reason !== 'deadline_expired' || $check->score !== null
            || DB::table('loan_applications')->where('id', $applicationId)->value('status') !== 'pending_verification'
            || json_encode(DB::table('application_submissions')->where('id', $submissionId)->sole()) !== $snapshotBefore) {
            throw new RuntimeException('Scheduler did not expire the owned fixture without changing snapshot/status');
        }
        $result['expired_fixture_check'] = $checkId;
        $result['snapshot_and_business_status_preserved'] = true;
    }
    echo json_encode($result).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Restricted runtime context or scheduler verification failed.\n");
    exit(1);
}
