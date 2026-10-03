# TablePlay API

All endpoints are under `/api/v1`. Staff endpoints use Laravel Sanctum bearer tokens. Tablet endpoints use the bearer token returned by device registration plus the `X-Device-UUID` header.

The implemented route inventory is available with `php artisan route:list --path=api/v1`. Order totals and item names/prices are snapshotted by the server. Confirmation restarts game access for the configured duration; cash payment closes the table and stops all game access.

The customer app uses `GET /api/v1/table/snapshot` for its live session, orders, and game-access state in one request. The Admin staff client uses section endpoints under `/api/v1/admin` for dashboard, tables, team, catalog, reports, settings, and system health, plus role-protected create/update/pair/archive actions matching the Laravel website.

Application update discovery and delivery use:

```text
GET /api/v1/app-updates/check
GET /api/v1/app-updates/download/{target}
```

Supported targets are `staff-android`, `customer-android`, and `staff-windows`. The check endpoint records installation UUID, device label, app/platform, version/build, IP address, signed-in staff or paired device when available, last-check time, and whether an update is available. Download responses include SHA-256 and version headers.
