<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Http\Requests\DraftRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\LoanApplication;
use App\Services\CustomerWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    private function owned(Request $request, string $id): LoanApplication
    {
        return LoanApplication::where('customer_id', $request->user()->id)->whereKey($id)->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $query = $request->validate([
            'status' => ['sometimes', Rule::enum(ApplicationStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $applications = LoanApplication::where('customer_id', $request->user()->id)
            ->when(isset($query['status']), fn ($builder) => $builder->where('status', $query['status']))
            ->orderByDesc('id')->paginate($query['per_page'] ?? 15)->withQueryString();

        return response()->json([
            'data' => ApplicationResource::collection($applications->getCollection())->resolve($request),
            'message' => 'Daftar pengajuan Anda.',
            'meta' => [
                'current_page' => $applications->currentPage(), 'per_page' => $applications->perPage(),
                'total' => $applications->total(), 'last_page' => $applications->lastPage(),
            ],
            'links' => [
                'first' => $applications->url(1), 'last' => $applications->url($applications->lastPage()),
                'prev' => $applications->previousPageUrl(), 'next' => $applications->nextPageUrl(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(DraftRequest $request, CustomerWorkspace $workspace): JsonResponse
    {
        $application = $workspace->createDraft($request->user()->id, $request->validated());

        return response()->json(['data' => new ApplicationResource($application), 'message' => 'Draf pengajuan dibuat.'], 201);
    }

    public function show(Request $request, string $application): JsonResponse
    {
        return response()->json([
            'data' => new ApplicationResource($this->owned($request, $application)), 'message' => 'Detail pengajuan Anda.',
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(DraftRequest $request, string $application, CustomerWorkspace $workspace): JsonResponse
    {
        $owned = $this->owned($request, $application);
        $updated = $workspace->updateDraft($request->user()->id, $owned->id, $request->validated());

        return response()->json(['data' => new ApplicationResource($updated), 'message' => 'Draf pengajuan disimpan.']);
    }

    public function destroy(Request $request, string $application, CustomerWorkspace $workspace): Response
    {
        $owned = $this->owned($request, $application);
        $workspace->deleteDraft($request->user()->id, $owned->id);

        return response()->noContent();
    }
}
