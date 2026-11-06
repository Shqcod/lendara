<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentUploadRequest;
use App\Http\Resources\DocumentVersionResource;
use App\Models\DocumentVersion;
use App\Models\LoanApplication;
use App\Services\CustomerDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function types(): JsonResponse
    {
        $types = DB::table('document_types')->orderBy('code')->get(['id', 'code', 'name', 'is_required', 'is_active'])
            ->map(fn ($type) => ['id' => $type->id, 'code' => $type->code, 'name' => $type->name,
                'is_required' => (bool) $type->is_required, 'is_active' => (bool) $type->is_active]);

        return response()->json(['data' => $types, 'message' => 'Jenis dokumen.'])->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, string $application): JsonResponse
    {
        LoanApplication::where('customer_id', $request->user()->id)->whereKey($application)->firstOrFail();
        $latest = DB::table('document_versions')->where('application_id', $application)
            ->selectRaw('MAX(id) as id')->groupBy('document_type_id');
        $documents = DocumentVersion::whereIn('id', $latest)->orderBy('document_type_id')->get();

        return response()->json(['data' => DocumentVersionResource::collection($documents), 'message' => 'Dokumen terkini per jenis.'])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(DocumentUploadRequest $request, string $application, CustomerDocuments $documents): JsonResponse
    {
        $document = $documents->upload($request->user()->id, (int) $application, (int) $request->validated('document_type_id'), $request->file('file'));

        return response()->json(['data' => new DocumentVersionResource($document), 'message' => 'Versi dokumen baru tersimpan.'], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function download(string $document): StreamedResponse
    {
        $version = DocumentVersion::findOrFail($document);
        Gate::authorize('view', $version);
        $path = $version->storage_path;
        abort_unless(preg_match('#^loan-documents/[a-zA-Z0-9/_\-.]+$#D', $path) && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $version->original_filename, [
            'Content-Type' => $version->mime_type, 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
