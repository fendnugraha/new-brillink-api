<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class FcmChannel
{
    public function send($notifiable, Notification $notification)
    {
        if (!method_exists($notification, 'toFcm')) {
            return;
        }

        // 1. Ambil object CloudMessage dari method toFcm()
        $message = $notification->toFcm($notifiable);

        // 2. Jika message null (misal user tidak punya fcm_token)
        if (!$message) {
            Log::warning("FCM Channel: Batal kirim, FCM Token milik User ID {$notifiable->id} kosong.");
            return;
        }

        try {
            // 3. Gunakan helper app('firebase.messaging') agar lebih stabil di Queue Worker
            $messaging = app('firebase.messaging');
            $messaging->send($message);

            Log::info("FCM Channel: Notifikasi berhasil dikirim ke User ID {$notifiable->id}");
        } catch (\Throwable $e) {
            // 4. Catat eror asli Firebase ke storage/logs/laravel.log
            Log::error("FCM Channel Error untuk User ID {$notifiable->id}: " . $e->getMessage());

            // Lempar error agar Laravel Queue mencatat pesan eror aslinya di tabel failed_jobs
            throw $e;
        }
    }
}
