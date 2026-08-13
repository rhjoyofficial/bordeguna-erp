#!/bin/bash
# Step 8: restore an OLD backup and bring it up to the current code.
#
# ⚠⚠ THIS IS THE REALISTIC DISASTER, not the easy one. Losing the box on a
# Tuesday means restoring Sunday's weekly dump, which was taken BEFORE the
# migrations the running image now expects. A drill that only ever restores last
# night's dump never exercises `migrate --force` against real historical data,
# and that is exactly where a restore fails at 3am.
set -uo pipefail

cd /opt/bordeguna-erp
ROOT_PW=$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)
APP_USER=$(grep '^DB_USERNAME=' .env | cut -d= -f2-)
LIVE_DB=$(grep '^DB_DATABASE=' .env | cut -d= -f2-)
SCRATCH=bordeguna_restore_old
# Oldest weekly dump unless one is named on the command line - the further back
# it goes, the more migrations it has to survive, which is the point.
DUMP=${1:-$(ls -tr /root/backups/bordeguna-erp/weekly/erp-*.sql.gz | head -1)}
NET=bordeguna-erp_internal

myroot() { docker exec -i bordeguna-erp-db-1 mysql -uroot -p"${ROOT_PW}" "$@" 2>&1 | grep -v 'Using a password'; }

run() {
    docker run --rm --network "${NET}" --env-file /opt/bordeguna-erp/.env \
        -e DB_CONNECTION=mysql -e DB_HOST=db -e DB_DATABASE="${SCRATCH}" \
        --entrypoint sh bordeguna-erp:latest -c "php artisan config:clear >/dev/null 2>&1; $1"
}

echo "=== 8a. THE OLDEST WEEKLY DUMP WE STILL HOLD ==="
ls -lh "${DUMP}"
gzip -t "${DUMP}" && echo "gzip integrity OK"

myroot -e "DROP DATABASE IF EXISTS ${SCRATCH}; CREATE DATABASE ${SCRATCH} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ${SCRATCH}.* TO '${APP_USER}'@'%'; FLUSH PRIVILEGES;"

START=$(date -u +%s)
zcat "${DUMP}" | docker exec -i bordeguna-erp-db-1 mysql -uroot -p"${ROOT_PW}" "${SCRATCH}" 2>/tmp/old.err
echo "restore exit: $? in $(( $(date -u +%s) - START ))s"
grep -v 'Using a password' /tmp/old.err | head -10

echo
echo "=== 8b. HOW FAR BEHIND IS IT? ==="
run "php artisan tinker --execute=\"echo 'db: '.DB::connection()->getDatabaseName().PHP_EOL;\""
echo "tables:    $(myroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${SCRATCH}';")"
echo "migrations recorded: $(myroot -N "${SCRATCH}" -e 'SELECT COUNT(*) FROM migrations;')"
echo "PENDING BEFORE MIGRATE: $(run 'php artisan migrate:status 2>&1 | grep -c Pending')"

echo
echo "=== 8c. BRING IT UP TO THE CURRENT CODE ==="
START=$(date -u +%s)
run "php artisan migrate --force 2>&1 | tail -8"
echo "MIGRATE_SECONDS=$(( $(date -u +%s) - START ))"
echo "PENDING AFTER MIGRATE: $(run 'php artisan migrate:status 2>&1 | grep -c Pending')"
echo "tables now: $(myroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${SCRATCH}';")"

echo
echo "=== 8d. IS IT A WORKING SYSTEM AFTERWARDS? ==="
run "php artisan accounting:verify-ledger 2>&1 | tail -2"
run "php artisan inventory:verify-ledger 2>&1 | tail -2"
run "php artisan accounting:reconcile 2>&1 | tail -5"
run "php artisan tinker --execute=\"
  echo 'employees: '.App\\\\Modules\\\\Hrm\\\\Models\\\\Employee::withoutGlobalScopes()->count().PHP_EOL;
  echo 'punches: '.App\\\\Modules\\\\Hrm\\\\Models\\\\AttendancePunch::withoutGlobalScopes()->count().PHP_EOL;
  echo 'accounts: '.App\\\\Modules\\\\Accounting\\\\Models\\\\Account::count().PHP_EOL;
\""

echo
echo "=== 8e. WHAT THE 11 DAYS COST (old restored vs live today) ==="
for t in accounts account_mappings attendance_punches daily_attendances products suppliers customers leave_ledger_entries; do
  OLD=$(myroot -N "${SCRATCH}" -e "SELECT COUNT(*) FROM ${t};" 2>/dev/null || echo '-')
  NEW=$(myroot -N "${LIVE_DB}" -e "SELECT COUNT(*) FROM ${t};" 2>/dev/null || echo '-')
  printf "  %-22s restored=%-6s live=%-6s\n" "$t" "$OLD" "$NEW"
done

echo
echo "=== 8f. LIVE UNTOUCHED ==="
myroot "${LIVE_DB}" -e "SELECT DATABASE() db, (SELECT COUNT(*) FROM accounts) accounts, (SELECT COUNT(*) FROM attendance_punches) punches;"

echo
echo "=== OLD-BACKUP DRILL COMPLETE ==="
