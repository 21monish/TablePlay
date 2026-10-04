<?php

namespace App\Notifications;

use App\Models\CloudSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialWelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly CloudSubscription $subscription) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subscription = $this->subscription->loadMissing('restaurant', 'plan');

        return (new MailMessage)
            ->subject('Your TablePlay free trial is ready')
            ->greeting('Welcome to TablePlay, '.$notifiable->name.'!')
            ->line($subscription->restaurant->name.' now has an active '.$subscription->plan->trial_days.'-day free trial.')
            ->line('Your trial supports up to '.$subscription->plan->max_paired_tables.' paired customer tablets and does not require a payment card.')
            ->line('The trial ends on '.$subscription->expires_at->format('d M Y').'.')
            ->action('Continue restaurant setup', route('account.index'))
            ->line('Generate the one-time activation key only inside your secure TablePlay account. Never share it by email or chat.');
    }
}
