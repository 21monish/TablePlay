# TablePlay Market Release Readiness

Assessment date: 25 August 2026

## Release status

TablePlay v1.4.0 is ready for controlled direct deployment to restaurant-owned
Windows computers and Android tablets on a private local network. The Laravel
workflow, table isolation, role permissions, server-owned game access, app
updates, and signed Android packages pass the automated release gates.

This direct-deployment release is not yet a public Google Play or Microsoft
Store submission. Store packages need a separate distribution profile so the
local restaurant updater can remain available to private installations without
violating public-store rules.

## Verified v1.4.0 packages

| Target | Version | Build | Package |
| --- | --- | ---: | --- |
| Staff Android | 1.4.0 | 6 | `dist/TablePlay-Staff-Android-arm64-v1.4.0-release.apk` |
| Customer Android | 1.4.0 | 5 | `dist/TablePlay-Customer-Android-arm64-v1.4.0-release.apk` |
| Staff Windows x64 | 1.4.0 | 6 | `dist/TablePlay-Staff-Windows-x64-v1.4.0.zip` |

Both Android applications target API 36, use APK Signature Scheme v2, and use
the same protected release certificate. The certificate SHA-256 fingerprint is:

`4ADBA0C171BB6A0EC83246D28A2C37A911EFE4EE9921D097D3825D7E9C061A0E`

## Completed product checks

- 50 Laravel tests pass with 304 assertions.
- Staff and Customer Flutter analysis reports no issues.
- Staff and Customer Flutter tests pass, including Memory Match and Quick Math.
- All 106 Laravel routes register and all Blade views compile.
- Production web assets compile successfully.
- Both APK identities, versions, build numbers, and signatures pass the real
  TablePlay package inspector.
- The Windows ZIP contains the Staff executable, updater, Flutter runtime, and
  required data files.

## Required before Google Play submission

1. Add a Play distribution flavor that removes
   `android.permission.REQUEST_INSTALL_PACKAGES` and disables the local APK
   self-updater. Google Play restricts this permission and does not permit it
   for ordinary application self-updates.
2. Build signed Android App Bundles (`.aab`) for Staff and Customer. Google Play
   requires Android App Bundles for new applications.
3. Keep the private direct-install APK flavor for restaurant kiosk deployments;
   do not replace its signing key or application identity.
4. Complete Play Console privacy policy, Data safety, content rating, app access,
   target audience, screenshots, feature graphics, support URL, and testing
   track declarations.
5. Decide how a public Play build discovers a restaurant server and document
   why local-network HTTP is required. A production TLS/reverse-proxy option is
   recommended for deployments outside a fully controlled private LAN.

Official references:

- Google Play restricted install permission:
  https://support.google.com/googleplay/android-developer/answer/16558241
- Android App Bundle publishing:
  https://developer.android.com/guide/app-bundle
- Google Play target API requirements:
  https://developer.android.com/google/play/requirements/target-sdk

## Required before trusted Windows distribution

1. Obtain a trusted RSA code-signing certificate or configure Microsoft Trusted
   Signing.
2. Authenticode-sign and timestamp the Staff executable, updater, setup,
   service host, server manager, and uninstaller in the protected release
   pipeline.
3. Rebuild the final installer after signing its embedded executables, then sign
   and timestamp the outer setup executable.
4. Test install, repair, update, backup, restore, and uninstall on clean Windows
   10 and Windows 11 machines with Smart App Control and Defender enabled.
5. Publish a privacy policy, licence/EULA, support contact, release notes, and a
   vulnerability-reporting process.

Current Windows binaries are functionally built but are not Authenticode-signed.
Microsoft documents trusted RSA signing as the reliable path for Smart App
Control acceptance:

https://learn.microsoft.com/windows/apps/develop/smart-app-control/code-signing-for-smart-app-control

## Commercial pilot gate

Before the first paid restaurant deployment, complete a two-table pilot using
the exact release artifacts, then verify simultaneous orders, Wi-Fi recovery,
timer restart/extend/stop, every game, billing, printer output, update rollback,
database backup/restore, and kiosk restrictions. Record the device models,
Windows version, Android versions, router, printer, and test results.
