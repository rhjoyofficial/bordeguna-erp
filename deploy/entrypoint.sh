#!/bin/bash
# Container start-up for app, worker and scheduler.
#
# Everything here is idempotent, because all three services run this and the
# stack restarts them independently.

set -euo pipefail

log() { echo "[entrypoint] $*"; }

# ---------------------------------------------------------------------------
# 1. Publish the front end into the volume nginx serves.
#
# ⚠ The web container is plain nginx and has none of the application files, so
# `public/` is a shared volume. A named volume is initialised from the image
# ONLY when it is empty — on every deploy after the first it would still hold
# the PREVIOUS release's compiled assets, and the page would load an index.php
# referencing a build manifest that no longer matches. Copying on every start is
# what makes a redeploy actually take effect.
# ---------------------------------------------------------------------------
if [ -d /opt/public-dist ]; then
    log "publishing public/ assets"
    mkdir -p /var/www/html/public
    cp -a /opt/public-dist/. /var/www/html/public/
fi

# ---------------------------------------------------------------------------
# 2. Wait for MySQL.
#
# Compose's `depends_on: service_healthy` covers the normal case, but a database
# restart under a running app is not the normal case and a worker that dies on
# boot loops noisily.
# ---------------------------------------------------------------------------
until mysqladmin ping -h "${DB_HOST:-db}" --silent 2>/dev/null; do
    log "waiting for database at ${DB_HOST:-db}"
    sleep 2
done

# ---------------------------------------------------------------------------
# 3. Caches.
#
# ⚠ CLEAR BEFORE CACHING, always. A stale config cache has cost this project a
# debugging session already: the suite failed ~27 tests with auth not persisting
# and the cause was a leftover config:cache, not the code. The build deliberately
# does NOT cache, because the build has no real .env.
#
# ⚠ `config:cache` must come AFTER the .env is present, which is why this is
# here and not in the Dockerfile.
# ---------------------------------------------------------------------------
log "rebuilding caches"
php artisan config:clear >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ⚠ NO `php artisan migrate` here. Three containers run this script and they
# start concurrently — racing migrations against each other is how a schema gets
# half-applied. Migrations are an explicit, single, operator-run step.

log "starting: $*"
exec "$@"
