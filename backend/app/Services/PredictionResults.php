<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PredictionResults
{
    public function fail(int $id, string $reason): int
    {
        return DB::table('anomaly_checks')->where('id', $id)->where('status', 'processing')->update([
            'status' => 'failed', 'failure_reason' => $reason, 'finished_at' => now(),
        ]);
    }

    public function expire(): int
    {
        return DB::table('anomaly_checks')->where('status', 'processing')->where('deadline_at', '<=', now())->update([
            'status' => 'failed', 'failure_reason' => 'deadline_expired', 'finished_at' => now(),
        ]);
    }

    public function store(int $id, array $response): void
    {
        $context = DB::table('anomaly_checks as c')->join('application_submissions as s', 's.id', '=', 'c.submission_id')
            ->where('c.id', $id)->first(['s.application_id']);
        if (! $context) {
            return;
        }
        DB::transaction(function () use ($id, $response, $context) {
            $application = DB::table('loan_applications')->where('id', $context->application_id)->lockForUpdate()->first();
            $check = DB::table('anomaly_checks')->where('id', $id)->lockForUpdate()->first();
            $model = DB::table('model_versions')->where('id', $check->model_version_id)->first();
            $reason = null;
            if ($check->status !== 'processing') {
                $reason = 'terminal_check';
            } elseif (Carbon::parse($check->deadline_at)->lte(now())) {
                $reason = 'deadline_expired';
            } elseif ((int) $application->current_submission_id !== (int) $check->submission_id) {
                $reason = 'obsolete_snapshot';
            } else {
                $score = $response['score'] ?? null;
                if (($response['anomaly_check_id'] ?? null) !== $id || ($response['submission_id'] ?? null) !== (int) $check->submission_id
                    || ($response['model_version_code'] ?? null) !== $model->version_code
                    || ($response['contract_version'] ?? null) !== '1' || ($response['feature_schema'] ?? null) !== 'loan-finance-v1'
                    || ($response['source'] ?? null) !== (str_starts_with($model->version_code, 'stub-') ? 'stub' : 'model')
                    || ($response['score_method'] ?? null) !== 'decision_function' || ($response['decision_threshold'] ?? null) !== 0.0 && ($response['decision_threshold'] ?? null) !== 0
                    || ! is_int($score) && ! is_float($score) || ! is_finite((float) $score) || ($response['is_anomaly'] ?? null) !== ($score < 0)) {
                    $reason = 'invalid_response';
                }
            }
            if ($reason !== null) {
                $this->fail($id, $reason);
                DB::table('activity_logs')->insert(['actor_id' => null, 'action' => 'anomaly.response_ignored', 'target_type' => 'anomaly_check',
                    'target_id' => $id, 'metadata' => json_encode(['reason' => $reason]), 'created_at' => now()]);

                return;
            }
            DB::table('anomaly_checks')->where('id', $id)->where('status', 'processing')->update([
                'status' => 'succeeded', 'score' => $response['score'], 'is_anomaly' => $response['is_anomaly'], 'finished_at' => now(),
            ]);
        });
    }
}
