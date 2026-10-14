# Production deployment — Bor de Guna ERP

Target: `srv1808022` (Hostinger KVM, `200.97.162.40`), reached as `ssh vps`.
Serves `https://erp.bordeguna.com`.

Design rationale is in `D:\WORK\BorDeGuna\VPS-DEPLOYMENT-PLAN.md`. This file is
the runbook.

---

## What is already on this host

Four stacks created by Hostinger's hPanel one-click installer, with **no compose
files anywhere on disk**:

| Stack | What | Exposure |
|---|---|---|
| `traefik-rsf3` | Reverse proxy, **owns :80 and :443**, runs on the **host** network | public |
| `n8n-vb99` | n8n — the Hermes / Bionic Garden pipeline | 127.0.0.1:5678 |
| `postgresql-4jjn` | PostgreSQL 17 | internal |
| `content-render-b7k2` | Content render service | 127.0.0.1:3010 |

**The host has no php, nginx, apache2, mysql, mariadb, caddy or certbot.** It is
a pure Docker host, and it must stay that way.

⚠ **This ERP stack is managed by hand and will not appear in hPanel.** Keep its
compose file in this repository, and **never edit Traefik's own configuration** —
routing is declared with container labels and Traefik picks them up.

### Why there is no shared Traefik network

Traefik runs on the **host** network, so it reaches these containers by their
bridge IP directly. The ERP therefore sits on a completely private bridge with
nothing else on it: n8n has no route in, and the ERP has no route out to n8n.

---

## The stack

| Service | Purpose |
|---|---|
| `app` | PHP-FPM 8.3 + Laravel |
| `web` | nginx, the only service Traefik can see |
| `db` | MySQL 8, **no published ports** |
| `worker` | `queue:work` — payroll calculation, queued reports, payslip email |
| `scheduler` | `schedule:work` — **the nightly attendance run (C15)** |
| `backup` | nightly backup set (database + uploaded files), copied off-server with restic and verified |
| `backup-watch` | Telegram alert when no verified backup for 26 hours |

Every service has CPU and memory caps, because 2 cores are shared with n8n and
an unbounded payroll calculation could otherwise take the machine.

---

## First deployment

### Prerequisites (not mine to do)

1. **Deploy key** — the VPS public key added to `rhjoyofficial/bordeguna-erp`
   under Settings → Deploy keys, **read-only**.
2. **The code pushed to `master`** — the server clones from GitHub, so anything
   unpushed does not exist as far as production is concerned.
3. **The admin password** — set by Joy directly, never handled by anyone else.

### Steps

```bash
# --- ON THE VPS ---
mkdir -p /opt/bordeguna-erp /root/backups/bordeguna-erp
cd /opt/bordeguna-erp
git clone github-bordeguna:rhjoyofficial/bordeguna-erp.git .

cp deploy/.env.example .env
# Fill in DB_PASSWORD and DB_ROOT_PASSWORD with generated values, e.g.
#   openssl rand -base64 24
# ⚠ Both must be set BEFORE the first `up`. MySQL initialises its data
# directory once and changing them later does not change what is in the volume.

cd deploy
docker compose build            # slowest step; builds Node assets then PHP
docker compose up -d db
docker compose run --rm app php artisan key:generate --force
```

⚠ **Do not add the Traefik labels yet.** The compose file ships with them, so
for the very first bring-up comment out the `labels:` block on `web` — the stack
should start isolated and unreachable, be verified, and only then be exposed.

```bash
docker compose up -d
docker compose exec app php artisan migrate --force

# ⚠ Verify against MySQL before exposing anything. The suite runs on SQLite
# locally and this project has already had one CAST(... AS INTEGER) near-miss
# that works in SQLite and not in MySQL.
docker compose exec app php artisan test

# Internal smoke test, still unexposed:
docker compose exec web curl -sS -o /dev/null -w '%{http_code}\n' http://localhost/login
```

Once that is green: restore the `labels:` block, `docker compose up -d web`, and
the subdomain goes live over HTTPS with a Let's Encrypt certificate issued
automatically. An A record for `erp.bordeguna.com` points at the VPS, so
there is no DNS step.

### Seeding

```bash
docker compose exec app php artisan db:seed --force
```

This is how a newly added permission reaches production: every module's
permission seeder uses `givePermissionTo`, which is additive, so re-running it
grants what is new without touching what a role already had.

⚠ `DatabaseSeeder` is idempotent and safe to re-run, **and the order matters**:
`BorDeGunaOrganisationSeeder` must run directly after `FoundationSeeder`,
because every module seeder iterates `Business::all()` and a business created
after them gets no permissions, calendar, policy, leave types or salary
components.

⛔⛔ **"Safe to re-run" was not true until 12 September 2026, and it is worth
knowing why.** `DatabaseSeeder` also calls `InventoryDemoDataSeeder`, which
invents a catalogue and records real **stock-in movements** for it. It looks its
businesses up with `firstOrFail`, and `laladia`, `bionic` and `azmion` all exist
on production — so it would have succeeded rather than failed safe, writing demo
products and opening movements into a live ERP whose stock ledger is
deliberately empty while the real opening count is prepared.

It never ran: production was measured at 153 real products, **0 demo SKUs and 0
stock movements**. The seeder now refuses outright when `app()->isProduction()`,
with a test either side of the guard, so this command is now as safe as this
section always claimed. ⚠ Any future demo or sample-data seeder needs the same
guard before it is wired into `DatabaseSeeder`.

---

## Verifying the scheduler (the reason this deployment happened)

```bash
docker compose ps scheduler
docker compose logs --tail=50 scheduler

# The real proof — rows appearing WITHOUT anybody running a command:
docker compose exec app php artisan tinker --execute \
  "foreach (App\Modules\Hrm\Models\AttendanceProcessingRun::withoutGlobalScopes()->latest('id')->take(5)->get() as \$r) { echo \$r->business_id.' '.\$r->status.' '.\$r->started_at.' '.\$r->days_processed.PHP_EOL; }"
```

⚠ **An empty `attendance_processing_runs` table means the scheduler is not
working**, whatever `docker ps` says. The run fires at **23:00 Dhaka**, so the
first automatic row appears the night after deployment.

To confirm sooner without waiting, run it by hand once and check a row is
written — but that proves the command, not the schedule:

```bash
docker compose exec app php artisan hrm:process-attendance --dry-run
```

---

## Routine operations

```bash
# Deploy a new version
cd /opt/bordeguna-erp && git pull
cd deploy && docker compose build && docker compose up -d
docker compose exec app php artisan migrate --force
```

⚠ **A code change needs the containers restarted.** `opcache.validate_timestamps=0`
means PHP never re-reads a changed file, so editing in place appears to do
nothing at all. `docker compose up -d` after a build handles it.

⚠ **If you ever see a 502 after a deploy, check nginx's upstream first.**
`nginx.conf` resolves `app` through Docker's embedded DNS (`127.0.0.11`) via a
variable upstream, precisely so a recreated app container's new bridge IP is
picked up. Reverting that to a literal `fastcgi_pass app:9000` reintroduces a
502 on every deploy that recreates `app`, curable only by restarting `web`.
Diagnose with:

```bash
docker compose logs --tail=20 web | grep 'connect() failed'
```

```bash
docker compose logs -f app worker scheduler   # logs
docker compose exec app php artisan down      # maintenance mode
docker compose down                           # full rollback, removes the stack
```

### Backups

See **Backups** at the end of this file.

---

## Checks after any deployment

```bash
docker ps                                          # all four original stacks still up
curl -sS -o /dev/null -w '%{http_code}\n' https://n8n-vb99.srv1808022.hstgr.cloud
free -h && uptime
```

⚠ Verifying that n8n and Hermes are still healthy is **not a formality**. The
whole design is meant to make the answer obviously yes; check it anyway.

## Backups

### What runs

The `backup` container (image built from `deploy/backup.Dockerfile`: mysql:8.0
tools plus restic 0.17.3) runs `deploy/backup.sh` nightly at **01:00 UTC
(07:00 Dhaka)**, after the 23:00 attendance run. Each night produces ONE backup
set, `/root/backups/bordeguna-erp/sets/erp-YYYYmmdd-HHMMSS/`:

- `db.sql.gz`: mysqldump of the ERP database (single transaction, routines, triggers, events)
- `files.tar`: all of `storage/app` (private and public: attachments, sounds,
  popup media, relax media, avatars, staged imports), taken from the `storage`
  volume, mounted read-only
- `files.sha256`: the sha256 of every file inside `files.tar`
- `MANIFEST.txt`: set id, time, database, server version, table and file counts, sizes
- `SHA256SUMS`: the sha256 of the four files above

The set is then copied with restic to an S3-compatible bucket (Backblaze B2),
encrypted on the server before it leaves. A night counts as **successful only
when all of these pass**: the dump is valid gzip, non-trivial, complete, and has
exactly as many tables as the live database; the tar reads back end to end and
matches its checksum list; `sha256sum -c SHA256SUMS` passes; restic wrote the
snapshot, `restic check` passes, and `SHA256SUMS` and the dump read back from
the bucket match. Only then is `/root/backups/bordeguna-erp/state/last-success`
written.

- Off-server retention: `restic forget --prune` keeps 14 daily, 8 weekly, 12 monthly.
- On-server: the newest 7 sets (`BACKUP_LOCAL_KEEP`). Dumps in the old
  `daily/` and `weekly/` folders age out on their old schedule.
- Failure: Telegram alert naming the stage, set id and time (no secrets, no business data).
- Silence: `backup-watch` alerts when there has been no verified backup for 26 hours,
  repeats every 6 hours, and says when it recovers.
- No Telegram settings: `backup.sh` logs every failure and `once` exits non-zero;
  `backup-watch` exits non-zero and shows as Restarting in `docker compose ps`.

⚠ The dump is taken first and the files second. An upload in between is in the
tar without a row (harmless); a file deleted in between is in the previous set.

### Owner setup (once)

1. **Backblaze B2: create a private bucket**, e.g. `bordeguna-erp-backup`, with
   **Object Lock enabled** (it can only be turned on when the bucket is created;
   it also turns on versioning). Set a default retention of 30 days in
   Governance mode. If restic's first upload is refused because of the lock,
   recreate the bucket with versioning only and a lifecycle rule that keeps
   prior versions for 30 days: the point either way is that this server cannot
   erase older recovery points.
2. **Create an application key** under App Keys: "Allow access to bucket" =
   that bucket only, type Read and Write. Copy the `keyID` and `applicationKey`
   now (B2 shows the key once). Never use the master key.
3. **Generate the encryption password on the VPS** (`openssl rand -base64 32`)
   and save it in a password manager OFF the server. Backblaze cannot recover
   it; without it every offsite backup is unreadable.
4. **Put these values in `/opt/bordeguna-erp/deploy/.env`** (examples in
   `deploy/.env.example`):
   - `RESTIC_REPOSITORY=s3:https://<bucket endpoint>/<bucket>/erp` (the endpoint
     is on the bucket's page, e.g. `s3.us-west-004.backblazeb2.com`)
   - `RESTIC_PASSWORD=` the password from step 3
   - `AWS_ACCESS_KEY_ID=` the B2 keyID
   - `AWS_SECRET_ACCESS_KEY=` the B2 applicationKey
   - `AWS_DEFAULT_REGION=` the region part of the endpoint (e.g. `us-west-004`)
   - `TELEGRAM_BOT_TOKEN=` and `TELEGRAM_BACKUP_CHAT_ID=` (the company's alert
     bot and the chat that should hear about backups)
5. **Build and start, then take the first backup now**:

```bash
# --- ON THE VPS ---
cd /opt/bordeguna-erp/deploy
docker compose build backup
docker compose up -d backup backup-watch
docker compose run --rm --no-deps backup-watch test-alert   # a test message arrives in Telegram
docker compose run --rm --no-deps backup once                # exit 0 = verified end to end
cat /root/backups/bordeguna-erp/state/last-success
```

6. **Run the restore drill** (next section) and keep its output.

⚠ The old `offsite` service and its `--profile offsite` flag are gone; the
`backup` service now does the whole chain. Its old `B2_ACCOUNT_ID` /
`B2_ACCOUNT_KEY` variables are no longer read and can be deleted from `.env`.

### Checking it

```bash
# --- ON THE VPS ---
cd /opt/bordeguna-erp/deploy
docker compose ps backup backup-watch
docker compose logs --tail=60 backup
cat /root/backups/bordeguna-erp/state/last-success      # epoch, set id, snapshot
docker compose run --rm --no-deps --entrypoint restic backup snapshots
```

### Restore drill (database + files, isolated)

⚠ **Do this once now, then monthly, not for the first time during an incident.**

```bash
# --- ON THE VPS ---
cd /opt/bordeguna-erp
deploy/restore-drill.sh                 # newest local set
deploy/restore-drill.sh --offsite       # pulled from the bucket: the "VPS is gone" path
```

The drill checks the set's checksums, extracts the files and verifies every
file checksum, then starts a **throwaway stack**: its own Docker network with
no internet access, its own mysql:8.0 with a random password, and a throwaway
app container whose storage is the restored copy. It loads the dump, compares
the table count with the manifest, runs the ledger verifiers, checks that every
attachment row has its file, and opens the newest attachments (size compared
with the row, images decoded). It never touches the live `db` container or the
live `storage` volume, and removes everything on exit (`KEEP_DRILL=1` keeps it).
Exit 0 = PASS.

`deploy/restore-old.sh` restores the OLDEST set into a scratch database and
runs the migrations against it, which is the realistic disaster: a backup taken
before the running code's newest migrations.

### Restoring for real

```bash
# --- ON THE VPS --- (after the drill has passed on the chosen set)
SET=/root/backups/bordeguna-erp/sets/erp-<stamp>      # or restic restore it first
(cd "$SET" && sha256sum -c SHA256SUMS)
gunzip -c "$SET/db.sql.gz" | docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose run --rm --no-deps -v "$SET:/set:ro" --entrypoint sh app   -c 'tar -C /var/www/html/storage -xf /set/files.tar && chown -R www-data:www-data /var/www/html/storage/app'
```
