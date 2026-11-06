<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Models\ApplicationSubmission;
use App\Models\DocumentVersion;
use App\Models\LoanApplication;
use App\Services\CustomerSubmissions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubmissionController extends Controller
{
    public function owned(Request $request, string $id): LoanApplication
    {
        return LoanApplication::whereKey($id)->where('customer_id', $request->user()->id)->firstOrFail();
    }

    private function withDocuments(ApplicationSubmission $submission): ApplicationSubmission
    {
        $documents = DocumentVersion::whereIn('id', DB::table('submission_documents')->where('submission_id', $submission->id)->select('document_version_id'))
            ->orderBy('document_type_id')->get();

        return $submission->setRelation('documents', $documents);
    }

    public function store(SubmissionRequest $request, string $application, CustomerSubmissions $submissions)
    {
        $submission = $submissions->submit($request->user()->id, (int) $application, $request->validated('expected_current_submission_id'));

        return response()->json(['data' => new SubmissionResource($submission),
            'message' => 'Pengajuan diterima untuk verifikasi.'], 202)->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, string $application)
    {
        $this->owned($request, $application);
        $query = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $rows = ApplicationSubmission::where('application_id', $application)->orderByDesc('version_number')->paginate($query['per_page'] ?? 20)->withQueryString();
        $rows->getCollection()->each(fn ($row) => $this->withDocuments($row));

        return response()->json(['data' => SubmissionResource::collection($rows->getCollection()), 'meta' => [
            'current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(),
        ], 'links' => ['first' => $rows->url(1), 'last' => $rows->url($rows->lastPage()), 'prev' => $rows->previousPageUrl(), 'next' => $rows->nextPageUrl()]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $application, string $submission)
    {
        $this->owned($request, $application);
        $row = ApplicationSubmission::where('application_id', $application)->whereKey($submission)->firstOrFail();

        return response()->json(['data' => new SubmissionResource($this->withDocuments($row)), 'message' => 'Data pengiriman tersimpan.'])
            ->header('Cache-Control', 'no-store, private');
    }

    public function histories(Request $request, string $application)
    {
        $this->owned($request, $application);
        $query = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $rows = DB::table('application_status_histories')->where('application_id', $application)->orderByDesc('id')
            ->paginate($query['per_page'] ?? 20)->withQueryString();
        $data = $rows->getCollection()->map(function ($row) {
            // Only the explicit request_revision content is public; never use generic history notes.
            $reason = $row->to_status === 'revision_required' ? DB::table('verification_results')->where('submission_id', $row->submission_id)
                ->where('result_type', 'request_revision')->value('notes') : null;

            return ['id' => $row->id, 'submission_id' => $row->submission_id, 'from_status' => $row->from_status,
                'to_status' => $row->to_status, 'changed_at' => Carbon::parse($row->changed_at)->utc()->toIso8601String(), 'revision_reason' => $reason];
        });

        return response()->json(['data' => $data, 'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
            'last_page' => $rows->lastPage(), 'total' => $rows->total()], 'links' => ['first' => $rows->url(1), 'last' => $rows->url($rows->lastPage()),
                'prev' => $rows->previousPageUrl(), 'next' => $rows->nextPageUrl()]])->header('Cache-Control', 'no-store, private');
    }
}
