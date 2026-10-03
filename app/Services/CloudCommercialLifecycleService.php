<?php

namespace App\Services;

use App\Models\{CloudInvoice, CloudRenewalReminder, CloudSubscription, CloudSubscriptionEvent};
use Illuminate\Support\Facades\DB;

class CloudCommercialLifecycleService
{
    public function run(): array
    {
        $result = ['scheduled_changes'=>0,'grace_started'=>0,'expired'=>0,'reminders'=>0,'overdue_invoices'=>0];

        CloudSubscription::with(['plan','scheduledPlan'])->whereNotNull('scheduled_change_at')->where('scheduled_change_at','<=',now())->orderBy('id')->chunkById(100, function ($subscriptions) use (&$result) {
            foreach ($subscriptions as $subscription) DB::transaction(function () use ($subscription, &$result) {
                $subscription = CloudSubscription::lockForUpdate()->find($subscription->id);
                if (! $subscription?->scheduled_plan_id || $subscription->scheduled_change_at?->isFuture()) return;
                $oldPlan = $subscription->commercial_plan_id; $oldStatus = $subscription->status; $plan = $subscription->scheduledPlan;
                $starts = now(); $expires = $subscription->scheduled_duration_days ? $starts->copy()->addDays($subscription->scheduled_duration_days) : null;
                $subscription->update(['commercial_plan_id'=>$plan->id,'status'=>$plan->slug==='trial'?'trial':'active','starts_at'=>$starts,'expires_at'=>$expires,'grace_ends_at'=>$expires?->copy()->addDays($plan->grace_days),'scheduled_plan_id'=>null,'scheduled_change_at'=>null,'scheduled_duration_days'=>null,'scheduled_reason'=>null,'license_revision'=>((int)$subscription->license_revision)+1]);
                CloudSubscriptionEvent::create(['cloud_subscription_id'=>$subscription->id,'event'=>'scheduled_change_applied','from_status'=>$oldStatus,'to_status'=>$subscription->status,'from_plan_id'=>$oldPlan,'to_plan_id'=>$plan->id,'effective_at'=>now(),'reason'=>'Scheduled commercial change applied automatically.']);
                $result['scheduled_changes']++;
            });
        });

        CloudSubscription::whereIn('status',['trial','active','grace'])->whereNotNull('expires_at')->where('expires_at','<',now())->orderBy('id')->chunkById(100, function ($subscriptions) use (&$result) {
            foreach ($subscriptions as $subscription) {
                $next = $subscription->grace_ends_at?->isFuture() ? 'grace' : 'expired';
                if ($subscription->status === $next) continue;
                $old = $subscription->status; $subscription->update(['status'=>$next,'license_revision'=>((int)$subscription->license_revision)+1]);
                CloudSubscriptionEvent::create(['cloud_subscription_id'=>$subscription->id,'event'=>$next==='grace'?'grace_started':'expired','from_status'=>$old,'to_status'=>$next,'from_plan_id'=>$subscription->commercial_plan_id,'to_plan_id'=>$subscription->commercial_plan_id,'effective_at'=>now(),'reason'=>'Automatic expiry lifecycle.']);
                $result[$next==='grace'?'grace_started':'expired']++;
            }
        });

        CloudSubscription::whereIn('status',['trial','active'])->whereNotNull('expires_at')->whereBetween('expires_at',[now(),now()->addDays(31)])->each(function ($subscription) use (&$result) {
            $days = (int) round(now()->startOfDay()->diffInDays($subscription->expires_at->copy()->startOfDay(), false));
            if (! in_array($days,[30,14,7,1],true)) return;
            $created = CloudRenewalReminder::firstOrCreate(['cloud_subscription_id'=>$subscription->id,'days_before_expiry'=>$days,'expires_at'=>$subscription->expires_at],['created_at'=>now()]);
            if ($created->wasRecentlyCreated) $result['reminders']++;
        });

        $result['overdue_invoices'] = CloudInvoice::where('status','issued')->whereNotNull('due_on')->whereDate('due_on','<',today())->update(['status'=>'overdue']);
        return $result;
    }
}
