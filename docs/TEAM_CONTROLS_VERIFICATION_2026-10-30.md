# Team controls verification — 30 October 2026

## Scope and outcome

Added matching Edit/Delete controls to the native Staff app, sharing the web page's account validation, deletion safeguards and audit behavior. The private Windows build is **1.11.3+2020**. This report covers Team/account management, not every button on unrelated application pages.

All testing mutations used isolated SQLite fixtures or mocked Flutter HTTP responses. No existing restaurant staff account, order, bill or payment was modified by these tests.

## Automated results

| Check | Result |
| --- | --- |
| Full Laravel suite | 237 tests passed; 1,862 assertions |
| Full Staff Flutter suite | 43 tests passed |
| Web confirmation handlers | 8 Node tests passed |
| Flutter analysis | No issues found |
| Private Windows release build | Successful, 1.11.3+2020 |
| Installed Windows files | All 14 files SHA-256 verified before replacement |

### Button and flow coverage

| Control or flow | Evidence |
| --- | --- |
| Create account | Web create/login tests and native form validation, selected-role and credential payload tests |
| Edit | Read-only web edit page; populated native editor; HTTP PUT payload and refreshed directory tests |
| Save changes | Shared web/API validation; hashed credentials; preserved blank password/PIN; native success and error states |
| Enable / Disable | Web status transitions and login rejection while disabled; native confirmation Cancel/Confirm and round-trip UI state |
| Delete | Shared server soft deletion, token revocation, hidden directory entry and retained financial/audit history |
| Delete confirmation | Named custom dialog; no request before acceptance; exactly one selected action after acceptance |
| Cancel / close / Escape | No deletion on web handler cancellation; native editor/dialog cancellation does not save or delete |
| Show / Hide password and PIN | Native visibility toggles tested in both directions; web controls retained and server-rendered markup checked |
| Remove saved PIN | Explicit native checkbox and shared server clearing behavior |
| Role | Native role selection; current-account role locked; server rejects self-demotion and platform roles |
| Duplicate Save | Save and Cancel disabled while request is in flight; only one save callback |
| Invalid / duplicate data | Field errors without database mutation; native server errors keep entered data in the editor |
| Current account protection | No native self-delete button; disabled self-toggle; server rejects self-delete and self-demotion |
| Narrow and wide layouts | Flutter interaction tests at 360×900 and 1400×1100; no layout exceptions |

Main tests: `tests/Feature/StaffAccountManagementTest.php`, `tests/Feature/StaffAccountApiTest.php`, `tests/JavaScript/team-confirmation.test.mjs`, `staff_app/test/staff_account_editor_test.dart`, `staff_app/test/staff_team_actions_test.dart`.

The JavaScript tests exercise the actual application handlers against an event/DOM fixture. Flutter tests use mocked HTTP. Neither is a real Chrome/Safari/device screen test.

## Local installation checks

- Applied the four-file API update with pinned hashes and protected backups; installer reported `STAFF_API_UPDATE_INSTALLED`.
- Recompiled routes/views and restarted `TablePlayServer` because installed PHP disables OPcache timestamp checks.
- Confirmed installed Staff API POST/PUT/DELETE/toggle route mappings require Sanctum authentication, privileged-email checks and administrator role.
- LAN health check at `http://10.122.125.253:8000/up`: HTTP 200; service Running.
- Reopened Staff process with installed version 1.11.3+2020.
- Read-only post-install counts: 6 total accounts, 4 active, 1 order, 1 bill, 1 payment. No business-data migration was run by this API update.

Recovery copies:

- Previous native build: `C:\Users\monis\Downloads\staff-windows-before-team-edit-676763d8e6bb4b62b47e24030ed946d6`.
- API originals: `C:\Program Files\TablePlay\backups\staff-api-update-2b69d309c49d446cb666cdfdc9382d77`.

## Remaining verification limits

Computer-use runtime tools were unavailable in this session. Actual authenticated clicks, screenshots, focus behavior and appearance in the user's open Chrome and Staff windows have **not** been verified. A live unauthenticated write-method probe was blocked by execution policy and was not retried; installed route mappings and isolated API tests provide the recorded endpoint evidence instead.

Do not interpret automated success as a guarantee that every application button is perfect. No Render/Vercel deployment, public release, Android APK rebuild or Windows installer publication was performed. The Windows build remains private, consistent with the requirement to keep unsigned releases private.
