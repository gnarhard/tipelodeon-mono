# Production deployment

This walkthrough sets up a fresh **Ubuntu 22.04 LTS** DigitalOcean droplet
(2 vCPU / 2 GB / 25 GB SSD) to run the Tipelodeon backend with the import
pipeline tuned for the hardware.

All commands run as root unless noted. Replace `/var/www/tipelodeon` with
your actual path if different.

---

## 1. OS-level prep

### Swap (2 GB)

Without this, one Imagick spike during a chart render will OOM-kill your
queue worker mid-job.

```bash
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
sysctl vm.swappiness=10
echo 'vm.swappiness=10' >> /etc/sysctl.conf
```

**Verify:** `free -h` shows `Swap: 2.0Gi`.

### Timezone + NTP

Logs are easier to read in local time.

```bash
timedatectl set-timezone America/Denver
apt-get install -y systemd-timesyncd
systemctl enable systemd-timesyncd
```

---

## 2. System packages

```bash
apt-get update
apt-get install -y \
  software-properties-common ca-certificates curl gnupg \
  ufw fail2ban htop
```

### Lock down SSH + firewall (optional but recommended)

```bash
ufw allow OpenSSH
ufw allow 80,443/tcp
ufw --force enable
```

---

## 3. PHP 8.4 + extensions

The app needs Imagick (chart renders), Redis (cache + queue), and
**pdftotext** (Poppler) for the OCR Haiku fast-path.

```bash
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y \
  php8.4 php8.4-fpm php8.4-cli php8.4-common \
  php8.4-mysql php8.4-redis \
  php8.4-curl php8.4-mbstring php8.4-xml \
  php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl \
  php8.4-imagick \
  composer \
  ghostscript poppler-utils \
  imagemagick
```

**Why each package:**

- `php8.4-imagick` + `imagemagick` + `ghostscript` — chart PDF → PNG rendering
- `poppler-utils` — provides `pdftotext`; enables the OCR Haiku fast-path
  (without it the path silently no-ops)
- `php8.4-redis` — Redis driver for queue + cache + cache tags

### Allow Imagick to read PDFs

Ubuntu's `imagemagick-6` ships with a policy that blocks PDF reading.
Patch it:

```bash
sed -i 's|<policy domain="coder" rights="none" pattern="PDF" />|<policy domain="coder" rights="read\|write" pattern="PDF" />|' /etc/ImageMagick-6/policy.xml
```

**Verify:** `convert -list policy | grep PDF` shows `rights: Read Write`.

### PHP-FPM pool

Edit `/etc/php/8.4/fpm/pool.d/www.conf`:

```ini
user = www-data
group = www-data
listen = /run/php/php8.4-fpm.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 4
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500
```

**Why `max_children = 4`:** each PHP-FPM process eats ~80–120 MB resident.
Four + workers + MySQL + Redis fits with headroom for swap.

Edit BOTH `/etc/php/8.4/fpm/php.ini` AND `/etc/php/8.4/cli/php.ini` —
the CLI ini drives `artisan` + queue workers:

```ini
memory_limit = 256M
post_max_size = 100M
upload_max_filesize = 50M
max_execution_time = 300
max_input_time = 300
date.timezone = America/Denver
```

Reload:

```bash
systemctl restart php8.4-fpm
systemctl enable php8.4-fpm
```

---

## 4. Redis

```bash
apt-get install -y redis-server
```

Edit `/etc/redis/redis.conf`:

```ini
maxmemory 256mb
maxmemory-policy allkeys-lru
appendonly no
save ""
```

**Why** — caps Redis at 256 MB so it can't squeeze MySQL.
`allkeys-lru` means metadata cache entries get evicted on pressure
(safe; we re-fetch from AI on miss). Disabling persistence is fine
because cache + queue can be cold-started without data loss.

```bash
systemctl restart redis-server
systemctl enable redis-server
```

**Verify:** `redis-cli ping` returns `PONG`.

---

## 5. MySQL 8

```bash
apt-get install -y mysql-server
mysql_secure_installation
```

Edit `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
[mysqld]
innodb_buffer_pool_size  = 256M
innodb_log_file_size     = 64M
max_connections          = 60
wait_timeout             = 600
default_authentication_plugin = mysql_native_password
```

**Why** — default MySQL will balloon to 1 GB+ on Ubuntu. Capping
`innodb_buffer_pool_size` at 256 MB leaves room for workers. Reduce
`max_connections` from default 151 to 60 since we have 4 PHP-FPM + 3
workers max.

```bash
systemctl restart mysql
```

Create DB + user:

```bash
mysql -uroot -p <<SQL
CREATE DATABASE tipelodeon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tipelodeon'@'localhost' IDENTIFIED BY 'STRONG-PASSWORD-HERE';
GRANT ALL ON tipelodeon.* TO 'tipelodeon'@'localhost';
FLUSH PRIVILEGES;
SQL
```

---

## 6. Nginx + TLS

```bash
apt-get install -y nginx certbot python3-certbot-nginx
```

Create `/etc/nginx/sites-available/tipelodeon`:

```nginx
server {
    listen 80;
    server_name tipelodeon.com www.tipelodeon.com;
    root /var/www/tipelodeon/web/public;
    index index.php;

    client_max_body_size 100M;
    client_body_timeout 300s;
    client_header_timeout 60s;
    keepalive_timeout 75s;

    proxy_read_timeout 300s;
    proxy_send_timeout 300s;

    # SSE: disable proxy buffering on the bulk-enrich stream so events
    # flush immediately to the Dart client.
    location ~ /bulk-enrich-jobs/[0-9]+/stream$ {
        proxy_buffering off;
        proxy_cache off;
        proxy_set_header X-Accel-Buffering no;
        try_files $uri $uri/ /index.php?$query_string;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
ln -s /etc/nginx/sites-available/tipelodeon /etc/nginx/sites-enabled/tipelodeon
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

TLS certificate:

```bash
certbot --nginx -d tipelodeon.com -d www.tipelodeon.com \
        --redirect --agree-tos -m you@example.com -n
```

---

## 7. Deploy the app

```bash
mkdir -p /var/www
git clone git@github.com:gnarhard/tipelodeon-web.git /var/www/tipelodeon/web
cd /var/www/tipelodeon/web

composer install --no-dev --optimize-autoloader --no-interaction

cp .env.example .env
nano .env   # fill in prod values (see next section)

php artisan key:generate
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

chown -R www-data:www-data /var/www/tipelodeon
find /var/www/tipelodeon -type d -exec chmod 755 {} \;
find /var/www/tipelodeon -type f -exec chmod 644 {} \;
chmod -R 775 /var/www/tipelodeon/web/storage \
             /var/www/tipelodeon/web/bootstrap/cache
```

### Required `.env` settings

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tipelodeon.com

DB_CONNECTION=mysql
DB_DATABASE=tipelodeon
DB_USERNAME=tipelodeon
DB_PASSWORD=STRONG-PASSWORD-HERE

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1

LOG_CHANNEL=stack
LOG_LEVEL=info

# AI provider keys
ANTHROPIC_API_KEY=...
GEMINI_API_KEY=...
# OPENAI_API_KEY=... (only if you flip metadata_enrichment provider)

# Tuning knobs you can flip without code changes:
CHART_ENRICHMENT_CHUNK_SIZE=20
CHART_ENRICHMENT_SSE_MAX_LIFETIME=600
CHART_ENRICHMENT_SSE_POLL_INTERVAL_MS=250
CHART_RENDER_DPI=300
CHART_RENDER_PAGE_CONCURRENCY=4
CHART_RENDER_CONCURRENCY=1
```

### AI fast-path knobs

All have safe defaults. Override only when you want to change behavior:

| Config key | Default | Purpose |
|---|---|---|
| `services.ai.haiku_filename_fast_path_enabled` | `true` | Filename Haiku canonicalize — set `false` to roll back |
| `services.ai.haiku_hint_min_confidence` | `0.85` | Confidence floor for filename fast-path (raise to be stricter) |
| `services.ai.haiku_ocr_fast_path_enabled` | `true` | OCR Haiku fast-path |
| `services.ai.haiku_ocr_min_confidence` | `0.9` | Confidence floor for OCR fast-path |
| `services.ai.vision.max_long_edge_px` | `1024` | Vision image downsize cap (0 disables) |

These read via `config(...)` so edit `config/services.php` to change
defaults, or wrap them in `env()` calls there and set in `.env`.

---

## 8. Supervisor (queue workers)

```bash
apt-get install -y supervisor
mkdir -p /var/log/tipelodeon
chown www-data:www-data /var/log/tipelodeon
```

Save as `/etc/supervisor/conf.d/tipelodeon-workers.conf`:

```ini
[program:tipelodeon-ai]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/tipelodeon/web/artisan queue:work redis --queue=imports,enrichments,default --sleep=1 --tries=1 --timeout=300 --memory=256 --max-jobs=500 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/tipelodeon/ai.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
stopwaitsecs=310

[program:tipelodeon-renders]
process_name=%(program_name)s
command=php /var/www/tipelodeon/web/artisan queue:work redis --queue=renders --sleep=1 --tries=1 --timeout=900 --memory=384 --max-jobs=200 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/tipelodeon/renders.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
stopwaitsecs=910

[program:tipelodeon-schedule]
process_name=%(program_name)s
command=php /var/www/tipelodeon/web/artisan schedule:work
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/tipelodeon/schedule.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=3
```

**Why per-worker:**

- **`tipelodeon-ai`** (`numprocs=2`): I/O-bound waiting on Anthropic /
  Gemini; 2 parallel doubles AI throughput. Subscribed to
  `imports,enrichments,default` in priority order — chart-identification
  work preempts everything else.
- **`tipelodeon-renders`** (`numprocs=1`): CPU-bound via Imagick. Internal
  `pcntl_fork` already parallelizes 4 pages per render; a second worker
  would thrash the 2 vCPU.
- **`tipelodeon-schedule`** (`numprocs=1`): runs Laravel's scheduler.
  Without this you don't get any scheduled jobs (e.g. song integrity AI
  batch).

Apply:

```bash
supervisorctl reread
supervisorctl update
supervisorctl status
```

Expected:

```
tipelodeon-ai:tipelodeon-ai_00           RUNNING   ...
tipelodeon-ai:tipelodeon-ai_01           RUNNING   ...
tipelodeon-renders:tipelodeon-renders    RUNNING   ...
tipelodeon-schedule:tipelodeon-schedule  RUNNING   ...
```

---

## 9. Verify end-to-end

```bash
# 1. Workers alive
supervisorctl status

# 2. Queues consuming
redis-cli llen queues:imports
redis-cli llen queues:enrichments
redis-cli llen queues:renders

# 3. PHP-FPM responding
curl -I https://tipelodeon.com

# 4. Imagick + Ghostscript + pdftotext work
php -r 'echo (extension_loaded("imagick") ? "imagick OK" : "MISSING").PHP_EOL;'
gs --version
pdftotext -v 2>&1 | head -1
```

Tail live logs during a real import:

```bash
tail -f /var/log/tipelodeon/ai.log /var/log/tipelodeon/renders.log \
       /var/www/tipelodeon/web/storage/logs/laravel.log
```

Look for these structured events — they're your perf signal:

- `pipeline.timing` — per-stage `duration_ms`
- `ai.token_usage` — input / output / cache tokens per provider per call
- `ensemble.consensus` — `hit: true|false` rate
- `metadata_lookup.cache_hit` / `cache_miss` / `cache_put`
- `chart_image.downsized_for_vision` — sized down chart pages
- `EnsembleSongService: pre-flight cache hit; skipping vision.`
- `EnsembleSongService: filename canonicalize accepted; skipping vision.`
- `EnsembleSongService: OCR Haiku accepted; skipping vision.`

---

## 10. Log rotation

```bash
cat > /etc/logrotate.d/tipelodeon <<'EOF'
/var/log/tipelodeon/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    postrotate
        supervisorctl restart tipelodeon-ai:* tipelodeon-renders tipelodeon-schedule > /dev/null
    endscript
}

/var/www/tipelodeon/web/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
EOF
```

---

## 11. Post-deploy ritual (every `git pull`)

```bash
cd /var/www/tipelodeon/web
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache \
  && php artisan view:cache && php artisan event:cache
supervisorctl restart tipelodeon-ai:* tipelodeon-renders tipelodeon-schedule
```

Workers MUST restart so they pick up new code — Laravel's worker holds
the bootstrap in memory between jobs.

---

## 12. Smoke-test the AI fast-paths

After deploy, kick a small import and watch for these log signatures:

```bash
tail -f storage/logs/laravel.log \
  | grep -E 'EnsembleSongService|metadata_lookup|pipeline.timing'
```

- First chart with a known title: `pre-flight cache hit; skipping vision.`
  on the second import.
- A chart with `Title - Artist.pdf` filename: `filename canonicalize
  accepted; skipping vision.` on first import.
- A scanned PDF: `OCR Haiku accepted; skipping vision.` if pdftotext
  returns text.

If you see all ensemble vision calls happening every time, check:

1. `services.ai.haiku_filename_fast_path_enabled` is `true`
2. Anthropic API key is set + has credit
3. `pdftotext -v` returns exit code 0

---

## 13. Troubleshoot

| Symptom | Diagnosis |
|---|---|
| Worker keeps OOM-killing | Swap not enabled, or `--memory=256` not set on the worker command |
| 413 on upload | `client_max_body_size` (nginx) + `post_max_size` / `upload_max_filesize` (php.ini) must all be ≥ your max PDF size |
| SSE never streams | nginx `proxy_buffering off` not active for that location |
| Imagick "not authorized" | `/etc/ImageMagick-6/policy.xml` still blocks PDFs |
| Render stuck at "identifying" forever | `tipelodeon-ai` not subscribed to `imports` queue — check `supervisorctl status` |
| `redis-cli llen queues:enrichments` keeps growing | No worker consuming the enrichments queue — the supervisord config above fixes this pre-existing gap |
| `Cache::tags` fails with `BadMethodCall` | Driver doesn't support tags — confirm `CACHE_STORE=redis`, not `file` |

---

## 14. Quick reference: every config knob

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
