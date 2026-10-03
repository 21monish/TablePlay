# TablePlay Customer App

The `tablet_app` Flutter client is the paired guest experience for table ordering, order tracking, table service, and games.

## Responsive navigation

- Phones and compact tablets show a hamburger button in the top-left corner.
- Wide tablets keep the navigation visible as a persistent sidebar.
- The four destinations are Menu, My Orders, Game Lounge, and Table Service.
- Navigation shows live cart quantity, order count, game lock state, table identity, guest count, visit state, and restaurant-network health.
- The cart has a persistent sidebar summary and remains available from the top bar on compact devices.
- Pairing settings are separated from everyday guest actions and require an in-app confirmation before the device is reset.
- The prior bottom navigation bar has been removed so navigation behavior is consistent on phones and tablets.

The navigation upgrade does not change table pairing, API authentication, game unlocking, timer enforcement, or planned Android kiosk restrictions.

## Release 1.12 interface upgrade

- A persistent session strip shows the paired table, guest count, active orders,
  cart quantity, restaurant-network state, and the server-owned game timer.
- Pairing uses a guided three-step layout with QR/security context and practical
  help for timeout or local-network problems.
- Menu results show active-filter counts, larger food photography, category and
  offer badges, preparation time, calories, spice level, and accessible labels.
- Game Lounge can be filtered by All, 1 player, 2 players, or 2-4 players, with
  live game counts for each mode.
- Cards, buttons, forms, dialogs, notifications, spacing, and typography now use
  the same TablePlay design language as the Staff app.

## Performance and games

- Foreground refresh now uses one consolidated table snapshot instead of three sequential requests.
- Both apps reuse network connections, prevent overlapping polls, and pause polling while inactive.
- The Game Lounge includes TablePlay Snake with swipe and on-screen controls, progressive speed, local best score, and server-authorized score recording.
- Dinosaur Dash is a native Flutter runner with touch, Space, and Arrow Up controls; frame-rate-independent gravity; randomized cactus/bird obstacles; progressive speed; collision detection; local best score; and server-authorized result recording.
- Balloon Ascent is an original TablePlay endless-flight game with smooth drag/arrow controls, generated safe paths, spike gates, +10 bubbles, progressive speed, local best score, and server-authorized result recording.
- Magnet and Shield power-ups last 15 seconds; Slow lasts 7 seconds. The game pauses with the application lifecycle and remains inside the server-controlled table-access guard.
- The production catalog contains five one-player, four two-player, and four four-player games.
- Every production game slug now opens its dedicated game screen.

## Local updates

The customer app checks the Laravel server at startup and from **Check for app updates** in the sidebar. It downloads the APK on the private restaurant network, verifies its SHA-256 checksum, and then opens Android's installer. Device identity, table pairing, and local preferences remain intact for signed-to-signed updates. See `docs/LOCAL_APP_UPDATES.md` for release signing and the one-time debug-to-release migration note.

## Signed Android release

The current publishable version is 1.12.1/build 2015:

`dist/TablePlay-Customer-Android-v1.12.1-release.apk`

SHA-256: `8CC02ED5024FA39FD034CEFB94A7C92E3F2CDE571E3E81CA290BF64E9EF701DB`

Signing certificate SHA-256: `4ADBA0C171BB6A0EC83246D28A2C37A911EFE4EE9921D097D3825D7E9C061A0E`

The APK includes ARM, ARM64, and x64 Android native libraries. Install it over
the previous production-signed Customer app so table pairing and local settings
remain intact. Do not uninstall first.
