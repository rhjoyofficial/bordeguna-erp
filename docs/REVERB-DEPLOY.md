# Live dashboards: deploying Laravel Reverb (11 Oct 2026)

The role dashboards listen on a WebSocket (Laravel Reverb). Nothing here has been done on the
server. Until it is, the dashboards work exactly the same, refreshing each widget on a timer
(the "Refreshing on a timer" dot); once Reverb is up the dot turns green ("Live") and widgets
refresh the moment something happens.

## How it fits the current stack

```
browser --wss://erp.bordeguna.com/app/...--> Traefik (TLS) --> web (nginx) --/app--> reverb:8080
app / worker --http://reverb:8080/apps/...--> reverb            (server publishes events)
```

- One extra container, `reverb`, from the same `bordeguna-erp:latest` image, running
  `php artisan reverb:start`. It listens on 8080 inside the `internal` bridge only. No labels,
  no published ports: Traefik keeps seeing only `web`.
- nginx (`web`) forwards `/app` (browser sockets) and `/apps` (the HTTP publish API) to it, on the
  same domain, so there is no new hostname, certificate or Traefik rule.
- Broadcast events are queued (ShouldBroadcast) and released after the database commit, so the
  existing `worker` container sends them. No change to `worker`.
- Channels carry no figures: event type, record ids, widget keys, a sound class. Each widget then
  re-reads itself through its authorised endpoint.

## 1. Runtime `.env` (`/opt/bordeguna-erp/deploy/.env`)

Generate the key and secret once: `openssl rand -hex 16` (twice).

```
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=bordeguna-erp
REVERB_APP_KEY=<32 hex>
REVERB_APP_SECRET=<32 hex, different>

# Where app and worker PUBLISH to: the container, inside the bridge, plain http.
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=http

# What the reverb container binds to.
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Only the ERP's own pages may open a socket.
REVERB_ALLOWED_ORIGINS=erp.bordeguna.com
```

## 2. Build arguments (the browser side is compiled in)

Vite inlines `VITE_*` at build time (deploy/Dockerfile already declares these ARGs, empty by
default). In `deploy/docker-compose.yml`, service `app`, add under `build:`:

```yaml
  app:
    build:
      context: ..
      dockerfile: deploy/Dockerfile
      args:
        VITE_REVERB_APP_KEY: ${REVERB_APP_KEY}
        VITE_REVERB_HOST: erp.bordeguna.com
        VITE_REVERB_PORT: "443"
        VITE_REVERB_SCHEME: https
```

(Compose reads `${REVERB_APP_KEY}` from `deploy/.env`, the same file `env_file` uses.)

## 3. The `reverb` service (add to `deploy/docker-compose.yml`, beside `worker`)

```yaml
  reverb:
    image: bordeguna-erp:latest
    restart: unless-stopped
    env_file: [.env]
    command: php artisan reverb:start --host=0.0.0.0 --port=8080
    # Long-running PHP process, same reason as the scheduler: reap children.
    init: true
    volumes:
      - storage:/var/www/html/storage
    networks: [internal]
    depends_on: [app]
    # No labels and no ports: only `web` is visible to Traefik.
    deploy:
      resources:
        limits: {cpus: '0.25', memory: 192M}
```

## 4. nginx (`deploy/nginx.conf`, inside the `server { }` block, above `location / {`)

```nginx
    # Live dashboards: WebSocket upgrade to the reverb container. Same variable
    # upstream + Docker DNS rule as php below, so a recreated reverb container
    # (new bridge IP) is found again without restarting nginx.
    location ~ ^/(app|apps)(/|$) {
        set $upstream_reverb reverb:8080;
        proxy_pass http://$upstream_reverb;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
    }
```

The `resolver 127.0.0.11` line already in the file must stay above it (it is, at server level).
Traefik passes WebSocket upgrades through without any extra configuration.

## 5. Deploy order (the usual recipe, plus two lines)

1. Full suite green locally, read-only state check, database dump.
2. `git merge --ff-only origin/master` in `/opt/bordeguna-erp`.
3. Edit `deploy/.env` (step 1), `deploy/docker-compose.yml` (steps 2 and 3), `deploy/nginx.conf` (step 4).
4. `docker compose build`
5. `docker compose run --rm app php artisan migrate --force` (role dashboard tables, the
   `dashboard.view_all` permission for every Owner role, default dashboards per role).
6. `docker compose up -d` (starts `reverb`, recreates the rest).
7. `docker compose restart web` once, so nginx loads the new location.

## 6. Verify

- `docker compose logs reverb` shows `Starting server on 0.0.0.0:8080`.
- Open https://erp.bordeguna.com/dashboard: the dot next to the sound button says **Live**.
  Browser devtools, Network, WS: `wss://erp.bordeguna.com/app/<key>?protocol=7...` with status 101.
- Publish a test event from the server:
  `docker compose exec app php artisan tinker --execute="event(App\Events\Dashboards\DashboardSignal::business('approval.requested', 1, ['approval' => 0]));"`
  The "Waiting for you" widget on an open dashboard of business 1 refreshes within a second.
  (A sound plays only if sound is on for that login and the page has been clicked once.)
- `docker compose logs worker` shows `BroadcastEvent ... DONE` lines.

## Rollback

Set `BROADCAST_CONNECTION=log` in `.env` and `docker compose up -d` (stop `reverb` if wanted).
The dashboards fall back to timed refresh on their own; nothing else depends on the socket.
