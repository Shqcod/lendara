<?php

namespace App\Jobs;

use App\Services\PredictionClient;
use App\Services\PredictionResults;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunPrediction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public int $checkId) {}

    public function handle(PredictionClient $client, PredictionResults $results): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Job prediksi wajib dijalankan di luar transaksi database.');
        }
        $lock = Cache::store('database')->lock('anomaly-check:'.$this->checkId, 60);
        if (! $lock->get()) {
            return;
        }
        try {
            $check = DB::table('anomaly_checks as c')->join('application_submissions as s', 's.id', '=', 'c.submission_id')
                ->join('loan_applications as a', 'a.id', '=', 's.application_id')->join('model_versions as m', 'm.id', '=', 'c.model_version_id')
                ->where('c.id', $this->checkId)->first(['c.*', 'a.current_submission_id', 'm.version_code']);
            if (! $check || $check->status !== 'processing') {
                return;
            }
            $remaining = (int) now()->diffInSeconds(Carbon::parse($check->deadline_at), false);
            $timeout = min((int) config('services.ml.timeout'), 20, $remaining - 5);
            if ($timeout < 1) {
                $results->fail($this->checkId, 'deadline_expired');

                return;
            }
            if ((int) $check->current_submission_id !== (int) $check->submission_id) {
                $results->fail($this->checkId, 'obsolete_snapshot');

                return;
            }
            if (config('services.ml.mode') === 'stub' && ! app()->environment('local', 'testing')) {
                $results->fail($this->checkId, 'configuration_invalid');

                return;
            }
            // All context reads are complete. No transaction or row lock spans the HTTP request.
            try {
                $response = $client->predict(['contract_version' => '1', 'feature_schema' => 'loan-finance-v1',
                    'anomaly_check_id' => $this->checkId, 'submission_id' => (int) $check->submission_id,
                    'model_version_code' => $check->version_code, 'features' => json_decode($check->input_features, true, flags: JSON_THROW_ON_ERROR)], $timeout);
            } catch (Throwable) {
                $results->fail($this->checkId, 'prediction_failed');

                return;
            }
            $results->store($this->checkId, $response);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $error): void
    {
        app(PredictionResults::class)->fail($this->checkId, 'worker_failed');
    }
}
