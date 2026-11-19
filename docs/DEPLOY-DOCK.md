# Deploying on a dockN host (plan, not deployed)

Runbook for running this branch on one of the fleet's `dockN` LXC Docker
hosts, following the house pattern (wiki `servers/nuc-fleet.md` § "Docker
hosts (dockN)"): publish on the dock's mesh IP only, keep secrets in
`/opt/<app>/.env` on the dock, put Authelia in front, and let the nightly
`nuc-nightly` vzdump to yellow cover the LXC. Nothing here contains secrets
or personal locations.

The stack (validated locally on 2026-11-18 with exactly this layout) is
`docker/compose.dock.yml`:

- `app`: image built from `docker/Dockerfile` (serversideup/php 8.4
  fpm-nginx, runs as `www-data`, listens on 8080). On start it runs
  `storage:link`, `migrate --force` and the Laravel caches (AUTORUN).
- `db`: MariaDB 11, internal only.
- Volumes: `db` (database) and `uploads` (postcard/stamp photos,
  `storage/app/public`). `./backups` is a bind mount for nightly SQL dumps.
- Every required value uses `${VAR:?}`, so compose refuses to start when
  `DOCK_BIND_IP`, `PCT_HTTP_PORT`, `APP_KEY`, `APP_URL`, `APP_BASE_CURRENCY` or
  `DB_PASSWORD` is missing. An unset `DOCK_BIND_IP` would otherwise publish
  on `0.0.0.0`.

## 0. Placement

Dock placement is coordinated by the `docker` peer session. Agree on a dock
and a free host port (wiki `reference/ports.md`) with it first. The app plus
MariaDB needs a few hundred MiB of RAM and very little CPU.

## 1. Lay out `/opt/postcrossing-tracker` on the dock

```text
/opt/postcrossing-tracker/
  compose.yml   # copy of src/docker/compose.dock.yml
  .env          # from src/docker/env.example, chmod 600, root-owned
  src/          # source tree of this branch (build context)
  backups/      # nightly SQL dumps
```

The branch is not on any remote, so ship it as an archive. Copy the file
with scp and extract it in a second step, because piping through
`ssh … sudo` can truncate stdin:

```bash
# on dev
git -C ~/github/avic/postcrossing-tracker archive --format=tar.gz -o /tmp/pct-src.tar.gz usd-localization
scp -P 29221 /tmp/pct-src.tar.gz claudeai@<dock-mesh-ip>:/tmp/
# on the dock
sudo mkdir -p /opt/postcrossing-tracker/src /opt/postcrossing-tracker/backups
sudo tar -xzf /tmp/pct-src.tar.gz -C /opt/postcrossing-tracker/src && rm /tmp/pct-src.tar.gz
sudo cp /opt/postcrossing-tracker/src/docker/compose.dock.yml /opt/postcrossing-tracker/compose.yml
sudo install -m 600 /opt/postcrossing-tracker/src/docker/env.example /opt/postcrossing-tracker/.env
```

**opt-snapshot:** create the dock's `.gitignore.extra` (excluding `.env`,
`src/` and `backups/`) **before** the first opt-snapshot init. The invidious
standup leaked its `.env` this way; see `ServerOps/docker/deployed/invidious/README.md`.

## 2. Fill in `.env`

- `DOCK_BIND_IP` = the dock's mesh IP; `PCT_HTTP_PORT` = the agreed port.
- `DB_PASSWORD`: `openssl rand -base64 24`.
- `APP_URL`: the public https URL. `APP_BASE_CURRENCY`, `APP_TIMEZONE`,
  `APP_DATE_FORMAT`, `OWNER_*`.
- `HOME_LAT` / `HOME_LNG`: the owner's home (the default "sent from"
  place). Private: set them only in this file.
- Mail (`OWNER_EMAIL`, `MAIL_*`) can wait. Arrival alerts are skipped,
  with a logged warning, until `OWNER_EMAIL` and `MAIL_FROM_ADDRESS` are set.
- `APP_KEY`: `echo "base64:$(openssl rand -base64 32)"` (the same format
  `php artisan key:generate` produces, 32 random bytes for AES-256-CBC).
  Generate it here: compose refuses to build or run while it is empty.

Put `APP_KEY` and both database passwords in your secret store too.
**Losing `APP_KEY` makes every encrypted contact address, phone number and
coordinate unreadable**, and the vzdump only helps if the key survives as
well.

## 3. Build, key, start

```bash
cd /opt/postcrossing-tracker
sudo docker compose build app        # several minutes on a J4005
sudo docker compose up -d
sudo docker compose ps               # app must show <dock-mesh-ip>:<port>->8080, healthy
sudo docker logs postcrossing-tracker | grep -E 'migrat|DONE'
```

One-time setup:

```bash
sudo docker compose exec app php artisan db:seed --force   # 200 countries
sudo docker compose exec -e HOME=/tmp app php artisan tinker
# > App\Models\User::create(['username' => '...', 'password' => '...']);
```

(Run tinker interactively so the password stays out of shell history.)

Check from dev: `curl -s -o /dev/null -w '%{http_code}' http://<dock-mesh-ip>:<port>/up` → `200`.

## 4. Expose through gateway + Authelia

Use the standup script on dev, dry run first:

```bash
sudo bash ~/github/avic/ServerOps/scripts/ops/new-docker-standup.sh \
  --name <name> --target-host <dockN> --local-port <port> \
  --description "Postcard tracker" --icon "fas fa-envelope" \
  --health-path /up --dashy-section "<section>" --dry-run
```

At ServerOps `8ff6cca5` (read 2026-11-18), `--access public` with a dock
target creates the DNS record → gateway and a Traefik route with Authelia
**pointing straight at `<dock-mesh-ip>:<port>`**. For non-dev targets it
creates no socat relay; `--relay-port` is ignored. The dock apps moved off
dev in October still use `socat-relay@<app>` on dev. Either path works;
the script is what decides.

**Authelia and recipients:** the QR label on each card sends the recipient
to `/receive/<uid>`, which has no login. With Authelia in front of
everything, recipients cannot confirm arrival (they can still register the
card on postcrossing.com). Start fully gated. If you want self-hosted
arrival confirmations later, add Authelia `bypass` rules for that host and
these paths only: `^/receive/`, the Livewire endpoints (`^/livewire-[^/]+/`),
and static assets (`^/build/`, `^/vendor/`, `^/images/`, `^/fonts/`,
`^/js/`, `^/storage/`, `^/favicon.ico$`). The app's own login still guards
everything else. Before opening anything, fix login hardening: the
reCAPTCHA token is never verified server-side and login is not rate-limited.
`/gallery` and `/` are public by design. Their maps show only rounded
coordinates, but decide whether you want them public at all.

## 5. Backups

The nightly vzdump of the LXC (00:30–02:15 window) contains the named
volumes, `/opt/postcrossing-tracker/.env` and `backups/`. A vzdump of a
running MariaDB is only crash-consistent, so also take a logical dump
before the window. A root cron entry or systemd timer on the dock at about
00:05 is enough:

```bash
cd /opt/postcrossing-tracker && docker compose exec -T db sh -c \
  'mariadb-dump --single-transaction --routines -u postcards -p"$MARIADB_PASSWORD" postcards | gzip > /backups/postcards-$(date +%F).sql.gz' \
  && find /opt/postcrossing-tracker/backups -name 'postcards-*.sql.gz' -mtime +14 -delete
```

Restore: `zcat postcards-YYYY-MM-DD.sql.gz | docker compose exec -T db sh -c 'mariadb -u postcards -p"$MARIADB_PASSWORD" postcards'`,
then restore the `uploads` volume from the vzdump. Both need the same `APP_KEY`.

## 6. Upgrades

Take a dump first, ship a new archive into `src/`, run
`sudo docker compose build app && sudo docker compose up -d`. Migrations run
on start. Do not roll back the 2026-11-18 currency migration after entering
data: it drops `base_currency` and rounds exchange rates to 2 decimals.

## 7. Monitoring

- Uptime Kuma: the standup script adds a monitor; point it at `/up`.
- cAdvisor and node_exporter on the dock pick the containers up
  automatically.
- Laravel logs go to stderr (`LOG_CHANNEL=stderr`), so `docker logs postcrossing-tracker`.
- Verify an alert path by delivery, not unit state: confirm one arrival
  once mail is configured.
