<?php

namespace App\Services;

use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuItemDeletionService
{
    public function __construct(private AuditService $audit) {}

    public function delete(Request $request, MenuItem $menuItem, bool $mobile = false): array
    {
        DB::transaction(function () use ($request, $menuItem, $mobile): void {
            $item = MenuItem::lockForUpdate()->findOrFail($menuItem->id);
            $old = $item->toArray();
            // Keep the original dish and photo for historical orders and bills.
            // Soft deletion excludes it from every live menu and future order.
            $item->update(['is_active' => false, 'is_available' => false]);
            $item->delete();
            $this->audit->record($request, 'menu_item.deleted'.($mobile ? '.mobile' : ''), $item, $old, $item->toArray());
        });

        return ['message' => 'Menu item removed. Past orders and bills are preserved.'];
    }
}
