# Super Admin visibility and monthly financial summaries

## Scope

- `/superadmin/overview`: cloud account counts, restaurant counts, active installation records, subscription receipts/refunds/net cash by currency, outstanding invoice balances and owner-reported restaurant financial summaries.
- `/superadmin/users`: paginated, searchable, read-only directory of cloud users, roles, email verification, deleted/disabled status and linked restaurant accounts. Credentials are not selected for this directory. Local restaurant staff and customer devices are not uploaded.
- `/superadmin/restaurants`: paginated restaurant directory with owner contacts, latest subscription, monthly TablePlay net cash and restaurant-reported net sales, expenses and profit/loss. Existing customer billing is linked separately.
- `/account/financial-reports`: verified restaurant owners can optionally share and correct their own monthly totals. Explicit sharing confirmation, server-derived ownership, a unique restaurant/month constraint, transaction locking, revision conflict protection and audit history apply.

The existing plans/licences and customer-billing workflows remain available from separate Super Admin navigation items. No restaurant access, subscription, licence, invoice or payment is changed by reading the new directories.

## Financial definitions and limits

- TablePlay cash receipts include completed and subsequently refunded payments using their payment dates. Refund outflows use their refund dates. Net cash is receipts minus refunds for the chosen month; current-month cash stops at the current time.
- Invoice currency is used; currencies are never combined. Unallocated payments have no persisted currency and are displayed separately as unassigned rather than guessed from current billing settings.
- Outstanding invoices show remaining balances after completed payments, clamp overpayments to zero and exclude draft/void invoices. The balance is current across all dates, not a period revenue figure.
- Restaurant reported profit/loss equals reported net sales minus reported expenses. Missing reports are labelled not reported, not zero. No food sales, orders, customer records or local staff credentials are automatically synchronized.
- Restaurant summaries are manual and owner-reported, not audited financial statements. Incomplete expense reporting makes reported profit incomplete. Platform net profit is not fabricated: TablePlay platform expenses are not currently recorded.

## Verification

- Final full Laravel SQLite regression suite: 255 passed, 2,167 assertions after the migration indexes and privacy-date adjustments. Existing JavaScript confirmation tests: 8 passed.
- Isolated PostgreSQL 18: all 11 new feature tests passed, 106 assertions, including authorization, literal/case-insensitive searches, pagination, latest-plan selection, refund timing, partial payments, multiple currencies, negative profit, owner isolation, consent, precision validation, stale submissions and audit history.
- PostgreSQL inspection confirmed RLS enabled on the new table, and SELECT/INSERT for `anon` and SELECT/UPDATE for `authenticated` denied. No client policies or public grants were added. The trusted Laravel backend uses its database role, not Supabase user metadata, for access.
- The table has a unique restaurant/month index, a month index, a submitter index, and supporting payment-date/refund-date/invoice-status indexes.
- Production Vite build succeeded. No new JavaScript, dependencies or native app changes were required.
- The disposable PostgreSQL cluster was separate from installed services and production databases. It used test-only fixtures. No live customer financial records were created or changed.
- The temporary PostgreSQL process was stopped and its port closed. Existing PostgreSQL 13 and 18 Windows services remained running. Automated deletion of the generated test cluster was blocked by execution policy; the stopped test files remain at `C:\Users\monis\AppData\Local\Temp\tableplay-finance-qa-c941b054bb284cd78ae070905eddf0f5`.

## Deployment and remaining checks

- Source changes and a Laravel migration only; not pushed or deployed by this task. Use the existing Laravel migration workflow on deployment. Do not run the Supabase CLI migration workflow against these Laravel-owned tables.
- No live Supabase advisor scan was run because the new schema is not deployed there. Recheck table permissions/advisors after deployment.
- Live browser/mobile click-through was not performed because the computer/browser runtime is unavailable. Server-rendered pages and authorization were tested through actual Laravel routes.
- The Windows installer remains private and unchanged.
