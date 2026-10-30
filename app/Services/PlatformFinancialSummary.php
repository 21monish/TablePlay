<?php

namespace App\Services;

use App\Models\CloudPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlatformFinancialSummary
{
    public function cash(CarbonImmutable $month, ?array $restaurantIds = null): Collection
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth()->min(CarbonImmutable::now());
        $currency = "COALESCE(i.currency, 'UNASSIGNED')";

        $query = DB::table('cloud_payments as p')
            ->leftJoin('cloud_invoices as i', 'i.id', '=', 'p.cloud_invoice_id')
            ->whereIn('p.status', ['completed', 'refunded'])
            ->when($restaurantIds !== null, fn ($q) => $q->whereIn('p.cloud_restaurant_id', $restaurantIds))
            ->where(fn ($q) => $q->whereBetween('p.paid_at', [$from, $to])
                ->orWhere(fn ($refund) => $refund->where('p.status', 'refunded')->whereBetween('p.refunded_at', [$from, $to])))
            ->selectRaw("$currency as currency")
            ->selectRaw('SUM(CASE WHEN p.paid_at BETWEEN ? AND ? THEN p.amount ELSE 0 END) as receipts', [$from, $to])
            ->selectRaw("SUM(CASE WHEN p.status = 'refunded' AND p.refunded_at BETWEEN ? AND ? THEN p.amount ELSE 0 END) as refunds", [$from, $to])
            ->groupByRaw($currency)->orderBy('currency');

        if ($restaurantIds !== null) {
            $query->addSelect('p.cloud_restaurant_id')->groupBy('p.cloud_restaurant_id');
        }

        return $query->get()->map(function ($row) {
            $row->net_cents = (int) round((float) $row->receipts * 100) - (int) round((float) $row->refunds * 100);
            return $row;
        });
    }

    public function outstanding(): Collection
    {
        $paid = CloudPayment::query()->where('status', 'completed')
            ->selectRaw('cloud_invoice_id, SUM(amount) as received')->groupBy('cloud_invoice_id');

        return DB::table('cloud_invoices as i')->leftJoinSub($paid, 'p', 'p.cloud_invoice_id', '=', 'i.id')
            ->whereIn('i.status', ['issued', 'overdue'])
            ->select('i.currency')->selectRaw('SUM(CASE WHEN i.total > COALESCE(p.received, 0) THEN i.total - COALESCE(p.received, 0) ELSE 0 END) as balance')
            ->groupBy('i.currency')->orderBy('i.currency')->get();
    }
}
