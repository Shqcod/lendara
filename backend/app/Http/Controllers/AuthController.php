<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'role' => ['prohibited'],
            'is_active' => ['prohibited'],
        ]);

        try {
            $user = DB::transaction(function () use ($data) {
                $user = new User;
                $user->fill(collect($data)->only(['name', 'email', 'password'])->all());
                $user->role = Role::Customer;
                $user->is_active = true;
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['email' => ['Email sudah digunakan.']]);
        }

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'Pendaftaran berhasil. Silakan masuk.',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::where('email', $data['email'])->lockForUpdate()->first();

            if (! $user || ! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => ['Email atau kata sandi tidak sesuai.']]);
            }

            if (! $user->is_active) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Akun dinonaktifkan. Hubungi administrator.',
                    'code' => 'ACCOUNT_INACTIVE', 'errors' => (object) [],
                ], 403));
            }

            Auth::guard('web')->login($user);

            return $user;
        });

        $request->session()->regenerate();

        return response()->json([
            'data' => new UserResource($user), 'message' => 'Berhasil masuk.',
        ]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()), 'message' => 'Sesi aktif.',
        ])->header('Cache-Control', 'no-store, private');
    }
}
