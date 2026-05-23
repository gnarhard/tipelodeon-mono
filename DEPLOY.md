# Production deployment — Laravel Forge

End-to-end deploy of the Tipelodeon backend on a **Laravel Forge**–managed
DigitalOcean droplet (2 vCPU / 2 GB / 25 GB SSD). Forge handles the
boilerplate (Nginx, PHP-FPM, MySQL, Redis, certbot, supervisord);
this guide covers the Tipelodeon-specific bits and the spots where
Forge's defaults need tuning for our import pipeline.

Forge conventions used below — **assuming Isolated Sites + Zero-downtime
deploys are enabled** (Forge's modern default):

- Each site has its own UNIX user (e.g. `tipelodeon-k7m2x9q4`), not `forge`
- Site root: `/home/<isolated-user>/<domain>/`
- The live release lives at `/home/<isolated-user>/<domain>/current/`
  — a symlink that Forge swaps after a successful deploy
- Nginx, PHP-FPM, MySQL, Redis are managed via Forge's UI

Replace `tipelodeon.com` with your actual domain and
`<isolated-user>` with the username Forge generated for your site
(visible at **Site → Meta**, or via `ps aux` on a running worker).

---

## 1. Create the server in Forge

1. **Connect a provider** (Forge → Account → Connected Accounts → DigitalOcean).
2. **Servers → Create Server → DigitalOcean**:
   - Type: **App Server**
   - Region: closest to your audience
   - Size: **2 GB / 2 vCPU** (or larger)
   - PHP version: **8.5** (or the latest Forge offers)
   - Database: **MySQL 8**
3. After provisioning (≈5 min), Forge gives you the sudo password and
   server SSH key. Save both.

Forge installs by default: Nginx, PHP-FPM 8.5 with most common extensions
(`imagick`, `redis`, `mysql`, `curl`, `mbstring`, `xml`, `zip`, `bcmath`,
`gd`, `intl`), Composer, Node.js, ImageMagick, Ghostscript, Git, UFW,
fail2ban, supervisord.

What Forge does NOT install — we add manually in Step 3.

---

## 2. Swap (2 GB)

Forge usually doesn't provision swap. Without it, an Imagick spike
during a chart render will OOM-kill a worker.

**First check whether the server already has swap:**

```bash
ssh forge@<server-ip>   # the `forge` SSH user is always available, even
                        # when sites are isolated
swapon --show
free -h | grep Swap
```

- **`Swap: 2.0Gi`** (or larger): skip the rest of this step.
- **Empty output** or **smaller than 2 GB**: create / resize:

```bash
# Resize path: tear down the existing swapfile first.
sudo swapoff /swapfile 2>/dev/null || true
sudo rm -f /swapfile

sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile

# /etc/fstab line — only add if it isn't already there.
grep -q '^/swapfile ' /etc/fstab || \
  echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# Swappiness — only add if not already set.
sudo sysctl vm.swappiness=10
grep -q '^vm.swappiness=' /etc/sysctl.conf || \
  echo 'vm.swappiness=10' | sudo tee -a /etc/sysctl.conf
```

**Verify:** `free -h` shows `Swap: 2.0Gi`.

**Common errors:**

- `fallocate failed: Text file busy` / `mkswap: /swapfile is mounted` /
  `swapon: Device or resource busy` — swap already exists. Run the
  check at the top of this section; if you see 2 GB you're done.

---

## 3. Install Poppler + patch ImageMagick

`pdftotext` enables the **OCR Haiku fast-path**. Without it that path
silently no-ops — still works, just misses a perf win.

```bash
sudo apt-get update
sudo apt-get install -y poppler-utils
pdftotext -v 2>&1 | head -1   # → pdftotext version 22.x.x
```

Some Ubuntu images ship an ImageMagick policy that blocks PDF reading,
which breaks chart renders. Forge's current image is permissive, but
older images and some hosts aren't — check whether the restriction is
present and patch only if it is:

```bash
grep -i pdf /etc/ImageMagick-6/policy.xml
```

- **No output** OR **`rights="read|write"`** → permissive. Skip the rest.
- **`rights="none"`** with PDF → patch it:

```bash
sudo sed -i 's|<policy domain="coder" rights="none" pattern="PDF" />|<policy domain="coder" rights="read\|write" pattern="PDF" />|' /etc/ImageMagick-6/policy.xml
```

The authoritative end-to-end check is via PHP-imagick — Forge servers
typically don't have the `convert` CLI installed even when the PHP
extension is. Run from the site directory:

```bash
php8.5 -r '
$pdf = "/tmp/test.pdf";
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000099 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n148\n%%EOF\n");
try {
    $im = new Imagick();
    $im->setResolution(150, 150);
    $im->readImage($pdf);
    $im->setImageFormat("png");
    $im->writeImage("/tmp/test.png");
    echo "OK: ", $im->getImageWidth(), "x", $im->getImageHeight(), "\n";
    $im->clear();
} catch (Throwable $e) {
    echo "FAILED: ", $e->getMessage(), "\n";
}
'
```

Expect `OK: <width>x<height>`. Any `attempt to perform an operation
not allowed by the security policy 'PDF'` means the policy still
blocks — re-run the `sed` and re-test.

---

## 4. Tune PHP, Redis, MySQL

### PHP-FPM (Forge → Server → PHP → 8.5)

Forge has **two PHP config screens that look similar** — make sure
you're editing the right one for each setting:

| Forge screen | What it edits | What you set there |
|---|---|---|
| **Edit FPM Configuration** | The FPM pool config (`pm.*`) | child process count + lifecycle |
| **Edit FPM PHP Configuration** | The per-request `php.ini` for web | `memory_limit`, `upload_max_filesize`, etc. |
| **Edit CLI PHP Configuration** | The same `php.ini` but for `artisan` / queue workers | same settings as FPM PHP — edit both |

**FPM pool config** (`Edit FPM Configuration`):

```ini
pm = dynamic
pm.max_children = 3
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 2
pm.max_requests = 500
```

> **With Isolated Sites + multiple sites sharing one box**, each site
> has its OWN pool config at **Site → Files → Edit PHP-FPM
> Configuration**. Set `pm.max_children=3` on each site so combined
> PHP-FPM stays under ~720 MB.

**FPM PHP Configuration AND CLI PHP Configuration** (edit both — the
CLI ini drives `artisan` + queue workers):

```ini
memory_limit = 256M
max_memory_limit = 256M       ; Forge-custom ceiling — match memory_limit
post_max_size = 100M
upload_max_filesize = 50M
max_execution_time = 300
max_input_time = 300
date.timezone = UTC           ; or your preferred TZ
```

Forge restarts PHP-FPM automatically after each save.

**Why `pm.max_children = 3`:** each PHP-FPM process eats ~80–120 MB
resident. With two sites on a 2 GB box, capping each pool at 3 keeps
combined PHP-FPM footprint under ~720 MB worst case — leaves room for
MySQL (256 MB), Redis (256 MB), workers (~400 MB), and OS.

### OpCache (Forge enables it by default)

OpCache is configured separately from the main `php.ini` via
`/etc/php/8.5/fpm/conf.d/10-opcache.ini`. Verify defaults are sane:

```bash
php8.5 -i | grep -E 'opcache.enable|memory_consumption|max_accelerated_files'
```

Expect `opcache.enable => On`, `memory_consumption => 128`,
`max_accelerated_files => 10000`. Leave these alone unless you have
specific evidence they need tuning — the deploy script's PHP-FPM
reload clears OpCache automatically after each deploy.

### Redis

Forge installs Redis by default. SSH and tune:

```bash
sudo nano /etc/redis/redis.conf
```

Set:

```ini
maxmemory 256mb
maxmemory-policy allkeys-lru
appendonly no
save ""
```

```bash
sudo systemctl restart redis-server
redis-cli ping   # → PONG
```

**Why** — caps Redis at 256 MB so it can't squeeze MySQL.
`allkeys-lru` means metadata cache entries get evicted on pressure
(safe; we re-fetch from AI on miss). Disabling persistence is fine —
cache + queue can be cold-started without data loss.

### MySQL

```bash
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

Add under `[mysqld]`:

```ini
innodb_buffer_pool_size  = 256M
innodb_log_file_size     = 64M
max_connections          = 60
wait_timeout             = 600
```

```bash
sudo systemctl restart mysql
```

**Why** — default MySQL will balloon to 1 GB+ on Ubuntu. Capping
`innodb_buffer_pool_size` at 256 MB leaves room for workers.

### Create the database

Forge → **Server → MySQL → Add Database**:

- Database: `tipelodeon`
- User: `tipelodeon`
- Password: generate a strong one

Save the password — you'll paste it into `.env` below.

---

## 5. Create the site

Forge → **Server → Sites → New Site**:

- Root domain: `tipelodeon.com`
- Aliases: `www.tipelodeon.com`
- Project type: **General PHP / Laravel**
- Web directory: `/public`
- PHP version: 8.5
- Create database: leave unchecked (we already made it)

After creation, click into the site:

### Connect the Git repo

**Apps → Git Repository**:

- Provider: GitHub
- Repository: `gnarhard/tipelodeon-web`
- Branch: `main`
- Composer install: **checked**

### Environment file

**Site → Environment → Edit File**:

```ini
APP_NAME=Tipelodeon
APP_ENV=production
APP_DEBUG=false
APP_KEY=        # Forge generates this on first deploy if blank
APP_URL=https://tipelodeon.com

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tipelodeon
DB_USERNAME=tipelodeon
DB_PASSWORD=PASTE-FROM-FORGE-MYSQL-CREATION

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# AI provider keys
ANTHROPIC_API_KEY=...
GEMINI_API_KEY=...
# OPENAI_API_KEY=...  (only if you flip metadata_enrichment provider)

# Tuning knobs — flip without code changes
CHART_ENRICHMENT_CHUNK_SIZE=20
CHART_ENRICHMENT_SSE_MAX_LIFETIME=600
CHART_ENRICHMENT_SSE_POLL_INTERVAL_MS=250
CHART_RENDER_DPI=300
CHART_RENDER_PAGE_CONCURRENCY=4
CHART_RENDER_CONCURRENCY=1

# Mail, Stripe, S3, etc. — fill in per your account
```

### AI fast-path knobs

All have safe defaults. Override only when you want to change behavior:

| Config key | Default | Purpose |
|---|---|---|
| `services.ai.haiku_filename_fast_path_enabled` | `true` | Filename Haiku canonicalize — set `false` to roll back |
| `services.ai.haiku_hint_min_confidence` | `0.85` | Confidence floor for filename fast-path |
| `services.ai.haiku_ocr_fast_path_enabled` | `true` | OCR Haiku fast-path |
| `services.ai.haiku_ocr_min_confidence` | `0.9` | Confidence floor for OCR fast-path |
| `services.ai.vision.max_long_edge_px` | `1024` | Vision image downsize cap (0 disables) |

These read via `config(...)` — edit `config/services.php` to change
defaults, or wrap them in `env()` and set in `.env`.

### Deploy script

**Site → App → Edit Deploy Script**:

```bash
cd $FORGE_SITE_PATH

git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if [ -f artisan ]; then
    $FORGE_PHP artisan migrate --force
    $FORGE_PHP artisan config:cache
    $FORGE_PHP artisan route:cache
    $FORGE_PHP artisan view:cache
    $FORGE_PHP artisan event:cache
fi

# Restart workers so they pick up new code — Laravel's worker holds the
# bootstrap in memory between jobs.
( flock -w 10 9 || exit 1
    echo 'Restarting FPM…'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
sudo -S supervisorctl restart tipelodeon-ai:* tipelodeon-renders:* > /dev/null 2>&1 || true
# If you opted for the schedule:work daemon (rare), also restart it:
# sudo -S supervisorctl restart tipelodeon-schedule:* > /dev/null 2>&1 || true

# Forge daemons get auto-generated supervisord names like `daemon-12345`.
# If `tipelodeon-ai:*` etc. don't match, run `sudo supervisorctl status`
# once and use the real names — or set the daemon's "Process Name" to the
# values above in the Forge UI.
```

**First deploy:** click **Site → Deploy Now**. Forge runs the script,
generates `APP_KEY` if blank, runs migrations, and caches everything.

### TLS

**Site → SSL → LetsEncrypt → Obtain Certificate** with both domains
checked. Forge handles renewals.

### Nginx — patch for SSE

The bulk-enrich SSE stream needs proxy buffering off so events flush
immediately. Edit **Site → Files → Edit Nginx Configuration** and add
this block INSIDE the existing `server` block (right above `location /`):

```nginx
# SSE: stream bulk-enrich progress without proxy buffering so events
# flush to the Dart client immediately.
location ~ /bulk-enrich-jobs/[0-9]+/stream$ {
    proxy_buffering off;
    proxy_cache off;
    fastcgi_buffering off;
    proxy_set_header X-Accel-Buffering no;
    try_files $uri $uri/ /index.php?$query_string;
}
```

While you're in there, raise the upload limit on the `server` block:

```nginx
client_max_body_size 100M;
client_body_timeout 300s;
proxy_read_timeout 300s;
proxy_send_timeout 300s;
fastcgi_read_timeout 300s;
```

Save — Forge reloads Nginx automatically.

---

## 6. Queue workers (Forge Daemons)

Forge manages workers via supervisord under the hood — the UI is at
**Server → Daemons**.

**Add three daemons.** Substitute `<isolated-user>` with your site's
UNIX user (find it under **Site → Meta**, or from any running process
via `ps aux | grep artisan`).

### Daemon 1: `tipelodeon-ai`

- **Command:**
  ```
  php8.5 /home/<isolated-user>/tipelodeon.com/current/artisan queue:work redis --queue=imports,enrichments,default --sleep=1 --tries=1 --timeout=300 --memory=256 --max-jobs=500 --max-time=3600
  ```
- **User:** `<isolated-user>`
- **Directory:** `/home/<isolated-user>/tipelodeon.com/current`
- **Processes:** `2`
- **Start on Boot:** ✓
- **Stop Wait Seconds:** `310`

### Daemon 2: `tipelodeon-renders`

- **Command:**
  ```
  php8.5 /home/<isolated-user>/tipelodeon.com/current/artisan queue:work redis --queue=renders --sleep=1 --tries=1 --timeout=900 --memory=384 --max-jobs=200 --max-time=3600
  ```
- **User:** `<isolated-user>`
- **Directory:** `/home/<isolated-user>/tipelodeon.com/current`
- **Processes:** `1`
- **Start on Boot:** ✓
- **Stop Wait Seconds:** `910`

### Scheduler — use Forge's Scheduled Jobs (recommended)

Laravel needs `php artisan schedule:run` to fire every minute for any
scheduled tasks (e.g. the daily song-integrity AI batch). Two ways:

**Option A — Forge Scheduled Jobs UI (recommended).** Forge → **Server
→ Scheduled Jobs → New Scheduled Job**:

- **Command:** `php8.5 /home/<isolated-user>/tipelodeon.com/current/artisan schedule:run`
- **User:** `<isolated-user>`
- **Frequency:** **Every Minute**

This adds a cron entry. Slightly less responsive than `schedule:work`
(1-minute granularity vs near-instant) but zero memory cost — perfect
for the 2 GB box.

**Option B — `schedule:work` daemon** (more responsive, costs ~50 MB
resident):

- Add a third daemon with command:
  `php8.5 /home/<isolated-user>/tipelodeon.com/current/artisan schedule:work`
- Processes: `1`

Pick one. Don't do both.

> **Why `current/` and `php8.5`?** Forge zero-downtime deploys ship each
> release into `/home/<isolated-user>/<domain>/releases/<timestamp>/`
> and swap the `current/` symlink atomically. Daemons MUST target
> `current/` so they pick up new code after deploy. `php8.5` is the
> exact PHP CLI binary Forge installs — `php` alone may not be on
> `$PATH` for daemon processes.

Click each daemon → **Start**. Status should flip to **Running**.

**Why per-daemon:**

- **AI** (2 procs) — I/O-bound waiting on Anthropic / Gemini; 2 parallel
  doubles AI throughput. Subscribed to `imports,enrichments,default` in
  priority order — chart-identification work preempts everything else.
- **Renders** (1 proc) — CPU-bound via Imagick. Internal `pcntl_fork`
  already parallelizes 4 pages per render; a second worker would thrash
  the 2 vCPU.
- **Scheduler** — handled by Forge's Scheduled Jobs cron entry (see
  above). Without it, no scheduled tasks fire (e.g. song integrity AI
  batch). The cron option is the default; `schedule:work` daemon is
  optional and only adds responsiveness.

---

## 7. Verify end-to-end

SSH in and check:

```bash
# 1. Workers alive (Forge wraps supervisord)
sudo supervisorctl status

# 2. Queues consuming
redis-cli llen queues:imports
redis-cli llen queues:enrichments
redis-cli llen queues:renders

# 3. PHP-FPM responding
curl -I https://tipelodeon.com

# 4. Imagick + Ghostscript + pdftotext all work
php -r 'echo (extension_loaded("imagick") ? "imagick OK" : "MISSING").PHP_EOL;'
gs --version
pdftotext -v 2>&1 | head -1
```

Tail live logs during a real import (Forge → Site → Logs, or via SSH):

```bash
tail -f /home/<isolated-user>/tipelodeon.com/current/storage/logs/laravel.log
# Worker stdout is written into supervisor logs:
sudo tail -f /var/log/supervisor/tipelodeon-ai*.log
sudo tail -f /var/log/supervisor/tipelodeon-renders*.log
```

Watch for these structured events — they're your perf signal:

- `pipeline.timing` — per-stage `duration_ms`
- `ai.token_usage` — input / output / cache tokens per provider per call
- `ensemble.consensus` — `hit: true|false` rate
- `metadata_lookup.cache_hit` / `cache_miss` / `cache_put`
- `chart_image.downsized_for_vision`
- `EnsembleSongService: pre-flight cache hit; skipping vision.`
- `EnsembleSongService: filename canonicalize accepted; skipping vision.`
- `EnsembleSongService: OCR Haiku accepted; skipping vision.`

---

## 8. Log rotation

Forge enables logrotate by default for Nginx + system logs. For our
worker logs (under `/var/log/supervisor`) the supervisord defaults are
fine. The Laravel log at `/home/<isolated-user>/tipelodeon.com/current/storage/logs/laravel.log`
uses Laravel's built-in `daily` channel — set in `.env`:

```ini
LOG_CHANNEL=stack
LOG_LEVEL=info
```

In `config/logging.php` make sure the stack includes `daily`:

```php
'channels' => [
    'stack' => ['driver' => 'stack', 'channels' => ['daily']],
    'daily' => [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel.log'),
        'level' => env('LOG_LEVEL', 'info'),
        'days' => 14,
    ],
],
```

---

## 9. Smoke-test the AI fast-paths

After deploy, kick a small import and tail:

```bash
tail -f /home/<isolated-user>/tipelodeon.com/current/storage/logs/laravel-*.log \
  | grep -E 'EnsembleSongService|metadata_lookup|pipeline.timing'
```

- First chart with a known title: **second** import should show
  `pre-flight cache hit; skipping vision.`
- A chart with `Title - Artist.pdf` filename: `filename canonicalize
  accepted; skipping vision.` on first import.
- A scanned PDF (no selectable text): falls through to vision.
- A digital chord chart (selectable text): `OCR Haiku accepted; skipping
  vision.` if pdftotext returns text.

If every chart still goes through full ensemble vision, check:

1. `services.ai.haiku_filename_fast_path_enabled` is `true`
2. Anthropic API key is set + has credit
3. `pdftotext -v` returns exit code 0

---

## 10. Troubleshoot

| Symptom | Diagnosis |
|---|---|
| Worker OOM-killed | Swap not enabled (Step 2), or `--memory=256` missing from daemon command |
| 413 on upload | `client_max_body_size` (Nginx) + `post_max_size` / `upload_max_filesize` (php.ini) must all be ≥ your max PDF size |
| SSE never streams | `proxy_buffering off` not applied to the stream location in Nginx |
| Imagick "not authorized" | `/etc/ImageMagick-6/policy.xml` still blocks PDFs — re-run Step 3 |
| Render stuck at "identifying" | `tipelodeon-ai` daemon not running or not subscribed to `imports` queue |
| `redis-cli llen queues:enrichments` keeps growing | No worker consuming the enrichments queue — confirm `tipelodeon-ai` is subscribed to `imports,enrichments,default` (not just `default`) |
| `Cache::tags` BadMethodCall | Driver doesn't support tags — confirm `CACHE_STORE=redis`, not `file` |
| Forge deploy fails on `migrate` | `.env` `DB_PASSWORD` doesn't match the password Forge generated under Server → MySQL |
| Daemon won't start after `supervisorctl status` shows `FATAL` | Check the daemon's stdout log under `/var/log/supervisor/` — usually a missing PHP extension or wrong site path |

---

## 11. Post-deploy ritual

Every push to `main` triggers Forge's deploy script (Step 5). Manually:

- **Site → Deploy Now** — runs the script
- **Server → Daemons → Restart All** — picks up new code in workers

Or from SSH (manually, bypassing Forge's deploy script):

```bash
cd /home/<isolated-user>/tipelodeon.com/current
git pull && composer install --no-dev --optimize-autoloader
php8.5 artisan migrate --force
php8.5 artisan config:cache && php8.5 artisan route:cache \
  && php8.5 artisan view:cache && php8.5 artisan event:cache
sudo supervisorctl restart tipelodeon-ai:* tipelodeon-renders:*
```

> **Heads up about `current/`:** if you `git pull` inside `current/`,
> the next Forge deploy will swap the symlink to a fresh release and
> your manual commits disappear from the live path. SSH-side changes
> should only be temporary — push to GitHub and let Forge deploy.

---

## 12. Quick reference: every config knob

```ini
# Queue + chunking
CHART_ENRICHMENT_QUEUE=enrichments
CHART_ENRICHMENT_CHUNK_SIZE=20            # tune up to 30 if Anthropic accepts
CHART_ENRICHMENT_MAX_CHUNKS_PARALLEL=0    # 0 = no app-level cap; lower if rate-limited
CHART_ENRICHMENT_SSE_MAX_LIFETIME=600     # seconds per SSE connection
CHART_ENRICHMENT_SSE_POLL_INTERVAL_MS=250 # in-handler DB diff cadence

# Rendering
CHART_RENDER_QUEUE=renders
CHART_RENDER_DPI=300
CHART_RENDER_PAGE_CONCURRENCY=4
CHART_RENDER_CONCURRENCY=1                # serialize chart-level (existing mutex)
CHART_RENDER_PNG_COMPRESSION_LEVEL=3

# Identification queue
CHART_IDENTIFICATION_QUEUE=imports
```
