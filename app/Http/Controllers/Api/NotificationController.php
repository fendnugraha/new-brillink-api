<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Ambil daftar notifikasi milik user yang sedang login
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Ambil notifikasi dengan pagination (10 per halaman)
        $notifications = $user->notifications()->paginate(10);

        // 2. Hitung berapa notifikasi yang belum dibaca (untuk badge angka)
        $unreadCount = $user->unreadNotifications()->count();

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'data' => $notifications,
        ]);
    }

    /**
     * Tandai SATU notifikasi sebagai "sudah dibaca"
     */
    public function markAsRead(Request $request, $id)
    {
        $user = $request->user();

        // Cari notifikasi spesifik milik user
        $notification = $user->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notifikasi tidak ditemukan'
            ], 404);
        }

        // Tandai sudah dibaca
        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notifikasi ditandai sudah dibaca'
        ]);
    }

    /**
     * Tandai SEMUA notifikasi user sebagai "sudah dibaca"
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();

        // Tandai semua notifikasi yang belum dibaca sekaligus
        $user->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Semua notifikasi ditandai sudah dibaca'
        ]);
    }
}
