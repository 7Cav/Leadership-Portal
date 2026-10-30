# Running the portal with Docker

`docker-compose.yml` at the repo root is a full production stack on one host:

| Service | What it runs |
| --- | --- |
| `caddy` | HTTPS on 80/443, with a Let's Encrypt certificate for `APP_DOMAIN`, proxying to `app` |
| `app` | nginx + PHP-FPM. Runs `migrate --force` and `optimize` on every start |
| `queue` | `queue:work`: the roster, LOA and practice-capture jobs |
| `scheduler` | `schedule:work`: queues the syncs on their cron (see the main README) |
| `mysql` | MySQL 8.4, data in the `mysql` volume |

`app`, `queue` and `scheduler` run the same image, built from the `Dockerfile`.

## First install

Needs Docker Engine with the compose plugin, and a DNS record for the domain pointing at the server.

```bash
git clone https://github.com/7Cav/Leadership-Portal.git portal && cd portal
umask 077
cp .env.production.example .env
```

Fill in `.env`:

- `APP_DOMAIN` and `APP_URL` (`https://` + the domain), and `SEVENCAV_OAUTH_REDIRECT_URI`
  (`APP_URL` + `/auth/callback`). Register that callback with the 7cav.us OAuth client.
- `APP_KEY`: generate one with `docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`.
  Keep it; a new key signs everyone out.
- `DB_PASSWORD` and `DB_ROOT_PASSWORD`: long random strings (`openssl rand -hex 24`).
- The SSO, forum, roster API and game-server keys, as for any install.

Then start it and finish the first-run steps from the main README:

```bash
docker compose up -d --build
docker compose exec app php artisan portal:admin <your forum username>   # after signing in once
docker compose exec app php artisan db:seed --class=CourseCatalogSeeder --force
```

## Updating

```bash
git pull
docker compose up -d --build
```

The rebuilt `app` container migrates and re-caches the config on start, and `queue` and `scheduler`
are recreated on the new image, so no `queue:restart` is needed. After editing only `.env`, run
`docker compose up -d` to recreate the containers with the new values.

## Day to day

```bash
docker compose ps                                   # all five should be up, four of them (healthy)
docker compose logs -f app queue                    # Laravel logs go to stderr
docker compose exec app php artisan schedule:list
mkdir -p ../portal-backups && chmod 700 ../portal-backups
umask 077
docker compose exec mysql sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$MYSQL_DATABASE"' > "../portal-backups/portal-$(date -u +%Y%m%dT%H%M%SZ).sql"
```

Keep backups outside the checkout, encrypt them before off-host storage, and limit access to the backup directory. An older `backup.sql` in the checkout should be moved out before rebuilding the app image.

`docker compose down` keeps the data; `down -v` deletes the database and certificates.
