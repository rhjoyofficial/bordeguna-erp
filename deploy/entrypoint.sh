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
# ⚠⚠ PROBED THROUGH PDO, the same driver the application uses. Do not "simplify"
# this back to a command-line client.
#
# Two earlier versions of this check were wrong in ways that both looked like a
# database outage:
#
#  1. `mysqladmin ping` with no credentials — MySQL 8 rejects it, so the loop
#     spun forever against a database that was already reporting healthy.
#  2. `mysql -h db -u… -p…` — Alpine's `mysql-client` package is actually the
#     MARIADB client, which verifies TLS by default and refuses MySQL 8's
#     self-signed server certificate with "TLS/SSL error: self-signed
#     certificate in certificate chain". The credentials were correct the whole
#     time; the tool was wrong.
#
# Probing with PDO tests the path that actually matters and cannot disagree with
# the application about TLS, auth plugins or defaults.
#
# ⚠ BOUNDED, deliberately. An unbounded wait turns a misconfiguration into a
# container that hangs silently and looks like a slow start.
db_ready() {
    php -r '
        try {
            new PDO(
                sprintf("mysql:host=%s;port=%s;dbname=%s",
                    getenv("DB_HOST") ?: "db",
                    getenv("DB_PORT") ?: "3306",
                    getenv("DB_DATABASE")),
                getenv("DB_USERNAME"),
                getenv("DB_PASSWORD"),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage().PHP_EOL);
            exit(1);
        }
    ' 2>/dev/null
}

attempts=0
until db_ready; do
    attempts=$((attempts + 1))
    if [ "${attempts}" -ge 60 ]; then
        log "FATAL: database at ${DB_HOST:-db} not reachable as ${DB_USERNAME} after 2 minutes"
        # Re-run without swallowing stderr, so the log carries the real reason
        # rather than just the fact that something failed.
        php -r '
            try {
                new PDO(
                    sprintf("mysql:host=%s;port=%s;dbname=%s",
                        getenv("DB_HOST") ?: "db",
                        getenv("DB_PORT") ?: "3306",
                        getenv("DB_DATABASE")),
                    getenv("DB_USERNAME"),
                    getenv("DB_PASSWORD"),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            } catch (Throwable $e) {
                fwrite(STDERR, "  reason: ".$e->getMessage().PHP_EOL);
            }
        ' || true
        exit 1
    fi
    log "waiting for database at ${DB_HOST:-db} (${attempts}/60)"
    sleep 2
done
log "database reachable"

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
