# TablePlay update batch — 18 October 2026

Implemented and tested locally. No Git push, production deployment, live password
change, VM reset, paid-service change or installed-runtime upgrade was performed.
Analytics remains disabled. Unsigned Windows files must remain private.

## Account security

- Login links to /forgot-password; reset form includes password visibility.
- Laravel's password broker stores hashed, single-use tokens, expiring after
  60 minutes, with resend throttling and IP route limits. Unknown and disabled
  accounts receive the same public result; disabled accounts cannot reset.
- Links use configured APP_URL, not the request Host. Recovery pages send
  no-store, no-referrer and noindex headers. CSRF and server validation apply.
- Passwords require 12 characters, mixed case, digits and symbols, up to 72
  bytes for bcrypt. Passwords and reset tokens are not flashed back to sessions.
- Successful reset rotates the remember token, revokes Sanctum user tokens and
  deletes the user's database-backed browser sessions. This matches TablePlay's
  supported default SESSION_DRIVER=database. Other session drivers need their
  own revocation strategy; assigned staff PINs are not changed by recovery.
- Reset does not grant roles, reactivate an account or mark email verified.
- Existing superadmin credentials, identity, verification and suspension are
  preserved during deployment seeding. Bootstrap secrets create new accounts
  only; editing them is no longer a password-reset mechanism.
- Recovery/contact reject logging/array mail transports, including unsafe
  failover aliases. Sending errors do not disclose raw provider errors or tokens.
- Real cloud email delivery remains an acceptance gate after deployment.

## Native apps

Staff 1.11.1+2018 adds recovery-link guidance/copying from the configured server,
explicit first-run server setup (no guessed credential destination), and visible
background connection failures. Existing saved addresses and sessions persist.

Customer 1.13.1+2017 adds price/name/preparation sorting, narrow-screen menu fixes,
server-provided bill totals/payment status and recent table-request status.
Ordering blocks concurrent submissions. The API now charges the displayed
effective/discount price, ignoring any client-supplied unit price or total.

Both clients make at most one extra GET attempt for transient failures. POST,
PUT, DELETE and uploads are never replayed automatically. A timeout warns that
an action may have been saved: check orders before submitting again. Pairing is
not erased by connection failure. HTML outages produce typed, actionable errors.

## Local ports

New installer source prefers HTTP 18740 and realtime 18741, checks occupied ports
and IPv4/IPv6 binding permissions/reservations, and selects an available candidate
within its bounded range. The database prefers 3310 and changes only if unavailable.
Selected ports are persisted in installation.json and used by generated environment
values, firewall rules, shortcuts, validation and Network Doctor. Database access
remains loopback-only. No fixed port is guaranteed available.

Upgrades read and preserve existing ports. Startup rejects occupied/reserved or
duplicate configured ports; it never stops XAMPP or silently changes addresses.
Saved app addresses are not automatically redirected with credentials. Existing
installation port migration/re-pairing still requires coordinated maintenance.
The running laptop installation was not changed. With-XAMPP operation and physical
multi-device ordering are not claimed tested by a helper-level port test.

## Verification evidence

- Full backend: **198 tests passed, 1,483 assertions**.
- Staff: **26 tests passed**; analyzer: **no issues**.
- Customer: **39 tests passed**; analyzer: **no issues**.
- Installer, ServiceHost and Server Manager compile with zero warnings/errors.
- Port helper tests pass for occupied/excluded/duplicate/invalid ports and safe
  refusal without configuration changes.
- Seven existing installer smoke checks pass: interrupted-install preservation,
  junction-safe copy/delete, upload recovery, Ed25519 trust and PHP HTTPS/extensions.
  These are not a clean-machine install or complete installed-runtime upgrade test.
- Chrome: login → recovery, reset form at 360/390/768/1440px, password visibility,
  no horizontal overflow/JS errors, privacy headers, missing-CSRF 419 and valid
  form mail-disabled feedback. Isolated in-memory database; no email sent.
- Android release builds and Windows Staff build succeeded. Android package IDs
  and certificate SHA-256 match prior releases; Windows is Authenticode NotSigned.
- Android build emits existing Java source/target 8 obsolescence warnings.

## Private app candidates

| File in dist/ | Build | Bytes | SHA-256 |
| --- | --- | ---: | --- |
| TablePlay-Staff-Android-arm64-v1.11.1-release.apk | 2018 | 34696247 | 691ed8ce6898319ca49d42463339f2fe7050d0c1a4c38e44f8560916d868bc9e |
| TablePlay-Customer-Android-v1.13.1-release.apk | 2017 | 34299877 | 309f853492958fb31b0694c9072b4895a4d3ef8e4c00712822dd624c6a0ff336 |
| TablePlay-Staff-Windows-x64-v1.11.1.zip | 2018 | 12124611 | e8f2734e44bef8005e324b20e9b5c55fccfbb265cb53337729806f98e8713d64 |

Android certificate SHA-256:
4adba0c171bb6a0ec83246d28a2c37a911efe4ee9921d097d3825d7e9c061a0e.
Installer **dist/installer/TablePlay-Setup-v2.5.8.exe** was built successfully:
465565767 bytes; SHA-256
0495c834e6e90aaffd7bdb1c6c80bdda3c32504cf192eeb968187bd87af1c0e1.
The embedded ZIP equals the tested payload, the sidecar checksum matches and the
manifest pins all three app versions above. Development .env/database/logs/storage
link are absent. Installer Authenticode status is **NotSigned**; remain private.

The provided isolated payload lifecycle test also passed: fresh migration/seed,
bootstrap, app publication inside the temporary database, upgrade file replacement,
licensing trust, post-upgrade workflows and preserved restaurant storage. Temporary
test files/database were cleaned by the test. This is NOT an actual Windows service
install, clean-machine test or existing laptop runtime upgrade.

The temporary Chrome/PHP verification server was stopped. Installed HTTP 8000,
realtime 8080 and database 3310 listeners retain the same process IDs as before.

## Still required before public launch

- Review/push/deploy the dirty source worktree deliberately; deploy backend before
  Vercel contact links, then verify live recovery/contact delivery and all links.
- Safari/iPhone and physical Staff/Customer ordering, reconnect, pairing and bill tests.
- Authenticode certificate and Windows signing; keep candidates private meanwhile.
- Existing-install port migration and XAMPP coexistence/upgrade acceptance testing.
- Choose an owned domain/mail provider for domain email; Gmail/Vercel stay unchanged.
- Choose an online payment provider and sandbox account; no real charges or claim
  of an online checkout. Customer bill display is not payment processing.
- Cloud backup schedule/retention and an isolated restore test, with approval for
  any paid infrastructure. No backup policy was silently enabled or live DB reset.
- GA4 only when the owner later supplies an ID; Search Console ownership and sitemap.
- Final privacy, support-hours, commercial-offer and public copy approval.

References: Laravel 12 password broker documentation at
https://laravel.com/framework/docs/12.x/passwords. Verification used the available
verification skill's browser → server → data → response approach, separating
mocked-mail tests from actual inbox delivery.
