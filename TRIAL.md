# Local trial (USD / US localization branch)

Notes for trialling this fork locally on the `usd-localization` branch. It
is not deployed anywhere. Postcrossing.com has no API, so data comes from
manual entry or CSV import.

## What differs from upstream

- Base currency is configurable (`APP_BASE_CURRENCY`, default `USD`).
  Upstream hardcoded IDR.
- Each card stores the `base_currency` it was recorded in. `kurs_idr` is
  now `exchange_rate` with 10 decimals.
- Timezone (`APP_TIMEZONE`), date format (`APP_DATE_FORMAT`) and the
  location shown to recipients (`OWNER_LOCATION`) are configurable.
- A base-schema migration was added. Upstream never committed its
  create-table migrations, so `php artisan migrate` failed on an empty
  database.
- CSV import accepts English country names and ISO codes, YYYY-MM-DD
  dates, and keeps cents. It rejects invalid dates and missing exchange
  rates instead of storing wrong values.
- The repo now has 24 tests (`php artisan test`). Upstream had none.

## Run it locally with Docker

No PHP, Composer or MySQL is needed on the host. Every container here is
throwaway, so pick your own names and port. Run from the repo root.

```bash
# 1. PHP and JS dependencies, plus the Vite build (the layout uses @vite)
docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD":/app -w /app composer:2 composer install --no-interaction
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp \
  -v "$PWD":/app -w /app node:22-bookworm-slim sh -c 'npm ci && npm run build'

# 2. Database and app container on a private network
docker network create pct-net
docker run -d --name pct-db --network pct-net \
  -e MARIADB_DATABASE=postcards -e MARIADB_USER=pct -e MARIADB_PASSWORD=change-me \
  -e MARIADB_RANDOM_ROOT_PASSWORD=1 mariadb:11
docker run -d --name pct-app --network pct-net -p 127.0.0.1:8080:8000 \
  -v "$PWD":/app -w /app php:8.4-cli sleep infinity
docker exec pct-app docker-php-ext-install pdo_mysql   # official image lacks it

# 3. Configure: cp .env.example .env, then set
#    APP_URL=http://127.0.0.1:8080  DB_HOST=pct-db  DB_DATABASE=postcards
#    DB_USERNAME=pct  DB_PASSWORD=change-me  MAIL_MAILER=log  (+ keys below)
cp .env.example .env

# 4. Key, schema, country list, first user, server
A="docker exec -u $(id -u):$(id -g) -e HOME=/tmp pct-app php artisan"
$A key:generate
$A migrate --force
$A db:seed --force          # 200 countries
$A storage:link
$A tinker --execute 'App\Models\User::create(["username" => "me", "password" => "choose-one"]);'
docker exec -d -u "$(id -u):$(id -g)" -e HOME=/tmp pct-app php artisan serve --host 0.0.0.0 --port 8000
# open http://127.0.0.1:8080/login

# Tear down
docker rm -f pct-app pct-db && docker network rm pct-net
```

Run the tests (SQLite in memory, no database container needed):

```bash
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.4-cli php artisan test
```

## Environment variables

| Variable | Needed? | Notes |
|----------|---------|-------|
| `APP_KEY` | yes | `php artisan key:generate`. **Never change or lose it.** Contact addresses, phone numbers and coordinates are encrypted with it. |
| `APP_URL` | yes | Base URL for links generated outside a web request. The QR arrival label on a card's page encodes the host you are browsing from, so print labels while using the hostname recipients can reach, not `127.0.0.1`. |
| `DB_*` | yes | MySQL/MariaDB. (Stats use `DATEDIFF`, so SQLite only works for tests.) |
| `APP_BASE_CURRENCY` | yes | `USD`. Set before entering data. Changing it later does not convert existing cards. |
| `APP_TIMEZONE` | yes | `America/New_York`. Decides which day an arrival is dated. |
| `APP_DATE_FORMAT` | yes | `m/d/Y`. Used for display and date pickers. |
| `APP_LOCALE` | | `en`. Also sets the home page's default language. |
| `OWNER_NAME`, `OWNER_EMAIL`, `OWNER_USERNAME`, `OWNER_CONTACT_EMAIL` | yes | `OWNER_EMAIL` receives arrival alerts. The others appear on public pages. |
| `OWNER_LOCATION` | recommended | e.g. `"New York, USA"`. Shown to recipients on the arrival page and on the home page. |
| `HOME_LAT`, `HOME_LNG` | recommended | Origin for distance stats. |
| `GOOGLE_API_KEY` | for maps | Maps JavaScript API (maps) plus Places Text Search and Geocoding APIs (address → coordinates). Without it, maps don't render, coordinates are 0,0 and distances are 0. Everything else works. |
| `AMAP_JS_KEY`, `AMAP_WEB_KEY` | optional | Only for visitors in China / Chinese addresses. |
| `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY` | for login | The Sign In button stays disabled until the reCAPTCHA v2 widget passes. For localhost, Google publishes test keys in the [reCAPTCHA FAQ](https://developers.google.com/recaptcha/docs/faq). |
| `MAIL_*` | for arrival alerts | SMTP. When a recipient confirms arrival, an email goes to `OWNER_EMAIL`. Send failures are silently ignored, so test it. Use `MAIL_MAILER=log` while trialling. |
| Exchange rates | no key | [Frankfurter](https://frankfurter.dev) (ECB reference rates, historical, ~30 currencies), falling back to open.er-api.com (latest only, ~160 currencies). Both are free and keyless. The server calls them during CSV import, and the browser calls them in the register/edit forms. |

## CSV import format

Upload at `/import`. The file is **semicolon-separated**, and the first line is a header that gets skipped.

| # | Field | Format |
|---|-------|--------|
| 1 | type | `sent` or `received` |
| 2 | postcard_id | e.g. `US-1234567` (no dash = direct swap) |
| 3 | tgl_kirim (sent date) | `YYYY-MM-DD` (recommended) or `DD/MM/YYYY` |
| 4 | tgl_terima (arrival date) | same, optional |
| 5 | deskripsi | description |
| 6 | nama | contact name |
| 7 | alamat | full address (geocoded if `GOOGLE_API_KEY` is set) |
| 8 | negara | English or Indonesian country name, or ISO code (`Germany` / `Jerman` / `DE`) |
| 9 | telepon | **sent rows only** (11 columns). Received rows omit it (10 columns). |
| 9/10 | biaya_asal | amount, `1.65` or `1,65` (no thousands separators) |
| 10/11 | mata_uang | ISO code. Blank = base currency. Any other currency is converted to the base currency at the rate for the sent date. |

```csv
type;postcard_id;tgl_kirim;tgl_terima;deskripsi;nama;alamat;negara;telepon;biaya_asal;mata_uang
sent;US-1234567;2024-03-15;2024-04-02;Lighthouse card;Anna Schmidt;Hauptstr. 1, 10115 Berlin;Germany;+49 30 1234567;1.65;USD
received;JP-987654;2024-03-15;2024-04-01;Mount Fuji;Taro Yamada;1-1 Chiyoda, Tokyo;Japan;500;JPY
received;DE-55555;10/01/2024;;Castle;Lena Weber;Domplatz 3, Koeln;DE;0,95;EUR
```

This sample imported as $1.65, $3.36 (500 JPY × 0.0067222) and $1.04
(0.95 EUR × 1.0946). The import runs in one transaction, so a bad row
(an invalid date such as US-style `03/15/2024`, or a currency with no
available rate) rejects the whole file and names the row. `DD/MM/YYYY`
is the only slash format accepted, so `01/04/2024` means 1 April. Use
`YYYY-MM-DD` to avoid any ambiguity.

## Before exposing it on the mesh

- **Login protection is weak.** It is single-user Laravel auth with no
  sign-up page. The server only checks that a reCAPTCHA token is
  non-empty and never verifies it with Google (`RECAPTCHA_SECRET_KEY` is
  unused), and login is not rate-limited. Put it behind the mesh/Authelia,
  or add throttling, before anything reachable.
- **Some pages are public by design.** `/` (profile page), `/gallery`
  (your collection) and `/receive/{uid}` (recipients confirm arrival
  without logging in; this writes the arrival date and their message).
  **`/gallery` publishes each card's map marker using the contact's
  decrypted, geocoded coordinates**, which are effectively recipients'
  street addresses. Review that before making it public.
- **Production settings:** `APP_ENV=production`, `APP_DEBUG=false`,
  HTTPS, `php artisan config:cache`.
- **Backups:** the database, `storage/app/public` (uploaded photos and
  stamps) and `.env`/`APP_KEY`. Without the key the encrypted contact
  data is unrecoverable.
- **The home page body is the original author's personal intro**
  (Indonesian/English/Chinese text mentioning Indonesian landscapes, plus
  an "IDN" stamp). Edit `resources/views/livewire/home.blade.php`.
- **`.github/workflows/deploy.yml` is upstream's deploy to their VPS** on
  every push to `main`. It runs `git reset --hard` and `git clean -df` on
  the target. Delete it before this ever lives in a GitHub repo of ours.

## Known upstream issues not addressed here

- Country → currency auto-fill in the register/edit forms calls
  restcountries.com v3.1, which is retired (it returns a deprecation
  error, and the browser also blocks it on CORS). Type the currency code
  yourself.
- The Registry Date picker starts empty even though the server defaults
  to today, so you have to pick a date before the form submits.
