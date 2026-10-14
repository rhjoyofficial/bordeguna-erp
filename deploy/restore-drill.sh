#!/bin/bash
# RESTORE DRILL for the Bor de Guna ERP: restores one backup set (database AND
# uploaded files) into a THROWAWAY stack and proves the application works on
# it, including that real attachments open.
#
#   ./restore-drill.sh                     newest verified local set
#   ./restore-drill.sh /path/to/erp-<id>   a specific local set directory
#   ./restore-drill.sh --offsite [snap]    pull the set from the bucket with
#                                          restic (default: latest) - this is
#                                          the real "the VPS is gone" path
#
# Run on the VPS from the repository: cd /opt/bordeguna-erp && deploy/restore-drill.sh
# Exit 0 = PASS. Anything else = do not trust this backup until explained.
#
# ⚠⚠ ISOLATED BY CONSTRUCTION. The drill creates its own Docker network with
# NO internet access (`--internal`), its own mysql:8.0 container with its own
# random root password and an anonymous volume, and a throwaway app container
# whose storage is a COPY of the backup. It never connects to the live `db`
# container, never mounts the live `storage` volume, and removes everything it
# created on exit (KEEP_DRILL=1 keeps it for inspection).
#
# ⚠ The throwaway app container gets the live env file only for APP_KEY and
# similar settings; every database, mail, queue, cache and session setting is
# overridden below, and the network it sits on cannot reach anything anyway.

set -euo pipefail

REPO=${REPO:-/opt/bordeguna-erp}
SETS_DIR=${SETS_DIR:-/root/backups/bordeguna-erp/sets}
APP_IMAGE=${APP_IMAGE:-bordeguna-erp:latest}
DB_IMAGE=${DB_IMAGE:-mysql:8.0}
ATTACH_SAMPLE=${ATTACH_SAMPLE:-5}
if [ -f "${REPO}/deploy/.env" ]; then ENV_FILE=${ENV_FILE:-${REPO}/deploy/.env}; else ENV_FILE=${ENV_FILE:-${REPO}/.env}; fi

ID="drill-$(date -u +%Y%m%d%H%M%S)-$$"
NET="erp-${ID}"
DBC="erp-${ID}-db"
WORK=$(mktemp -d "${DRILL_TMP:-/root}/erp-restore-${ID}.XXXX")
DRILL_PW=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
FAILS=0

log() { echo "[$(date -u +'%H:%M:%S')] $*"; }
check() { if [ "$1" = ok ]; then echo "  PASS  $2"; else echo "  FAIL  $2"; FAILS=$((FAILS + 1)); fi; }

# shellcheck disable=SC2329  # invoked by the EXIT trap
cleanup() {
    if [ "${KEEP_DRILL:-0}" = 1 ]; then
        log "KEEP_DRILL=1: left ${DBC}, network ${NET} and ${WORK} in place"
        return
    fi
    docker rm -f -v "${DBC}" >/dev/null 2>&1 || true
    docker network rm "${NET}" >/dev/null 2>&1 || true
    rm -rf "${WORK}"
}
trap cleanup EXIT

[ -f "${ENV_FILE}" ] || { echo "env file not found: ${ENV_FILE}" >&2; exit 2; }

echo "=== 1. WHICH BACKUP SET ==="
if [ "${1:-}" = "--offsite" ]; then
    SNAP=${2:-latest}
    log "restoring snapshot '${SNAP}' from the bucket into ${WORK}"
    # The backup service's own image and env: the same restic, the same keys.
    docker compose -f "${REPO}/deploy/docker-compose.yml" run --rm --no-deps -T \
        -v "${WORK}/offsite:/restore" --entrypoint restic backup \
        restore "${SNAP}" --tag erp-set --target /restore
    SET_DIR=$(find "${WORK}/offsite" -mindepth 1 -type d -name 'erp-*' | sort | tail -n 1)
elif [ -n "${1:-}" ]; then
    SET_DIR=$1
else
    SET_DIR=$(find "${SETS_DIR}" -mindepth 1 -maxdepth 1 -type d -name 'erp-*' | sort | tail -n 1)
fi
if [ -z "${SET_DIR}" ] || [ ! -f "${SET_DIR}/SHA256SUMS" ]; then
    echo "no backup set found (looked in ${SETS_DIR})" >&2
    exit 2
fi
echo "set: ${SET_DIR}"
sed 's/^/  /' "${SET_DIR}/MANIFEST.txt"
DB_NAME=$(sed -n 's/^database=//p' "${SET_DIR}/MANIFEST.txt")
MANIFEST_TABLES=$(sed -n 's/^live_base_tables=//p' "${SET_DIR}/MANIFEST.txt")

echo
echo "=== 2. THE SET IS INTACT BEFORE WE TRUST IT ==="
if (cd "${SET_DIR}" && sha256sum -c --quiet SHA256SUMS); then check ok "SHA256SUMS"; else check fail "SHA256SUMS"; fi
if gzip -t "${SET_DIR}/db.sql.gz"; then check ok "db.sql.gz is valid gzip"; else check fail "db.sql.gz is valid gzip"; fi
[ "${FAILS}" -eq 0 ] || { echo "set is damaged; stopping before restore"; exit 1; }

echo
echo "=== 3. FILES: EXTRACT A COPY AND CHECK EVERY CHECKSUM ==="
mkdir -p "${WORK}/storage"
tar -C "${WORK}/storage" -xf "${SET_DIR}/files.tar"
if (cd "${WORK}/storage" && sha256sum -c --quiet "${SET_DIR}/files.sha256"); then
    check ok "all $(wc -l < "${SET_DIR}/files.sha256") restored files match their checksums"
else
    check fail "restored files match files.sha256"
fi
# The skeleton Laravel needs beside storage/app, writable by the app container.
mkdir -p "${WORK}/storage/framework/cache/data" "${WORK}/storage/framework/sessions" \
    "${WORK}/storage/framework/views" "${WORK}/storage/logs"
chmod -R a+rwX "${WORK}/storage"

echo
echo "=== 4. ISOLATED DATABASE ==="
docker network create --internal "${NET}" >/dev/null
docker run -d --name "${DBC}" --network "${NET}" --network-alias db \
    -e MYSQL_ROOT_PASSWORD="${DRILL_PW}" -e MYSQL_DATABASE="${DB_NAME}" \
    --memory 1g "${DB_IMAGE}" \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
# Over TCP on purpose: the image's init phase answers on the socket only, so a
# socket ping would report ready before the real server has started.
for _ in $(seq 1 90); do
    if docker exec -e MYSQL_PWD="${DRILL_PW}" "${DBC}" mysql -h127.0.0.1 -uroot -e 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 2
done
docker exec -e MYSQL_PWD="${DRILL_PW}" "${DBC}" mysql -h127.0.0.1 -uroot -N -e 'SELECT CONCAT("drill server ", @@hostname, " ", VERSION())'

echo
echo "=== 5. RESTORE THE DATABASE (timed) ==="
START=$(date -u +%s)
if gunzip -c "${SET_DIR}/db.sql.gz" | docker exec -i -e MYSQL_PWD="${DRILL_PW}" "${DBC}" mysql -uroot "${DB_NAME}"; then
    check ok "dump loaded in $(( $(date -u +%s) - START )) s"
else
    check fail "dump loaded"
fi
RESTORED_TABLES=$(docker exec -e MYSQL_PWD="${DRILL_PW}" "${DBC}" mysql -uroot -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_type='BASE TABLE'")
if [ "${RESTORED_TABLES}" = "${MANIFEST_TABLES}" ]; then check ok "${RESTORED_TABLES} tables, as the manifest says"; else check fail "tables: restored ${RESTORED_TABLES}, manifest ${MANIFEST_TABLES}"; fi
if [ -f "${REPO}/deploy/fingerprint.sql" ]; then
    echo "fingerprint of the restored database:"
    docker exec -i -e MYSQL_PWD="${DRILL_PW}" "${DBC}" mysql -uroot "${DB_NAME}" < "${REPO}/deploy/fingerprint.sql" | sed 's/^/  /' || check fail "fingerprint.sql ran"
fi

echo
echo "=== 6. THE APPLICATION ON THE RESTORED DATA AND FILES ==="
cat > "${WORK}/drill-check.php" <<'PHP'
<?php
// Runs inside the throwaway app container. Prints counts and ids only.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$fail = 0;
$row = DB::selectOne('SELECT DATABASE() AS d, @@hostname AS h');
echo "  connected to database {$row->d} on server {$row->h}\n";

foreach (['businesses', 'users', 'employees', 'accounts', 'journals', 'attachments'] as $t) {
    echo "  {$t}: ".DB::table($t)->count()."\n";
}

// Every attachment row: does its file exist in the restored storage?
$missing = 0;
DB::table('attachments')->select('id', 'disk', 'path')->orderBy('id')->chunk(500, function ($rows) use (&$missing) {
    foreach ($rows as $r) {
        if (! Storage::disk($r->disk)->exists($r->path)) {
            $missing++;
        }
    }
});
$total = DB::table('attachments')->count();
echo "  attachment rows whose file is missing: {$missing} of {$total}\n";
if ($missing > 0) {
    $fail++;
}

// A sample actually OPENED: read in full, size compared with the row, images
// decoded. The row's mime_type is what the BROWSER claimed at upload, so a
// different detected type is reported as a note, not a failure.
$sample = (int) (getenv('ATTACH_SAMPLE') ?: 5);
$finfo = new finfo(FILEINFO_MIME_TYPE);
foreach (DB::table('attachments')->orderByDesc('id')->limit($sample)->get() as $a) {
    $disk = Storage::disk($a->disk);
    if (! $disk->exists($a->path)) {
        echo "  FAIL  attachment #{$a->id}: file missing\n";
        $fail++;
        continue;
    }
    $bytes = $disk->get($a->path);
    $okSize = $a->size == 0 || strlen($bytes) == $a->size;
    $mime = $finfo->buffer($bytes);
    $okMime = $a->mime_type === null || $mime === $a->mime_type;
    $okImage = ! str_starts_with((string) $mime, 'image/') || $mime === 'image/svg+xml' || @getimagesizefromstring($bytes) !== false;
    $ok = $okSize && $okImage;
    echo '  '.($ok ? 'PASS' : 'FAIL')."  attachment #{$a->id}: ".strlen($bytes)." bytes, {$mime}"
        .($okSize ? '' : ", row says {$a->size} bytes")
        .($okMime ? '' : " (note: uploaded as {$a->mime_type})")
        .($okImage ? '' : ', image does not decode')."\n";
    if (! $ok) {
        $fail++;
    }
}
if ($total === 0) {
    echo "  (no attachments in this backup: nothing to open)\n";
}
exit($fail === 0 ? 0 : 1);
PHP

run_app() {
    docker run --rm --network "${NET}" \
        --env-file "${ENV_FILE}" \
        -e APP_ENV=production -e DB_CONNECTION=mysql -e DB_HOST=db -e DB_PORT=3306 \
        -e DB_DATABASE="${DB_NAME}" -e DB_USERNAME=root -e DB_PASSWORD="${DRILL_PW}" \
        -e MAIL_MAILER=log -e QUEUE_CONNECTION=sync -e CACHE_STORE=array \
        -e SESSION_DRIVER=array -e BROADCAST_CONNECTION=null -e LOG_CHANNEL=stderr \
        -e ATTACH_SAMPLE="${ATTACH_SAMPLE}" \
        -v "${WORK}/storage:/var/www/html/storage" \
        -v "${WORK}/drill-check.php:/drill/check.php:ro" \
        --entrypoint sh "${APP_IMAGE}" -c "php artisan config:clear >/dev/null 2>&1; $1"
}

PENDING=$(run_app "php artisan migrate:status 2>/dev/null | grep -c Pending || true")
echo "  pending migrations against this code: ${PENDING} (non-zero is normal for an OLD set; restore-old.sh exercises migrate)"
if run_app "php artisan accounting:verify-ledger" | tail -n 3; then check ok "accounting:verify-ledger"; else check fail "accounting:verify-ledger"; fi
if run_app "php artisan inventory:verify-ledger" | tail -n 3; then check ok "inventory:verify-ledger"; else check fail "inventory:verify-ledger"; fi
if run_app "php /drill/check.php"; then check ok "records and uploaded files work together"; else check fail "records and uploaded files work together"; fi

echo
if [ "${FAILS}" -eq 0 ]; then
    echo "=== PASS: ${SET_DIR##*/} restores (database + files) and the application reads it ==="
else
    echo "=== FAIL: ${FAILS} check(s) failed for ${SET_DIR##*/}; do not rely on this set until explained ==="
fi
exit $(( FAILS == 0 ? 0 : 1 ))
