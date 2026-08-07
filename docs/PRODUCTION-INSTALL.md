# Production install guide — Weather Risk Map

A step-by-step deployment onto a live LibreNMS server, including the part that
is easy to miss: **surviving `daily.sh`**.

Paths below assume LibreNMS at `/opt/librenms`. Adjust if yours differs.

---

## 0. Before you start

### Three rules that cause most failures

> [!IMPORTANT]
> **1. Everything runs as the `librenms` user.**
> LibreNMS refuses `artisan` as anyone else. Worse, running it as `root` or as
> yourself leaves cache files the web server cannot write, and that surfaces
> later as an unrelated-looking HTTP 500. Start every session with:
>
> ```sh
> sudo su - librenms
> ```

> [!IMPORTANT]
> **2. There is usually no `composer` on PATH.**
> Use LibreNMS's own wrapper, which falls back to a bundled `composer.phar`:
>
> ```sh
> php /opt/librenms/scripts/composer_wrapper.php <args>
> ```

> [!IMPORTANT]
> **3. A LibreNMS core update will uninstall this plugin** unless you complete
> [Step 6](#6-make-it-survive-dailysh). This is not optional hardening — the
> widget silently disappears and the only symptom is *"Problem with backend"*.

### Prerequisites

| Requirement | Check |
| --- | --- |
| LibreNMS 24.x+ with the package plugin system | `./lnms plugin:add --help` |
| PHP 8.2+ | `php -v` |
| Outbound HTTPS to `api.met.no` | `curl -sI https://api.met.no/weatherapi/locationforecast/2.0/status \| head -1` |
| A contact email for the MET Norway User-Agent | required, see [Step 4](#4-configure) |
| Devices with GPS coordinates | see [Step 5](#5-verify) — without these the map is empty |

### Take a backup

```sh
sudo cp /opt/librenms/composer.json /opt/librenms/composer.json.bak
```

```sh
sudo cp /opt/librenms/.env /opt/librenms/.env.bak
```

The plugin creates **no database tables** and modifies **no LibreNMS core
files**, so a rollback is just removing the package ([Step 9](#9-rollback)).

---

## 1. Get the code onto the server

Place it **beside** the LibreNMS directory, not inside it — installing into
`/opt/librenms` would put it inside LibreNMS's git tree, where updates would
clobber it.

```sh
sudo git clone https://github.com/Drakelid/weather-risk-map-widget.git /opt/weather-risk-map-widget
```

Pin to a release rather than tracking a moving branch:

```sh
cd /opt/weather-risk-map-widget
```

```sh
sudo git checkout v1.0.0
```

> If you have not tagged a release, `main` works, but you then have no control
> over what a `git pull` brings in. Tag before deploying to production.

Ownership — without this, git refuses with *"detected dubious ownership"* and
pulls fail silently:

```sh
sudo chown -R librenms:librenms /opt/weather-risk-map-widget
```

---

## 2. Switch to the librenms user

```sh
sudo su - librenms
```

```sh
cd /opt/librenms
```

Everything from here runs as `librenms`.

---

## 3. Register and install

Tell Composer where the package is:

```sh
php scripts/composer_wrapper.php config repositories.weather-risk-map '{"type":"path","url":"../weather-risk-map-widget","options":{"symlink":true}}'
```

Install it:

```sh
./lnms plugin:add drakelid/librenms-weather-risk-map-widget @dev
```

Expect `Symlinking from ../weather-risk-map-widget` and
`drakelid/librenms-weather-risk-map-widget … DONE` in the discovery list.

Publish the config so your settings survive package updates:

```sh
php artisan vendor:publish --tag=weather-risk-map-config
```

That writes `/opt/librenms/config/weather-risk-map.php`.

---

## 4. Configure

### Required: MET Norway identification

MET Norway returns **403** to any request whose `User-Agent` lacks an
application name and a contact email. Add to `/opt/librenms/.env`:

```dotenv
WEATHER_RISK_MAP_USER_AGENT="YourISP-LibreNMS/1.0 noc@yourisp.no"
```

Use a mailbox someone actually reads. MET's terms are explicit: *"If we cannot
contact you in case of problems, you risk being blocked without warning."*

### Recommended: working error logging

LibreNMS's default log stack references a `flare` driver that a
`composer install --no-dev` removes. When that happens **errors are not written
at all**, which makes any future problem far harder to diagnose. Add:

```dotenv
LOG_CHANNEL=single
```

```dotenv
LOG_LEVEL=warning
```

`LOG_LEVEL=warning` matters — at debug level the SNMP poller floods the log.

### Optional: infrastructure sensitivity

Edit `/opt/librenms/config/weather-risk-map.php` to weight hazards per device
group. Find your group ids:

```sh
./lnms tinker --execute="App\Models\DeviceGroup::pluck('name','id')->dump();"
```

Then uncomment and populate the `sensitivity.profiles` block. See §6 of the
main README.

Apply the configuration:

```sh
php artisan optimize:clear
```

---

## 5. Verify

Run the built-in health check:

```sh
php artisan weather-risk-map:diagnose
```

A healthy result looks like:

```
Configuration
  Forecast provider : met-norway
  Warning provider  : met-norway
  User-Agent        : YourISP-LibreNMS/1.0 noc@yourisp.no

Widget registration
  Route      : OK (ajax/dash/weather-risk-map)
  Prefix     : OK ("ajax/dash" - matches listWidgets())
  Add Widget : "Weather Risk Map"

Device coordinates
  Devices total            : 412
  Devices with a location  : 388
  Distinct valid locations : 96
  Skipped (no/bad coords)  : 24

Request volume
  Radius                   : 10 km
  Geo cells                : 31
  Forecast requests/refresh: 31 (budget 80)
  Warning requests/refresh : 1 (all warnings, matched locally)

Provider connectivity
  Forecast  : OK (85 points, 0 min old, sample 59.4138,5.2680)
  Warnings  : OK (4 active, 4 with polygons)
```

### If "Distinct valid locations" is 0

The map will be empty. Coordinates come from LibreNMS's `locations` table and
this plugin never stores its own. Fix by any of:

- Append `[lat, lng]` to a device's `sysLocation`, e.g. `Haugesund POP [59.4138, 5.2680]`
- Edit under **Devices → Locations**, or drag the pin on the map
- Enable geocoding under **Settings → External → Geolocation**

### If "Geo cells" approaches the budget

Raise the widget's **Weather risk radius** to merge nearby sites, or increase
`max_cells_per_refresh` in the config. Each cell is one upstream request per
cache period, so this is your API traffic.

### Confirm the route

This is the single best health check — a missing route renders as LibreNMS's
generic *"Problem with backend"*:

```sh
php artisan route:list --path=ajax/dash | grep weather
```

---

## 6. Make it survive `daily.sh`

> [!CAUTION]
> **Do not skip this step.** Without it, your next nightly update removes the
> plugin.

### Why it happens

`daily.sh` runs, in order:

```sh
git checkout --quiet -- composer.json composer.lock          # line 291
PLUGINS=$(call_daily_php "composer_get_plugins")             # line 352
FORCE=1 ${COMPOSER} require --update-no-dev --no-install $PLUGINS
${COMPOSER} install --no-dev
```

`composer.json` is tracked in LibreNMS's git, so the checkout **destroys the
path repository** you added in Step 3. LibreNMS then re-requires the plugin by
name from `composer.plugins.json` — but `composer_get_plugins` reads only that
file's `require` block and ignores any `repositories` you add beside it. With no
repository, Composer cannot resolve the package and drops it.

Pick **one** of the following.

### Option A — publish the package (best)

If `drakelid/librenms-weather-risk-map-widget` resolves from Packagist, `daily.sh`'s
`composer require` works untouched and no repository entry is needed anywhere.
Submit the GitHub URL at [packagist.org](https://packagist.org), then install
with plain `./lnms plugin:add drakelid/librenms-weather-risk-map-widget` and skip
Step 3's `config repositories` command entirely.

This is what LibreNMS's plugin system is designed around. A path repository is a
development arrangement.

### Option B — self-healing guard (no publishing required)

Idempotent and cheap: it exits immediately when the plugin is present, so it is
safe to run frequently.

```sh
sudo tee /usr/local/bin/wxrisk-ensure >/dev/null <<'EOF'
#!/bin/sh
# Reinstalls the Weather Risk Map widget if a LibreNMS core update removed it.
# daily.sh reverts composer.json, which destroys the path repository.
set -e
LNMS=/opt/librenms
PKG=/opt/weather-risk-map-widget
LOG=/var/log/wxrisk-ensure.log

cd "$LNMS" || exit 1

# Present and loadable? Nothing to do.
[ -d "$LNMS/vendor/drakelid/librenms-weather-risk-map-widget" ] && exit 0

echo "$(date -Is) plugin missing, reinstalling" >> "$LOG"

php scripts/composer_wrapper.php config repositories.weather-risk-map \
  "{\"type\":\"path\",\"url\":\"$PKG\",\"options\":{\"symlink\":true}}" >> "$LOG" 2>&1

./lnms plugin:add drakelid/librenms-weather-risk-map-widget @dev >> "$LOG" 2>&1
php artisan optimize:clear >> "$LOG" 2>&1

echo "$(date -Is) reinstall complete" >> "$LOG"
EOF
```

```sh
sudo chmod +x /usr/local/bin/wxrisk-ensure
```

```sh
sudo touch /var/log/wxrisk-ensure.log
```

```sh
sudo chown librenms:librenms /var/log/wxrisk-ensure.log
```

Schedule it every 15 minutes, so a nightly removal is repaired quickly:

```sh
sudo crontab -u librenms -l | { cat; echo "*/15 * * * * /usr/local/bin/wxrisk-ensure >/dev/null 2>&1"; } | sudo crontab -u librenms -
```

Confirm it is installed and no-ops correctly:

```sh
sudo -u librenms /usr/local/bin/wxrisk-ensure && echo "guard OK (no action needed)"
```

> [!WARNING]
> Do **not** test the recovery path by deliberately uninstalling on production.
> Verify it on a staging box, where you can run the `daily.sh` sequence in
> anger. On production, trust the no-op check above.

### Option C — take control of updates

Arguably correct for a NOC system regardless. In your LibreNMS config:

```php
$config['update'] = 0;
```

You then update deliberately, and re-run Step 3 as part of that procedure.

### What does *not* work

Defining the repository in Composer's **global** config
(`COMPOSER_HOME/config.json`) does not survive, and adding a `repositories`
block to `composer.plugins.json` has no effect — LibreNMS reads only the
`require` block from that file. Both were tested and failed.

---

## 7. Add the widget

**Overview → your dashboard → edit (pencil) → Add Widget → Weather Risk Map**

Then open its settings (pencil on the widget) and set at minimum:

| Setting | Guidance |
| --- | --- |
| **Device groups** | Empty = all devices. Scope it if the map is crowded. |
| **Risk period** | 24 hours suits most NOC use. |
| **Weather risk radius** | 10 km default. Larger merges sites and cuts API calls. |
| **Refresh** | 600 s. Below the 900 s forecast cache TTL gains nothing. |
| **Default map mode** | *Auto-fit devices*, or *Fixed position* with your region's lat/lng/zoom. |

Size it at least **6 columns × 5 rows**. It adapts below that, but the map gets
cramped.

---

## 8. Ongoing maintenance

### Updating the plugin

```sh
sudo su - librenms
```

```sh
cd /opt/weather-risk-map-widget
```

```sh
git fetch --tags && git checkout v1.1.0
```

```sh
cd /opt/librenms && php artisan optimize:clear
```

Then **hard-refresh the browser** (`Ctrl+Shift+R`). The widget's CSS and
JavaScript are inlined into the Blade output, so a cached page keeps running the
old script.

No Composer step is needed — the package is symlinked, so a checkout is live
immediately.

### Optional: pre-warm the cache

Only worthwhile for a permanently displayed NOC dashboard. It is deliberately
not scheduled by default, so an installation that never opens the widget
generates no API traffic.

```sh
sudo crontab -u librenms -l | { cat; echo "*/10 * * * * /usr/bin/php /opt/librenms/artisan weather-risk-map:warm --radius=10 --quiet-success"; } | sudo crontab -u librenms -
```

Match `--radius` to your widget setting, or you will warm the wrong cells.

### Health monitoring

`weather-risk-map:diagnose` exits non-zero on failure, so it can drive a check:

```sh
php /opt/librenms/artisan weather-risk-map:diagnose >/dev/null 2>&1 || echo "weather risk map degraded"
```

---

## 9. Rollback

No database changes were made, so removal is clean.

```sh
sudo su - librenms
```

```sh
cd /opt/librenms
```

```sh
./lnms plugin:remove drakelid/librenms-weather-risk-map-widget
```

```sh
php scripts/composer_wrapper.php config --unset repositories.weather-risk-map
```

```sh
php artisan optimize:clear
```

Remove the guard if you installed Option B:

```sh
sudo crontab -u librenms -l | grep -v wxrisk-ensure | sudo crontab -u librenms -
```

```sh
sudo rm -f /usr/local/bin/wxrisk-ensure
```

Widgets already on dashboards will show as unavailable; delete them from the
dashboard editor. Your published `config/weather-risk-map.php` and the `.env`
lines are harmless if left behind.

---

## 10. Troubleshooting quick reference

| Symptom | Cause | Fix |
| --- | --- | --- |
| **"Problem with backend"** in the widget tile | Usually the package is not installed, so the route 404s | `php artisan route:list --path=ajax/dash \| grep weather` — if absent, re-run Step 3 |
| Widget vanished overnight | `daily.sh` reverted `composer.json` | Complete [Step 6](#6-make-it-survive-dailysh) |
| Widget missing from **Add Widget** | Route cache stale | `php artisan optimize:clear` |
| *"artisan must be run as the user librenms"* | Wrong user | `sudo su - librenms`. If caches were written with wrong ownership: `sudo chown -R librenms:librenms /opt/librenms/storage /opt/librenms/bootstrap/cache` |
| *"detected dubious ownership"* | Package directory owned by someone else | `sudo chown -R librenms:librenms /opt/weather-risk-map-widget` |
| Nothing in `laravel.log` | Broken `flare` driver in the default log stack | Set `LOG_CHANNEL=single`, then `php artisan config:clear` |
| `403` from MET Norway | Placeholder User-Agent, or >4-decimal coordinates | Set `WEATHER_RISK_MAP_USER_AGENT` with a real contact email |
| `429` from MET Norway | Too many geo cells, or TTL too short | Increase the widget radius, raise `cache.forecast_ttl`, or lower `max_cells_per_refresh` |
| Empty map | No lat/lng on locations | `weather-risk-map:diagnose`, then see [Step 5](#5-verify) |
| Warnings never appear | Devices outside Norway, or `providers.warnings` set to a provider without warnings | MetAlerts covers Norway only; set `providers.warnings` to `met-norway` |
| Stale after a config edit | Config cached | `php artisan config:clear` |

Logs: `/opt/librenms/storage/logs/weather-risk-map.log` (dedicated channel), and
`/var/log/wxrisk-ensure.log` if you installed the guard.

---

## Appendix — complete command sequence

For a server at `/opt/librenms` with the package at `/opt/weather-risk-map-widget`,
using the Option B guard:

```sh
# --- as root -------------------------------------------------------------
sudo git clone https://github.com/Drakelid/weather-risk-map-widget.git /opt/weather-risk-map-widget
sudo chown -R librenms:librenms /opt/weather-risk-map-widget
sudo cp /opt/librenms/composer.json /opt/librenms/composer.json.bak

# --- as librenms ---------------------------------------------------------
sudo su - librenms
cd /opt/librenms
php scripts/composer_wrapper.php config repositories.weather-risk-map '{"type":"path","url":"../weather-risk-map-widget","options":{"symlink":true}}'
./lnms plugin:add drakelid/librenms-weather-risk-map-widget @dev
php artisan vendor:publish --tag=weather-risk-map-config

# edit /opt/librenms/.env, add:
#   WEATHER_RISK_MAP_USER_AGENT="YourISP-LibreNMS/1.0 noc@yourisp.no"
#   LOG_CHANNEL=single
#   LOG_LEVEL=warning

php artisan optimize:clear
php artisan weather-risk-map:diagnose
php artisan route:list --path=ajax/dash | grep weather
exit

# --- as root: the persistence guard --------------------------------------
# (create /usr/local/bin/wxrisk-ensure per Step 6, Option B)
sudo chmod +x /usr/local/bin/wxrisk-ensure
sudo touch /var/log/wxrisk-ensure.log
sudo chown librenms:librenms /var/log/wxrisk-ensure.log
sudo crontab -u librenms -l | { cat; echo "*/15 * * * * /usr/local/bin/wxrisk-ensure >/dev/null 2>&1"; } | sudo crontab -u librenms -
sudo -u librenms /usr/local/bin/wxrisk-ensure && echo "guard OK"
```
