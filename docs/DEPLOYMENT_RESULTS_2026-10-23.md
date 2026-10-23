# TablePlay production deployment - 23 October 2026

## Released source

GitHub main received source commit `22bdc7d374d66217ff7f919b3a17372126d97350`
(Improve account recovery, native apps, local ports and website).
The 89 changed files were reviewed; a credential-signature scan had no findings.
Ignored environment files, private keys, installers and app binaries were not pushed.
No release tag or public installer/app release was created.

| Target | Result | Deployment |
| --- | --- | --- |
| https://tableplay-vercel-site.vercel.app/ | Production READY; commit 22bdc7d | dpl_8k5Qz2AxJsd8p2FD8gHq9ySDKYyW |
| https://tableplay-api.onrender.com/ | Live; commit 22bdc7d | dep-dbdnam5g1s2s73c3f97g |
| https://tableplay-realtime.onrender.com/ | Live; commit 22bdc7d | dep-dbdnanpsrm7s73cbg4hg |

Vercel deployed through Git integration. Render did not queue a deployment after
the push: deployment history and events still showed only older commits. One
explicit deployment per Render service was then requested and monitored to live.
No compute plans, billing, environment secrets, DNS or signing keys were changed.
The existing source URLs and accounts were reused.

Render startup applied both new migrations successfully: immutable licence start
dates and menu-item soft deletion. No restaurant data was reset or demo data added.
The existing superadmin is preserved by deployment seeding, not reset from the
bootstrap password environment variable.

## Verification

Before pushing, the full backend suite passed: 198 tests, 1,483 assertions.
Staff passed 26 tests; Customer passed 39. Production Vite build succeeded.
Earlier private-package and isolated installer-lifecycle evidence remains in
BATCH_UPDATE_RESULTS_2026-10-18.md; it is not a physical-device acceptance test.

Live Chrome checks confirmed:

- Vercel HTTPS 200; no horizontal overflow at 320/390/768/1440px.
- Mobile navigation, theme, search, consent-only UTM forwarding, correct approved
  WhatsApp number and disabled GA4; eight public assets returned 200, including
  the social-preview PNG. No browser JavaScript errors were observed.
- Backend /up, /, /login, /forgot-password, /contact, /privacy, /terms and
  /trial/register returned 200. The first cold API health response took about
  40 seconds; that delay must not be mistaken for guaranteed fast startup.
- Login-to-recovery navigation, password visibility, mobile reset form, no-store
  privacy headers and real missing-CSRF rejection (419).
- A public Reverb WebSocket handshake connected from the Render origin. This
  does not prove delivery of authenticated order events to paired hardware.
- Visible same-origin public links and portal anchors were checked, using HEAD
  for linked pages/download routes rather than downloading installers.
- Protected web workspaces redirect guests to login; the versioned admin licence
  API returns 401 to an unauthenticated request. Authenticated licence activation
  and login with the owner's password were not exercised.

One clearly labelled contact enquiry was accepted by the configured mail
transport, and one password-recovery request was submitted for the owner's
registered email. The owner explicitly confirmed **both emails arrived**.
No reset link was read, followed or saved; no password was changed. Completing
a real reset and signing in is still an owner acceptance step. A support reply
was not sent or verified.

Post-deployment Render error-level log checks returned no entries in the tested
window. Routine Apache ServerName notices appeared at startup. Vercel runtime-log
inspection returned 403 Forbidden; no installed Vercel CLI fallback was available.
There are no configured Vercel drains. Do not describe error monitoring as fully
configured or the runtime-log scan as clean.

## Private builds and remaining gates

Staff 1.11.1+2018, Customer 1.13.1+2017 and Setup 2.5.8 remain local private
candidates. Setup Authenticode is NotSigned; no signing certificate was supplied.
The installed restaurant runtime and saved device addresses were not upgraded.

Still required:

- Owner completes a reset if needed, signs in and verifies authenticated licence
  state; do not send passwords, reset links or licence keys in chat.
- Physical Staff/Customer pairing, order -> kitchen -> bill, reconnect and
  upgrade checks, plus XAMPP coexistence and coordinated existing-port migration.
- Windows Authenticode signing and release approval before publishing candidates.
- Safari/iPhone, actual WhatsApp preview cache refresh and support reply checks.
- Owner-selected domain/email, payment sandbox and backup/restore policy.
  No new paid services or real charges were authorized or performed.
- Search Console ownership and sitemap submission. GA4 remains disabled as
  requested; no analytics ID/account was invented.

The documentation-only follow-up commit does not change deployed application
source. Previously published downloads were left unchanged.
