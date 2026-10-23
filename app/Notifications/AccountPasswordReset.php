<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AccountPasswordReset extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        // Never build a recovery link from a visitor-controlled Host header.
        $base = rtrim((string) config('app.url'), '/');
        $url = $base.'/reset-password/'.rawurlencode($this->token).'?'.http_build_query([
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your TablePlay password')
            ->line('A password reset was requested for your TablePlay account.')
            ->action('Choose a new password', $url)
            ->line('This single-use link expires in '.config('auth.passwords.users.expire', 60).' minutes.')
            ->line('If you did not request this, ignore this email. Your password has not changed.');
    }
}
