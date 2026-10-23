# TablePlay One-Click Windows Installer

This package builds a self-contained `TablePlay-Setup.exe` for a Windows x64 counter/server PC. The installed product does not use XAMPP and does not modify any existing MySQL or MariaDB installation.

## Installed layout

```text
C:\Program Files\TablePlay\
|-- server             Laravel production application
|-- database           Private MariaDB runtime and data on 127.0.0.1:3310
|-- php                Private PHP runtime
|-- services           Windows service host
|-- server-manager     Graphical TablePlay Server Manager
|-- uninstaller        Safe delayed-removal helper
|-- packages           Signed Staff/Customer releases
|-- config             Installation and private database client settings
|-- backups            SQL backups
`-- logs               Runtime logs and health state
```

`TablePlayServer` is a native automatic Windows service. It supervises MariaDB, Laravel, Reverb, the queue worker and scheduler. New-install source now prefers HTTP 18740 and realtime 18741, checks IPv4/IPv6 bindings (including Windows exclusions), and selects another available port if necessary. The private database prefers 3310 and remains localhost-only with a dedicated `tableplay_app` account. Actual selected ports are persisted in `config/installation.json`; no fixed port is guaranteed free. Existing installations retain their saved ports, including legacy 8000/8080/3310, during upgrades.

## Build requirements

- Windows 10 or Windows 11 x64
- .NET 9 SDK
- The current TablePlay Laravel project with `vendor` and built public assets
- Portable PHP source (defaults to `C:\xampp\php`)
- MariaDB source containing `bin` and `share` (defaults to `C:\xampp\mysql`)
- The latest signed releases in the project `dist` directory

The build never packages `.env`, XAMPP database data directories, Laravel logs, live uploaded update files, signing keys, Android keystores, or customer records.

## Offline licence trust key

Restaurant installations contain only the Ed25519 **public** verification key.
The private signing key must remain exclusively in the TablePlay Cloud
environment and must never be passed to the installer build.

On the secured Cloud source machine, generate and install a keypair once:

```powershell
php -d extension=php_sodium.dll artisan tableplay:license-keypair --install --key-id=tableplay-market-v1
php -d extension=php_sodium.dll artisan tableplay:license-check
```

The generation command never displays the private key. It installs the private
key into the Cloud `.env` and writes only public metadata to
`installer\license-public-key.json`. Back up the Cloud `.env` in protected
secret storage. Use `--force` only for an intentional signing-key rotation,
because old restaurant releases trust the previous key.

Current production trust metadata:

- Key ID: `tableplay-market-v1`
- Public-key SHA-256 fingerprint: `ba19a3d79e744b92b07dda9df1109f8f537204663983bc54418f4446c47fa9ba`

The build validates that the public key is a 32-byte Ed25519 key and that its
SHA-256 fingerprint matches. It rejects metadata containing a private or secret
property. Setup then installs only the public key and key ID in restaurant
`.env` files and removes any legacy private-key entry during upgrades.

The installer also bundles Microsoft's signed Visual C++ x64 Redistributable
from `installer/prerequisites/vc_redist.x64.exe` and installs it before PHP is
started. This is required for reliable installation on clean Windows systems.
The build stops if that prerequisite is absent; never replace it with DLLs from
unofficial download sites.

## Build

Run an elevated PowerShell only if the local policy requires it:

```powershell
cd C:\xampp\htdocs\TablePlay\installer
.\build-installer.ps1 -LicensePublicKeyFile .\license-public-key.json
```

The result is written to:

```text
C:\xampp\htdocs\TablePlay\dist\installer\TablePlay-Setup-v2.5.7.exe
```

A matching `.sha256` file is generated. The final EXE should be Authenticode-signed with the TablePlay production code-signing certificate before it is distributed to restaurants.

The build version is also applied to the Windows file, product and assembly
metadata of every bundled .NET component, so Explorer properties and the payload
manifest match the installer filename.

## Installation flow

1. Double-click the setup EXE and approve UAC.
2. Enter the restaurant name, administrator email and administrator password.
3. Click **Install TablePlay**.
4. Wait while the private database, schema, seed data, releases, firewall and service are configured.
5. Click **Launch TablePlay** and sign in as `admin` with the password entered during setup.

If Windows or setup is interrupted before first-time installation completes,
the marker is detected on the next run. Setup moves the entire partial directory,
including any database files, to `C:\ProgramData\TablePlay\Recovery` and then
restarts with the restaurant and administrator fields enabled. It never silently
deletes an interrupted installation.

Private-network firewall rules use the selected HTTP and realtime ports only. The private database port is never exposed to the LAN. Shortcuts, environment values, health checks and Network Doctor use the saved configuration. Startup refuses an occupied/reserved saved port with an actionable error; it never stops XAMPP or silently renumbers an existing installation. Staff connects using the Server Manager address/Staff QR; Customer devices use a fresh signed pairing QR containing the correct LAN port. Saved addresses are preserved and credentials are never automatically migrated to an unverified server. Existing-port migration is a separate administrator-coordinated maintenance task, not a silent upgrade side effect.

## Offline activation operation

Offline activation does not require a Cloud URL or internet connection:

1. Install or upgrade the restaurant server with `TablePlay-Setup-v2.5.7.exe`.
2. On that server, open **Admin > License** and download the `.tpr` request.
3. Transfer the `.tpr` file to the secured Super Admin computer by USB.
4. Open **Super Admin > Restaurant Customers > Offline activation**.
5. Select the customer's active subscription, upload the `.tpr`, and issue the license.
6. Download the generated `.tpl` file and transfer it back by USB.
7. On the restaurant server, import the `.tpl` file from **Admin > License**.
8. Confirm that the expected plan, status, limits, and expiry are displayed.
9. Never copy the Cloud `.env` or private signing key to a restaurant computer.

Requests and licenses are signed and bound to the installation and device. The
server rejects tampered, wrong-device, replayed, and older-revision files without
replacing an existing valid license. By default, an offline licence must be
refreshed every 30 days; this bounds delayed suspension or plan changes while
preserving fully offline restaurant operation.

## Server Manager

The installer creates Admin and Server Manager shortcuts on the shared desktop and Start menu. Server Manager supports:

- Service start, stop and restart
- HTTP health and local IP status
- Open Admin and logs
- Offline QR codes for Staff Android, Customer Android and Staff Windows downloads
- Transaction-consistent SQL backups
- Two-step SQL restore while Laravel is in maintenance mode
- Two-step uninstall with exact-path validation

## Release safety

The Android APKs included by the current manifest use package identifiers `com.tableplay.tableplay_staff` and `com.tableplay.tableplay_tablet`. The build manifest records their signing-certificate SHA-256 fingerprint. Do not replace them with debug or differently signed APKs.

The setup EXE itself is not automatically Authenticode-signed because a private Windows code-signing key must not be stored in this repository. Sign the completed artifact in the secured release pipeline.

## Release verification

Before distribution, run the installer regression and exact-payload lifecycle
suite from the project root:

```powershell
dotnet run --project installer\tests\TablePlay.InstallerSmokeTests\TablePlay.InstallerSmokeTests.csproj --configuration Release -- C:\xampp\php C:\xampp\htdocs\TablePlay\installer\build\payload
```

The release is acceptable only when the junction, legacy-storage, PHP-extension,
fresh-install and in-place-upgrade checks all report `PASS`. A clean Windows VM
acceptance test and Authenticode signing are still required before commercial
distribution.

### 2.5.7 local verification (5 October 2026)

- Laravel: 169 tests passed, 1,270 assertions; no skipped tests.
- Staff Flutter: analyzer clean, 7 tests passed; Android 1.10.0 build 2016
  retains the production signing certificate. Windows 1.10.0 build 15 includes
  the updater, Flutter runtime, plugins and application data.
- Customer Flutter: analyzer clean, 27 tests passed; the unchanged signed
  Customer Android 1.12.1 build 2015 is bundled.
- Exact-payload fresh-install/upgrade lifecycle passed with an isolated MariaDB
  database, including immutable signed licence dates and historical menu deletion.
- Final EXE overlay matches the tested ZIP; all 13 critical integrity checks
  passed. No live database, uploads, `.env` or private signing keys are bundled.
- The existing Windows laptop was upgraded from 2.5.6 to 2.5.7. A protected
  pre-upgrade backup was restored into a separate temporary database for
  comparison. Original restaurant records and signed licence fields were
  unchanged. The added Restaurant Owner role and subscription-expiry backfill
  were verified as expected migrations, not data loss.
- Localhost/LAN HTTP health, live Reverb WebSocket handshake, exact app download
  checksums, login Show/Hide, empty-login rejection and unauthenticated endpoint
  protection passed after the real upgrade.

Clean-VM testing was not performed, at the user's request. Physical Android
tablet pairing and a multi-device live order/bill remain manual acceptance
checks; there were no paired devices during this laptop test. The installer is
unsigned and remains private until Windows production code signing is complete.

Current unsigned 2.5.7 test-build SHA-256:

```text
dc51e6ee1af9f473f9d13aa5671513bea509276e3446e6e1a84046b2a7d86c8a
```

For release 2.5.6, the Laravel suite passed 129 tests with 981 assertions;
Staff Flutter passed 5 tests and Customer Flutter passed 27 tests, with both
analyzers clean. The exact payload also passed the complete fresh-install and
in-place-upgrade smoke lifecycle. The
installer's SHA-256 is recorded beside the EXE in
`TablePlay-Setup-v2.5.6.exe.sha256`; verify that sidecar after every rebuild.

Previous v2.5.6 SHA-256 (unchanged historical artifact):

```text
29e6b00b3b15d187736b87a7c95297765641bc29972b29d75c9ff1ca853fa618
```
