# TablePlay interface and configuration

## Staff workspace

The Admin, Counter, and Kitchen interfaces share one responsive design system. The sidebar collapses into a mobile drawer, tables turn into labelled mobile cards, and the saved light/dark theme follows each browser. Reverb connection state is visible at the bottom of the navigation.

Admin navigation is intentionally split into focused pages:

- Overview: live restaurant snapshot and shortcuts.
- Tables & devices: table setup and tablet pairing.
- Menu: category, item, availability, price, and preparation management.
- Team: role-based staff accounts and access status.
- Games: catalog and activation controls.
- Reports: sales metrics and audit history.
- Restaurant settings: identity, brand, receipt, tax, currency, timezone, kitchen refresh, tablet-offline threshold, and game duration.
- System health: database, cache, storage, queue, Reverb, runtime, and device diagnostics.

Counter and Kitchen screens are optimized for live operation, large status indicators, short actions, instructions, preparation timers, service calls, game timers, billing, and receipts.

### Safe table removal

Choose **Manage** beside a table in **Admin → Tables & devices** to edit its display name, capacity, status, or active state.

- An unused table with no visits can be permanently deleted.
- A table with historical visits is archived instead of deleted, preserving orders, bills, and reports.
- A table with an open or billing visit cannot be disabled, archived, or deleted until that visit is closed.
- Disabling, archiving, or deleting a table automatically removes its active tablet pairing.
- Every update, archive, and deletion is written to the audit history.

## TablePlay Guide chatbot

Every authenticated staff screen includes a floating **Need help?** button. TablePlay Guide runs entirely on the local Laravel server and does not require internet or send restaurant data to an external AI provider.

The guide understands questions about tablet pairing, tables, menu items, staff accounts, settings, health checks, reports, incoming orders, rejection, service requests, billing, receipts, game timers, kitchen workflow, customer ordering, and hotspot troubleshooting. Answers include short instructions, numbered steps, suggested follow-up questions, and a direct link when the user has permission to open the relevant screen.

Guidance is role-aware:

- Admin receives configuration and all operational guidance.
- Counter receives order, guest-service, billing, receipt, and game-timer guidance.
- Kitchen receives ticket, instruction, preparation-status, timer, sound, and fullscreen guidance.
- Restricted Admin links are never returned to Counter or Kitchen accounts.

Supported topics and wording live in `app/Services/HelpAssistantService.php`. Add a topic there when a new workflow is introduced, and include the route only when every role listed for the topic can access it.

## Brand assets

- Primary vector mark: `public/brand/tableplay-mark.svg`
- Browser icon: `public/favicon.svg`
- Web components: `resources/views/components/brand.blade.php` and `resources/views/components/icon.blade.php`
- Tablet lockup: `tablet_app/lib/widgets/tableplay_brand.dart`

The restaurant accent color can be changed in **Admin → Restaurant settings** without rebuilding the Laravel frontend.

## Operational notes

- Configuration changes are stored in `restaurant_settings` and audited.
- The selected restaurant timezone is applied to the Laravel process on each request.
- The health JSON endpoint is authenticated and admin-only at `/admin/system/health`.
- Health checks are diagnostics, not a substitute for the physical pilot checklist in `deployment/PILOT_RUNBOOK.md`.
- Debug Android builds allow local cleartext HTTP for hotspot testing. Release cleartext and device-owner kiosk mode remain disabled until explicitly approved for pilot hardware.
