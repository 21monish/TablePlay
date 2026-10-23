# Public website launch - 18 October 2026

Target launch: 19 October 2026. Source changes are not a deployment.

Public site: https://tableplay-vercel-site.vercel.app/
Backend/portal: https://tableplay-api.onrender.com/
Public WhatsApp: +91 9313623141. Support email: monishkanojiya5@gmail.com.
The owner selected the existing Vercel address, not a purchased custom domain.

## Features and scope

Shared dependency-free public utilities: dark/light preference, optional-analytics
consent with essential-only choice and re-open settings, section search, back to
top, scroll progress, copy-link feedback, print styles, keyboard focus, reduced
motion, WhatsApp links. Existing portal menu, sticky header and animations are
retained; Vercel gets an accessible mobile menu. Existing authenticated web forms
already have password visibility and custom confirmation dialogs.

A separate /contact page uses CSRF, server validation, honeypot, rate limiting,
fixed recipient, a confirmation modal, busy state and truthful server feedback.
It uses the existing configured transactional mail transport, not a client API key.
A provider accepting email does not prove inbox delivery. Before launch send one
owner-approved enquiry and confirm arrival/reply in the support inbox.

## Required owner/account checks - not claimed complete

- Deploy source changes to both hosts, keeping unsigned Windows binaries private.
- Verify HTTPS and redirects on deployed URLs. A padlock is a valid certificate,
  not an icon to add. Do not force HTTPS on offline HTTP/LAN installs.
- Confirm configured mail transport sends real email, not log/array transport.
- Domain email is blocked until a custom domain is owned and a mail provider is
  chosen. Configure MX, SPF, DKIM and DMARC; do not invent DNS records.
- Supply a GA4 G- measurement ID. Set Vercel website-analytics meta and Render
  TABLEPLAY_ANALYTICS_ID. The ID is public, never an OAuth/client secret. Update
  the static CSP when configuring GA and verify in GA Realtime after consent.
  No GA account/property was created or connected by these changes.
- Verify the Vercel URL in Search Console and submit /sitemap.xml.
  A sitemap does not itself submit the site or guarantee indexing.
- Refresh WhatsApp preview caches after deployment and verify the absolute OG
  PNG URL responds 200 to an unauthenticated crawler. Old previews may persist.
- Run real Safari/iPhone checks on Apple hardware; Chromium emulation is not Safari.
- No customer-facing online payment checkout is configured. Test only in a
  provider sandbox after selecting/integrating one; do not make a real charge.
- Set a backup schedule and retention for the actual cloud database/storage,
  verify permissions and restore a backup to an isolated target. Source changes
  do not turn on provider backups. Never reset the live restaurant database.
- Confirm contact recipient, privacy wording, commercial promises and final copy.

## Release evidence

Record actual tests and remaining gaps in WEBSITE_LAUNCH_RESULTS.md. Search is
local to the public page, not a search of private restaurant orders or accounts.
UTM is an allowlist of five campaign parameters forwarded only after consent;
unknown query strings and credentials are not forwarded as analytics URLs.
No account setup, payment processing or backup activation should be described as
complete until verified. Zero defects cannot be guaranteed by automated checks.
