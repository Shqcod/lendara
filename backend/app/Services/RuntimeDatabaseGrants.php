<?php

namespace App\Services;

use InvalidArgumentException;

class RuntimeDatabaseGrants
{
    /** Generate a reviewable plan only. Provisioning and credentials belong to the operator. */
    public function statements(string $database, string $account, string $host): array
    {
        if (! preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database)
            || ! preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $account)
            || ! preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/D', $host)) {
            throw new InvalidArgumentException('Nama database/akun/host tidak valid; wildcard host tidak diizinkan.');
        }

        $permissions = [
            'users' => 'SELECT, INSERT, UPDATE',
            'customer_profiles' => 'SELECT, INSERT, UPDATE',
            'document_types' => 'SELECT',
            'loan_applications' => 'SELECT, INSERT, UPDATE, DELETE',
            'document_versions' => 'SELECT, INSERT, DELETE',
            'application_submissions' => 'SELECT, INSERT',
            'submission_documents' => 'SELECT, INSERT',
            'verification_results' => 'SELECT, INSERT',
            'approval_decisions' => 'SELECT, INSERT',
            'application_status_histories' => 'SELECT, INSERT',
            'activity_logs' => 'SELECT, INSERT',
            'anomaly_checks' => 'SELECT, INSERT, UPDATE (status, score, is_anomaly, finished_at, failure_reason)',
            'assignments' => 'SELECT, INSERT, UPDATE (ended_at, end_type, end_reason, ended_by)',
            'model_versions' => 'SELECT, INSERT, UPDATE (is_active)',
        ];
        foreach (['sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'] as $table) {
            $permissions[$table] = 'SELECT, INSERT, UPDATE, DELETE';
        }

        $statements = [];
        foreach ($permissions as $table => $privileges) {
            $statements[] = "GRANT $privileges ON `$database`.`$table` TO '$account'@'$host';";
        }

        return $statements;
    }
}
