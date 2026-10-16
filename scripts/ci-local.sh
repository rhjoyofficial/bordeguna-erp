#!/usr/bin/env bash
# Local CI gate for the Bor de Guna ERP (Windows 11 + Git Bash, Laragon MySQL).
#
# Why: GitHub Actions is blocked by billing on the owner's account, so nothing
# runs on push. This script runs the same jobs as .github/workflows/lint.yml and
# .github/workflows/tests.yml on this laptop, against a clean checkout of ONE
# commit, and leaves a stamp that deploy-release.sh checks before it will deploy.
# See docs/LOCAL-CI.md.
#
# Usage:
#   bash scripts/ci-local.sh <sha|branch> [--full-lint] [--skip-mysql]
#
#   --full-lint   Pint and Prettier check every file (lint.yml "full" mode).
#                 Default: only the files changed since merge-base(<sha>, origin/master),
#                 or the last commit when <sha> is already on master (lint.yml push mode).
#   --skip-mysql  Skip the MySQL job. The run then writes <sha>.partial, NOT <sha>.ok,
#                 and deploy-release.sh will refuse it.
#
# Output (CI_STAMPS, default H:/WORK/BorDeGuna/ci-stamps):
#   <sha>.log      everything
#   <sha>.ok       all jobs green (deployable)
#   <sha>.partial  green but --skip-mysql (not deployable)
#   <sha>.fail     failing step + last 80 lines of the log; exit code non-zero
#
# ⚠ 6 GB laptop: tests run with --parallel --processes=2, and a lock stops two
#   gate runs at once. Do not run another test suite while this runs.
# ⚠ node_modules in the run worktree is a Windows JUNCTION to the main checkout's
#   node_modules. It is removed with [System.IO.Directory]::Delete (junction only)
#   BEFORE the worktree is removed. Never rm -rf a folder that still holds it.
set -uo pipefail

# ---------------------------------------------------------------- settings
CI_WT_ROOT=${CI_WT_ROOT:-/h/WORK/BorDeGuna/erp-wt}
CI_STAMPS=${CI_STAMPS:-/h/WORK/BorDeGuna/ci-stamps}
CI_PROCESSES=${CI_PROCESSES:-2}
MYSQL_BIN=${MYSQL_BIN:-/c/laragon/bin/mysql/mysql-8.4.6-winx64/bin}
MYSQL_DATADIR=${MYSQL_DATADIR:-C:/laragon/data/mysql-8.4}
CI_MYSQL_HOST=${CI_MYSQL_HOST:-127.0.0.1}
CI_MYSQL_PORT=${CI_MYSQL_PORT:-3306}
CI_MYSQL_USER=${CI_MYSQL_USER:-root}
CI_MYSQL_PASSWORD=${CI_MYSQL_PASSWORD:-}
CI_MYSQL_DB=${CI_MYSQL_DB:-erp_ci_local}
RUN_WT="$CI_WT_ROOT/ci-run"

usage() { sed -n '9,23p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

REF=""; FULL_LINT=0; SKIP_MYSQL=0
for a in "$@"; do
    case "$a" in
        --full-lint) FULL_LINT=1 ;;
        --skip-mysql) SKIP_MYSQL=1 ;;
        -h|--help) usage ;;
        -*) echo "unknown option: $a"; usage ;;
        *) [ -z "$REF" ] && REF=$a || { echo "only one <sha|branch> please"; usage; } ;;
    esac
done
[ -n "$REF" ] || usage

# A stray DB_* or APP_* variable in the calling shell would silently change
# which database the SQLite job uses. CI starts from a clean environment; so do we.
unset DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_URL DB_SOCKET \
      APP_ENV APP_KEY APP_TIMEZONE APP_DEBUG

# The repository this script belongs to (works from any worktree).
REPO=$(cd "$(git -C "$(dirname "$0")" rev-parse --git-common-dir)/.." && pwd)
MAIN_NODE_MODULES="$REPO/node_modules"
mkdir -p "$CI_STAMPS" "$CI_WT_ROOT"

# ---------------------------------------------------------------- resolve the commit
git -C "$REPO" fetch -q origin || { echo "git fetch failed"; exit 1; }
if git -C "$REPO" rev-parse -q --verify "origin/$REF^{commit}" >/dev/null 2>&1; then
    SHA=$(git -C "$REPO" rev-parse "origin/$REF^{commit}")     # a pushed branch: test what is pushed
elif git -C "$REPO" rev-parse -q --verify "$REF^{commit}" >/dev/null 2>&1; then
    SHA=$(git -C "$REPO" rev-parse "$REF^{commit}")
else
    echo "cannot resolve '$REF' to a commit"; exit 1
fi
SHORT=${SHA:0:8}
LOG="$CI_STAMPS/$SHA.log"
STEPDIR="$CI_STAMPS/.steps-$SHA"

# ---------------------------------------------------------------- one run at a time
LOCK="$CI_STAMPS/.lock"
if ! mkdir "$LOCK" 2>/dev/null; then
    other=$(cat "$LOCK/pid" 2>/dev/null || echo "?")
    if [ "$other" != "?" ] && kill -0 "$other" 2>/dev/null; then
        echo "another gate run is active (pid $other, $(cat "$LOCK/what" 2>/dev/null)). One at a time on this laptop."
        exit 3
    fi
    echo "removing a stale lock (pid $other is gone)"; rm -rf "$LOCK"; mkdir "$LOCK"
fi
echo $$ > "$LOCK/pid"; echo "$SHA" > "$LOCK/what"

rm -f "$CI_STAMPS/$SHA.ok" "$CI_STAMPS/$SHA.partial" "$CI_STAMPS/$SHA.fail" "$LOG"
rm -rf "$STEPDIR"; mkdir -p "$STEPDIR"
exec > >(tee -a "$LOG") 2>&1

T_START=$(date +%s)
CURRENT_STEP="setup"
FINISHED=0
declare -A DUR=() PASSED=() FAILED=() TESTLINE=()

# ---------------------------------------------------------------- junction-safe worktree removal
# Prints JUNCTION, DIR or NONE for a Windows path.
path_kind() {
    powershell.exe -NoProfile -NonInteractive -Command "\$p='$(cygpath -w "$1")'; if (-not (Test-Path -LiteralPath \$p)) { 'NONE' } else { \$i = Get-Item -LiteralPath \$p -Force; if (\$i.Attributes -band [IO.FileAttributes]::ReparsePoint) { 'JUNCTION' } else { 'DIR' } }" | tr -d '\r'
}

remove_run_worktree() {
    local nm="$RUN_WT/node_modules" kind
    kind=$(path_kind "$nm")
    if [ "$kind" = "JUNCTION" ]; then
        # Deletes the junction itself, never the real node_modules it points to.
        powershell.exe -NoProfile -NonInteractive -Command "[System.IO.Directory]::Delete('$(cygpath -w "$nm")')"
        kind=$(path_kind "$nm")
    fi
    # Only NONE (no node_modules) or DIR (a real npm ci copy) are safe to delete.
    # Anything else, including PowerShell failing to answer, stops here.
    case "$kind" in
        NONE|DIR) ;;
        *) echo "!! node_modules in $RUN_WT is '$kind' (still a junction or unknown); NOT removing the worktree"; return 1 ;;
    esac
    git -C "$REPO" worktree remove --force "$RUN_WT" 2>/dev/null || true
    # Leftover files (a locked file, or a folder that was never registered).
    if [ -e "$RUN_WT" ]; then
        kind=$(path_kind "$nm")
        case "$kind" in NONE|DIR) rm -rf "$RUN_WT" ;; *) echo "!! node_modules is '$kind'; stop"; return 1 ;; esac
    fi
    git -C "$REPO" worktree prune
}

# ---------------------------------------------------------------- stamps
fmt() { local s=$1; printf '%dm%02ds' $((s/60)) $((s%60)); }

job_line() {   # job_line <key> <label>
    local k=$1
    if [ -n "${DUR[$k]:-}" ]; then
        if [ -n "${TESTLINE[$k]:-}" ]; then
            echo "$2: passed=${PASSED[$k]} failed=${FAILED[$k]} duration=$(fmt "${DUR[$k]}")  [Tests: ${TESTLINE[$k]}]"
        else
            echo "$2: ok duration=$(fmt "${DUR[$k]}")"
        fi
    else
        echo "$2: not run"
    fi
}

write_summary() {   # write_summary <file> <result>
    {
        echo "result=$2"
        echo "sha=$SHA"
        echo "ref=$REF"
        echo "branches=$(git -C "$REPO" branch -r --contains "$SHA" 2>/dev/null | sed 's/^ *//' | grep -v HEAD | tr '\n' ' ')"
        echo "date=$(date -Iseconds)"
        echo "gate=$([ $SKIP_MYSQL = 1 ] && echo 'lint+sqlite (mysql SKIPPED)' || echo 'lint+sqlite+mysql')"
        echo "php=$(php -r 'echo PHP_VERSION;') node=$(node -v) mysql=${MYSQL_VERSION:-n/a} processes=$CI_PROCESSES"
        echo "lint: $(grep -m1 '^lint mode' "$STEPDIR/lint.out" 2>/dev/null || echo 'not run')"
        job_line install "install (composer, node_modules, .env)"
        job_line lint    "lint (pint, prettier, eslint)"
        job_line build   "build (npm run build)"
        job_line sqlite  "tests sqlite"
        job_line migrate "mysql migrate:fresh"
        job_line mysql   "tests mysql"
        echo "total=$(fmt $(( $(date +%s) - T_START )))"
        echo "log=$LOG"
    } > "$1"
}

fail() {   # fail <step> <rc>
    FINISHED=1
    local f="$CI_STAMPS/$SHA.fail"
    write_summary "$f" "FAIL"
    {
        echo "failed_step=$1 (exit $2)"
        echo "---- last 80 lines of the log ----"
        tail -n 80 "$LOG" | sed 's/\x1b\[[0-9;]*[A-Za-z]//g'
    } >> "$f"
    echo
    echo "##### GATE FAILED at step '$1' (exit $2). Stamp: $f"
    if [ -d "$RUN_WT" ]; then
        echo "The run worktree is kept for inspection: $RUN_WT"
        echo "The next gate run removes it junction-safely. To remove it by hand, in THIS order:"
        echo "  powershell -c \"[System.IO.Directory]::Delete('$(cygpath -w "$RUN_WT/node_modules")')\""
        echo "  git -C $REPO worktree remove --force $RUN_WT"
    fi
    rm -rf "$LOCK"
    exit 1
}

on_exit() {
    local rc=$?
    if [ $FINISHED = 0 ]; then
        FINISHED=1
        write_summary "$CI_STAMPS/$SHA.fail" "FAIL"
        { echo "failed_step=$CURRENT_STEP (script stopped, exit $rc)"; echo "---- last 80 lines ----"; tail -n 80 "$LOG"; } >> "$CI_STAMPS/$SHA.fail"
        rm -rf "$LOCK"
    fi
}
trap on_exit EXIT
trap 'echo "interrupted"; exit 130' INT TERM

# run_step <key> <function>: times it, keeps its output, stops the gate on failure.
run_step() {
    local key=$1 fn=$2 t0 rc
    CURRENT_STEP=$key
    echo; echo "=================== [$key] $(date '+%H:%M:%S')"
    t0=$(date +%s)
    ( set -eo pipefail; cd "$RUN_WT"; "$fn" ) 2>&1 | tee "$STEPDIR/$key.out"
    rc=${PIPESTATUS[0]}
    DUR[$key]=$(( $(date +%s) - t0 ))
    if [ "$key" = sqlite ] || [ "$key" = mysql ]; then count_tests "$key"; fi
    [ $rc -eq 0 ] || fail "$key" "$rc"
    echo "=================== [$key] ok in $(fmt "${DUR[$key]}")"
}

# Reads "Tests:  2 failed, 5672 passed (..)" from the suite output.
count_tests() {
    local line
    line=$(sed 's/\x1b\[[0-9;]*[A-Za-z]//g' "$STEPDIR/$1.out" | grep -E '^\s*Tests:' | tail -1)
    TESTLINE[$1]=$(echo "$line" | sed -E 's/^\s*Tests:\s*//' | tr -s ' ')
    [ -n "$line" ] || TESTLINE[$1]="no 'Tests:' summary line (suite crashed or never started)"
    PASSED[$1]=$(echo "$line" | grep -oE '[0-9]+ passed' | grep -oE '[0-9]+' || echo 0)
    FAILED[$1]=$(echo "$line" | grep -oE '[0-9]+ failed' | grep -oE '[0-9]+' || echo 0)
    [ -n "${PASSED[$1]}" ] || PASSED[$1]=0
    [ -n "${FAILED[$1]}" ] || FAILED[$1]=0
}

# ---------------------------------------------------------------- the steps
mysql_cli() {
    MYSQL_PWD="$CI_MYSQL_PASSWORD" "$MYSQL_BIN/mysql.exe" -h "$CI_MYSQL_HOST" -P "$CI_MYSQL_PORT" -u "$CI_MYSQL_USER" "$@"
}

step_install() {
    # Same order and flags as tests.yml: composer (real vendor, never a junction).
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress
    # node_modules: shared junction when the lock file matches the main checkout's,
    # otherwise a real `npm ci` (the commit changed dependencies).
    if cmp -s package-lock.json "$REPO/package-lock.json" && [ -d "$MAIN_NODE_MODULES" ]; then
        powershell.exe -NoProfile -NonInteractive -Command "New-Item -ItemType Junction -Path '$(cygpath -w "$RUN_WT/node_modules")' -Target '$(cygpath -w "$MAIN_NODE_MODULES")' | Out-Null"
        echo "node_modules -> junction to $MAIN_NODE_MODULES"
    else
        echo "package-lock.json differs from the main checkout: real npm ci"
        npm ci --no-audit --no-fund
    fi
    cp .env.example .env
    php artisan key:generate
    touch database/database.sqlite
}

step_lint() {
    local base
    if [ $FULL_LINT = 1 ]; then
        echo "lint mode: full"
        php vendor/bin/pint --test
        npm run format:check
    else
        base=$(git merge-base "$SHA" origin/master)
        if [ "$base" = "$SHA" ]; then
            base=$(git rev-parse "$SHA~1")     # already on master: the last commit, like a push run
        fi
        git diff --name-only --diff-filter=ACMR "$base" "$SHA" > "$STEPDIR/changed.txt"
        grep -E '\.php$' "$STEPDIR/changed.txt" > "$STEPDIR/changed-php.txt" || true
        grep -E '^resources/.*\.(js|jsx|ts|tsx|css|json|md)$' "$STEPDIR/changed.txt" > "$STEPDIR/changed-fe.txt" || true
        echo "lint mode: changed files since ${base:0:8}: $(wc -l < "$STEPDIR/changed-php.txt") PHP, $(wc -l < "$STEPDIR/changed-fe.txt") front-end"
        if [ -s "$STEPDIR/changed-php.txt" ]; then
            xargs -d '\n' php vendor/bin/pint --test < "$STEPDIR/changed-php.txt"
        else
            echo "no PHP files changed"
        fi
        if [ -s "$STEPDIR/changed-fe.txt" ]; then
            xargs -d '\n' npx prettier --check --ignore-unknown < "$STEPDIR/changed-fe.txt"
        else
            echo "no front-end files changed"
        fi
    fi
    npx eslint . --max-warnings=0
    echo "eslint: clean"
}

step_build() { npm run build; }

step_sqlite() {
    # tests.yml sqlite job: phpunit.xml's sqlite :memory: env, .env from .env.example.
    php artisan test --parallel --processes="$CI_PROCESSES"
}

ensure_mysql() {
    if mysql_cli -N -e "select 1" >/dev/null 2>&1; then return 0; fi
    echo "local MySQL is not answering: starting $MYSQL_BIN/mysqld.exe (left running afterwards)"
    nohup "$MYSQL_BIN/mysqld.exe" --datadir="$MYSQL_DATADIR" --port="$CI_MYSQL_PORT" --bind-address=127.0.0.1 >/dev/null 2>&1 &
    disown || true
    for _ in $(seq 1 60); do
        mysql_cli -N -e "select 1" >/dev/null 2>&1 && return 0
        sleep 1
    done
    echo "MySQL did not come up in 60 s"; return 1
}

drop_ci_databases() {
    local d
    for d in $(mysql_cli -N -e "SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$CI_MYSQL_DB' OR schema_name LIKE '${CI_MYSQL_DB//_/\\_}\\_test\\_%'" | tr -d '\r'); do
        echo "drop database $d"
        mysql_cli -e "DROP DATABASE \`$d\`"
    done
}

export_mysql_env() {
    # tests.yml mysql job env. Real environment variables beat phpunit.xml's
    # sqlite <env> defaults (not force="true"), so the suite runs on MySQL.
    export DB_CONNECTION=mysql DB_HOST="$CI_MYSQL_HOST" DB_PORT="$CI_MYSQL_PORT" \
           DB_DATABASE="$CI_MYSQL_DB" DB_USERNAME="$CI_MYSQL_USER" DB_PASSWORD="$CI_MYSQL_PASSWORD" \
           APP_TIMEZONE=Asia/Dhaka
}

step_migrate() {
    ensure_mysql
    # CI SET PERSISTs the server charset/collation on its throwaway container. This
    # is the owner's shared dev server, so we do not change globals: the CI database
    # is created with the production collation explicitly, and Laravel creates the
    # per-process test databases (<db>_test_1.._N) with the connection's
    # utf8mb4/utf8mb4_unicode_ci (MySqlGrammar::compileCreateDatabase).
    drop_ci_databases
    mysql_cli -e "CREATE DATABASE \`$CI_MYSQL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    mysql_cli -e "SELECT VERSION(), @@character_set_server, @@collation_server, @@time_zone, @@global.sql_mode; SELECT default_collation_name FROM information_schema.schemata WHERE schema_name='$CI_MYSQL_DB'"
    export_mysql_env
    php artisan migrate:fresh --force
}

step_mysql() {
    export_mysql_env
    php artisan test --parallel --processes="$CI_PROCESSES"
}

# ---------------------------------------------------------------- go
echo "Local CI gate: $REF -> $SHA"
echo "repo $REPO  run worktree $RUN_WT  stamps $CI_STAMPS"
echo "started $(date -Iseconds)"

CURRENT_STEP="worktree"
remove_run_worktree || fail worktree 1
git -C "$REPO" worktree add -q --detach "$RUN_WT" "$SHA" || fail worktree 1
echo "clean worktree at $(git -C "$RUN_WT" rev-parse --short HEAD)"

run_step install step_install
run_step lint    step_lint
run_step build   step_build
run_step sqlite  step_sqlite

if [ $SKIP_MYSQL = 0 ]; then
    run_step migrate step_migrate
    MYSQL_VERSION=$(mysql_cli -N -e "select version()" 2>/dev/null | tr -d '\r')
    run_step mysql   step_mysql
    CURRENT_STEP=cleanup
    drop_ci_databases || true
fi

CURRENT_STEP=cleanup
remove_run_worktree || echo "!! run worktree not removed; see above"

FINISHED=1
if [ $SKIP_MYSQL = 1 ]; then
    write_summary "$CI_STAMPS/$SHA.partial" "PASS (partial: mysql skipped, NOT deployable)"
    echo; echo "##### GATE GREEN WITHOUT MYSQL: $CI_STAMPS/$SHA.partial (deploy-release.sh will refuse it)"
    cat "$CI_STAMPS/$SHA.partial"
else
    write_summary "$CI_STAMPS/$SHA.ok" "PASS"
    echo; echo "##### GATE GREEN: $CI_STAMPS/$SHA.ok"
    cat "$CI_STAMPS/$SHA.ok"
fi
rm -rf "$STEPDIR" "$LOCK"
exit 0
