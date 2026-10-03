# TablePlay local deployment

1. Reserve `192.168.10.10` for the XAMPP server in the private restaurant router.
2. Point Apache's document root or virtual host to `C:/xampp/htdocs/TablePlay/public`.
3. Create a MySQL 8 database named `tableplay`, copy `.env.example` to `.env`, and set `DB_CONNECTION=mysql`, host, port, database, username, and password.
4. Run `php artisan migrate --seed --force` and `php artisan optimize`.
5. Set `BROADCAST_CONNECTION=reverb`, configure the generated `REVERB_*` values, and run `php artisan reverb:start --host=0.0.0.0`.
6. Permit TCP 80/443 and the chosen Reverb port only on the private LAN firewall profile.
7. Keep guest Wi-Fi isolated from the TablePlay subnet. Put the server, counter, and router on a UPS.
8. Configure tablet base URL as `http://192.168.10.10/api/v1`, pair each tablet once, then enable Android lock-task/device-owner mode.

For hotspot testing with `php artisan serve`, use `deployment/start-tableplay-server.ps1` so Admin can upload update packages up to 256 MB. See `docs/LOCAL_APP_UPDATES.md` for signed package publishing and device update behavior.

Seeded local users are `admin`, `counter`, `kitchen`, and `waiter`. Change the initial password `TablePlay@123` immediately.
