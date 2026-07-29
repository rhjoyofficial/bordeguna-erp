# Production deployment — Bor De Guna ERP

Target: `srv1808022` (Hostinger KVM, `200.97.162.40`), reached as `ssh vps`.
Serves `https://erp.srv1808022.hstgr.cloud`.

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
| `backup` | nightly `mysqldump` into `/root/backups/bordeguna-erp` |

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
automatically. Wildcard DNS for `*.srv1808022.hstgr.cloud` already resolves, so
there is no DNS step.

### Seeding

```bash
docker compose exec app php artisan db:seed --force
```

⚠ `DatabaseSeeder` is idempotent and safe to re-run, **and the order matters**:
`BorDeGunaOrganisationSeeder` must run directly after `FoundationSeeder`,
because every module seeder iterates `Business::all()` and a business created
after them gets no permissions, calendar, policy, leave types or salary
components.

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

Nightly at **01:00 UTC (07:00 Dhaka)**, after the attendance run, into
`/root/backups/bordeguna-erp/{daily,weekly}`. 14 daily, 8 weekly.

```bash
ls -la /root/backups/bordeguna-erp/daily
docker compose logs --tail=20 backup

# Restore
gunzip -c /root/backups/bordeguna-erp/daily/erp-<stamp>.sql.gz \
  | docker compose exec -T db mysql -uroot -p"$DB_ROOT_PASSWORD" bordeguna_erp
```

⚠⚠ **These backups are ON THE SAME DISK as the database.** They protect against
a bad migration or a dropped table. They do **not** protect against losing the
VPS. Payroll and salary data is the least replaceable data in the company, so an
**offsite copy remains an open item.**

---

## Checks after any deployment

```bash
docker ps                                          # all four original stacks still up
curl -sS -o /dev/null -w '%{http_code}\n' https://n8n-vb99.srv1808022.hstgr.cloud
free -h && uptime
```

⚠ Verifying that n8n and Hermes are still healthy is **not a formality**. The
whole design is meant to make the answer obviously yes; check it anyway.
