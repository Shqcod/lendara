<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AssignmentClaimRequest;
use App\Http\Requests\AssignmentReleaseRequest;
use App\Http\Requests\VerificationRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\ApplicationSubmission;
use App\Models\Assignment;
use App\Services\InternalReadModel;
use App\Services\PredictionRetries;
use App\Services\ReviewAssignments;
use App\Services\ReviewScope;
use App\Services\VerificationActions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InternalApplicationController extends Controller
{
    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, ReviewScope $scope, InternalReadModel $read)
    {
        $filter = $request->validate(['status' => ['sometimes', Rule::in([$scope->context($request->user())['status']])],
            'application_number' => ['sometimes', 'string', 'max:40'],
            'assignment' => ['sometimes', Rule::in(['all', 'mine', 'unassigned'])], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = $scope->applications($request->user());
        if (isset($filter['application_number'])) {
            $query->where('application_number', $filter['application_number']);
        }
        $assigned = DB::table('assignments')->whereColumn('application_id', 'loan_applications.id')->whereNull('ended_at');
        if (($filter['assignment'] ?? 'all') === 'unassigned') {
            $query->whereNotExists($assigned);
        }
        if (($filter['assignment'] ?? 'all') === 'mine') {
            $query->whereExists($assigned->where('assigned_to', $request->user()->id)
                ->whereColumn('submission_id', 'loan_applications.current_submission_id')->where('stage', $scope->context($request->user())['stage']));
        }
        $rows = $query->orderBy('id')->paginate($filter['per_page'] ?? 20)->withQueryString();

        return $this->json($read->page($rows, $rows->getCollection()->map(fn ($row) => $read->row($row))));
    }

    public function summary(Request $request, ReviewScope $scope)
    {
        $context = $scope->context($request->user());
        $counts = $scope->applications($request->user())->leftJoin('assignments as active', function ($join) {
            $join->on('active.application_id', '=', 'loan_applications.id')->whereNull('active.ended_at');
        })->selectRaw('COUNT(*) AS total, COALESCE(SUM(active.id IS NULL), 0) AS unassigned,
            COALESCE(SUM(active.assigned_to = ? AND active.submission_id = loan_applications.current_submission_id AND active.stage = ?), 0) AS mine',
            [$request->user()->id, $context['stage']])->first();
        $total = (int) $counts->total;
        $unassigned = (int) $counts->unassigned;
        $mine = (int) $counts->mine;

        return $this->json(['data' => ['status' => $context['status'], 'stage' => $context['stage'], 'total' => $total,
            'unassigned' => $unassigned, 'mine' => $mine, 'assigned_to_others' => $total - $unassigned - $mine,
            'by_status' => [$context['status'] => $total]]]);
    }

    public function show(Request $request, string $application, ReviewScope $scope, InternalReadModel $read)
    {
        $row = $scope->applications($request->user())->whereKey($application)->firstOrFail();
        $pages = $request->validate(['submission_page' => ['sometimes', 'integer', 'min:1'], 'assignment_page' => ['sometimes', 'integer', 'min:1'], 'history_page' => ['sometimes', 'integer', 'min:1']]);
        $submissions = ApplicationSubmission::where('application_id', $row->id)->orderByDesc('version_number')->paginate(10, ['*'], 'submission_page', $pages['submission_page'] ?? 1);
        $assignments = Assignment::where('application_id', $row->id)->orderByDesc('id')->paginate(10, ['*'], 'assignment_page', $pages['assignment_page'] ?? 1);
        $histories = DB::table('application_status_histories')->where('application_id', $row->id)->orderByDesc('id')->paginate(10, ['*'], 'history_page', $pages['history_page'] ?? 1);
        $data = $read->row($row);
        $data['current_submission'] = $read->snapshot($row->id, $row->current_submission_id);
        $data['submissions'] = $read->page($submissions, $submissions->getCollection()->map(fn ($s) => ['id' => $s->id, 'version_number' => $s->version_number, 'submitted_at' => $s->submitted_at->utc()->toIso8601String()]));
        $data['assignments'] = $read->page($assignments, $assignments->getCollection()->map(fn ($a) => $read->assignment($a)));
        $data['status_histories'] = $read->page($histories, $histories->getCollection()->map(fn ($h) => ['id' => $h->id, 'from_status' => $h->from_status, 'to_status' => $h->to_status,
            'submission_id' => $h->submission_id, 'changed_at' => Carbon::parse($h->changed_at)->utc()->toIso8601String()]));

        return $this->json(['data' => $data]);
    }

    public function snapshot(Request $request, string $application, string $submission, ReviewScope $scope, InternalReadModel $read)
    {
        $scope->applications($request->user())->whereKey($application)->firstOrFail();

        return $this->json(['data' => $read->snapshot((int) $application, (int) $submission)]);
    }

    public function checks(Request $request, string $submission, ReviewScope $scope, InternalReadModel $read)
    {
        $snapshot = ApplicationSubmission::findOrFail($submission);
        $scope->applications($request->user())->whereKey($snapshot->application_id)->firstOrFail();

        return $this->json(['data' => $read->snapshot($snapshot->application_id, (int) $submission)['anomaly_checks']]);
    }

    public function retry(Request $request, string $submission, ReviewScope $scope, PredictionRetries $retries)
    {
        abort_unless($request->user()->role === Role::Verifier, 403);
        $snapshot = ApplicationSubmission::findOrFail($submission);
        $scope->applications($request->user())->whereKey($snapshot->application_id)->firstOrFail();
        abort_if(count($request->all()) !== 0, 422, 'Retry tidak menerima field dari klien.');

        return $this->json(['data' => $retries->retry($request->user()->id, (int) $submission), 'message' => 'Percobaan pemeriksaan baru diterima. Periksa data terbaru untuk hasilnya.'], 202);
    }

    public function verify(VerificationRequest $request, string $submission, VerificationActions $actions)
    {
        return $this->json(['data' => $actions->store($request->user()->id, (int) $submission, $request->validated()),
            'message' => 'Hasil verifikasi berhasil disimpan.'], 201);
    }

    public function claim(AssignmentClaimRequest $request, string $application, ReviewAssignments $assignments)
    {
        return $this->json(['data' => new AssignmentResource($assignments->claim($request->user()->id, (int) $application, $request->validated('expected_current_submission_id'))),
            'message' => 'Tugas berhasil diambil.'], 201);
    }

    public function release(AssignmentReleaseRequest $request, string $assignment, ReviewAssignments $assignments)
    {
        return $this->json(['data' => new AssignmentResource($assignments->release($request->user()->id, (int) $assignment, $request->validated('reason'))),
            'message' => 'Tugas dilepas ke antrean. Status pengajuan tetap.']);
    }
}
