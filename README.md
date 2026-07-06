# ⛰️ Alpine Hut Finder — last-minute beds in Austria

Find Austrian Alpine huts with **free beds in the next two weeks**, sorted by
distance from you, on a map and in a list. Search a place or use your location,
pick a night, and click straight through to book.

Live: **https://huts.aydinhassan.co.uk**

Laravel 13 API + a Vue 3 / **shadcn-vue** front end, deployed to a self-hosted
server (`imp`) with auto-deploy on merge to `main`.

## Where the data comes from

Two booking platforms expose **real per-date free-bed counts** and are the only
sources with queryable availability for Austrian huts:

| Source (`huts.source`) | What | Endpoint |
| --- | --- | --- |
| `hrs` | Alpenverein / SAC Hut Reservation Service — DAV, ÖAV, AVS, SAC huts | `hut-reservation.org/api/v1/reservation` |
| `huetten-holiday` | ÖTK, private Schutzhütten, opted-out AV sections (e.g. Fischerhütte) | `huetten-holiday.com` |

Together ≈ **275 Austrian huts** with live availability. (Most of Austria's
~1,500 huts — Naturfreunde, many private — have *no* queryable availability at
all; they book by phone/email only.)

## How availability checking works (and no, it doesn't hammer the APIs)

The golden rule: **the upstream APIs are never called when someone loads the
page.** Page loads only read our own database. All fetching happens on a
schedule, off to the side, and is cached.

**Two scheduled jobs** (`routes/console.php`, run by Laravel's scheduler via a
one-line cron on the server):

| Job | Cadence | What it does |
| --- | --- | --- |
| `huts:sync --availability` | **hourly** | refresh free-bed counts for every stored hut |
| `huts:sync --catalog` | **weekly** (Sun 03:00) | re-discover huts + metadata |

**Three layers keep it gentle:**

1. **Poll-and-cache** — availability lives in the `hut_availabilities` table.
   The website serves that; it does not touch the upstream. A page view = 0
   upstream requests.
2. **On-disk response cache** (`storage/app/{hrs,hh}-cache`): hut metadata is
   cached **30 days**, availability **30 minutes**. So re-runs, deploys and
   local experiments reuse saved responses instead of re-fetching.
3. **Per-request throttle** — every *live* upstream call is followed by a
   ~150 ms pause, and each request sends an identifying `User-Agent`.

**Roughly what that means in requests:**

- **Hourly availability sync:** ~275 huts → ~300 requests, spaced ~150 ms
  apart, so the whole run takes ~1 minute at ≈5 req/s. Cache hits (anything
  refreshed within the last 30 min) make no request at all.
- **Weekly catalogue sync:** hut metadata is cached 30 days, so most weekly runs
  are **entirely cache hits (zero upstream requests)**. A full re-probe of the
  id space (~700 lightweight `hutInfo` calls, throttled) happens only about
  **once a month**, when the metadata cache ages out.
- **Deploys:** do **not** sync — data lives in the DB and persists across
  releases, so a deploy makes no upstream calls.

You can run any of it by hand:

```bash
php artisan huts:sync                      # both phases, all sources
php artisan huts:sync --availability       # just free beds (what cron runs hourly)
php artisan huts:sync --catalog            # just the hut list
php artisan huts:sync --source=hrs         # only one source
```

> **Note on the sources.** The `hrs` API is undocumented/unofficial and no ToS
> explicitly permits automated polling. The design above (cache, throttle,
> schedule, never-on-page-view) is deliberately a good citizen; keep it that way
> if you fork/deploy it.

## Architecture

```
schedule ─▶ huts:sync ─▶ HutSource (per platform) ─▶ huts + hut_availabilities (DB)
                                                             │
GET /  ─▶ HutFinderController ─▶ Blade shell + JSON payload ─▶ Vue/shadcn app
GET /geocode ─▶ cached Nominatim proxy (search + reverse, for the location box)
```

Adding a data source = implement `App\Sources\HutSource` and add one line to
`config/huts.php`. The shared base class (`AbstractHutSource`) handles the
Austria border test, hut upserts, and the free-beds clamp.

| Path | What |
| --- | --- |
| `app/Sources/*` | the source abstraction + `HrsSource`, `HuettenHolidaySource` |
| `app/Services/*` | the raw HTTP clients (with caching + throttle) |
| `app/Console/Commands/SyncHuts.php` | the `huts:sync` command |
| `app/Http/Controllers/HutFinderController.php` | builds the page payload |
| `app/Http/Controllers/GeocodeController.php` | cached geocoding proxy |
| `resources/js/**` | the Vue 3 / shadcn-vue front end |

## Run it locally

Requires **PHP 8.3+**, Composer, and **Node 22+**.

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate

php artisan huts:sync            # populate the cache (first run ~a few min)

npm run build                    # or `npm run dev` for HMR
php artisan serve                # → http://localhost:8000
```

Keep it fresh with cron: `* * * * * cd /path && php artisan schedule:run`.

## Deploy

Push to `main` → GitHub Actions runs the test suite, builds the Vue assets, and
deploys to `imp` via Deployer over Tailscale. See `deploy.php` and
`.github/workflows/`.

## License

MIT
