<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Throwable;

class FirebaseAuthController extends Controller
{
    protected FirebaseAuth $firebaseAuth;

    public function __construct(FirebaseAuth $firebaseAuth)
    {
        $this->firebaseAuth = $firebaseAuth;
    }

    public function handleFirebaseLogin(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        try {
            // 1. Verifikasi Firebase ID Token dari Next.js
            $verifiedIdToken = $this->firebaseAuth->verifyIdToken($request->id_token);
            $firebaseUid = $verifiedIdToken->claims()->get('sub');

            // 2. Ambil detail informasi akun dari Firebase
            $firebaseUser = $this->firebaseAuth->getUser($firebaseUid);
            $email = $firebaseUser->email;
            $name = $firebaseUser->displayName ?? explode('@', $email)[0];
            $emailVerified = $firebaseUser->emailVerified;

            // 3. Cari User di MySQL berdasarkan firebase_uid atau email
            $user = User::where('firebase_uid', $firebaseUid)
                ->orWhere('email', $email)
                ->first();

            if (!$user) {
                // Jika user belum ada di database Laravel, buat akun baru
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'firebase_uid' => $firebaseUid,
                    'password' => bcrypt(Str::random(16)), // Password acak aman
                    'email_verified_at' => $emailVerified ? now() : null,
                ]);
            } else {
                // Update firebase_uid & email_verified_at jika belum tersimpan
                $user->update([
                    'firebase_uid' => $firebaseUid,
                    'email_verified_at' => ($user->email_verified_at || $emailVerified) ? ($user->email_verified_at ?? now()) : null,
                ]);
            }

            // 4. Terbitkan token Sanctum resmi dari Laravel
            $token = $user->createToken('web-access-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login berhasil',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified' => !is_null($user->email_verified_at),
                ]
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token Firebase tidak valid atau kedaluwarsa: ' . $e->getMessage()
            ], 401);
        }
    }
}
