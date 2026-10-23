# TablePlay Staff 1.11.0 - private candidate

Build: 2017 (Windows x64 and Android ARM64).

## Added native workflows

- Setup guide with the web application's server-checked onboarding checklist,
  restaurant-network API address and application download links.
- Staff connection QR: configures a phone's server without signing a user in.
- Customer pairing QR on Tables: signed, single-use, expires after 15 minutes,
  and checked against the restaurant's active licence and tablet quota.
- Licence page with online activation, signature status, server-calculated days
  remaining, separate subscription/offline-verification deadlines, plan limits,
  synchronization history, offline-request export and signed-licence import.
- Administrator Orders & billing workspace: counter order confirmation/rejection,
  guest requests, billing, cash collection and entitled game-timer controls.
- Bill preview showing historical item names and server-calculated totals.
- Menu search by dish/description/ingredient and availability filters.
- Compact phone-sized table/menu action menus and corrected dashboard-card sizing.
- A rejected saved login now offers Sign in again and is distinguished from a
  network outage. Transient connection failures do not delete saved credentials.

These are additional web-equivalent restaurant workflows, not a claim that every
web screen has native parity. Cloud Super Admin management, installer distribution
and application-release publication remain outside the Staff app.

## Server dependency and local installation

The client requires the new admin routes in routes/api.php and
app/Http/Controllers/Api/AdminExperienceController.php. The local installation's
two API files were backed up and updated; no database migration or data reset
was performed. Backup directory:

C:\Program Files\TablePlay\backups\staff-api-1.11.0-20261005-175756

On 16 October 2026, the installed files matched the source and authenticated
requests to auth/me, admin/license, admin/setup and counter/dashboard returned
HTTP 200. The local HTTP health check also returned 200.

This candidate is not uploaded to GitHub, Render or any public release channel.
Existing published downloads and the previously built installer are unchanged.
Windows Authenticode signing is still pending: keep the Windows candidate private.

## Verification

- Laravel: 176 tests, 1,361 assertions passed using the isolated test database.
- Staff Flutter: all 16 tests passed; analyzer reports no issues.
- Desktop navigation and phone-sized layouts are covered by widget tests.
- Tests cover menu deletion Cancel, receipt history/totals, validation, duplicate
  action prevention, expired QR display, rejected-session recovery, API role
  protection, single-use pairing, trial quota and private-key non-disclosure.
- Physical Android tablet pairing and a real multi-device order/bill still need
  manual acceptance; no clean-VM test is claimed.

## Try the Windows candidate

Both new artifacts passed the real server package inspector. The Android APK's
package identity, version 1.11.0/build 2017 and existing production signing
certificate were verified. The Windows ZIP contains the complete runtime,
updater and compiled Dart application; it is not Authenticode-signed.

Artifact SHA-256 checksums (also stored beside each package):

- Windows ZIP: e1ffdcb696accfed16fa4f870b08dec0e8bc63c566a6d4272392eb47f916df41
- Android APK: 64e4df6e2bc74d39b2e1d0747aa45124980f256827e5ff1aea68ec6bd0b9b96a

1. Close the old Staff app, but leave TablePlay Server running.
2. Extract the entire dist/TablePlay-Staff-Windows-x64-v1.11.0.zip archive.
3. Run tableplay_staff.exe from the extracted directory; keep its DLLs/data folder.
4. Use the existing login. On the server laptop use
   http://127.0.0.1:8000/api/v1; phones/tablets must use the restaurant LAN address.
5. Check Setup guide, Licence and Orders & billing, then test menu Delete/Cancel.

The Android candidate is dist/TablePlay-Staff-Android-arm64-v1.11.0-release.apk.
Do not uninstall an existing Android app to upgrade: install the compatible
production-signed APK over it so its saved server/login can be retained.
