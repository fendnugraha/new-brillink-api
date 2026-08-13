<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Channels\FcmChannel;

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
        // Ensure all data values are strings for FCM compatibility
        $this->data = array_map('strval', $data);
    }

    public function via($notifiable): array
    {
        // Sends to FCM via your custom channel and saves to the local database
        return [FcmChannel::class, 'database'];
    }

    public function toFcm($notifiable)
    {
        // 1. Get the token from the user being notified
        $token = $notifiable->fcm_token;

        if (!$token) {
            return null;
        }

        // 2. Construct the message using the properties set in __construct
        return CloudMessage::fromArray([
            'token' => $token,
            'notification' => [
                'title' => $this->title,
                'body'  => $this->body,
            ],
            'data' => $this->data,
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'jourdroid_alerts', // 🎯 Matches the Android App
                    'icon'       => 'ic_launcher',
                    'color'      => '#8F6AFF',
                    'sound'      => 'default',
                ],
            ],
        ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'title'      => $this->title,
            'body'       => $this->body,
            'extra_data' => $this->data,
        ];
    }
}
