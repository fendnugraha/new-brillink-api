<?php

namespace App\Notifications;

use App\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Messaging\CloudMessage;

class SendPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $title;
    protected string $body;
    protected array $data;

    public function __construct(string $title, string $body, array $data = [])
    {
        $this->title = $title;
        $this->body = $body;
        $this->data = array_map('strval', $data);
    }

    public function via(object $notifiable): array
    {
        return [FcmChannel::class, 'database'];
    }

    public function toFcm($notifiable)
    {
        $token = $notifiable->fcm_token;

        if (!$token) {
            return;
        }

        return CloudMessage::fromArray([
            'token' => $token,
            'notification' => [
                'title' => $this->title,
                'body' => $this->body,
            ],
            'data' => $this->data,
            // 🟢 Konfigurasi Android Pop-up
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'jourdroid_alerts',
                    'sound' => 'default',
                ],
            ],
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'extra_data' => $this->data,
        ];
    }
}
