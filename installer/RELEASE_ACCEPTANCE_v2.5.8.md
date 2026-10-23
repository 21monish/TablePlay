# TablePlay 2.5.8 — PRIVATE, UNSIGNED

Candidate: dist/installer/TablePlay-Setup-v2.5.8.exe
Size: 465565767 bytes
SHA-256: 0495c834e6e90aaffd7bdb1c6c80bdda3c32504cf192eeb968187bd87af1c0e1
Authenticode: NotSigned. Do not publish until signed and acceptance is approved.

Includes Staff Android/Windows 1.11.1+2018 and Customer Android 1.13.1+2017,
account recovery, stable existing superadmin credentials, customer discount pricing,
new-install port selection and saved-port conflict checks.

Verified: backend 198 tests/1483 assertions; Staff 26 tests; Customer 39 tests;
both analyzers clean; native components build without warnings/errors; port helper
tests; seven installer file/trust/PHP smoke checks; isolated payload fresh bootstrap
and in-place upgrade, with temporary MariaDB and storage preservation; embedded
payload ZIP/hash/version validation and absence of development runtime data.

The lifecycle test simulates backend bootstrap/file replacement; it is NOT a clean
Windows/VM install, actual SCM/service installation, XAMPP coexistence or physical
multi-device acceptance test. No live restaurant data or runtime was changed.
No cloud deploy, public release, real mail delivery or payment-provider test occurred.
See docs/BATCH_UPDATE_RESULTS_2026-10-18.md and remaining TABLEPLAY_TODO.txt gates.
