<?php

namespace App\Notifications;

use App\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Kreait\Laravel\Firebase\Facades\Firebase;

class SendPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $title;
    protected $body;
    protected $data;

    public function __construct($title, $body, array $data = [])
    {
        $this->title = $title;
        $this->body = $body;
        $this->data = array_map('strval', $data);
    }

    public function via(object $notifiable): array
    {
        // 💡 Kirim ke HP via FCM DAN Simpan ke Database sekaligus!
        return [FcmChannel::class, 'database'];
    }

    public function toFcm($notifiable)
    {
        $token = $notifiable->fcm_token;

        if (!$token) {
            return;
        }

        $message = CloudMessage::withTarget('token', $token)
            ->withNotification(FcmNotification::create($this->title, $this->body))
            ->withData($this->data);

        return Firebase::messaging()->send($message);
    }

    // 💡 Method ini menentukan struktur data yang tersimpan di kolom 'data' tabel notifications
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'extra_data' => $this->data,
        ];
    }
}
