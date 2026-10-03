# Phase 2 local-network installation checklist

## Hardware

- [ ] Dedicated dual-band router or access point installed.
- [ ] Router, server/counter PC, and network switch connected to a UPS.
- [ ] Server uses Ethernet rather than Wi-Fi where possible.
- [ ] Kitchen screen, counter terminals, and tablets are inventoried and labelled.

## Network

- [ ] Private system SSID and strong WPA2/WPA3 credentials configured.
- [ ] Guest Wi-Fi is isolated from the system VLAN/subnet.
- [ ] Server receives reserved address `192.168.10.10`.
- [ ] DHCP range excludes reserved infrastructure addresses.
- [ ] Tablets can reach HTTP API and Reverb port on the server.
- [ ] Firewall permits Laravel/Apache and Reverb only on the private profile.
- [ ] Internet disconnection does not interrupt ordering, billing, or games.

## Server

- [ ] MySQL 8 uses InnoDB and `utf8mb4`.
- [ ] A database-scoped TablePlay user is used; root is not used by the app.
- [ ] Apache virtual host points to `C:/xampp/htdocs/TablePlay/public`.
- [ ] `APP_URL` and Reverb host use the reserved server IP.
- [ ] Migrations, seeders, queue worker, scheduler, and Reverb start successfully.
- [ ] Daily local backup and restore procedure is tested.

## Device acceptance

- [ ] Each tablet has a unique UUID and one active table pairing.
- [ ] Heartbeats update while connected and recover after Wi-Fi reconnection.
- [ ] Kitchen and counter screens receive live events.
- [ ] Android device-owner/lock-task mode prevents leaving TablePlay.

Record router model, subnet, SSID, server MAC address, reserved IP, device labels, and acceptance date in the restaurant deployment record. Never record Wi-Fi or database passwords in source control.
