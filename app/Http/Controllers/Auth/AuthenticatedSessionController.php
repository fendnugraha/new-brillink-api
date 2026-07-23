<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): Response
    {
        $request->authenticate();

        $request->session()->regenerate();

        return response()->noContent();
    }

    public function storeAndroid(LoginRequest $request): JsonResponse
    {
        try {
            // Validasi email & password menggunakan logic Breeze
            $request->authenticate();

            // Buat Bearer Token khusus untuk perangkat Android
            $token = $request->user()->createToken('android_device')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login berhasil',
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $request->user()->load([
                    'role.warehouse',
                    'role.warehouse.contact',
                    'role.warehouse.zone.contact',
                    'attendances' => function ($q) {
                        $q->with([
                            'contact.employee.warningActive',
                        ])->whereDate('date', now()->format('Y-m-d'));
                    }
                ])
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah.'
            ], 401);
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
