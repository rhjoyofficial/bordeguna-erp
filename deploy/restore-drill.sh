#!/bin/bash
# Backup RESTORE DRILL for the Bor de Guna ERP.
#
# ⚠⚠ READ-ONLY WITH RESPECT TO THE LIVE DATABASE. Everything below happens in a
# scratch database. The only statements that name bordeguna_erp are SELECTs.
#
# ⚠ THIS FILE LIVES IN THE REPO ON PURPOSE. A restore runbook whose scripts exist
# only on the machine you have just lost is not a runbook. Copy it to the VPS
# alongside fingerprint.sql and run it there:
#   scp deploy/restore-drill.sh deploy/fingerprint.sql vps:/root/
#
# Usage:  ./restore-drill.sh [path-to-dump.sql.gz]
# Then:   ./restore-appcheck.sh     (the step that actually decides the question)
# Always: drop the scratch database afterwards - see BACKUP-RESTORE-RUNBOOK.md.
set -uo pipefail

cd /opt/bordeguna-erp

ROOT_PW=$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)
APP_USER=$(grep '^DB_USERNAME=' .env | cut -d= -f2-)
LIVE_DB=$(grep '^DB_DATABASE=' .env | cut -d= -f2-)
SCRATCH=bordeguna_restore_drill
# Newest nightly dump unless one is named on the command line.
DUMP=${1:-$(ls -t /root/backups/bordeguna-erp/daily/erp-*.sql.gz | head -1)}

myroot() { docker exec -i bordeguna-erp-db-1 mysql -uroot -p"${ROOT_PW}" "$@" 2>&1 | grep -v 'Using a password'; }

echo "=== 0. WHICH SERVER, WHICH DATABASE ==="
myroot -e "SELECT @@hostname AS host, @@version AS version;"
echo "dump: ${DUMP}"
ls -lh "${DUMP}"

echo
echo "=== 1. THE ARCHIVE IS INTACT BEFORE WE TRUST IT ==="
gzip -t "${DUMP}" && echo "gzip integrity OK"
echo "CREATE TABLE statements in the dump: $(zcat "${DUMP}" | grep -c 'CREATE TABLE')"
echo "dump header:"
zcat "${DUMP}" | head -3 | sed 's/^/  /'

echo
echo "=== 2. SCRATCH DATABASE ==="
myroot -e "DROP DATABASE IF EXISTS ${SCRATCH}; CREATE DATABASE ${SCRATCH} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ${SCRATCH}.* TO '${APP_USER}'@'%'; FLUSH PRIVILEGES;"
myroot -e "SHOW DATABASES;" | sed 's/^/  /'

echo
echo "=== 3. RESTORE (timed) ==="
START=$(date -u +%s)
zcat "${DUMP}" | docker exec -i bordeguna-erp-db-1 mysql -uroot -p"${ROOT_PW}" "${SCRATCH}" 2>/tmp/restore.err
RC=$?
END=$(date -u +%s)
grep -v 'Using a password' /tmp/restore.err | head -20
echo "restore exit code: ${RC}"
echo "RESTORE_SECONDS=$(( END - START ))"

echo
echo "=== 4. FINGERPRINT OF THE RESTORED DATABASE ==="
docker exec -i bordeguna-erp-db-1 mysql -uroot -p"${ROOT_PW}" "${SCRATCH}" < /root/fingerprint.sql 2>&1 \
  | grep -v 'Using a password' > /root/fingerprint-restored.txt
cat /root/fingerprint-restored.txt

echo
echo "=== 5. DIFF AGAINST LIVE (the whole point) ==="
# ⚠ The 'db' row differs by design - it names the database being read.
if diff <(grep -v '^db	' /root/fingerprint-live.txt) <(grep -v '^db	' /root/fingerprint-restored.txt); then
    echo "IDENTICAL: every table count, hash, FK count and schema object matches live."
else
    echo "DIFFERENCES ABOVE - investigate before trusting this backup."
fi

echo
echo "=== 6. LIVE DATABASE UNTOUCHED ==="
docker exec -i bordeguna-erp-db-1 mysql -uroot -p"${ROOT_PW}" "${LIVE_DB}" \
  -e "SELECT DATABASE() AS db, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()) AS tables, (SELECT COUNT(*) FROM accounts) AS accounts, (SELECT COUNT(*) FROM attendance_punches) AS punches;" 2>&1 | grep -v 'Using a password'

echo
echo "=== DRILL BODY COMPLETE - scratch database ${SCRATCH} left in place for the app check ==="
