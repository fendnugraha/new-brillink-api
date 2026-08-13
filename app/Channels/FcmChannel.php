<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;

class FcmChannel
{
    public function send($notifiable, Notification $notification)
    {
        if (method_exists($notification, 'toFcm')) {
            $notification->toFcm($notifiable);
        }
    }
}
