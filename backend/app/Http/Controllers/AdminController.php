<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\InternalAccounts;
use App\Services\InternalReadModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }

    public function users(Request $request, InternalReadModel $read)
    {
        $data = $request->validate(['role' => ['sometimes', Rule::in(InternalAccounts::ROLES)], 'is_active' => ['sometimes', Rule::in(['0', '1'])],
            'email' => ['sometimes', 'string', 'max:255'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = User::whereIn('role', InternalAccounts::ROLES);
        foreach (['role', 'is_active', 'email'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 20)->withQueryString();

        return $this->json($read->page($page, $page->getCollection()->map(fn ($user) => (new UserResource($user))->resolve())));
    }

    public function store(Request $request, InternalAccounts $accounts)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'], 'role' => ['required', Rule::in(InternalAccounts::ROLES)],
            'is_active' => ['prohibited'], 'id' => ['prohibited'], 'actor_id' => ['prohibited']]);

        return $this->json(['data' => new UserResource($accounts->create($request->user()->id, $data)), 'message' => 'Akun internal berhasil dibuat.'], 201);
    }

    public function logs(Request $request, InternalReadModel $read)
    {
        $data = $request->validate(['action' => ['sometimes', 'string', 'max:100'], 'actor_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = DB::table('activity_logs');
        foreach (['action', 'actor_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 20, ['id', 'actor_id', 'action', 'target_type', 'target_id', 'created_at'])->withQueryString();

        return $this->json($read->page($page, $page->getCollection()->map(function ($log) {
            $log->created_at = Carbon::parse($log->created_at)->utc()->toIso8601String();

            return $log;
        })));
    }
}
