# TablePlay cloud deployment

TablePlay uses three managed layers:

- **Vercel** serves the public marketing site from `vercel-site/`.
- **Render** runs the Laravel web application, scheduler, and Reverb service from `render.yaml`.
- **Supabase** provides PostgreSQL and two Storage buckets.

## Production data policy

`php artisan db:seed --force` creates only system roles, the built-in game catalog, commercial plan definitions from migrations, and the Super Admin supplied through protected environment variables. It does not create a restaurant, tables, menu items, sample orders, payments, or default staff accounts.

Never place passwords, database URLs, SMTP passwords, S3 secrets, signing keys, or service-role keys in Git.

## Supabase

1. Create a project in `ap-south-1` for the lowest latency from India.
2. Use the PostgreSQL session-pooler URL on port 5432 as Render's `DB_URL` and keep `DB_SSLMODE=require`.
3. Create a public bucket named `tableplay-media`.
4. Create a private bucket named `tableplay-updates`.
5. Create project S3 access keys and set the Render secrets beginning with `SUPABASE_S3_`.
6. Set `TABLEPLAY_MEDIA_URL` to the public object URL for the media bucket, ending in `/tableplay-media`.

## Render secrets

The Blueprint asks for these values during its first deployment:

- `APP_KEY`: output of `php artisan key:generate --show`
- `DB_URL`: Supabase PostgreSQL session-pooler URL
- `TABLEPLAY_SUPERADMIN_EMAIL` and `TABLEPLAY_SUPERADMIN_PASSWORD`
- SMTP host, port, username, password, and from address
- Supabase S3 endpoint, access key, secret key, and public media URL
- Matching Reverb app ID, key, and secret for both services

Email verification is mandatory for Admin and Super Admin in the cloud configuration. Counter, Kitchen, and Waiter PIN login remains available.

## Deploy order

1. Create Supabase and its two buckets.
2. Push the verified release commit to GitHub.
3. Create a Render Blueprint from the repository's `render.yaml` and enter every secret.
4. Wait for `/up` on the Laravel service to return HTTP 200.
5. Confirm the Reverb service is running and update the Render host values if Render assigned a different name.
6. Deploy `vercel-site/` as the Vercel project root.
7. Verify the Super Admin login, email-verification message, signed verification link, Storage uploads, app-package download, and real-time updates.

The free Render tier is suitable for evaluation but can sleep while idle. A paid always-on Render instance is recommended before selling TablePlay to restaurants.
