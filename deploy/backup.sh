#!/bin/bash
# Nightly database backup for the Bor de Guna ERP.
#
# Runs as its own long-lived container with an internal sleep loop, for the same
# reason the scheduler does: there is no PHP and no useful cron on this host, and
# a container that restarts with the stack is easier to see in `docker ps` than a
# crontab entry nobody remembers.
#
# ⚠⚠ THIS IS AN ON-SERVER BACKUP ONLY. It protects against a bad migration, a
# dropped table or a corrupt row. It does NOT protect against losing the VPS,
# because the dumps sit on the same disk as the database. Payroll and salary
# data is the least replaceable data in the company, so an offsite copy is a
# real outstanding item, not a nicety.

set -uo pipefail

BACKUP_DIR=/backups
RETAIN_DAILY=14
RETAIN_WEEKLY=8

log() { echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] $*"; }

take_backup() {
    local stamp target
    stamp=$(date -u +'%Y%m%d-%H%M%S')
    target="${BACKUP_DIR}/daily/erp-${stamp}.sql.gz"

    mkdir -p "${BACKUP_DIR}/daily" "${BACKUP_DIR}/weekly"

    # --single-transaction so the dump is consistent without locking the
    # application out mid-payroll. --routines/--triggers because a schema-only
    # restore that silently drops them is not a restore.
    if mysqldump \
            --host=db \
            --user=root \
            --password="${DB_ROOT_PASSWORD}" \
            --single-transaction \
            --quick \
            --routines \
            --triggers \
            --default-character-set=utf8mb4 \
            "${DB_DATABASE}" 2>/tmp/dump.err | gzip -9 > "${target}"; then

        # ⚠ A zero-length or tiny gzip means mysqldump failed while gzip
        # succeeded — the pipeline's exit status alone would call that a pass.
        local size
        size=$(stat -c%s "${target}" 2>/dev/null || echo 0)

        if [ "${size}" -lt 1024 ]; then
            log "FAILED: dump is only ${size} bytes, treating as failure"
            cat /tmp/dump.err >&2 || true
            mv "${target}" "${target}.suspect"
            return 1
        fi

        log "OK: ${target} (${size} bytes)"

        # Sunday's dump is also kept as the weekly copy, so a fortnight-old
        # mistake is still recoverable after the daily window has rolled.
        if [ "$(date -u +%u)" = "7" ]; then
            cp "${target}" "${BACKUP_DIR}/weekly/erp-$(date -u +'%Y%m%d').sql.gz"
        fi
    else
        log "FAILED: mysqldump returned non-zero"
        cat /tmp/dump.err >&2 || true
        rm -f "${target}"
        return 1
    fi

    find "${BACKUP_DIR}/daily" -name 'erp-*.sql.gz' -mtime "+${RETAIN_DAILY}" -delete
    find "${BACKUP_DIR}/weekly" -name 'erp-*.sql.gz' -mtime "+$((RETAIN_WEEKLY * 7))" -delete
}

log "backup container started; first dump in 5 minutes, then daily at ~01:00 UTC"

# Let the database settle after a stack start before the first dump.
sleep 300
take_backup || log "initial backup failed"

while true; do
    # 01:00 UTC is 07:00 Dhaka: after the 23:00 Dhaka attendance run has
    # finished, so a dump captures a settled day rather than one mid-process.
    now=$(date -u +%s)
    next=$(date -u -d 'tomorrow 01:00' +%s)
    sleep $(( next - now ))

    take_backup || log "scheduled backup failed"
done
