# Phase 8 testing and Phase 9 pilot runbook

## Automated and bench testing

- Run `php artisan test` and `flutter test`; no failures may enter the pilot.
- Run `flutter analyze`; no analyzer issues may enter the pilot.
- Simulate orders from at least three paired devices and verify table isolation.
- Disconnect each tablet from Wi-Fi during menu browsing, order status, and game play; reconnect and confirm server state is restored without duplicate orders.
- Confirm kitchen sound, preparation timer, order instructions, and every status transition.
- Confirm rejected orders never unlock games and a new confirmed order restarts the full configured duration.
- Confirm bill payment immediately closes the session, locks games, calculates change, and prints the receipt correctly on the selected printer.
- Restart the server and one tablet during an active game session; confirm the server expiry remains authoritative.
- Perform a database backup and restore before pilot day.

## Two-to-three-table pilot

1. Label pilot tablets and pair them to physical tables in Admin.
2. Train staff using one complete test visit: order, confirm, prepare, serve, service request, bill, cash, receipt.
3. Assign one staff member as incident recorder; capture time, table, action, expected result, actual result, and recovery.
4. Run real customers only after the private network, UPS, backups, printer, and device recovery process pass.
5. Review feedback daily. Classify issues as safety/data loss, workflow blocker, usability, or enhancement.
6. Expand only after at least three consecutive pilot shifts without unresolved blockers or financial discrepancies.

## Go-live evidence

Store router configuration backup, device inventory, test results, staff sign-off, restore test date, printer model, and known limitations outside source control. Never store production passwords in this repository.
