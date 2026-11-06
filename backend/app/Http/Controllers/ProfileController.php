<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileRequest;
use App\Services\CustomerWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = DB::table('users as u')->leftJoin('customer_profiles as p', 'p.user_id', '=', 'u.id')
            ->where('u.id', $request->user()->id)->first([
                'u.name', 'p.identity_number', 'p.birth_date', 'p.address', 'p.phone',
                'p.occupation_type', 'p.workplace_name', 'p.work_duration_months',
            ]);

        return response()->json(['data' => $profile, 'message' => 'Profil Anda.'])
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(ProfileRequest $request, CustomerWorkspace $workspace): JsonResponse
    {
        $workspace->updateProfile($request->user()->id, $request->validated());

        return $this->show($request);
    }
}
