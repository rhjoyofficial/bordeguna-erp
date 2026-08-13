#!/bin/bash
# Step 7 of the restore drill: prove the APPLICATION runs on the restored data.
#
# ⚠⚠ THIS IS THE STEP MOST RESTORE DRILLS SKIP, and the one that decides whether
# a backup is actually a recovery. A dump that loads is not a system that works:
# it can be missing a migration the running code expects, and nothing in the SQL
# layer would say so.
#
# ⚠⚠ A THROWAWAY CONTAINER, NOT THE RUNNING ONE. The running app has its config
# CACHED, so `-e DB_DATABASE=` is silently ignored and every command would hit
# the LIVE database while appearing to test the scratch one. This container runs
# `config:clear` first, and prints DATABASE() before doing anything else.
set -uo pipefail

NET=$(docker inspect bordeguna-erp-app-1 -f '{{json .NetworkSettings.Networks}}' | tr ',' '\n' | grep -o '"[a-z0-9_-]*internal[a-z0-9_-]*"' | head -1 | tr -d '"')
echo "network: ${NET}"

SCRATCH=bordeguna_restore_drill

run() {
    docker run --rm \
        --network "${NET}" \
        --env-file /opt/bordeguna-erp/.env \
        -e DB_CONNECTION=mysql \
        -e DB_HOST=db \
        -e DB_DATABASE="${SCRATCH}" \
        --entrypoint sh \
        bordeguna-erp:latest -c "php artisan config:clear >/dev/null 2>&1; $1"
}

echo
echo "=== 7a. PROVE WHICH DATABASE THIS CONTAINER IS ACTUALLY ON ==="
# ⚠ Never trust the env var. Ask the server.
run "php artisan tinker --execute=\"echo DB::connection()->getDatabaseName().' | '.DB::selectOne('select @@hostname h, DATABASE() d')->d.PHP_EOL;\""

echo
echo "=== 7b. IS THE RESTORED SCHEMA WHAT THE CODE EXPECTS? ==="
run "php artisan migrate:status 2>&1 | tail -4"
echo "pending migrations:"
run "php artisan migrate:status 2>&1 | grep -c Pending"

echo
echo "=== 7c. DO THE INTEGRITY ENGINES PASS ON RESTORED DATA? ==="
run "php artisan accounting:verify-ledger 2>&1 | tail -3"
run "php artisan inventory:verify-ledger 2>&1 | tail -3"
run "php artisan accounting:reconcile 2>&1 | tail -6"

echo
echo "=== 7d. CAN THE APP READ REAL BUSINESS DATA OUT OF IT? ==="
run "php artisan tinker --execute=\"
  \\\$b = App\\\\Modules\\\\Foundation\\\\Models\\\\Business::withoutGlobalScopes()->orderBy('id')->pluck('name')->implode(', ');
  echo 'businesses: '.\\\$b.PHP_EOL;
  echo 'employees: '.App\\\\Modules\\\\Hrm\\\\Models\\\\Employee::withoutGlobalScopes()->count().PHP_EOL;
  echo 'punches: '.App\\\\Modules\\\\Hrm\\\\Models\\\\AttendancePunch::withoutGlobalScopes()->count().PHP_EOL;
  echo 'accounts: '.App\\\\Modules\\\\Accounting\\\\Models\\\\Account::count().PHP_EOL;
  echo 'users: '.App\\\\Models\\\\User::count().PHP_EOL;
\""

echo
echo "=== 7e. LIVE DATABASE STILL UNTOUCHED ==="
cd /opt/bordeguna-erp
ROOT_PW=$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)
LIVE_DB=$(grep '^DB_DATABASE=' .env | cut -d= -f2-)
docker exec -i bordeguna-erp-db-1 mysql -uroot -p"${ROOT_PW}" "${LIVE_DB}" \
  -e "SELECT DATABASE() db, (SELECT COUNT(*) FROM accounts) accounts, (SELECT COUNT(*) FROM attendance_punches) punches, (SELECT COUNT(*) FROM journals) journals;" 2>&1 | grep -v 'Using a password'

echo
echo "=== APP CHECK COMPLETE ==="
