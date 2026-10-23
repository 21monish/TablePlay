# TablePlay 2.5.7: clean Windows acceptance

Status: private local release candidate. The existing laptop upgrade, protected
backup restore/comparison, licence, login, LAN/realtime and download checks passed
on 5 October 2026. Clean-VM testing was skipped at the user's request; clean-PC
compatibility remains unverified. Windows Authenticode signing is pending, and
the user requested that the build remain private until signed.

The unchecked items below are a future clean-system/manual acceptance checklist,
not a claim that physical-device or clean-VM testing was completed.

## Test environment

Use a new Windows 10/11 x64 VM with no TablePlay, XAMPP, PHP, MariaDB or .NET
installed. Take a clean snapshot before installation. Do not erase or reset an
existing restaurant VM. Use test-only accounts and orders, not real customer data.

Installer: `dist/installer/TablePlay-Setup-v2.5.7.exe`.
Verify its SHA-256 against the adjacent `.sha256` file before running it.
An unsigned test build may trigger Windows SmartScreen; this is not evidence
that Windows production signing is complete.

## Fresh installation

- [ ] Start setup, approve UAC, and enter a test restaurant name and administrator
      email/password. Confirm required-field and password validation.
- [ ] Installation completes without separately installing PHP, MariaDB, .NET or
      Visual C++; the bundled Microsoft prerequisite installs successfully.
- [ ] Open the Admin and Server Manager shortcuts. `http://127.0.0.1:8000/up`
      responds successfully, and the private database is not exposed over LAN.
- [ ] Sign in as `admin` with the password entered in setup; Show/Hide works.
      An incorrect password is rejected. Menu/orders contain no demo records.
- [ ] Restart Windows and confirm the service and local application return.

## Licence and tablet connection

- [ ] Activate a legitimate test Free Trial through TablePlay Cloud. Confirm
      signature-valid status, expected expiry/remaining days, and 3-tablet limit.
- [ ] Synchronize again and restart the service: the licence stays valid, with
      no changed signed start/expiry dates and no false successful activation.
- [ ] On a disposable test installation, export an offline `.tpr`, issue a signed
      `.tpl` in Cloud, and import it locally. Check wrong-device/tampered imports
      are rejected without replacing a valid licence.
- [ ] Connect a physical Android tablet/Staff device on the same LAN. Use the
      server's LAN URL, not that device's `127.0.0.1`. Confirm private-network
      firewall access, QR pairing, and realtime online status.
- [ ] Pair 3 customer tablets; a fourth is rejected by the server during trial.

## Ordering, deletion and billing

- [ ] Create a test category/dish/table and role-specific Staff accounts. Install
      Staff 1.10.0 and Customer 1.12.1 from Server Manager's local downloads.
- [ ] Place an order from Customer App; Counter/Kitchen/Waiter receive it, status
      changes arrive live, and the final bill uses the expected totals.
- [ ] Delete the dish from the web Menu page: a TablePlay-branded confirmation
      appears, naming the dish. Cancel/Escape keeps it; confirm removes it once.
- [ ] Repeat with Staff app's Delete button. Non-admin users cannot delete items.
- [ ] Deleted dishes disappear from customer/waiter menus and cannot be ordered
      through the API. Existing orders, bill totals, names and photos remain.
- [ ] Disconnect internet while keeping LAN connected: local ordering, Staff
      updates and bill creation continue within the valid licence period.

## Upgrade, recovery and release gates

- [ ] Make a backup and record licence, users, tables, orders and menu counts.
      Rerun setup for repair/upgrade; those records and uploaded images survive.
- [ ] Test backup/restore on the disposable VM and verify the restored records.
- [ ] Test uninstall only on this disposable VM, checking data-preservation and
      confirmation choices; never use the real restaurant PC for this test.
- [ ] Record Windows version, test date, results and screenshots for any failure.
- [ ] Sign the final Windows release with a protected production code-signing
      certificate and validate the signature. Regenerate checksums after signing.
- [ ] Publish verified artifacts and update public download links only after all
      required gates pass. Do not upload signing keys or private Cloud secrets.
