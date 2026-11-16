<?php

use App\Enums\Role;
use App\Jobs\RunPrediction;
use App\Models\User;
use App\Services\RestoreWorkspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Only synthetic fixtures on the explicitly approved restore, never source/test fallback.
try {
    require __DIR__.'/../../backend/vendor/autoload.php';
    $app = require __DIR__.'/../../backend/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (PHP_SAPI !== 'cli' || getenv('RESTORE_BROWSER_TEST') !== '1' || ! $app->environment('local')
        || config('database.default') !== 'mysql' || DB::selectOne('SELECT DATABASE() AS name')->name !== 'kkp_pinjaman_restore_test'
        || Artisan::call('system:assert-runtime-database', ['--restore' => true]) !== 0
        || Artisan::call('system:assert-restore-workspace') !== 0) {
        throw new RuntimeException;
    }
    RestoreWorkspace::root((string) env('LENDARA_RESTORE_ID'), 'kkp_pinjaman_restore_test', 'local');
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $run = $input['run_id'] ?? '';
    if (! is_string($run) || ! Str::isUuid($run)) {
        throw new RuntimeException;
    }
    $op = $input['op'] ?? '';
    if ($op === 'reviewers') {
        $password = $input['password'] ?? '';
        if (! is_string($password) || strlen($password) < 16 || strlen($password) > 128) {
            throw new RuntimeException;
        }
        $result = DB::transaction(function () use ($run, $password) {
            $users = [];
            foreach (['verifier' => Role::Verifier, 'one' => Role::ApproverOne, 'two' => Role::ApproverTwo] as $key => $role) {
                $email = 'restore-'.$key.'-'.$run.'@example.test';
                User::forceCreate(['name' => 'Peninjau Restore Fiktif', 'email' => $email, 'password' => $password, 'role' => $role, 'is_active' => true]);
                $users[$key] = $email;
            }

            return $users;
        });
    } else {
        if (! in_array($op, ['route-job', 'inspect'], true) || ! is_int($input['application_id'] ?? null)
            || ! preg_match('/\Arestore-[a-f0-9-]{36}@example\.test\z/D', $input['customer_email'] ?? '')) {
            throw new RuntimeException;
        }
        $customer = DB::table('users')->where('email', $input['customer_email'])->where('role', 'nasabah')->where('name', 'Nasabah Restore Fiktif')->sole();
        $result = DB::transaction(function () use ($input, $customer, $op, $run) {
            $loan = DB::table('loan_applications')->where('id', $input['application_id'])->where('customer_id', $customer->id)->lockForUpdate()->sole();
            $checks = DB::table('anomaly_checks')->where('submission_id', $loan->current_submission_id)->orderBy('attempt_number')->get();
            if ($op === 'route-job') {
                $check = $checks->sole();
                if ($check->status !== 'processing') {
                    throw new RuntimeException;
                }
                $queue = 'restore-smoke-'.str_replace('-', '', $run);
                $routed = 0;
                foreach (DB::table('jobs')->where('queue', 'predictions')->whereNull('reserved_at')->where('attempts', 0)->lockForUpdate()->get() as $job) {
                    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
                    if (($payload['displayName'] ?? '') === RunPrediction::class
                        && str_contains($payload['data']['command'] ?? '', 's:7:"checkId";i:'.$check->id.';')) {
                        $routed += DB::table('jobs')->where('id', $job->id)->where('queue', 'predictions')->whereNull('reserved_at')->update(['queue' => $queue]);
                    }
                }
                if ($routed !== 1) {
                    throw new RuntimeException;
                }

                return ['queue' => $queue];
            }
            $submissions = DB::table('application_submissions')->where('application_id', $loan->id)->orderBy('id')->get();
            $links = DB::table('submission_documents')->whereIn('submission_id', $submissions->pluck('id'))->orderBy('id')->get();
            $documents = DB::table('document_versions')->where('application_id', $loan->id)->orderBy('id')->get();
            $integrity = true;
            foreach ($documents as $document) {
                $stream = Storage::disk('local')->readStream($document->storage_path);
                if (! is_resource($stream)) {
                    throw new RuntimeException;
                }
                try {
                    $hash = hash_init('sha256');
                    $size = hash_update_stream($hash, $stream);
                    $integrity = $integrity && $size === (int) $document->file_size_bytes && hash_final($hash) === $document->file_hash;
                } finally {
                    fclose($stream);
                }
            }

            return ['status' => $loan->status, 'submission_count' => $submissions->count(), 'document_count' => $documents->count(),
                'document_link_count' => $links->count(), 'private_integrity_passed' => $integrity,
                'immutable_sha256' => hash('sha256', json_encode([$submissions, $links, $documents], JSON_THROW_ON_ERROR)),
                'checks' => $checks->map(fn ($check) => ['status' => $check->status,
                    'model' => DB::table('model_versions')->where('id', $check->model_version_id)->value('version_code'),
                    'score_flag_consistent' => $check->status === 'succeeded' && is_finite((float) $check->score) && (bool) $check->is_anomaly === ((float) $check->score < 0)]),
                'history' => DB::table('application_status_histories')->where('application_id', $loan->id)->orderBy('id')->pluck('to_status'),
                'approved_levels' => DB::table('approval_decisions')->whereIn('submission_id', $submissions->pluck('id'))->where('decision', 'approved')->orderBy('level')->pluck('level'),
                'active_assignments' => DB::table('assignments')->where('application_id', $loan->id)->whereNull('ended_at')->count()];
        });
    }
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR, "Restore browser probe rejected or failed; no source fallback.\n");
    exit(1);
}
