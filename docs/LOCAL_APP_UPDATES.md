# TablePlay local application updates

TablePlay distributes Staff Android, Customer Android, and Staff Windows updates from the restaurant's Laravel server. Internet access is not required.

## Server setup

Run the migration once:

```powershell
php artisan migrate --force
```

When using Laravel's development server, launch it with the TablePlay-specific upload limits:

```powershell
powershell -ExecutionPolicy Bypass -File deployment\start-tableplay-server.ps1
```

Apache reads the matching limits from `public/.htaccess` and `public/.user.ini`. Package files remain private under `storage/app/updates`; they are delivered only through the download endpoint.

## Publish an update

1. Sign in as Admin and open **App Updates**.
2. Select Staff Android, Customer Android, or Staff Windows.
3. Enter the exact package version. Android also requires the exact version code/build number.
4. Add release notes and, when needed, a minimum supported version or mandatory flag.
5. Upload as a draft first, test it, and then publish it.

The server calculates SHA-256 itself. It rejects unsigned, invalid, debug-signed, wrong-application-ID, and version-mismatched APKs. It also rejects Windows ZIPs missing `tableplay_staff.exe`, `tableplay_updater.exe`, `flutter_windows.dll`, or `data/app.so`.

Publishing a release unpublishes the previous release for the same application and platform. Do not remove or overwrite published package files manually.

## Build signed Android packages

Run the signing setup once from a private terminal:

```powershell
powershell -ExecutionPolicy Bypass -File deployment\create-android-signing.ps1
```

Choose a strong password when prompted and securely back up both the generated keystore and its password. Never send the password in chat or place it in source control.

For each release, increase the `version` and build number in both Flutter `pubspec.yaml` files, then build:

```powershell
cd staff_app
flutter build apk --release --target-platform android-arm64

cd ..\tablet_app
flutter build apk --release --target-platform android-arm64
```

Every future update must keep the same application ID and signing key and use a higher build number. A phone currently running a debug-signed pilot APK must uninstall it once before the first signed release; that one transition clears local app data. Later signed-to-signed updates preserve login, pairing, and local preferences.

Android normally asks the user to permit installation from TablePlay and confirm the update. Device-owner controlled installation is a separate kiosk deployment feature.

## Build the Windows package

```powershell
cd staff_app
flutter build windows --release
```

ZIP the contents of `staff_app/build/windows/x64/runner/Release`, with the executables and `data` directory at the ZIP root. The Staff app downloads the ZIP, verifies SHA-256, starts `tableplay_updater.exe`, exits, and the updater validates, backs up, replaces, rolls back on failure, and restarts `tableplay_staff.exe`.

## API

```text
GET /api/v1/app-updates/check
GET /api/v1/app-updates/download/{target}
```

Valid targets are `staff-android`, `customer-android`, and `staff-windows`. Update checks also refresh the Admin connected-device version report.

## Release-network note

Android builds support the restaurant-owned private HTTP LAN because router and
hotspot addresses vary between installations. Application runtime defaults do
not contain a restaurant-specific LAN IP: Staff Android requires the current
laptop address, while Customer receives it from a secure pairing QR. Keep the
system on an isolated private network, reserve a stable laptop IP in the router,
and use HTTPS before exposing any deployment to the public internet.
