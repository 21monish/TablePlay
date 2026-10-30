# Restaurant pilot verification — 30 October 2026

## Status: permission regression fixed locally; all automated suites pass

Verification initially stopped at a failing permission boundary. After user approval, the identity guard was fixed and the focused and full suites were rerun successfully. This is not an all-buttons-pass or production-ready certification.

The tested story is customer pairing and visit → menu order → counter confirmation → kitchen preparing/ready → waiter service → bill → cash payment → table reset → a clean next visit.

## Evidence

| Check | Result |
| --- | --- |
| Full Laravel suite after the fix and new pilot tests | 244 passed, 2,061 assertions |
| Customer tablet analysis | No issues found |
| Full customer-tablet suite rerun | 39 tests passed |
| Staff app analysis | No issues found |
| Full Staff app suite rerun | 43 tests passed |
| Web confirmation JavaScript tests | 8 passed |
| New isolated pilot suite | All 7 passed as part of the full suite |
| Focused pilot and privileged-verification tests | 11 passed, 217 assertions |
| Read-only LAN `/up` check | HTTP 200; TablePlayServer Running |

All new pilot fixtures use SQLite in-memory tests, test-only signed entitlements, disposable staff identities and a disposable paired tablet. No real order, payment, staff account, table pairing or licence was changed by the tests. The local middleware installation was applied separately using a hash-checked helper and a protected backup; its final status is recorded below. No database migration was part of that installation.

## New scenarios that passed

1. Complete workflow using the waiter Serve endpoint, including a water request's acknowledgement/completion, correct discounted menu pricing, server tax, bill discount, cash change, retained history and a clean next visit. Old orders are not exposed to the next visit; games are locked and pairing is retained.
2. Insufficient cash leaves the bill unpaid and visit in billing; a second payment attempt does not double-charge.
3. Repeated bill generation returns the existing bill without changing its discount or creating a duplicate.
4. Reject requires a reason, does not unlock games and excludes rejected food from the bill.
5. Invalid/skipped/repeated order transitions are rejected without duplicate status logs or game timers.
6. Staff roles and customer devices cannot invoke other roles' operational actions; orders, bills and payments remain unchanged after rejected requests.
7. Device identities are denied on staff account, team, dashboard, kitchen, waiter, help and logout routes with email verification both enabled and disabled. Legitimate device menu/session access still works afterward, proving the rejected staff logout did not revoke the device token.

## Previously failing boundary and correction

Regression: `tests/Feature/RestaurantPilotFlowTest.php`, method `test_staff_roles_and_customer_device_cannot_use_other_roles_operational_buttons`.

Before the fix, sending a valid customer-tablet device token to a staff-only counter route returned HTTP 500 instead of HTTP 403:

```
BadMethodCallException:
Call to undefined method App\Models\Device::requiresEmailVerification()
```

Cause: `app/Http/Middleware/EnsurePrivilegedEmailIsVerified.php` calls staff-user methods on any authenticated identity. Sanctum can authenticate a `Device`, which is not an `App\Models\User`. The failure occurs before the role middleware/controller, not during legitimate ordering or payment.

Implemented fix: explicitly reject authenticated identities that are not `App\Models\User` before invoking staff email-verification methods. The response is HTTP 403 with `A staff account is required.` Existing user verification and role requirements remain intact. The formerly failing regression now passes, along with expanded cross-route device tests and the full suites.

## Local installation

- `scripts/local-team-update/apply-device-staff-guard.ps1` completed successfully and reported `DEVICE_STAFF_GUARD_INSTALLED`.
- The helper pins the original middleware, tested source and patch hashes, backs up the original, checks installed PHP syntax, and restarts TablePlay to invalidate old OPcache code.
- Read-only baseline before installation: 6 total accounts, 4 active accounts, 1 order, 1 bill, 1 payment; service Running.
- Post-install checks: installed middleware SHA-256 matches the tested source; LAN health HTTP 200; TablePlayServer Running. Counts remain 6 total accounts, 4 active accounts, 1 order, 1 bill and 1 payment.
- Original middleware backup: `C:\Program Files\TablePlay\backups\device-staff-guard-bc43ff33c19d4e78aed6553d592a5853`.

## Verification limits and remaining work

- Computer-use runtime tools are unavailable, so no live Chrome, Staff-window, Android-device or Safari click-through was performed.
- The new customer-to-payment scenario exercises actual Laravel routing, middleware, services and database behavior in isolation; it is not a physical tablet-to-Windows GUI test.
- Existing customer widget/game tests passed, but every customer/Staff application button has not been exhaustively clicked or newly covered.
- The full Staff suite was rerun after the correction. New visual/native-screen click-through checks remain unperformed because the required runtime tools are not exposed.
- The source-level game-timer billing-message fix from earlier work has not been assumed to be installed; this audit does not claim source tests prove every installed file matches.
- No public deployment, installer publication, APK rebuild, sale, actual cash receipt or licence change was performed.
