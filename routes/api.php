<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{AdminStaffController,AppUpdateController,AuthController,AutomationController,CloudLicenseController,CounterController,DeviceController,HelpController,KitchenController,TabletController,WaiterController};

Route::prefix('cloud/v1')->middleware('throttle:30,1')->group(function () {
    Route::post('licenses/activate', [CloudLicenseController::class, 'activate']);
    Route::post('licenses/sync', [CloudLicenseController::class, 'sync']);
});

Route::prefix('v1')->group(function () {
    Route::get('app-updates/check', [AppUpdateController::class, 'check'])->middleware('throttle:60,1');
    Route::get('app-updates/download/{target}', [AppUpdateController::class, 'download'])
        ->whereIn('target', ['staff-android', 'customer-android', 'staff-windows'])
        ->middleware('throttle:30,1')
        ->name('api.app-updates.download');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('devices/register', [DeviceController::class, 'legacyOnboardingDisabled'])
        ->middleware('throttle:20,1');
    Route::post('devices/pair-with-token', [DeviceController::class, 'pairWithToken'])->middleware('throttle:20,1');
    Route::get('branding', [TabletController::class, 'branding'])->middleware('throttle:60,1');
    Route::middleware(['auth:sanctum','device'])->group(function () {
        Route::post('devices/pair', [DeviceController::class, 'legacyOnboardingDisabled']); Route::post('devices/heartbeat', [DeviceController::class, 'heartbeat']);
        Route::middleware('entitlement:customer_app')->group(function () {
            Route::get('table/session', [TabletController::class, 'currentSession']); Route::get('table/snapshot', [TabletController::class, 'snapshot']); Route::post('table-sessions', [TabletController::class, 'openSession']);
            Route::get('menu', [TabletController::class, 'menu']); Route::post('orders', [TabletController::class, 'placeOrder']); Route::get('orders/{order}', [TabletController::class, 'showOrder']); Route::get('table/orders', [TabletController::class, 'tableOrders']);
            Route::post('service-requests', [TabletController::class, 'serviceRequest']);
            Route::middleware('entitlement:games')->group(function () {
                Route::get('games/access', [TabletController::class, 'gameAccess']); Route::get('games', [TabletController::class, 'games']); Route::post('games/results', [TabletController::class, 'gameResult']);
            });
        });
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']); Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('help/chat', [HelpController::class, 'chat'])->middleware('throttle:60,1');
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::get('overview',[AdminStaffController::class,'overview']);
            Route::get('dashboard',[AdminStaffController::class,'dashboard']); Route::get('tables',[AdminStaffController::class,'tables']); Route::get('team',[AdminStaffController::class,'team']); Route::get('catalog',[AdminStaffController::class,'catalog']); Route::get('reports',[AdminStaffController::class,'reports'])->middleware('entitlement:advanced_reports'); Route::get('settings',[AdminStaffController::class,'settings']); Route::get('system',[AdminStaffController::class,'system']);
            Route::middleware('entitlement:automation')->group(function () { Route::get('automation',[AutomationController::class,'show']); Route::put('automation',[AutomationController::class,'update']); Route::post('automation/run',[AutomationController::class,'run']); });
            Route::post('automation/backup',[AutomationController::class,'backup']);
            Route::post('tables',[AdminStaffController::class,'storeTable']); Route::put('tables/{table}',[AdminStaffController::class,'updateTable']); Route::delete('tables/{table}',[AdminStaffController::class,'destroyTable']);
            Route::post('pairings',[AdminStaffController::class,'pairDevice']); Route::post('pairings/{pairing}/unpair',[AdminStaffController::class,'unpairDevice']);
            Route::post('staff',[AdminStaffController::class,'storeUser']); Route::post('staff/{user}/toggle',[AdminStaffController::class,'toggleUser']);
            Route::post('categories',[AdminStaffController::class,'storeCategory']); Route::post('menu-items',[AdminStaffController::class,'storeMenuItem']); Route::put('menu-items/{menuItem}',[AdminStaffController::class,'updateMenuItem']); Route::post('menu-items/{menuItem}/image',[AdminStaffController::class,'uploadMenuItemImage']); Route::post('menu-items/{menuItem}/toggle',[AdminStaffController::class,'toggleMenuItem']);
            Route::put('settings',[AdminStaffController::class,'updateSettings']); Route::post('settings/branding',[AdminStaffController::class,'updateBranding']); Route::post('games',[AdminStaffController::class,'storeGame'])->middleware('entitlement:games'); Route::post('games/{game}/toggle',[AdminStaffController::class,'toggleGame'])->middleware('entitlement:games');
        });
        Route::prefix('counter')->middleware('role:admin,counter')->group(function () { Route::get('dashboard',[CounterController::class,'dashboard']); Route::get('orders',[CounterController::class,'orders']); Route::post('orders/{order}/confirm',[CounterController::class,'confirm']); Route::post('orders/{order}/reject',[CounterController::class,'reject']); Route::post('game-sessions/{session}/extend',[CounterController::class,'extend'])->middleware('entitlement:games'); Route::post('game-sessions/{session}/stop',[CounterController::class,'stop']); Route::post('bills',[CounterController::class,'bill']); Route::post('bills/{bill}/cash-payment',[CounterController::class,'cashPayment']); Route::get('service-requests',[CounterController::class,'serviceRequests']); Route::post('service-requests/{serviceRequest}',[CounterController::class,'updateServiceRequest']); });
        Route::prefix('kitchen')->middleware('role:admin,kitchen')->group(function () { Route::get('orders',[KitchenController::class,'orders']); Route::post('orders/{order}/preparing',[KitchenController::class,'preparing']); Route::post('orders/{order}/ready',[KitchenController::class,'ready']); Route::post('orders/{order}/served',[KitchenController::class,'served']); });
        Route::prefix('waiter')->middleware('role:admin,waiter')->group(function () { Route::get('dashboard',[WaiterController::class,'dashboard']); Route::post('requests/{serviceRequest}/acknowledge',[WaiterController::class,'acknowledge']); Route::post('requests/{serviceRequest}/complete',[WaiterController::class,'complete']); Route::post('orders/{order}/served',[WaiterController::class,'served']); Route::post('sessions/{tableSession}/request-bill',[WaiterController::class,'requestBill']); Route::middleware('entitlement:waiter_ordering')->group(function () { Route::post('tables/{table}/sessions',[WaiterController::class,'openSession']); Route::post('sessions/{tableSession}/orders',[WaiterController::class,'placeOrder']); }); });
    });
});
