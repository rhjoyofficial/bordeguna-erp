#!/bin/bash
# shellcheck disable=SC2034  # variables are used by the scripts that source this
# Shared helpers for backup.sh and backup-watch.sh. Sourced, never run.
#
# ⚠ ALERTS CARRY NO SECRETS AND NO BUSINESS DATA. A message names the stage
# that failed, the backup set id and the time. Never a password, a token, a
# table row, a file name from storage, or raw tool output: tool output can echo
# anything, and a Telegram chat is not a place for payroll data.

BACKUP_ROOT=${BACKUP_ROOT:-/backups}
SETS_DIR="${BACKUP_ROOT}/sets"
STATE_DIR="${BACKUP_ROOT}/state"
LAST_SUCCESS_FILE="${STATE_DIR}/last-success"
LAST_FAILURE_FILE="${STATE_DIR}/last-failure"
BACKUP_LABEL=${BACKUP_LABEL:-"Bor de Guna ERP"}

log() { echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] $*"; }

telegram_configured() {
    [ -n "${TELEGRAM_BOT_TOKEN:-}" ] && [ -n "${TELEGRAM_BACKUP_CHAT_ID:-}" ]
}

# notify "message"  ->  0 delivered, 3 not configured, 4 delivery failed.
# A non-zero return is deliberate: a caller that ignores it is choosing to.
notify() {
    local text="$1"
    if ! telegram_configured; then
        log "ALERT NOT DELIVERED: TELEGRAM_BOT_TOKEN / TELEGRAM_BACKUP_CHAT_ID are not set. Message: ${text}"
        return 3
    fi
    # The token goes to curl through a config on stdin, so it never appears in
    # the process list or in curl's own error output.
    if printf 'url = "https://api.telegram.org/bot%s/sendMessage"\n' "${TELEGRAM_BOT_TOKEN}" \
        | curl -sS -f --max-time 20 --retry 3 --retry-delay 5 -o /dev/null -K - \
            --data-urlencode "chat_id=${TELEGRAM_BACKUP_CHAT_ID}" \
            --data-urlencode "text=${text}"; then
        log "alert delivered"
        return 0
    fi
    log "ALERT NOT DELIVERED: the Telegram API call failed. Message: ${text}"
    return 4
}

# Epoch seconds of the last fully verified backup, or empty.
last_success_epoch() {
    if [ -s "${LAST_SUCCESS_FILE}" ]; then
        cut -d' ' -f1 "${LAST_SUCCESS_FILE}"
    fi
}
