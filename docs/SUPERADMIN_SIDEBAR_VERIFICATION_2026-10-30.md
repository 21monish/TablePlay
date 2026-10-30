# Super Admin sidebar expansion

## Features

The left navigation is grouped into three accessible sections with 11 destinations:

- Platform: Overview, Users, Restaurants & profits.
- Commercial: Plans & licences, Customer billing, Invoices, Payments, Renewals.
- Operations: Server installations, Offline activation, Activity log.

Five dedicated, paginated read-only pages were added at `/superadmin/invoices`, `/superadmin/payments`, `/superadmin/renewals`, `/superadmin/installations` and `/superadmin/activity`. Search and status filters preserve pagination state. Offline activation links to the existing signed-request workflow, not an automatic licence action.

Only the Super Admin sidebar receives the independently scrollable navigation region. Branding, real-time status and sign-out remain outside the scrolling menu. Existing mobile open/close and theme controls are retained. The current page has an `aria-current` marker; group labels identify each navigation region.

## Safety and financial meaning

- All five pages stay behind authentication, privileged email verification, active-account checks, the Super Admin role and the existing cloud-mode boundary.
- Opening, searching or paging does not renew a subscription, record a payment, transfer a server or issue a licence. Actions link to the existing review workflows.
- Invoice balances deduct completed payments and cannot become negative after overpayment; draft/void balances are not presented as outstanding receivables.
- Payment currency comes from its invoice. Unallocated payments remain unassigned rather than being guessed or merged across currencies.
- Renewals show only the latest subscription per restaurant, with 7/30/90-day, overdue and all-expiry filters. A recorded status is distinguished from whether its expiry date has passed.
- Server activation records show recorded version and last contact, not a fabricated live/online claim. Activation-token hashes and device fingerprints are not selected.
- The activity list selects only actor, action, record type/ID and timestamp. Raw audit payloads, credentials, licence material and IP addresses are not selected or displayed. Deleted actor names remain available through the existing archived-user relationship.

## Verification

- Final full Laravel SQLite regression suite: 263 tests passed, 2,343 assertions, after explicit column selection was tightened.
- The 8 new sidebar/operations tests also passed on isolated PostgreSQL 18, with 176 assertions. The temporary PostgreSQL process was stopped afterward; no production database or installed PostgreSQL service was changed.
- Includes every sidebar destination/current marker, all five pages, guest/other-role/inactive/unverified denials, literal/case-insensitive search, partial payments, refunds, unassigned currency, latest-subscription renewal filtering, secret exclusion, deleted actors, pagination/query bounds and read-only behavior.
- Production Vite build and PHP syntax checks passed.
- No browser/mobile screen click-through was performed; browser/computer runtime tools are unavailable. The tests render actual Laravel pages and inspect their HTML, rather than claiming visual device verification.

## Release status

Local source changes only, not pushed or deployed in this task. The earlier Super Admin overview/reporting changes include a new Laravel migration and must be released with these routes and views. No live database, native app, subscription, licence or Windows installer was changed.
