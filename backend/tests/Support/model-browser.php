<?php

use App\Models\User;
use App\Services\ModelHandover;
use App\Services\ModelRegistration;
use App\Services\PredictionResults;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || config('database.default') !== 'mysql'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_test'
    || config('services.ml.mode') !== 'model' || ! preg_match('/^[a-f0-9-]{36}$/D', getenv('MODEL_BROWSER_RUN') ?: '')) {
    throw new RuntimeException('Unsafe browser model fixture context');
}
$run = getenv('MODEL_BROWSER_RUN');
$statePath = base_path('../.tools/model-browser-'.$run.'.json');
$op = $argv[1] ?? '';
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
if ($op === 'expire') {
    app(PredictionResults::class)->expire();
    echo json_encode(['processing' => DB::table('anomaly_checks')->where('status', 'processing')->count()]);
} elseif ($op === 'prepare') {
    $lock = json_decode(file_get_contents(base_path('../ml-service/experiments/LOCK.json')), true);
    $version = app(ModelRegistration::class)->register(base_path('../'.$lock['selection']['metadata_path']), $lock['selection']['metadata_sha256']);
    $active = DB::table('model_versions')->where('is_active', true)->first();
    $target = DB::table('model_versions')->where('version_code', $version)->sole();
    $state = ['previous_id' => $active?->id, 'target_id' => $target->id, 'expected' => $active?->version_code ?? 'none'];
    $handle = fopen($statePath, 'x');
    if (! $handle) {
        throw new RuntimeException('State already exists');
    }
    fwrite($handle, json_encode($state));
    fclose($handle);
    app(ModelHandover::class)->switch($version, $state['expected']);
    echo json_encode(['active' => $version]);
} elseif ($op === 'restore') {
    if (! is_file($statePath)) {
        echo '{}';
        exit;
    }
    $state = json_decode(file_get_contents($statePath), true);
    if ((int) DB::selectOne("SELECT GET_LOCK('lendara:model-activation', 10) AS acquired")->acquired !== 1) {
        throw new RuntimeException('Restore busy');
    }
    try {
        DB::transaction(function () use ($state) {
            $models = DB::table('model_versions')->orderBy('id')->lockForUpdate()->get();
            $active = $models->firstWhere('is_active', true);
            if ($active?->id === $state['previous_id']) {
                return;
            }
            if ($active?->id !== $state['target_id']) {
                throw new RuntimeException('Unexpected active model; refusing restore');
            }
            DB::table('model_versions')->where('id', $state['target_id'])->update(['is_active' => false]);
            if ($state['previous_id']) {
                DB::table('model_versions')->where('id', $state['previous_id'])->update(['is_active' => true]);
            }
        });
    } finally {
        DB::select("SELECT RELEASE_LOCK('lendara:model-activation')");
    }
    echo json_encode(['restored' => true]);
} elseif ($op === 'users') {
    if (strlen($input['password'] ?? '') < 16) {
        throw new RuntimeException('Missing fixture password');
    }
    $users = [];
    foreach (['customer' => 'nasabah', 'verifier' => 'verifikator', 'one' => 'penyetuju_1', 'two' => 'penyetuju_2'] as $name => $role) {
        $user = User::factory()->create(['name' => 'Demo Model Fiktif '.$name, 'email' => 'model-browser-'.$run.'-'.$name.'@example.test', 'role' => $role, 'password' => $input['password']]);
        $users[$name] = ['email' => $user->email, 'id' => $user->id];
    }
    echo json_encode($users);
} else {
    $loan = DB::table('loan_applications as a')->join('users as u', 'u.id', '=', 'a.customer_id')->where('a.id', $input['application_id'] ?? 0)->first(['a.*', 'u.email']);
    if (! $loan || $loan->email !== 'model-browser-'.$run.'-customer@example.test') {
        throw new RuntimeException('Not this run application');
    }
    if ($op === 'documents') {
        $types = DB::table('document_types')->where('is_required', true)->where('is_active', true)->get();
        foreach ($types as $type) {
            if ($type->code === 'D01') {
                continue;
            }
            $path = 'loan-documents/browser-model-'.$run.'/'.Str::uuid().'.pdf';
            $bytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
            Storage::disk('local')->put($path, $bytes);
            DB::table('document_versions')->insert(['application_id' => $loan->id, 'document_type_id' => $type->id, 'version_number' => 1,
                'original_filename' => 'fiktif-'.$type->code.'.pdf', 'storage_path' => $path, 'mime_type' => 'application/pdf', 'file_size_bytes' => strlen($bytes),
                'file_hash' => hash('sha256', $bytes), 'uploaded_by' => $loan->customer_id, 'uploaded_at' => now()]);
        }
        echo '{}';
    } else {
        $checks = DB::table('anomaly_checks')->where('submission_id', $loan->current_submission_id)->orderBy('attempt_number')->get();
        $check = $checks->last();
        if ($op === 'route-job') {
            if (! $check || $check->status !== 'processing') {
                throw new RuntimeException('Expected processing owned check');
            }
            $queue = 'browser-model-'.$check->id;
            $jobs = DB::table('jobs')->where('queue', 'predictions')->get();
            $routed = 0;
            foreach ($jobs as $job) {
                $payload = json_decode($job->payload, true);
                if (str_contains($payload['data']['command'] ?? '', 's:7:"checkId";i:'.$check->id.';')) {
                    $routed += DB::table('jobs')->where('id', $job->id)->where('queue', 'predictions')->whereNull('reserved_at')->update(['queue' => $queue]);
                }
            }
            if ($routed !== 1) {
                throw new RuntimeException('Owned job not found exactly once');
            }
            echo json_encode(['queue' => $queue]);
        } elseif ($op === 'inspect') {
            echo json_encode(['status' => $loan->status, 'submission' => $loan->current_submission_id, 'checks' => $checks->map(fn ($c) => ['id' => $c->id, 'attempt' => $c->attempt_number, 'status' => $c->status, 'score' => $c->score, 'model' => DB::table('model_versions')->where('id', $c->model_version_id)->value('version_code')])]);
        } else {
            throw new RuntimeException('Invalid operation');
        }
    }
}
