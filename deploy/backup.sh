#!/bin/bash
# Nightly backup for the Bor de Guna ERP: ONE identifiable, verified backup set
# (database + uploaded files + manifest + checksums), copied off the server
# with restic to an S3-compatible bucket (Backblaze B2).
#
#   backup.sh loop    the container's normal mode: nightly at BACKUP_HOUR_UTC
#   backup.sh once    one run now; exit 0 only if the whole set is verified
#
# A set is /backups/sets/erp-YYYYmmdd-HHMMSS/ containing:
#   db.sql.gz      mysqldump of the ERP database
#   files.tar      storage/app (private + public: attachments, sounds, popup
#                  media, relax media, avatars, imports). Uncompressed on
#                  purpose: restic compresses and deduplicates it, a .tar.gz
#                  would re-upload every byte every night.
#   files.sha256   sha256 of every file INSIDE files.tar, read from the tar
#   MANIFEST.txt   what the set is: counts, sizes, versions
#   SHA256SUMS     sha256 of the four files above
#
# ⚠⚠ "SUCCESSFUL" MEANS ALL OF THIS PASSED, IN ORDER:
#   1. the dump is valid gzip, non-trivial, ends with mysqldump's completion
#      line, and holds exactly as many CREATE TABLEs as the live database has
#      base tables;
#   2. the tar reads back end to end and its file count matches files.sha256;
#   3. `sha256sum -c SHA256SUMS` passes;
#   4. restic wrote a snapshot, `restic check` passes, and SHA256SUMS and the
#      dump read back FROM THE BUCKET match the local copies.
# Only then is /backups/state/last-success written. The staleness watcher
# (backup-watch.sh) reads nothing else.
#
# ⚠ Consistency: the dump is taken FIRST (--single-transaction), the files
# after. Uploads write the file before the database row, so every attachment
# row in the dump has its file in the tar. A file uploaded in between is in the
# tar without a row, which is harmless. A file deleted in between is the only
# gap, and the previous night's set still has it.

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=backup-lib.sh
. "${SCRIPT_DIR}/backup-lib.sh"

FILES_ROOT=${FILES_ROOT:-/appstorage}       # the `storage` volume, read-only
FILES_SUBDIR=app                             # storage/app only: framework/ and logs/ are disposable
DB_HOST=${DB_BACKUP_HOST:-db}
DB_USER=${DB_BACKUP_USER:-root}
BACKUP_HOUR_UTC=${BACKUP_HOUR_UTC:-1}        # 01:00 UTC = 07:00 Dhaka, after the 23:00 attendance run
BACKUP_LOCAL_KEEP=${BACKUP_LOCAL_KEEP:-7}    # verified sets kept on this disk
BACKUP_MIN_TABLES=${BACKUP_MIN_TABLES:-20}   # a dump with fewer tables is not this ERP
RESTIC_HOST_TAG=bordeguna-erp
RESTIC_SET_TAG=erp-set
KEEP_DAILY=14
KEEP_WEEKLY=8
KEEP_MONTHLY=12
STAGE_FILE="${STATE_DIR}/.stage"
LOCK_DIR="${STATE_DIR}/.lock"

# --- one run ------------------------------------------------------------------

stage() { printf '%s\n' "$1" > "${STAGE_FILE}"; log "stage: $1"; }
die() { printf '%s: %s\n' "$(cat "${STAGE_FILE}" 2>/dev/null || echo unknown)" "$1" > "${STAGE_FILE}"; log "FAILED: $1"; exit 1; }

require_env() {
    local name missing=""
    for name in "$@"; do
        [ -n "${!name:-}" ] || missing="${missing} ${name}"
    done
    [ -z "${missing}" ] || die "missing environment:${missing}"
}

run_once() {
    stage preflight
    require_env DB_DATABASE DB_ROOT_PASSWORD RESTIC_REPOSITORY RESTIC_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
    [ -d "${FILES_ROOT}/${FILES_SUBDIR}" ] || die "${FILES_ROOT}/${FILES_SUBDIR} is not mounted"
    export MYSQL_PWD="${DB_ROOT_PASSWORD}"   # never on a command line

    local set_id work final
    set_id="erp-$(date -u +'%Y%m%d-%H%M%S')"
    work="${SETS_DIR}/.partial-${set_id}"
    final="${SETS_DIR}/${set_id}"
    printf '%s\n' "${set_id}" > "${STATE_DIR}/.current-set"
    rm -rf "${SETS_DIR}"/.partial-*          # leftovers of an interrupted run
    mkdir -p "${work}"

    # 1. DATABASE ---------------------------------------------------------------
    stage dump
    local live_tables server_version
    live_tables=$(mysql -h "${DB_HOST}" -u"${DB_USER}" -N -B -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}' AND table_type='BASE TABLE'")
    server_version=$(mysql -h "${DB_HOST}" -u"${DB_USER}" -N -B -e 'SELECT VERSION()')
    # --single-transaction: consistent without locking payroll out.
    # --routines/--triggers: a restore that silently drops them is not a restore.
    mysqldump -h "${DB_HOST}" -u"${DB_USER}" \
        --single-transaction --quick --routines --triggers --events \
        --set-gtid-purged=OFF --no-tablespaces \
        --default-character-set=utf8mb4 \
        "${DB_DATABASE}" 2>"${work}/dump.err" | gzip -6 > "${work}/db.sql.gz" \
        || { sed -n '1,5p' "${work}/dump.err" >&2; die "mysqldump or gzip returned non-zero"; }
    rm -f "${work}/dump.err"

    stage verify-dump
    gzip -t "${work}/db.sql.gz" || die "db.sql.gz is not valid gzip"
    local dump_bytes dump_tables
    dump_bytes=$(stat -c%s "${work}/db.sql.gz")
    [ "${dump_bytes}" -ge 1024 ] || die "db.sql.gz is only ${dump_bytes} bytes"
    zcat "${work}/db.sql.gz" | tail -n 1 | grep -q '^-- Dump completed' \
        || die "dump has no completion line (truncated)"
    dump_tables=$(zcat "${work}/db.sql.gz" | grep -c '^CREATE TABLE ' || true)
    [ "${dump_tables}" -ge "${BACKUP_MIN_TABLES}" ] \
        || die "dump has ${dump_tables} tables, fewer than ${BACKUP_MIN_TABLES}"
    [ "${dump_tables}" -eq "${live_tables}" ] \
        || die "dump has ${dump_tables} tables, live database has ${live_tables}"
    log "dump OK: ${dump_bytes} bytes, ${dump_tables} tables"

    # 2. UPLOADED FILES ---------------------------------------------------------
    stage files
    local tar_rc=0
    tar -C "${FILES_ROOT}" -cf "${work}/files.tar" "${FILES_SUBDIR}" || tar_rc=$?
    # GNU tar: 1 = a file changed or vanished while being read (an upload or a
    # delete during the backup). The tar is still valid; anything else is not.
    if [ "${tar_rc}" -eq 1 ]; then
        log "WARNING: a file changed during archiving; the archive is still complete and is checked below"
    elif [ "${tar_rc}" -ne 0 ]; then
        die "tar returned ${tar_rc}"
    fi

    stage verify-files
    # One pass over the archive: proves it reads back end to end AND records
    # the checksum of every file exactly as archived, for the restore drill.
    # shellcheck disable=SC2016  # expanded by tar's own sh, per file
    tar -xf "${work}/files.tar" \
        --to-command='h=$(sha256sum | cut -d" " -f1); printf "%s  %s\n" "$h" "$TAR_FILENAME"' \
        > "${work}/files.sha256" || die "files.tar does not read back"
    local tar_files sum_files files_bytes
    tar_files=$(tar -tvf "${work}/files.tar" | grep -c '^-' || true)
    sum_files=$(wc -l < "${work}/files.sha256")
    [ "${tar_files}" -eq "${sum_files}" ] \
        || die "files.tar has ${tar_files} files but ${sum_files} checksums"
    files_bytes=$(stat -c%s "${work}/files.tar")
    log "files OK: ${tar_files} files, ${files_bytes} bytes"

    # 3. MANIFEST + CHECKSUMS ---------------------------------------------------
    stage manifest
    {
        echo "set_id=${set_id}"
        echo "created_utc=$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
        echo "system=${BACKUP_LABEL}"
        echo "database=${DB_DATABASE}"
        echo "mysql_server_version=${server_version}"
        echo "live_base_tables=${live_tables}"
        echo "dump_create_tables=${dump_tables}"
        echo "dump_bytes=${dump_bytes}"
        echo "files_source=storage/${FILES_SUBDIR}"
        echo "files_count=${tar_files}"
        echo "files_tar_bytes=${files_bytes}"
        echo "restic_version=$(restic version | head -n 1)"
    } > "${work}/MANIFEST.txt"
    (cd "${work}" && sha256sum db.sql.gz files.tar files.sha256 MANIFEST.txt > SHA256SUMS)

    stage verify-checksums
    (cd "${work}" && sha256sum -c --quiet SHA256SUMS) || die "SHA256SUMS does not verify"
    mv "${work}" "${final}"
    log "local set verified: ${final}"

    # 4. OFF-SERVER COPY --------------------------------------------------------
    stage offsite-open
    if ! restic cat config >/dev/null 2>&1; then
        log "no restic repository found, initialising ${RESTIC_REPOSITORY%%\?*}"
        restic init || die "could not open or initialise the restic repository"
    fi

    stage offsite-backup
    local snapshot
    snapshot=$(restic backup "${final}" --host "${RESTIC_HOST_TAG}" \
        --tag "${RESTIC_SET_TAG}" --tag "${set_id}" --json \
        | grep -o '"snapshot_id":"[0-9a-f]*"' | tail -n 1 | cut -d'"' -f4 || true)
    [ -n "${snapshot}" ] || die "restic backup reported no snapshot"
    log "snapshot ${snapshot:0:8} written"

    stage offsite-verify
    # Structure every night; on Sundays also re-read a sample of the stored
    # data, so over a few weeks every pack has been downloaded and checked.
    if [ "$(date -u +%u)" = "7" ]; then
        restic check --read-data-subset="${RESTIC_READ_SUBSET:-10%}" || die "restic check --read-data-subset failed"
    else
        restic check || die "restic check failed"
    fi
    # Read THIS snapshot back from the bucket and compare it with what we hold.
    local remote_sums local_sums remote_db local_db
    remote_sums=$(restic dump "${snapshot}" "${final}/SHA256SUMS" | sha256sum | cut -d' ' -f1)
    local_sums=$(sha256sum < "${final}/SHA256SUMS" | cut -d' ' -f1)
    [ "${remote_sums}" = "${local_sums}" ] || die "SHA256SUMS read back from the bucket differs"
    remote_db=$(restic dump "${snapshot}" "${final}/db.sql.gz" | sha256sum | cut -d' ' -f1)
    local_db=$(grep -E '[ *]db[.]sql[.]gz$' "${final}/SHA256SUMS" | cut -d' ' -f1)
    [ "${remote_db}" = "${local_db}" ] || die "db.sql.gz read back from the bucket differs"

    # SUCCESS: the only place last-success is written.
    stage record-success
    printf '%s %s %s\n' "$(date -u +%s)" "${set_id}" "${snapshot:0:8}" > "${LAST_SUCCESS_FILE}.tmp"
    mv "${LAST_SUCCESS_FILE}.tmp" "${LAST_SUCCESS_FILE}"
    log "SUCCESS: ${set_id} verified locally and off-server (snapshot ${snapshot:0:8})"

    # 5. RETENTION (after success: a failed prune never un-does a good night) --
    stage retention
    # --group-by host: every set has its own path and tag, so the default
    # grouping (host+paths) would treat each snapshot as its own series and
    # keep them all forever.
    if ! restic forget --host "${RESTIC_HOST_TAG}" --tag "${RESTIC_SET_TAG}" --group-by host \
            --keep-daily "${KEEP_DAILY}" --keep-weekly "${KEEP_WEEKLY}" \
            --keep-monthly "${KEEP_MONTHLY}" --prune; then
        log "WARNING: restic forget/prune failed; tonight's snapshot is safe"
        notify "${BACKUP_LABEL} backup WARNING: off-server retention (forget/prune) failed. Tonight's backup ${set_id} is verified. Check: docker compose logs --tail=100 backup" || true
    fi
    prune_local
}

prune_local() {
    local old
    # Newest BACKUP_LOCAL_KEEP verified sets stay; set ids sort by time.
    old=$(find "${SETS_DIR}" -mindepth 1 -maxdepth 1 -type d -name 'erp-*' | sort | head -n "-${BACKUP_LOCAL_KEEP}")
    if [ -n "${old}" ]; then
        printf '%s\n' "${old}" | while IFS= read -r dir; do rm -rf -- "${dir}"; log "removed old local set ${dir##*/}"; done
    fi
    # Dumps from the pre-2026-10 layout age out on their old schedule.
    [ -d "${BACKUP_ROOT}/daily" ] && find "${BACKUP_ROOT}/daily" -name 'erp-*.sql.gz' -mtime +14 -delete
    [ -d "${BACKUP_ROOT}/weekly" ] && find "${BACKUP_ROOT}/weekly" -name 'erp-*.sql.gz' -mtime +56 -delete
    return 0
}

# --- wrapper: lock, run in a strict subshell, alert on failure -------------------

acquire_lock() {
    if mkdir "${LOCK_DIR}" 2>/dev/null; then return 0; fi
    # A lock older than six hours belongs to a run that died.
    if [ -n "$(find "${LOCK_DIR}" -maxdepth 0 -mmin +360 2>/dev/null)" ]; then
        rm -rf "${LOCK_DIR}" && mkdir "${LOCK_DIR}" && return 0
    fi
    return 1
}

run_guarded() {
    mkdir -p "${SETS_DIR}" "${STATE_DIR}"
    if ! acquire_lock; then
        log "another backup run holds ${LOCK_DIR}; not starting a second one"
        return 1
    fi
    : > "${STAGE_FILE}"
    rm -f "${STATE_DIR}/.current-set"

    # ⚠ errexit is IGNORED inside anything that is part of `if`, `&&` or `||`,
    # subshells included. So the subshell is run plainly with -e switched off
    # out here, and its status read afterwards. (The same is why main never
    # calls this function from a conditional: see run_and_report.)
    local rc
    set +e
    # The ERR trap names the line of any command that fails without its own
    # die message, so a failure is never silent in the log or the alert.
    # shellcheck disable=SC2016  # LINENO must expand when the trap fires
    ( set -Eeuo pipefail; trap 'die "unexpected command failure at line ${LINENO}"' ERR; run_once )
    rc=$?
    set -e
    rmdir "${LOCK_DIR}" 2>/dev/null || rm -rf "${LOCK_DIR}"

    if [ "${rc}" -eq 0 ]; then
        rm -f "${STAGE_FILE}"
        if ! telegram_configured; then
            log "WARNING: backup succeeded but TELEGRAM_BOT_TOKEN / TELEGRAM_BACKUP_CHAT_ID are not set, so a FAILURE would not be alerted"
            return 3
        fi
        return 0
    fi

    local where set_id
    where=$(cat "${STAGE_FILE}" 2>/dev/null || echo unknown)
    set_id=$(cat "${STATE_DIR}/.current-set" 2>/dev/null || echo none)
    printf '%s %s %s\n' "$(date -u +%s)" "${set_id}" "${where}" > "${LAST_FAILURE_FILE}"
    notify "${BACKUP_LABEL} backup FAILED
stage: ${where}
set: ${set_id}
time: $(date -u +'%Y-%m-%d %H:%M UTC')
The last verified backup is unchanged. Check: docker compose logs --tail=100 backup" || true
    return 1
}

# ⚠ Never call run_guarded as `run_guarded || ...`: bash then ignores -e in
# EVERYTHING it runs, the strict subshell included, and a failed dump would
# carry on to "success".
run_and_report() {
    local rc
    set +e
    run_guarded
    rc=$?
    set -e
    log "run finished with status ${rc}"
}

seconds_until_next_run() {
    local now next
    now=$(date -u +%s)
    next=$(date -u -d "today ${BACKUP_HOUR_UTC}:00" +%s)
    [ "${next}" -gt "${now}" ] || next=$(date -u -d "tomorrow ${BACKUP_HOUR_UTC}:00" +%s)
    echo $(( next - now ))
}

main() {
    local mode=${1:-loop}
    case "${mode}" in
        once)
            run_guarded
            ;;
        loop)
            log "backup container started; nightly at ${BACKUP_HOUR_UTC}:00 UTC"
            telegram_configured || log "WARNING: TELEGRAM_BOT_TOKEN / TELEGRAM_BACKUP_CHAT_ID not set; failures will only be logged here"
            # Catch up after a restart only if the last good set is old, so a
            # redeploy does not trigger an extra full upload every time.
            local last
            last=$(last_success_epoch)
            if [ -z "${last}" ] || [ $(( $(date -u +%s) - last )) -gt 72000 ]; then
                log "no verified backup in the last 20 hours; first run in 5 minutes"
                sleep 300
                run_and_report
            fi
            while true; do
                sleep "$(seconds_until_next_run)"
                run_and_report
            done
            ;;
        *)
            echo "usage: $0 [loop|once]" >&2
            return 2
            ;;
    esac
}

main "$@"
