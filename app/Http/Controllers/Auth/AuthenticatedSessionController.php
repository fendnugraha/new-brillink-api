<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\LogActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        // 1. Verifikasi credentials via LoginRequest
        $request->authenticate();

        // 2. Ambil user yang berhasil di-authenticate
        $user = $request->user();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // 3. Opsional: Hapus token lama agar tidak menumpuk di database
        // $user->tokens()->delete();

        // 4. Buat Sanctum Bearer Token baru
        $token = $user->createToken('auth_token')->plainTextToken;

        // 5. Catat Log Activity dengan format string yang rapi
        LogActivity::create([
            'user_id'      => $user->id,
            'warehouse_id' => $user->warehouse_id,
            'activity'     => 'Login',
            'description'  => sprintf(
                'IP Address: %s | Browser: %s | Device: %s',
                $request->ip(),
                $request->userAgent() ?? 'Unknown',
                $request->header('User-Agent-Device', $request->device ?? 'Desktop')
            ),
        ]);

        // 6. Return response JSON ke Next.js (Load relasi jika diperlukan)
        return response()->json([
            'status'  => 'success',
            'message' => 'Login berhasil',
            'token'   => $token,
            'user'    => $user, // Tambahkan ->load('role') jika ada relasi role
        ], 200);
    }

    public function storeAndroid(LoginRequest $request): JsonResponse
    {
        try {
            // Validasi email & password menggunakan logic Breeze
            $request->authenticate();

            // Buat Bearer Token khusus untuk perangkat Android
            $token = $request->user()->createToken('android_device')->plainTextToken;

            LogActivity::create([
                'user_id'      => $request->user()->id,
                'warehouse_id' => $request->user()->warehouse_id,
                'activity'     => 'Login',
                'description'  => sprintf(
                    'IP Address: %s | Browser: %s | Device: %s',
                    $request->ip(),
                    $request->userAgent() ?? 'Unknown',
                    $request->header('User-Agent-Device', $request->device ?? 'Desktop')
                ),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login berhasil',
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $request->user()->load([
                    'warehouse',
                    'warehouse.primaryCash.limit',
                    'contact.employee.warningActive',
                    'attendances' => function ($q) {
                        $q->whereMonth('date', now()->format('m'))->whereYear('date', now()->format('Y'));
                    }
                ])
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah.'
            ], 401);
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): JsonResponse
    {
        // 1. Hapus token Sanctum yang sedang digunakan saat ini oleh user
        if ($request->user()) {
            $request->user()->currentAccessToken()->delete();
        }

        // 2. Mengembalikan respons JSON sukses
        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil logout',
        ]);
    }
}
