#!/bin/bash
# Staleness watcher for the ERP backup: alerts on Telegram when no VERIFIED
# backup (database + files + off-server snapshot) has completed within
# BACKUP_MAX_AGE_HOURS (default 26).
#
#   backup-watch.sh loop         the container's normal mode, checks hourly
#   backup-watch.sh check        one check now; exit 0 fresh, 1 stale
#   backup-watch.sh test-alert   send a harmless test message (owner setup)
#
# ⚠ WHY A SEPARATE CONTAINER. backup.sh alerts on its own failures, but a
# backup container that has died, hangs, or never starts sends nothing at all.
# Only something outside it can notice silence.
#
# ⚠⚠ WITHOUT TELEGRAM THIS CONTAINER EXITS NON-ZERO, on purpose. A watcher that
# cannot tell anyone is not a watcher; `docker compose ps` then shows it
# restarting, which is visible, rather than "Up" and useless.

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=backup-lib.sh
. "${SCRIPT_DIR}/backup-lib.sh"

MAX_AGE_HOURS=${BACKUP_MAX_AGE_HOURS:-26}
REALERT_HOURS=${BACKUP_REALERT_HOURS:-6}
CHECK_EVERY=3600
STARTED=$(date -u +%s)

# Prints the age in hours of the last verified backup, or "never".
age_hours() {
    local last
    last=$(last_success_epoch)
    if [ -z "${last}" ]; then echo never; return; fi
    echo $(( ($(date -u +%s) - last) / 3600 ))
}

# 0 = fresh, 1 = stale. "never" counts from this container's start, so a fresh
# install gets one full window before the first alert.
is_stale() {
    local age
    age=$(age_hours)
    if [ "${age}" = never ]; then
        [ $(( ($(date -u +%s) - STARTED) / 3600 )) -ge "${MAX_AGE_HOURS}" ]
        return
    fi
    [ "${age}" -ge "${MAX_AGE_HOURS}" ]
}

stale_message() {
    local age last_set
    age=$(age_hours)
    last_set=$( [ -s "${LAST_SUCCESS_FILE}" ] && cut -d' ' -f2 "${LAST_SUCCESS_FILE}" || echo none )
    printf '%s backup STALE\nno verified backup for: %s hours (limit %s)\nlast verified set: %s\ntime: %s\nCheck: docker compose ps backup; docker compose logs --tail=100 backup' \
        "${BACKUP_LABEL}" "${age}" "${MAX_AGE_HOURS}" "${last_set}" "$(date -u +'%Y-%m-%d %H:%M UTC')"
}

require_telegram() {
    if ! telegram_configured; then
        log "FATAL: TELEGRAM_BOT_TOKEN / TELEGRAM_BACKUP_CHAT_ID are not set; a stale backup could not be reported. Exiting non-zero."
        exit 3
    fi
}

main() {
    local mode=${1:-loop}
    case "${mode}" in
        test-alert)
            require_telegram
            notify "${BACKUP_LABEL} backup alerts: test message, no action needed. Last verified backup age: $(age_hours) hours."
            ;;
        check)
            require_telegram
            STARTED=0   # a one-off check has no grace window
            if is_stale; then
                log "STALE: last verified backup age $(age_hours) hours"
                notify "$(stale_message)" || true
                return 1
            fi
            log "fresh: last verified backup age $(age_hours) hours"
            ;;
        loop)
            require_telegram
            log "backup watcher started; alerts when no verified backup for ${MAX_AGE_HOURS} h"
            local alerted_at=0 now
            while true; do
                now=$(date -u +%s)
                if is_stale; then
                    if [ $(( now - alerted_at )) -ge $(( REALERT_HOURS * 3600 )) ]; then
                        log "STALE: last verified backup age $(age_hours) hours; alerting"
                        if notify "$(stale_message)"; then alerted_at=${now}; fi
                    fi
                elif [ "${alerted_at}" -ne 0 ]; then
                    notify "${BACKUP_LABEL} backup RECOVERED: a verified backup completed $(age_hours) hours ago." || true
                    alerted_at=0
                fi
                sleep "${CHECK_EVERY}"
            done
            ;;
        *)
            echo "usage: $0 [loop|check|test-alert]" >&2
            return 2
            ;;
    esac
}

main "$@"
