<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\{AdminController,AppUpdateController,AutomationController,BrandAssetController,CloudManagementController,CounterController,HelpController,KitchenController,LicenseController,LoginController,PortalController,SetupWizardController,SuperAdminController};

Route::get('/', PortalController::class)->name('portal');
Route::get('/favicon.ico', [BrandAssetController::class, 'favicon'])->name('brand.favicon');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');
Route::post('/help/chat', [HelpController::class, 'chat'])->middleware(['auth', 'throttle:60,1'])->name('help.chat');
Route::prefix('superadmin')->name('superadmin.')->middleware(['auth','role:superadmin'])->group(function(){
    Route::get('/',[SuperAdminController::class,'index'])->name('index');
    Route::post('plans',[SuperAdminController::class,'storePlan'])->name('plans.store');
    Route::put('plans/{plan}',[SuperAdminController::class,'updatePlan'])->name('plans.update');
    Route::post('plans/{plan}/toggle',[SuperAdminController::class,'togglePlan'])->name('plans.toggle');
    Route::delete('plans/{plan}',[SuperAdminController::class,'destroyPlan'])->name('plans.destroy');
    Route::post('activate',[SuperAdminController::class,'activate'])->name('activate');
    Route::post('subscriptions/{subscription}/suspend',[SuperAdminController::class,'suspend'])->name('suspend');
    Route::post('subscriptions/{subscription}/resume',[SuperAdminController::class,'resume'])->name('resume');
    Route::get('cloud',[CloudManagementController::class,'index'])->name('cloud.index');
    Route::get('cloud/restaurants',[CloudManagementController::class,'index'])->name('cloud.restaurants.index');
    Route::get('cloud/export',[CloudManagementController::class,'exportRestaurants'])->name('cloud.export');
    Route::put('cloud/settings',[CloudManagementController::class,'updateCommercialSettings'])->name('cloud.settings.update');
    Route::post('cloud/restaurants',[CloudManagementController::class,'storeRestaurant'])->name('cloud.restaurants.store');
    Route::put('cloud/restaurants/{restaurant}',[CloudManagementController::class,'updateRestaurant'])->name('cloud.restaurants.update');
    Route::post('cloud/restaurants/{restaurant}/status',[CloudManagementController::class,'restaurantStatus'])->name('cloud.restaurants.status');
    Route::post('cloud/subscriptions/{subscription}/renew',[CloudManagementController::class,'renew'])->name('cloud.subscriptions.renew');
    Route::post('cloud/subscriptions/{subscription}/status',[CloudManagementController::class,'subscriptionStatus'])->name('cloud.subscriptions.status');
    Route::post('cloud/subscriptions/{subscription}/schedule',[CloudManagementController::class,'scheduleSubscription'])->name('cloud.subscriptions.schedule');
    Route::post('cloud/subscriptions/{subscription}/keys',[CloudManagementController::class,'issueKey'])->name('cloud.keys.issue');
    Route::post('cloud/offline-requests/import',[CloudManagementController::class,'importOfflineRequest'])->name('cloud.offline-requests.import');
    Route::get('cloud/offline-requests/{offlineRequest}/license',[CloudManagementController::class,'downloadOfflineLicense'])->name('cloud.offline-requests.license');
    Route::post('cloud/restaurants/{restaurant}/invoices',[CloudManagementController::class,'storeInvoice'])->name('cloud.invoices.store');
    Route::get('cloud/invoices/{invoice}',[CloudManagementController::class,'invoice'])->name('cloud.invoices.show');
    Route::post('cloud/restaurants/{restaurant}/payments',[CloudManagementController::class,'storePayment'])->name('cloud.payments.store');
    Route::post('cloud/payments/{payment}/refund',[CloudManagementController::class,'refundPayment'])->name('cloud.payments.refund');
    Route::post('cloud/invoices/{invoice}/void',[CloudManagementController::class,'voidInvoice'])->name('cloud.invoices.void');
    Route::post('cloud/installations/{installation}/deactivate',[CloudManagementController::class,'deactivate'])->name('cloud.installations.deactivate');
    Route::post('cloud/installations/{installation}/transfer',[CloudManagementController::class,'transfer'])->name('cloud.installations.transfer');
    Route::get('cloud/installations/{installation}/license',[CloudManagementController::class,'downloadLicense'])->name('cloud.installations.license');
});
Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('overview');
    Route::get('tables', [AdminController::class, 'tables'])->name('tables');
    Route::get('menu', [AdminController::class, 'menu'])->name('menu');
    Route::get('team', [AdminController::class, 'team'])->name('team');
    Route::get('games', [AdminController::class, 'games'])->middleware('entitlement:games')->name('games');
    Route::get('reports', [AdminController::class, 'reports'])->middleware('entitlement:advanced_reports')->name('reports');
    Route::get('settings', [AdminController::class, 'settings'])->name('settings');
    Route::get('system', [AdminController::class, 'system'])->name('system');
    Route::get('system/health', [AdminController::class, 'health'])->name('system.health');
    Route::get('license', [LicenseController::class, 'index'])->name('license.index');
    Route::post('license/activate', [LicenseController::class, 'activate'])->name('license.activate');
    Route::post('license/sync', [LicenseController::class, 'sync'])->name('license.sync');
    Route::post('license/offline/requests', [LicenseController::class, 'generateRequest'])->name('license.request');
    Route::get('license/offline/requests/{offlineRequest}', [LicenseController::class, 'downloadRequest'])->name('license.request.download');
    Route::post('license/import', [LicenseController::class, 'import'])->name('license.import');
    Route::get('app-updates', [AppUpdateController::class, 'index'])->name('app-updates.index');
    Route::get('setup', [SetupWizardController::class, 'index'])->name('setup.index');
    Route::put('setup/identity', [SetupWizardController::class, 'identity'])->name('setup.identity');
    Route::post('setup/tables', [SetupWizardController::class, 'tables'])->name('setup.tables');
    Route::post('setup/pairing/{table}', [SetupWizardController::class, 'pairingToken'])->name('setup.pairing');
    Route::post('setup/staff-connection', [SetupWizardController::class, 'staffConnection'])->name('setup.staff-connection');
    Route::post('setup/complete', [SetupWizardController::class, 'complete'])->name('setup.complete');
    Route::post('app-updates', [AppUpdateController::class, 'store'])->name('app-updates.store');
    Route::post('app-updates/{release}/publish', [AppUpdateController::class, 'publish'])->name('app-updates.publish');
    Route::post('app-updates/{release}/unpublish', [AppUpdateController::class, 'unpublish'])->name('app-updates.unpublish');
    Route::middleware('entitlement:automation')->group(function () {
        Route::get('automation', [AutomationController::class, 'index'])->name('automation.index');
        Route::put('automation', [AutomationController::class, 'update'])->name('automation.update');
        Route::post('automation/run', [AutomationController::class, 'run'])->name('automation.run');
    });
    Route::post('automation/backup', [AutomationController::class, 'backup'])->name('automation.backup');
    Route::get('automation/backups/{filename}', [AutomationController::class, 'download'])
        ->where('filename', '[A-Za-z0-9._-]+')->name('automation.backups.download');

    Route::post('staff', [AdminController::class, 'storeUser'])->name('staff.store');
    Route::post('staff/{user}/toggle', [AdminController::class, 'toggleUser'])->name('staff.toggle');
    Route::post('tables', [AdminController::class, 'storeTable'])->name('tables.store');
    Route::put('tables/{table}', [AdminController::class, 'updateTable'])->name('tables.update');
    Route::delete('tables/{table}', [AdminController::class, 'destroyTable'])->name('tables.destroy');
    Route::post('pairings', [AdminController::class, 'pairDevice'])->name('pairings.store');
    Route::post('pairings/{pairing}/unpair', [AdminController::class, 'unpairDevice'])->name('pairings.unpair');
    Route::post('categories', [AdminController::class, 'storeCategory'])->name('categories.store');
    Route::post('menu-items', [AdminController::class, 'storeMenuItem'])->name('menu-items.store');
    Route::put('menu-items/{menuItem}', [AdminController::class, 'updateMenuItem'])->name('menu-items.update');
    Route::post('menu-items/{menuItem}/toggle', [AdminController::class, 'toggleMenuItem'])->name('menu-items.toggle');
    Route::put('settings', [AdminController::class, 'updateSettings'])->name('settings.update');
    Route::post('games', [AdminController::class, 'storeGame'])->middleware('entitlement:games')->name('games.store');
    Route::post('games/{game}/toggle', [AdminController::class, 'toggleGame'])->middleware('entitlement:games')->name('games.toggle');
});

Route::prefix('counter')->name('counter.')->middleware(['auth', 'role:admin,counter'])->group(function () {
    Route::get('/', [CounterController::class, 'index'])->name('index');
    Route::post('orders/{order}/confirm', [CounterController::class, 'confirm'])->name('orders.confirm');
    Route::post('orders/{order}/reject', [CounterController::class, 'reject'])->name('orders.reject');
    Route::post('sessions/{tableSession}/bill', [CounterController::class, 'bill'])->name('sessions.bill');
    Route::post('bills/{bill}/pay', [CounterController::class, 'pay'])->name('bills.pay');
    Route::get('bills/{bill}/receipt', [CounterController::class, 'receipt'])->name('bills.receipt');
    Route::post('requests/{serviceRequest}', [CounterController::class, 'request'])->name('requests.update');
    Route::post('games/{gameSession}/extend', [CounterController::class, 'extend'])->middleware('entitlement:games')->name('games.extend');
    Route::post('games/{gameSession}/stop', [CounterController::class, 'stop'])->name('games.stop');
});

Route::prefix('kitchen')->name('kitchen.')->middleware(['auth', 'role:admin,kitchen'])->group(function () {
    Route::get('/', [KitchenController::class, 'index'])->name('index');
    Route::get('snapshot', [KitchenController::class, 'snapshot'])->name('snapshot');
    Route::post('orders/{order}/preparing', [KitchenController::class, 'preparing'])->name('orders.preparing');
    Route::post('orders/{order}/ready', [KitchenController::class, 'ready'])->name('orders.ready');
    Route::post('orders/{order}/served', [KitchenController::class, 'served'])->name('orders.served');
});
