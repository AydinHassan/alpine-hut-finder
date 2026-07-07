# ⛰️ Alpine Hut Finder — last-minute beds in Austria

Find Austrian Alpine huts with **free beds in the next two weeks**, sorted by
distance from you, on a map and in a list. Search a place or use your location,
pick a night, and click straight through to book.

Live: **https://huts.aydinhassan.co.uk**

Laravel 13 API + a Vue 3 / **shadcn-vue** front end, deployed to a self-hosted
server (`imp`) with auto-deploy on merge to `main`.

## Where the data comes from

The hut **catalogue** comes from the official **Alpenverein (Club Arc Alpin) hut
directory** — the same ArcGIS dataset the Alpenverein's own "Bettencheck" map
uses. One query returns every DAV/ÖAV/AVS hut with clean WGS84 coordinates,
club, altitude, phone/email/homepage, and an `ohrs_hut_id` that links to the
online booking system.

**Availability** then comes from two platforms:

| Source (`huts.source`) | Catalogue | Availability |
| --- | --- | --- |
| `alpenverein` | CAA ArcGIS directory (`services1.arcgis.com/.../AVT_GEO_CAA_HUETTEN_View_P`) | huts with an `ohrs_hut_id`: `hut-reservation.org` per-date beds |
| `huetten-holiday` | `huetten-holiday.com` (ÖTK, private, opted-out sections, e.g. Fischerhütte) | `huetten-holiday.com` per-date beds |

Every hut carries a `bookable_online` flag:

- **`true`** — has an online booking system with queryable availability (an
  Alpenverein hut with an `ohrs_hut_id`, or any huetten-holiday hut). ~235 in
  Austria. A booked-out one simply has no free beds — it is *not* shown as
  book-direct.
- **`false`** — **book-direct**: an Alpenverein-directory hut with no online
  system, only phone/email/homepage. ~415 in Austria, shown behind an opt-in
  toggle.

(An earlier version enumerated hut ids by hand and scraped OpenStreetMap for the
book-direct huts — the directory replaces all of that with one clean source.)

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
2. **On-disk response cache** (`storage/app/{caa,hh,hrs}-cache`): the hut
   directory is cached **7 days**, the huetten-holiday list **30 days**, and
   availability **30 minutes**. So re-runs, deploys and local experiments reuse
   saved responses instead of re-fetching.
3. **Per-request throttle** — every *live* availability call is followed by a
   ~150 ms pause, and each request sends an identifying `User-Agent`.

**Roughly what that means in requests:**

- **Catalogue sync** is cheap: **one** ArcGIS query returns the entire
  Alpenverein directory, plus a handful of paginated huetten-holiday calls —
  and both are cached (7 / 30 days), so most weekly runs make no request at all.
- **Hourly availability sync:** ~235 bookable huts → ~235 requests, spaced
  ~150 ms apart (~1 minute total). Cache hits (refreshed within the last 30 min)
  make no request.
- **Deploys:** do **not** sync — data lives in the DB and persists across
  releases, so a deploy makes no upstream calls.

You can run any of it by hand:

```bash
php artisan huts:sync                      # both phases, all sources
php artisan huts:sync --availability       # just free beds (what cron runs hourly)
php artisan huts:sync --catalog            # just the hut list
php artisan huts:sync --source=alpenverein # only one source
```

> **Note on the sources.** The hut-reservation.org availability API is
> undocumented/unofficial, and the ArcGIS directory & huetten-holiday aren't
> published as open APIs either — so no ToS explicitly permits automated
> polling. The design above (cache, throttle, schedule, never-on-page-view) is
> deliberately a good citizen; keep it that way if you fork/deploy it.

## Architecture

```
schedule ─▶ huts:sync ─▶ HutSource (per platform) ─▶ huts + hut_availabilities (DB)
                                                             │
GET /  ─▶ HutFinderController ─▶ Blade shell + JSON payload ─▶ Vue/shadcn app
GET /geocode ─▶ cached Nominatim proxy (search + reverse, for the location box)
```

Adding a data source = implement `App\Sources\HutSource` and add one line to
`config/huts.php`. The shared base class (`AbstractHutSource`) handles the
Austria border test, hut upserts, the free-beds clamp, and proximity dedupe
against other sources.

| Path | What |
| --- | --- |
| `app/Sources/*` | the source abstraction + `AlpenvereinSource`, `HuettenHolidaySource` |
| `app/Services/HutReservationService.php` | availability client for hut-reservation.org |
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
