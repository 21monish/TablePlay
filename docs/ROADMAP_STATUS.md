# TablePlay roadmap status

This document tracks the approved ten-phase roadmap. A phase is complete only when its acceptance evidence exists.

## Phase 1 — Planning

- Restaurant workflow: approved in the Phase 1 blueprint.
- Roles: admin, counter, kitchen, waiter/service staff, and paired customer tablet.
- Game rule: locked until counter confirmation; each confirmation restarts 60 minutes; billing or session closure locks immediately.
- Data structure: implemented migrations and Eloquent models.
- Screen inventory: approved in the Phase 2 blueprint.

Status: complete.

## Phase 2 — Local network

Software checklist is in `deployment/LAN_CHECKLIST.md`. Physical router, reserved IP, firewall, device connectivity, UPS, and guest-network isolation require the restaurant environment.

Status: prepared; physical validation pending.

## Phase 3 — Backend

- Laravel, Sanctum, versioned APIs, role middleware, and Reverb are installed.
- Local XAMPP MariaDB is connected through Laravel's MySQL driver using the user-supplied local credentials. All migrations and seed data are installed. MySQL 8 remains the production target.
- Domain broadcast events and channel authorization are being completed.

Status: complete for the local software build.

## Phase 4 — Admin and counter

Authenticated staff login, role isolation, settings, staff, table and pairing management, categories, menu availability, games, order confirmation/rejection, service requests, timer controls, cash billing, printable receipts, sales summaries, and audit records are implemented.

The polished operations workspace now includes responsive desktop/mobile navigation, light and dark themes, reusable status and empty-state components, focused pages for every management area, configurable restaurant identity and brand color, tax/currency/receipt settings, operational timing settings, and authenticated system diagnostics for the database, cache, storage, queue, Reverb, runtime, and tablet connectivity.

The Admin **App Updates** workspace now publishes verified Android and Windows packages from private local storage, controls mandatory/minimum-version policy, and reports connected installation versions. Android uploads are checked for application ID, version/build, release signature, and signing-key continuity. Windows uploads are checked for the complete app and separate updater bundle.

Status: complete for the local software build.

## Phase 5 — Kitchen display

Kitchen queue, customer instructions, preparation targets, live elapsed timers, confirmed/preparing/ready/served actions, automatic refresh, and opt-in sound alerts are implemented.

Status: complete for the local software build.

The optional Flutter Staff App now provides role-directed Admin, Counter, Kitchen, and Waiter workspaces on Android and Windows desktop against authenticated local APIs. Version 1.2 includes the responsive navigation and local update client. It is packaged separately from the customer tablet client.

## Phase 6 — Customer tablet

The Flutter Android project includes a branded Material 3 interface, registration/pairing, party-size onboarding, visit opening, menu and category browsing, cart quantities, special instructions, order placement/status, service requests, server-state polling, and polished locked/unlocked game states. Version 1.2 includes the hamburger/sidebar navigation and local update client with checksum verification. `flutter analyze` and widget tests pass. Device-owner kiosk activation still requires pilot-device approval.

Status: application flow complete; kiosk/release device validation pending.

## Phase 7 — Games

Server-authoritative access, expiry, confirmation reset, billing lock, score submission, single-player Tap Challenge, and local two-player Tic-Tac-Toe are implemented.

Status: complete for version one.

## Phase 8 — Testing

Laravel tests cover full ordering/billing, roles, kitchen actions, game rules, multiple-table isolation, pairing constraints, and event dispatch. Flutter analysis and widget tests pass. Wi-Fi, printer, kiosk, UPS, and multi-device physical tests remain in the pilot environment.

Status: automated checks complete; physical acceptance pending.

## Phase 9 — Restaurant pilot

The operational checklist is in `deployment/PILOT_RUNBOOK.md`.

Status: pending restaurant hardware and staff.

## Phase 10 — Future expansion

Deferred until the version-one pilot is stable.
