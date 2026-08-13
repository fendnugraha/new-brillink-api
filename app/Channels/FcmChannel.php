<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Kreait\Laravel\Firebase\Facades\Firebase; // atau app('firebase.messaging')

class FcmChannel
{
    public function send($notifiable, Notification $notification)
    {
        if (method_exists($notification, 'toFcm')) {
            // 1. Ambil object CloudMessage dari toFcm()
            $message = $notification->toFcm($notifiable);

            // 2. Jika pesan ada (token terisi), eksekusi pengiriman ke Firebase
            if ($message) {
                Firebase::messaging()->send($message);
            }
        }
    }
}
