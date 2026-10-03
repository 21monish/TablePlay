# TablePlay Staff App

TablePlay now has two independent clients:

- `staff_app`: authenticated Admin, Counter, Kitchen, and Waiter operations on Android and Windows desktop.
- `tablet_app`: paired customer ordering, service requests, and games.

The Android apps use different application identifiers and can be installed on the same phone for testing. The Windows Staff app uses the same staff accounts and role permissions as Android.

## Sign-in and local server

Staff Windows defaults to `http://127.0.0.1:8000/api/v1` when it runs on the server laptop. Staff Android requires the restaurant laptop API address from **Local server settings**, for example `http://192.168.1.10:8000/api/v1`. No release contains a restaurant-specific hard-coded LAN address. Staff can use their password or configured PIN. Laravel returns the role and the app opens the matching workspace automatically.

The Android app supports the restaurant-owned private HTTP LAN. Use a fixed router DHCP reservation for the server laptop so saved addresses remain stable.

## Responsive navigation

- Phones and narrow windows use a hamburger button that opens the complete navigation drawer.
- Wide Windows screens keep the same navigation visible as a persistent sidebar.
- The sidebar includes the signed-in staff identity, role, current local-server state, refresh access, and safe sign-out.
- Admin sections: Overview, Tables, Team, Menu & Games, Reports, Settings, and System Health.
- Counter sections: Operations, Orders, Guest Requests, Billing, and Game Timers.
- Kitchen sections: Kitchen Queue, New Tickets, Preparing, and Ready.
- Waiter sections: Floor Overview, Guest Requests, Ready Orders, and My Floor.

## Role workspaces

### Admin

- Today's sales, orders, open tables, and pending orders.
- Create, edit, archive, or safely delete tables; pair and unpair customer tablets.
- Create staff accounts and control access, excluding the signed-in Admin account.
- Create categories, menu items, and games; control item availability and game activation.
- View sales summaries, daily totals, and the latest audit activity.
- Edit restaurant, tax, receipt, game-timer, kitchen-refresh, and device-health settings.
- Inspect database, cache, storage, queue, real-time server, runtime, and tablet health.
- Open the floating **Help** assistant for role-aware, step-by-step guidance without internet.

### Counter

- Pending-order confirmation and rejection with a proper reason form.
- Guest-request acknowledgement and completion.
- Open-table billing, discounts, cash collection, and payment status.
- Active game-session extension and stopping.

### Kitchen

- Confirmed, Preparing, and Ready ticket queue.
- Item and guest instructions.
- Preparing, Ready, and Served actions.
- Efficient seven-second foreground polling with an audible alert when a new confirmed ticket arrives.

### Waiter

- Unassigned or personally assigned guest requests.
- Acknowledge and complete service calls.
- Ready-order collection and Served action.
- Open table visits with guest count.
- Notify Counter that an active table needs its bill.

## Security

- Every endpoint requires a Laravel Sanctum token.
- APIs enforce Admin, Counter, Kitchen, or Waiter permissions server-side.
- Password and PIN hashes are hidden from every API response.
- Signing out deletes the current API token and clears the local staff session.
- The app uses branded in-app confirmation forms and Snackbar notifications; it does not use browser alert, confirm, or prompt boxes.

## Session persistence

- Staff login remains saved across app close, computer restart, sleep, temporary network loss, and server restart.
- A temporary authentication or connection failure shows a retryable message and does not erase the saved token.
- Only the explicit **Sign out** action removes the saved Staff login from that device.
- The website also keeps staff signed in on the same browser until they choose **Sign out**.
- Customer tablet identity and table pairing remain saved until **Reset pairing** is explicitly confirmed.

## Local updates

- The app checks the Laravel server at startup and from **Check for app updates** in the sidebar.
- Downloads show progress and must pass the server-provided SHA-256 checksum.
- Android opens the system installer; Windows hands off to the bundled updater, which safely replaces and restarts the app.
- Mandatory updates cannot be dismissed after a failed download; the app asks again, and Android rechecks after returning from the installer.

Build and publishing instructions are in `docs/LOCAL_APP_UPDATES.md`.

## Signed Android release

The publishable version 1.9.2/build 2014 release is located at:

`dist/TablePlay-Staff-Android-arm64-v1.9.2-release.apk`

SHA-256: `742A71B7CE6865645471CC9080A5029A8164D435758C3510AD6770387E6D10EB`

Signing certificate SHA-256: `4ADBA0C171BB6A0EC83246D28A2C37A911EFE4EE9921D097D3825D7E9C061A0E`

## Windows desktop app

The Windows edition uses the same role-directed workspaces and local Laravel API as the Android Staff app. Its content is constrained for comfortable desktop reading, while the Admin metrics expand to four columns on wide windows.

Build it from `staff_app` with:

```powershell
flutter build windows --release
```

Flutter requires Windows Developer Mode because the app uses desktop plugins for persistent local settings. After the release is built, distribute the complete `build/windows/x64/runner/Release` folder or its ZIP file. Do not copy the executable by itself because its DLL and data files are required.

The packaged 64-bit Windows version 1.9.2 release is located at:

`dist/TablePlay-Staff-Windows-x64-v1.9.2.zip`

SHA-256: `C57D59E1C74D9147FD4B1C7A420EFEE8A192798D804F0B3E93777212B859974B`

On each counter, kitchen, waiter, or Admin PC:

1. Extract the complete release ZIP.
2. Run `tableplay_staff.exe`.
3. Allow the app on the restaurant's private network if Windows Firewall asks.
4. Open **Local server settings** at sign-in and enter the Laravel API address, such as `http://192.168.1.10:8000/api/v1`.
5. Sign in with a staff password or PIN; the app opens the dashboard for that account's role.
