<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{CloudCommercialSetting, CloudInstallation, CloudInvoice, CloudOfflineActivationRequest, CloudPayment, CloudRenewalReminder, CloudRestaurant, CloudSubscription, CloudSubscriptionEvent, CommercialPlan, LicenseSyncLog};
use App\Services\{AuditService, CloudLicenseService, LicenseSignatureService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CloudManagementController extends Controller
{
    public function index(Request $request, LicenseSignatureService $signatures)
    {
        $this->ensureCloud();
        $restaurants = CloudRestaurant::query()
            ->with([
                'subscriptions' => fn ($query) => $query->with(['plan', 'scheduledPlan', 'installations', 'events' => fn ($events) => $events->with(['fromPlan', 'toPlan', 'user'])->latest()])->latest(),
                'invoices' => fn ($query) => $query->with('payments')->latest('issued_on'),
                'payments' => fn ($query) => $query->latest('paid_at'),
            ])
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('q')).'%';
                $inner->where('name', 'like', $term)->orWhere('owner_name', 'like', $term)->orWhere('owner_email', 'like', $term);
            }))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('plan_id'), fn ($query) => $query->whereHas('subscriptions', fn ($subscriptions) => $subscriptions->where('commercial_plan_id', $request->integer('plan_id'))))
            ->when($request->filled('subscription_status'), fn ($query) => $query->whereHas('subscriptions', fn ($subscriptions) => $subscriptions->where('status', $request->string('subscription_status'))))
            ->when($request->filled('renewal_days'), fn ($query) => $query->whereHas('subscriptions', fn ($subscriptions) => $subscriptions->whereBetween('expires_at', [now(), now()->addDays($request->integer('renewal_days'))])))
            ->when($request->input('health') === 'attention', fn ($query) => $query->where(function ($health) {
                $health->where('status','!=','active')->orWhereHas('subscriptions', fn ($subscriptions) => $subscriptions->whereIn('status',['grace','suspended','expired'])->orWhere('expires_at','<=',now()->addDays(7)));
            }))
            ->latest()
            ->get();

        $commercialSettings = CloudCommercialSetting::firstOrCreate([], ['legal_name'=>'TablePlay','currency'=>'INR','invoice_prefix'=>'TP-INV']);

        return view('superadmin.cloud-operations', [
            'restaurants' => $restaurants,
            'plans' => CommercialPlan::where('is_active', true)->orderBy('sort_order')->get(),
            'syncLogs' => LicenseSyncLog::latest()->limit(30)->get(),
            'signingReady' => $signatures->signingReady(),
            'commercialSettings' => $commercialSettings,
            'reminders' => CloudRenewalReminder::with('subscription.restaurant','subscription.plan')->latest('created_at')->limit(20)->get(),
            'offlineRequests' => CloudOfflineActivationRequest::with(['subscription.restaurant', 'subscription.plan', 'installation', 'issuer'])->latest('imported_at')->limit(30)->get(),
            'offlineSubscriptions' => CloudSubscription::with(['restaurant', 'plan'])->whereIn('status', ['trial', 'active', 'grace'])->latest()->get()
                ->filter(fn ($subscription) => $subscription->restaurant?->status === 'active')->values(),
            'metrics' => [
                'customers' => CloudRestaurant::count(),
                'active' => CloudRestaurant::where('status', 'active')->count(),
                'revenue' => CloudPayment::sum('amount'),
                'outstanding' => CloudInvoice::whereIn('status', ['issued', 'overdue'])->sum('total'),
                'mrr' => CloudSubscription::where('status','active')->join('commercial_plans','commercial_plans.id','=','cloud_subscriptions.commercial_plan_id')->sum('commercial_plans.monthly_price'),
                'renewals_due' => CloudSubscription::whereIn('status',['trial','active'])->whereBetween('expires_at',[now(),now()->addDays(30)])->count(),
            ],
        ]);
    }

    public function exportRestaurants(Request $request)
    {
        $this->ensureCloud();
        $restaurants = CloudRestaurant::with(['subscriptions.plan','invoices','payments'])->orderBy('name')->get();
        return response()->streamDownload(function () use ($restaurants) {
            $stream = fopen('php://output','wb');
            fputcsv($stream,['Restaurant','Owner','Owner Email','Phone','Status','Current Plan','Subscription Status','Expires','Invoices','Payments Received']);
            foreach ($restaurants as $restaurant) {
                $subscription = $restaurant->subscriptions->sortByDesc('id')->first();
                fputcsv($stream,[$restaurant->name,$restaurant->owner_name,$restaurant->owner_email,$restaurant->owner_phone,$restaurant->status,$subscription?->plan?->name,$subscription?->status,$subscription?->expires_at?->toDateString(),$restaurant->invoices->count(),$restaurant->payments->where('status','completed')->sum('amount')]);
            }
            fclose($stream);
        }, 'tableplay-customers-'.today()->format('Y-m-d').'.csv', ['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    public function updateCommercialSettings(Request $request, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate(['legal_name'=>['required','string','max:255'],'tax_number'=>['nullable','string','max:80'],'billing_address'=>['nullable','string','max:1000'],'currency'=>['required','string','max:8'],'invoice_prefix'=>['required','alpha_dash','max:20']]);
        $settings = CloudCommercialSetting::firstOrCreate([]); $old = $settings->toArray(); $settings->update($data);
        $audit->record($request,'cloud_commercial_settings.updated',$settings,$old,$settings->fresh()->toArray());
        return back()->with('status','Commercial invoice settings updated.');
    }

    public function storeRestaurant(Request $request, CloudLicenseService $licenses, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'owner_name' => ['nullable', 'string', 'max:255'],
            'owner_email' => ['nullable', 'email', 'max:255'], 'owner_phone' => ['nullable', 'string', 'max:40'],
            'commercial_plan_id' => ['required', 'exists:commercial_plans,id'], 'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        [$restaurant, $subscription, $plainKey] = DB::transaction(function () use ($data, $licenses) {
            $plan = CommercialPlan::findOrFail($data['commercial_plan_id']);
            $days = (int) ($data['duration_days'] ?? ($plan->slug === 'trial' ? $plan->trial_days : 365));
            $starts = now(); $expires = $days ? $starts->copy()->addDays($days) : null;
            $restaurant = CloudRestaurant::create([
                'restaurant_uuid' => (string) Str::uuid(), 'name' => $data['name'], 'owner_name' => $data['owner_name'] ?? null,
                'owner_email' => $data['owner_email'] ?? null, 'owner_phone' => $data['owner_phone'] ?? null, 'status' => 'active',
            ]);
            $subscription = CloudSubscription::create([
                'cloud_restaurant_id' => $restaurant->id, 'commercial_plan_id' => $plan->id,
                'license_reference' => 'TP-'.strtoupper(Str::random(14)), 'status' => $plan->slug === 'trial' ? 'trial' : 'active',
                'starts_at' => $starts, 'expires_at' => $expires, 'grace_ends_at' => $expires?->copy()->addDays(7), 'max_installations' => 1,
            ]);
            CloudSubscriptionEvent::create(['cloud_subscription_id'=>$subscription->id,'event'=>'created','to_status'=>$subscription->status,'to_plan_id'=>$plan->id,'effective_at'=>now(),'created_by'=>auth()->id()]);
            return [$restaurant, $subscription, $licenses->issueKey($subscription)];
        });
        $audit->record($request, 'cloud_restaurant.created', $restaurant, null, $restaurant->toArray());
        return back()->with('status', "{$restaurant->name} created on {$subscription->plan()->first()->name}.")->with('issued_license_key', $plainKey);
    }

    public function updateRestaurant(Request $request, CloudRestaurant $restaurant, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate([
            'name'=>['required','string','max:255'],'owner_name'=>['nullable','string','max:255'],'owner_email'=>['nullable','email','max:255'],
            'owner_phone'=>['nullable','string','max:40'],'billing_name'=>['nullable','string','max:255'],'billing_email'=>['nullable','email','max:255'],
            'tax_number'=>['nullable','string','max:80'],'billing_address'=>['nullable','string','max:1000'],'notes'=>['nullable','string','max:2000'],
        ]);
        $old = $restaurant->toArray();
        $restaurant->update($data);
        $audit->record($request, 'cloud_restaurant.updated', $restaurant, $old, $restaurant->fresh()->toArray());
        return back()->with('status', "{$restaurant->name} customer profile updated.");
    }

    public function restaurantStatus(Request $request, CloudRestaurant $restaurant, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate(['status'=>['required',Rule::in(['active','suspended','closed'])]]);
        $old = $restaurant->toArray();
        DB::transaction(function () use ($restaurant, $data) {
            $restaurant->update($data);
            $restaurant->subscriptions()->increment('license_revision');
        });
        $audit->record($request, 'cloud_restaurant.status_changed', $restaurant, $old, $restaurant->fresh()->toArray());
        return back()->with('status', "{$restaurant->name} is now {$restaurant->status}.");
    }

    public function renew(Request $request, CloudSubscription $subscription, CloudLicenseService $licenses, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate(['commercial_plan_id' => ['required', 'exists:commercial_plans,id'], 'duration_days' => ['required', 'integer', 'min:1', 'max:3650']]);
        $old = $subscription->toArray(); $oldPlan = (int) $subscription->commercial_plan_id; $newPlan = (int) $data['commercial_plan_id'];
        $starts = now(); $expires = $starts->copy()->addDays($data['duration_days']);
        $subscription->update([
            'commercial_plan_id' => $data['commercial_plan_id'], 'status' => 'active',
            'starts_at' => $starts, 'expires_at' => $expires, 'grace_ends_at' => $expires->copy()->addDays(7),
            'license_revision' => ((int) $subscription->license_revision) + 1,
        ]);
        CloudSubscriptionEvent::create(['cloud_subscription_id'=>$subscription->id,'event'=>$oldPlan===$newPlan?'renewed':'plan_changed','from_status'=>$old['status'],'to_status'=>'active','from_plan_id'=>$oldPlan,'to_plan_id'=>$newPlan,'effective_at'=>now(),'reason'=>$request->input('reason'),'created_by'=>$request->user()->id]);
        $plainKey = $licenses->issueKey($subscription->fresh());
        $audit->record($request, 'cloud_subscription.renewed', $subscription, $old, $subscription->fresh()->toArray());
        return back()->with('status', 'Subscription renewed. Connected restaurants will receive the change at the next sync.')->with('issued_license_key', $plainKey);
    }

    public function subscriptionStatus(Request $request, CloudSubscription $subscription, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate(['status'=>['required',Rule::in(['trial','active','grace','suspended','expired'])],'reason'=>['required','string','max:500']]);
        $old = $subscription->toArray();
        $subscription->update([
            'status' => $data['status'],
            'license_revision' => ((int) $subscription->license_revision) + 1,
        ]);
        CloudSubscriptionEvent::create(['cloud_subscription_id'=>$subscription->id,'event'=>'status_changed','from_status'=>$old['status'],'to_status'=>$data['status'],'from_plan_id'=>$subscription->commercial_plan_id,'to_plan_id'=>$subscription->commercial_plan_id,'effective_at'=>now(),'reason'=>$data['reason'],'created_by'=>$request->user()->id]);
        $audit->record($request, 'cloud_subscription.status_changed', $subscription, $old, $subscription->fresh()->toArray());
        return back()->with('status', 'Subscription status updated and recorded in its timeline.');
    }

    public function scheduleSubscription(Request $request, CloudSubscription $subscription, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate(['commercial_plan_id'=>['required','exists:commercial_plans,id'],'scheduled_change_at'=>['required','date','after:now'],'duration_days'=>['nullable','integer','min:1','max:3650'],'reason'=>['required','string','max:500']]);
        $old = $subscription->toArray();
        $subscription->update(['scheduled_plan_id'=>$data['commercial_plan_id'],'scheduled_change_at'=>$data['scheduled_change_at'],'scheduled_duration_days'=>$data['duration_days'] ?? null,'scheduled_reason'=>$data['reason']]);
        CloudSubscriptionEvent::create(['cloud_subscription_id'=>$subscription->id,'event'=>'change_scheduled','from_status'=>$subscription->status,'to_status'=>$subscription->status,'from_plan_id'=>$subscription->commercial_plan_id,'to_plan_id'=>$data['commercial_plan_id'],'effective_at'=>$data['scheduled_change_at'],'reason'=>$data['reason'],'created_by'=>$request->user()->id]);
        $audit->record($request,'cloud_subscription.change_scheduled',$subscription,$old,$subscription->fresh()->toArray());
        return back()->with('status','Plan change scheduled successfully.');
    }

    public function storeInvoice(Request $request, CloudRestaurant $restaurant, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate(['cloud_subscription_id'=>['nullable','integer'],'subtotal'=>['required','numeric','min:0.01','max:99999999.99'],'tax_rate'=>['nullable','numeric','min:0','max:100'],'currency'=>['required','string','max:8'],'due_on'=>['nullable','date','after_or_equal:today'],'notes'=>['nullable','string','max:1000']]);
        if (filled($data['cloud_subscription_id'] ?? null) && ! $restaurant->subscriptions()->whereKey($data['cloud_subscription_id'])->exists()) abort(422, 'The selected subscription does not belong to this restaurant.');
        $invoice = DB::transaction(function () use ($restaurant, $data) {
            $subtotal = round((float)$data['subtotal'], 2); $rate = (float)($data['tax_rate'] ?? 0); $tax = round($subtotal * $rate / 100, 2);
            $invoice = CloudInvoice::create(['cloud_restaurant_id'=>$restaurant->id,'cloud_subscription_id'=>$data['cloud_subscription_id'] ?? null,'invoice_number'=>'PENDING-'.Str::uuid(),'status'=>'issued','currency'=>strtoupper($data['currency']),'subtotal'=>$subtotal,'tax_rate'=>$rate,'tax_amount'=>$tax,'total'=>$subtotal+$tax,'issued_on'=>today(),'due_on'=>$data['due_on'] ?? null,'notes'=>$data['notes'] ?? null]);
            $prefix = CloudCommercialSetting::first()?->invoice_prefix ?: 'TP-INV';
            $invoice->update(['invoice_number'=>$prefix.'-'.now()->format('Y').'-'.str_pad((string)$invoice->id, 6, '0', STR_PAD_LEFT)]);
            return $invoice;
        });
        $audit->record($request, 'cloud_invoice.created', $invoice, null, $invoice->toArray());
        return back()->with('status', "Invoice {$invoice->invoice_number} created.");
    }

    public function storePayment(Request $request, CloudRestaurant $restaurant, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate(['cloud_invoice_id'=>['nullable','integer'],'method'=>['required',Rule::in(['cash','bank','upi','card','other'])],'amount'=>['required','numeric','min:0.01','max:99999999.99'],'reference'=>['nullable','string','max:255'],'paid_at'=>['required','date'],'notes'=>['nullable','string','max:1000']]);
        $invoice = filled($data['cloud_invoice_id'] ?? null) ? $restaurant->invoices()->findOrFail($data['cloud_invoice_id']) : null;
        $payment = DB::transaction(function () use ($restaurant, $invoice, $data, $request) {
            $payment = CloudPayment::create(['cloud_restaurant_id'=>$restaurant->id,'cloud_invoice_id'=>$invoice?->id,'payment_number'=>'PENDING-'.Str::uuid(),'method'=>$data['method'],'amount'=>$data['amount'],'reference'=>$data['reference'] ?? null,'paid_at'=>$data['paid_at'],'notes'=>$data['notes'] ?? null,'recorded_by'=>$request->user()->id]);
            $payment->update(['payment_number'=>'TP-PAY-'.now()->format('Y').'-'.str_pad((string)$payment->id, 6, '0', STR_PAD_LEFT)]);
            if ($invoice && (float)$invoice->payments()->where('status','completed')->sum('amount') >= (float)$invoice->total) $invoice->update(['status'=>'paid','paid_at'=>now()]);
            return $payment;
        });
        $audit->record($request, 'cloud_payment.recorded', $payment, null, $payment->toArray());
        return back()->with('status', "Payment {$payment->payment_number} recorded.");
    }

    public function refundPayment(Request $request, CloudPayment $payment, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate(['reason'=>['required','string','max:500']]);
        abort_unless($payment->status === 'completed', 422, 'Only a completed payment can be refunded.');
        $old = $payment->toArray();
        DB::transaction(function () use ($payment,$data) {
            $payment->update(['status'=>'refunded','refunded_at'=>now(),'refund_reason'=>$data['reason']]);
            if ($payment->invoice) {
                $invoice = CloudInvoice::lockForUpdate()->find($payment->cloud_invoice_id);
                $refunded = $invoice->payments()->where('status','refunded')->sum('amount');
                $received = $invoice->payments()->where('status','completed')->sum('amount');
                $invoice->update(['refunded_amount'=>$refunded,'status'=>$received >= $invoice->total ? 'paid' : 'issued','paid_at'=>$received >= $invoice->total ? $invoice->paid_at : null]);
            }
        });
        $audit->record($request,'cloud_payment.refunded',$payment,$old,$payment->fresh()->toArray());
        return back()->with('status',"Payment {$payment->payment_number} refunded and recorded.");
    }

    public function voidInvoice(Request $request, CloudInvoice $invoice, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate(['reason'=>['required','string','max:500']]);
        abort_if($invoice->payments()->where('status','completed')->exists(),422,'Refund completed payments before voiding this invoice.');
        $old = $invoice->toArray(); $invoice->update(['status'=>'void','voided_at'=>now(),'notes'=>trim(($invoice->notes ? $invoice->notes."\n" : '').'Void reason: '.$data['reason'])]);
        $audit->record($request,'cloud_invoice.voided',$invoice,$old,$invoice->fresh()->toArray());
        return back()->with('status',"Invoice {$invoice->invoice_number} voided.");
    }

    public function invoice(CloudInvoice $invoice)
    {
        $this->ensureCloud();
        return view('superadmin.cloud-invoice', ['invoice'=>$invoice->load('restaurant','subscription.plan','payments'),'commercialSettings'=>CloudCommercialSetting::first()]);
    }

    public function issueKey(CloudSubscription $subscription, CloudLicenseService $licenses)
    {
        $this->ensureCloud();
        return back()->with('status', 'A new one-time activation key was issued.')->with('issued_license_key', $licenses->issueKey($subscription));
    }

    public function importOfflineRequest(Request $request, CloudLicenseService $licenses, AuditService $audit)
    {
        $this->ensureCloud();
        $data = $request->validate([
            'activation_request' => ['required', 'file', 'max:64', 'extensions:tpr,json'],
            'subscription_id' => ['required', 'integer', 'exists:cloud_subscriptions,id'],
        ]);
        $contents = file_get_contents($data['activation_request']->getRealPath());
        $offlineRequest = $licenses->importOfflineRequest(is_string($contents) ? $contents : '');
        $wasIssued = $offlineRequest->status === 'issued';
        $issued = $licenses->issueOfflineLicense(
            $offlineRequest,
            CloudSubscription::findOrFail($data['subscription_id']),
            $request->user()->id,
        );
        if (! $wasIssued) {
            $audit->record($request, 'cloud_offline_license.issued', $issued, null, [
                'request_id' => $issued->request_id,
                'installation_uuid' => $issued->installation_uuid,
                'cloud_subscription_id' => $issued->cloud_subscription_id,
                'license_revision' => $issued->license_revision,
            ]);
        }

        return back()
            ->with('status', $wasIssued ? 'This request was already licensed. The original signed file is ready to download.' : 'Offline activation request verified and licensed successfully.')
            ->with('issued_offline_request_id', $issued->id);
    }

    public function downloadOfflineLicense(CloudOfflineActivationRequest $offlineRequest)
    {
        $this->ensureCloud();
        abort_unless($offlineRequest->status === 'issued' && is_array($offlineRequest->license_envelope), 422, 'This activation request does not have an issued licence file.');
        $json = json_encode($offlineRequest->license_envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $filename = 'tableplay-offline-'.$offlineRequest->installation_uuid.'-r'.$offlineRequest->license_revision.'.tpl';

        return response($json, 200, [
            'Content-Type' => 'application/vnd.tableplay.license+json',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function deactivate(CloudInstallation $installation)
    {
        $this->ensureCloud();
        $installation->update(['status' => 'deactivated', 'deactivated_at' => now()]);
        return back()->with('status', 'The old server installation was deactivated and can now be replaced.');
    }

    public function transfer(CloudInstallation $installation, CloudLicenseService $licenses)
    {
        $this->ensureCloud();
        $installation->update(['status' => 'transferred', 'deactivated_at' => now()]);
        return back()->with('status', 'Transfer approved. Install TablePlay on the replacement server with this activation key.')->with('issued_license_key', $licenses->issueKey($installation->subscription));
    }

    public function downloadLicense(CloudInstallation $installation, CloudLicenseService $licenses)
    {
        $this->ensureCloud();
        abort_unless($installation->status === 'active', 422, 'Only active installations can receive an offline licence file.');
        abort_unless($installation->activation_method === 'online', 422, 'Use the matching offline request record to download this installation licence.');
        $json = json_encode($licenses->envelope($installation->load('subscription.restaurant', 'subscription.plan')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return response($json, 200, ['Content-Type' => 'application/vnd.tableplay.license+json', 'Content-Disposition' => 'attachment; filename="tableplay-'.$installation->installation_uuid.'.tpl"']);
    }

    private function ensureCloud(): void
    {
        abort_unless(
            config('tableplay.mode') === 'cloud'
            || config('tableplay.cloud_console_enabled')
            || app()->environment(['local', 'testing']),
            404
        );
    }
}
