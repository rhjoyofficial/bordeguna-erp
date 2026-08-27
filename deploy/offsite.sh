#!/bin/sh
# Offsite replication of the Bor de Guna ERP backups, to Backblaze B2 via restic.
#
# The nightly `backup` container writes dumps to /root/backups on the VPS. Those
# sit on the SAME DISK as the database, so they protect against a bad migration
# and against nothing else — losing the VPS loses both copies at once. Payroll
# and salary data is the least replaceable data in the company. This container
# closes that gap.
#
# ⚠⚠ WHY restic AND NOT `rclone copy`. restic encrypts client-side, so the
# payroll data is already unreadable before it reaches anyone else's disk; it
# deduplicates, so twenty years of near-identical dumps cost almost nothing; and
# `restic check` can actually verify a backup is restorable. rclone copies
# files. It cannot tell you the copy is sound, and it would put readable salary
# data in someone else's bucket.
#
# ⚠⚠ RESTIC_PASSWORD IS THE ONLY KEY TO THIS DATA. Backblaze cannot recover it,
# and neither can anybody else. If it is lost, every offsite backup is
# permanently unreadable. It must live in a password manager OFF this server,
# not only in the .env beside it.

set -u

: "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY is not set}"
: "${RESTIC_PASSWORD:?RESTIC_PASSWORD is not set}"
: "${B2_ACCOUNT_ID:?B2_ACCOUNT_ID is not set}"
: "${B2_ACCOUNT_KEY:?B2_ACCOUNT_KEY is not set}"

SOURCE=/backups
# Long, because payroll records are kept for years and the whole archive is a
# few megabytes. Storage is not the constraint here; regret is.
KEEP_DAILY=14
KEEP_WEEKLY=8
KEEP_MONTHLY=24
KEEP_YEARLY=7

log() { echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] $*"; }

ensure_repo() {
    if restic cat config >/dev/null 2>&1; then
        return 0
    fi

    log "no repository at ${RESTIC_REPOSITORY}, initialising"
    restic init || return 1
}

run_backup() {
    ensure_repo || { log "FAILED: could not open or create the repository"; return 1; }

    # ⚠ Refuse to back up an empty source. An empty snapshot is not a harmless
    # no-op: paired with `forget --prune` below it would age out the real
    # snapshots and leave a repository full of nothing, reporting success the
    # whole time. This is the failure mode that looks healthiest.
    if [ -z "$(ls -A "${SOURCE}" 2>/dev/null)" ]; then
        log "FAILED: ${SOURCE} is empty, refusing to snapshot"
        return 1
    fi

    if ! restic backup "${SOURCE}" --tag nightly --host bordeguna-erp; then
        log "FAILED: restic backup returned non-zero"
        return 1
    fi

    log "snapshot written"

    if ! restic forget \
            --tag nightly \
            --keep-daily "${KEEP_DAILY}" \
            --keep-weekly "${KEEP_WEEKLY}" \
            --keep-monthly "${KEEP_MONTHLY}" \
            --keep-yearly "${KEEP_YEARLY}" \
            --prune; then
        log "WARNING: forget/prune failed; the snapshot itself is safe"
    fi

    # Verifies repository structure every night, and re-reads a sample of the
    # actual data weekly. A backup nobody has ever read back is a hope, not a
    # backup — and the full read is limited to Sundays so the nightly run stays
    # cheap as the archive grows.
    if [ "$(date -u +%u)" = "7" ]; then
        log "weekly verification: checking 100% of pack data"
        restic check --read-data || log "WARNING: restic check --read-data FAILED"
    else
        restic check || log "WARNING: restic check FAILED"
    fi

    log "OK"
}

log "offsite container started; first run in 10 minutes, then daily at ~02:00 UTC"

# After the 01:00 UTC dump, and after the stack has settled on a restart.
sleep 600
run_backup || log "initial offsite backup failed"

while true; do
    now=$(date -u +%s)
    next=$(date -u -d 'tomorrow 02:00' +%s 2>/dev/null || date -u -d '+1 day 02:00' +%s)
    sleep $(( next - now ))

    run_backup || log "scheduled offsite backup failed"
done
