# Local CI gate

## Why

GitHub Actions stopped running on this repository on 15 Oct 2026: every job fails
in about 2 seconds with "recent account payments have failed or spending limit"
(the owner's Settings > Billing). With no CI, nothing checked a commit before it
went to production. `scripts/ci-local.sh` runs the same jobs on the laptop, and
the deploy script refuses any commit that has no green local stamp.

It mirrors `.github/workflows/lint.yml` and `.github/workflows/tests.yml`:

| Step | What runs | Same as |
|---|---|---|
| install | `composer install --prefer-dist --optimize-autoloader` (real `vendor`), `node_modules` (junction to the main checkout, or a real `npm ci` when `package-lock.json` differs), `.env` from `.env.example`, `key:generate`, `database/database.sqlite` | both workflows |
| lint | Pint `--test` and Prettier `--check` on changed files (or every file with `--full-lint`), then `npx eslint . --max-warnings=0` | lint.yml |
| build | `npm run build` | tests.yml (Inertia pages need the Vite manifest) |
| sqlite | `php artisan test --parallel --processes=2` | tests.yml `sqlite` |
| migrate | fresh `erp_ci_local` database (utf8mb4_unicode_ci), `php artisan migrate:fresh --force` | tests.yml `mysql` |
| mysql | the suite on MySQL with `DB_CONNECTION=mysql`, `APP_TIMEZONE=Asia/Dhaka` | tests.yml `mysql` |

The workflows do not run `tsc`, so the gate does not either.

## How to run

From Git Bash, in any checkout that has the script (one gate at a time; a lock
enforces it):

```bash
bash scripts/ci-local.sh <sha|branch>              # full gate, about 1 hour
bash scripts/ci-local.sh <sha|branch> --full-lint  # Pint/Prettier on every file
bash scripts/ci-local.sh <sha|branch> --skip-mysql # quick check, NOT deployable
```

A branch name resolves to `origin/<branch>` when it is pushed (what will be
deployed), otherwise to the local branch. The run happens in a clean detached
worktree `H:\WORK\BorDeGuna\erp-wt\ci-run`, never in your working copy.

**Lint scope.** By default Pint and Prettier check only the files changed since
`git merge-base <sha> origin/master`; for a commit already on master that is the
last commit, which is what a push to master checked on GitHub. The whole tree
does not pass yet (about 1 Pint file and 12 Prettier files predate the rules),
so `--full-lint` is expected to fail until that backlog is fixed.

**Results** go to `H:\WORK\BorDeGuna\ci-stamps\`:

- `<sha>.log`: the whole run.
- `<sha>.ok`: every job green. Lists the sha, branches, date, PHP/Node/MySQL
  versions, lint scope, and passed/failed counts and duration per job.
- `<sha>.partial`: green but MySQL skipped. Never deployable.
- `<sha>.fail`: the failing step and the last 80 lines of the log. The run
  worktree is kept for inspection; the next run removes it.

**Laptop rules.** `--processes=2` (change with `CI_PROCESSES`), never two suites
at once. If Laragon's MySQL is not answering, the script starts
`mysqld.exe --datadir=C:/laragon/data/mysql-8.4` and leaves it running. The
per-process test databases (`erp_ci_local_test_1`, `_2`) are created by Laravel
and dropped after a green run. Paths and MySQL settings can be overridden with
the variables at the top of the script.

**node_modules junction.** The run worktree's `node_modules` is a Windows
junction to the main checkout's. The script deletes the junction itself
(`[System.IO.Directory]::Delete`) and checks it is gone before it removes the
worktree. Never `rm -rf` a worktree that still contains the junction: that
deletes the real `node_modules`.

## Differences from GitHub CI

- MySQL 8.4 (Laragon) instead of 8.0. Production is `mysql:8.0`.
- PHP 8.4 locally instead of 8.3 (production image is `php:8.3-fpm-alpine`).
- CI `SET PERSIST`s the server charset/collation on a throwaway container. The
  local server is shared, so the gate changes no globals: the CI database and
  Laravel's per-process test databases are created explicitly with
  utf8mb4 / utf8mb4_unicode_ci, and every connection sets that collation.
- The MySQL server clock is the laptop's (Bangladesh, +06:00), not UTC. The app
  clock is Asia/Dhaka in both.
- Windows file paths and line endings (the repository forces LF).

A failure that looks like one of these is checked against the code before it is
called an environment difference.

## The deploy gate

`H:\WORK\BorDeGuna\deploy-release.sh <expected-sha> [release-branch]` (outside the
repository; the owner runs it with `! bash ...`) refuses unless:

1. `ci-stamps/<full sha>.ok` exists, says `result=PASS` for that exact sha, and
   the gate included MySQL; and
2. `origin/master` is `<expected-sha>`. With a release branch as the second
   argument it first fast-forwards master to `origin/<release-branch>` (which
   must already be `<expected-sha>`), pushes master, and checks again.

Then it keeps every step of the earlier per-release scripts: dump first, build,
migrate in the new image before the restart, recreate `web`, `/up` and `/login`
checks, pending-migration count, error count, and it prints the rollback
command with the previous server sha.

## Removing it when GitHub billing is fixed

1. Check one push runs green on GitHub (both workflows).
2. Delete `scripts/ci-local.sh` and this file in one commit.
3. Delete `H:\WORK\BorDeGuna\deploy-release.sh` and `H:\WORK\BorDeGuna\ci-stamps\`,
   and go back to "CI green on the PR" as the deploy rule.
4. Drop any leftover `erp_ci_local*` databases on the local MySQL.
