# Deployment runbook

## Secrets (hard requirement)

Every secret is generated **on the server**, once, and stored only in the server's `.env` (or the host's secret store). Nothing is copied from `backend/.env.example`, `backend/.env.testing`, `docker/development/.env`, `docker/e2e/.env` or any other tracked file: all of those values are public in the git history.

| Variable | Generate with | Notes |
|---|---|---|
| `APP_KEY` | `echo base64:$(openssl rand -base64 32)` or `php artisan key:generate --show` | Encrypts sessions, cookies and the stored Swish key passphrase. Rotating it invalidates all encrypted data. |
| `JWT_SECRET` | `openssl rand -base64 48` or `php artisan jwt:secret --show` | Signs API tokens. Rotating it logs every user out. |
| `POSTGRES_PASSWORD` | `openssl rand -hex 24` | Also appears inside `DATABASE_URL`. |
| `REDIS_PASSWORD` | `openssl rand -hex 24` | The all-in-one compose starts Redis with `--requirepass` and passes it to the app. |
| `STRIPE_*`, `MAIL_PASSWORD`, `GOOGLE_MAPS_API_KEY` | from the respective provider | Only if the feature is enabled. |

Swish certificates and private keys are files on the server (for example `/etc/hievents/swish/`), mounted into the container and referenced by path in the organizer's Swish settings or the `SWISH_*` variables. They are never committed; the root `.gitignore` blocks `*.pem`, `*.p12`, `*.pfx`, `*.key`, `*.crt` and `swish-certs/`.

## Pre-flight check

`docker/all-in-one/scripts/startup.sh` runs `preflight_secrets` before migrations and **refuses to start** when:

- `APP_KEY` or `JWT_SECRET` is empty, a placeholder, or one of the example/dev values that exist in this repository's history;
- the database password (in `DATABASE_URL` or `DB_PASSWORD`) is `secret`, `password`, `hievents` or `username`;
- `REDIS_PASSWORD` is empty.

The compose file additionally fails at `docker compose up` if `POSTGRES_PASSWORD` or `REDIS_PASSWORD` is unset. A refused start prints the offending variable and the generation commands above. There is no override flag; fix the value and start again.

## Verifying a deployment's secrets

```bash
docker compose -f docker/all-in-one/docker-compose.yml logs all-in-one | grep PREFLIGHT   # must be empty
git grep -n "$(grep '^JWT_SECRET=' .env | cut -d= -f2-)" -- . || echo "JWT_SECRET not in repo: ok"
```
