<?php

namespace App\Services;

use App\Http\Resources\AssignmentResource;
use App\Http\Resources\SubmissionResource;
use App\Models\ApplicationSubmission;
use App\Models\Assignment;
use App\Models\DocumentVersion;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InternalReadModel
{
    public function assignment(Assignment $assignment): array
    {
        return (new AssignmentResource($assignment->setRelation('assignee', User::find($assignment->assigned_to))))->resolve();
    }

    public function row(LoanApplication $application): array
    {
        $submission = ApplicationSubmission::where('application_id', $application->id)->whereKey($application->current_submission_id)->firstOrFail();
        $assignment = Assignment::where('application_id', $application->id)->whereNull('ended_at')->first();

        return ['id' => $application->id, 'application_number' => $application->application_number, 'status' => $application->status->value,
            'current_submission_id' => $submission->id, 'version_number' => $submission->version_number,
            'customer_name' => $submission->customer_snapshot['name'] ?? '', 'loan_amount' => $submission->loan_amount,
            'submitted_at' => $submission->submitted_at->utc()->toIso8601String(), 'current_assignment' => $assignment ? $this->assignment($assignment) : null];
    }

    public function snapshot(int $application, int $id): array
    {
        $submission = ApplicationSubmission::where('application_id', $application)->whereKey($id)->firstOrFail();
        $documents = DocumentVersion::where('application_id', $application)->whereIn('id', DB::table('submission_documents')
            ->where('submission_id', $id)->select('document_version_id'))->orderBy('document_type_id')->get();
        $data = (new SubmissionResource($submission->setRelation('documents', $documents)))->resolve();
        $data['anomaly_checks'] = DB::table('anomaly_checks as c')->join('model_versions as m', 'm.id', '=', 'c.model_version_id')
            ->where('c.submission_id', $id)->orderByDesc('c.attempt_number')
            ->get(['c.id', 'c.attempt_number', 'c.status', 'c.score', 'c.is_anomaly', 'c.failure_reason', 'c.started_at', 'c.deadline_at', 'c.finished_at', 'm.version_code'])
            ->map(fn ($check) => ['id' => $check->id, 'attempt_number' => $check->attempt_number, 'status' => $check->status,
                'score' => $check->score, 'is_anomaly' => $check->is_anomaly === null ? null : (bool) $check->is_anomaly,
                'failure_reason' => $check->failure_reason, 'model_version_code' => $check->version_code,
                'started_at' => Carbon::parse($check->started_at)->utc()->toIso8601String(), 'deadline_at' => Carbon::parse($check->deadline_at)->utc()->toIso8601String(),
                'finished_at' => $check->finished_at ? Carbon::parse($check->finished_at)->utc()->toIso8601String() : null])->all();

        return $data;
    }

    public function page($paginator, $data): array
    {
        return ['data' => $data, 'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
            'links' => ['first' => $paginator->url(1), 'last' => $paginator->url($paginator->lastPage()), 'prev' => $paginator->previousPageUrl(), 'next' => $paginator->nextPageUrl()]];
    }
}
