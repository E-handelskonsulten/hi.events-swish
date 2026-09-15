# Deployment runbook

Production runs on a single Ubuntu host (`2.29.37.168`, Hetzner) as the `deploy` user. Everything lives under `/srv/biljettera`:

| Path | Purpose |
|---|---|
| `/srv/biljettera/app` | Clone of this repository, branch `swish-integration` |
| `/srv/biljettera/.env` | The only copy of the production environment (mode 600, owner `deploy`) |
| `/srv/biljettera/secrets/swish/` | Swish certificate, private key and root CA (root-owned, mode 640, mounted read-only into the container at `/etc/hievents/swish`) |
| `/srv/biljettera/backups/` | Daily PostgreSQL dumps, 14 days retained |

Compose project: `docker/production/docker-compose.yml` (services `caddy`, `app`, `postgres`, `redis`). Caddy terminates TLS for `admin.biljettera.se` and `demo.biljettera.se` with Let's Encrypt and renews automatically; `app` is the all-in-one image (nginx, PHP-FPM, SSR frontend, queue worker and scheduler under supervisord, each auto-restarting). Shell alias used below:

```bash
alias bc='docker compose --env-file /srv/biljettera/.env -f /srv/biljettera/app/docker/production/docker-compose.yml'
```

## Secrets (hard requirement)

Every secret is generated **on the server**, once, and stored only in `/srv/biljettera/.env`. Nothing is copied from `backend/.env.example`, `backend/.env.testing`, `docker/development/.env`, `docker/e2e/.env` or any other tracked file: all of those values are public in the git history.

| Variable | Generate with | Notes |
|---|---|---|
| `APP_KEY` | `echo base64:$(openssl rand -base64 32)` | Encrypts sessions, cookies and the stored Swish key passphrase. Rotating it invalidates all encrypted data. |
| `JWT_SECRET` | `openssl rand -base64 48` | Signs API tokens. Rotating it logs every user out. |
| `POSTGRES_PASSWORD` | `openssl rand -hex 24` | Also inside `DATABASE_URL`. |
| `REDIS_PASSWORD` | `openssl rand -hex 24` | Redis runs with `--requirepass`. |
| `MAIL_PASSWORD` | Resend API key | See "Mail". |
| `STRIPE_*`, `GOOGLE_MAPS_API_KEY` | provider | Only if the feature is enabled. |

The root `.gitignore` blocks `*.pem`, `*.p12`, `*.pfx`, `*.key`, `*.crt` and `swish-certs/`.

## Pre-flight check

`docker/all-in-one/scripts/startup.sh` runs `preflight_secrets` before migrations and **refuses to start** when `APP_KEY` or `JWT_SECRET` is empty, a placeholder, or one of the example/dev values in this repository's history; when the database password is `secret`, `password`, `hievents` or `username`; or when `REDIS_PASSWORD` is empty. The compose file fails at `docker compose up` if `POSTGRES_PASSWORD`, `REDIS_PASSWORD` or `ACME_EMAIL` is unset. There is no override flag.

```bash
bc logs app | grep PREFLIGHT   # must print nothing
```

## Deploy an update

```bash
cd /srv/biljettera/app
git fetch origin && git checkout swish-integration && git pull --ff-only
bc build app            # builds frontend + backend into biljettera/app:latest (5-10 min on this host)
bc up -d app            # recreates only the app container; migrations run in startup.sh
bc logs -f app          # watch until "supervisord started"; PREFLIGHT lines mean the env is wrong
curl -fsS https://demo.biljettera.se/api/health
```

Caddy, Postgres and Redis keep running during an app update. Uploaded images live in the `app-storage` volume and survive rebuilds.

## Roll back

Images are tagged by commit when built with `APP_IMAGE_TAG`:

```bash
APP_IMAGE_TAG=$(git -C /srv/biljettera/app rev-parse --short HEAD) bc build app   # tag the current build
# roll back to a previous tag:
APP_IMAGE_TAG=<old-short-sha> bc up -d app
```

Migrations are forward-only; if a release added a migration, restore the database dump taken before the deploy (below) before starting the old image.

## Restart workers

The queue worker and scheduler are supervisord programs inside `app`; supervisord restarts them automatically if they exit.

```bash
bc exec app supervisorctl status
bc exec app supervisorctl restart laravel-queue-worker
bc exec app supervisorctl restart laravel-scheduler
bc restart app                      # everything, ~30 s of downtime
```

After deploying code that changes queued jobs, restart the queue worker so it loads the new code.

## Backups

`docker/production/backup.sh` runs from the deploy user's crontab at 03:15 every night, writes `hievents-<date>.sql.gz` to `/srv/biljettera/backups` and deletes dumps older than 14 days. Copy the directory off-host (object storage, rsync) for real disaster recovery; the host itself is a single point of failure.

```bash
/srv/biljettera/app/docker/production/backup.sh                      # manual dump
gunzip -c /srv/biljettera/backups/hievents-YYYY-MM-DD_HHMM.sql.gz \
  | bc exec -T postgres psql -U hievents hievents                     # restore (stop app first: bc stop app)
```

## Rotate Swish certificates

1. Put the new `.pem`, `.key` (and CA if it changed) in `/srv/biljettera/secrets/swish/` as root, mode 640, group readable by the container's `www-data` gid (`sudo install -m 640 -g <gid> …`). Keep the old files until the switch is verified.
2. Point `SWISH_CERT_PATH` / `SWISH_KEY_PATH` / `SWISH_CA_PATH` (or the organizer's Swish settings in the backoffice) at the new file names.
3. `bc up -d app` (env change) or press **Test connection** in the organizer's Swish settings (settings change). A green "Connection verified" means Swish accepted the certificate.
4. Delete the old files.

Certificates from Swish are valid for a limited period; note the expiry (`openssl x509 -enddate -noout -in <cert>`) in the calendar.

## Flip an organizer from MSS to Swish production

Prerequisites: the client's production certificate bundle from Swish Certificate Management (merchant certificate + key for their Swish number) and the Swish TLS root CA for production.

1. Copy the production files to `/srv/biljettera/secrets/swish/prod/` as in "Rotate Swish certificates".
2. In the backoffice, open the organizer → **Inställningar** → **Swish**:
   - Environment: **Production**
   - Swish number: the client's real payee alias
   - Certificate path, private key path, root CA path: the `/etc/hievents/swish/prod/...` paths
   - Passphrase: only if the key is encrypted
   - Save. The save runs a connectivity test against `cpc.getswish.net`; it must show "Connection verified".
3. Leave the `SWISH_*` variables in `.env` on MSS; organizer settings override them, so other organizers (the demo) keep using MSS.
4. Make a real 1 kr purchase on one of the organizer's events, then refund it from the order page. Both must complete, and the buyer must receive the ticket and refund mails.
5. Keep the MSS files; the demo organizer still uses them.

To move the whole installation to production instead, change `SWISH_ENVIRONMENT`, `SWISH_PAYEE_ALIAS` and the three `SWISH_*_PATH` variables in `.env` and `bc up -d app`.

## Mail

Resend over SMTP (`smtp.resend.com:587` with STARTTLS, user `resend`, password = API key; the host blocks outbound 465, and 2587 is the fallback if 587 is ever blocked). The domain `biljettera.se` is verified in Resend with CNAME records (`rsend._domainkey` for DKIM and `send` for the bounce/SPF domain, both pointing at `forge.rmta.net` targets) plus our own DMARC record at the registrar. Ticket, order and refund mails go to buyers; flagged Swish payments and failed refunds also go to `APP_ALERTS_EMAIL`.

Branding in mails: buyer-facing mails (ticket, order confirmation/cancellation/failure/refund, detail changes, occurrence cancellation, waitlist) show the organizer's uploaded logo (Organizer → Settings → logo) in the header and use the organizer's name as the From display name; the From address stays `MAIL_FROM_ADDRESS` so DKIM and DMARC keep aligning. Without an organizer logo, and for every organizer-facing mail (new order, flagged payment, refund failure, mass refund summary), the header shows `APP_EMAIL_LOGO_URL` (the Biljettera wordmark). The "Powered by Hi.Events" footer is the upstream licence notice and must stay as is.

## SMS ticket delivery (46elks)

Buyers get a text with a link to their tickets when an order completes and a notice when it is fully refunded. It is a per-organizer add-on (Organizer → Settings → SMS delivery, default off) on top of a platform master switch.

Env in `/srv/biljettera/.env`:

| Variable | Value |
|---|---|
| `SMS_ENABLED` | `true` to send at all; `false` keeps every organizer toggle inert (settings are still saved) |
| `ELKS_USERNAME` / `ELKS_PASSWORD` | API credentials from the 46elks dashboard (never in the repo) |
| `SMS_DEFAULT_SENDER` | Alphanumeric sender shown on the phone when the organizer has not set one (`Biljettera`) |
| `SMS_DRY_RUN` | `true` makes 46elks validate every message without sending or charging; use it for the first deploy, then set `false` |

After changing any of them: `bc up -d app` (env is read at container start). The recipient is the Swish payer number when the order was paid with Swish, otherwise the optional mobile number from checkout; orders without a number are skipped and logged (`SMS skipped: order has no mobile number`). Sending runs as the queued `SendOrderSmsJob` (3 attempts, 30 s / 2 min / 10 min backoff) and never blocks the order or the ticket email; every attempt is stored in `sms_messages`. Single-ticket orders link to the attendee's public ticket page (`/product/<eventId>/<attendeeShortId>`); orders with several attendees link to the order tickets page (`/t/<orderShortId>`, one QR at a time with swipe/arrow navigation, refunded or cancelled orders shown as invalid). Both are sessionless: the random short id in the URL is the access token, the same mechanism as the existing attendee links. The order confirmation email's button points to the same page for multi-attendee orders.

Timing: the ticket SMS is held until N hours before the event start (N = the organizer's "hours before start" setting, 1-24, default 3, first occurrence for recurring/multi-day events). Orders completed closer to the start, or after it, get the SMS immediately; ticket emails always go out immediately. Held messages sit in `sms_messages` as `SCHEDULED` with `scheduled_for`, the scheduler's `ProcessDueSmsMessagesJob` sends them every minute, a change of the event or occurrence start (or of N) moves them, and a full refund cancels them. A held SMS is billed in the month it is actually sent.

Per-organizer prices for the invoicing basis (defaults 6,00 kr per sold ticket and 0,50 kr per SMS):

```bash
bc exec app php artisan billing:set-organizer-fees <organizerId> --platform-fee=6 --sms-fee=0.5
```

## Monthly billing summary

On the 1st of every month at 07:00 Europe/Stockholm the scheduler runs `billing:send-monthly-summary`, which queues one mail with the invoicing basis for the previous calendar month (one section per organizer plus a CSV) to `BILLING_SUMMARY_EMAIL`. Organizers never receive it. Each month is recorded in `billing_summary_runs`, so a rerun of the scheduler does not send it twice.

```bash
bc exec app php artisan billing:send-monthly-summary --month=2026-09 --to=you@example.com --sync   # preview any month to another address, nothing recorded
bc exec app php artisan billing:send-monthly-summary --month=2026-08 --force --sync                # resend a month to BILLING_SUMMARY_EMAIL
```

Billing is gross: every ticket on a paid order counts, refunds are listed as information only, and SMS are charged per sent message only for organizers with the add-on enabled. Ticket sales reconcile with the organizer's accounting report (paid orders by order date in the organizer's timezone).

## Monitoring

- `GET https://demo.biljettera.se/api/health` returns `{"status":"ok","checks":{"database":"ok","redis":"ok"}}` with 200, or 503 with `"degraded"`. Point the uptime checker at it (interval 1 min, alert after 2 failures).
- `bc ps` shows the health of every container; Docker also probes `app` every 30 s.
- Alert mails to `APP_ALERTS_EMAIL`: "Action required: Swish payment received for order …" (payment could not be completed automatically) and "Action required: Swish refund failed for order …".
- Logs: `bc logs --since 1h app` (application, queue and scheduler all log to stderr).
