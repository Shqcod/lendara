<?php

use App\Jobs\RunPrediction;
use App\Services\ModelRegistration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DomainFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || config('database.default') !== 'mysql'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_test'
    || config('services.ml.mode') !== 'model') {
    throw new RuntimeException('Unsafe smoke database/runtime.');
}
if (($argv[1] ?? '') === 'prepare') {
    $lock = json_decode(file_get_contents(base_path('../ml-service/experiments/LOCK.json')), true);
    $selection = $lock['selection'];
    $version = app(ModelRegistration::class)->register(base_path('../'.$selection['metadata_path']), $selection['metadata_sha256']);
    $model = DB::table('model_versions')->where('version_code', $version)->sole();
    $fixture = new class
    {
        use DomainFixtures;

        public function create(int $model): array
        {
            $app = $this->loan(overrides: ['status' => 'pending_verification']);
            $snapshot = $this->submission($app);
            DB::table('loan_applications')->where('id', $app)->update(['current_submission_id' => $snapshot]);

            return ['application_id' => $app, 'check_id' => $this->check($snapshot, 'processing', overrides: ['model_version_id' => $model, 'deadline_at' => now()->addSeconds(120)])];
        }
    };
    $data = $fixture->create($model->id);
    $data['queue'] = 'model-smoke-'.Str::lower(Str::ulid());
    RunPrediction::dispatch($data['check_id'])->onQueue($data['queue']);
    $data['version_code'] = $version;
    $data['model_is_active'] = (bool) $model->is_active;
    echo json_encode($data, JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'inspect' && ctype_digit($argv[2] ?? '')) {
    $check = DB::table('anomaly_checks')->where('id', (int) $argv[2])->sole();
    $submission = DB::table('application_submissions')->where('id', $check->submission_id)->sole();
    $application = DB::table('loan_applications')->where('id', $submission->application_id)->sole();
    if ($check->status !== 'succeeded' || $application->status !== 'pending_verification') {
        throw new RuntimeException('Model queue smoke failed or changed business status.');
    }
    echo json_encode(['check_id' => $check->id, 'status' => $check->status, 'score' => $check->score,
        'is_anomaly' => (bool) $check->is_anomaly, 'application_status' => $application->status], JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Invalid smoke operation.');
}
