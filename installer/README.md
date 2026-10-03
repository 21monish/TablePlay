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

`TablePlayServer` is a native automatic Windows service. It starts and supervises MariaDB, Laravel on port 8000, Reverb on port 8080, and the queue worker. MariaDB is bound to localhost only, uses port 3310, and gives Laravel a dedicated `tableplay_app` account rather than database-root access.

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
C:\xampp\htdocs\TablePlay\dist\installer\TablePlay-Setup-v2.5.6.exe
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

Private-network firewall rules are created only for TCP 8000 and TCP 8080. Database port 3310 is not exposed to the network.

## Offline activation operation

Offline activation does not require a Cloud URL or internet connection:

1. Install or upgrade the restaurant server with `TablePlay-Setup-v2.5.6.exe`.
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

For release 2.5.6, the Laravel suite passed 129 tests with 981 assertions;
Staff Flutter passed 5 tests and Customer Flutter passed 27 tests, with both
analyzers clean. The exact payload also passed the complete fresh-install and
in-place-upgrade smoke lifecycle. The
installer's SHA-256 is recorded beside the EXE in
`TablePlay-Setup-v2.5.6.exe.sha256`; verify that sidecar after every rebuild.

Current v2.5.6 SHA-256:

```text
29e6b00b3b15d187736b87a7c95297765641bc29972b29d75c9ff1ca853fa618
```
