#!/bin/sh

cd /app/backend

preflight_secrets() {
    failed=0

    case "${APP_KEY:-}" in
        ""|"your_generated_app_key"|\
        "base64:DwMidgIu8YVSEg0BLMrh5JS2dk1POpCn3rvDaZk3fEQ="|\
        "base64:z7qfYEwsFdTn6zMCYwgSwDoWZlk6SuKU54+woXYNHlk="|\
        "base64:rasMRv+Gm0oDMcBq+j9MvRgR3a6JYPTZjpRD4rGG2wA="|\
        "base64:ZIkx+O/ILP80gxHRLH+Yv7qKv94oHUgFdPn4OdD/hO8=")
            echo "PREFLIGHT: APP_KEY is empty or a publicly known example/dev value."
            failed=1
            ;;
    esac

    case "${JWT_SECRET:-}" in
        ""|"your_generated_jwt_secret"|\
        "2hoccgHb9r1fqW1lU16C6khSHVa7O0eai6FxkWK95UtQ0LqNDTO5mq1RzDwcq18I"|\
        "TJMzToO9cKLFpF5v4ADvNbTnvuWfgYNj"|\
        test-jwt-secret-*|e2e-jwt-secret-*)
            echo "PREFLIGHT: JWT_SECRET is empty or a publicly known example/dev value."
            failed=1
            ;;
    esac

    case "${DATABASE_URL:-}" in
        *":secret@"*|*":password@"*|*":hievents@"*|*":username@"*)
            echo "PREFLIGHT: the database password in DATABASE_URL is a tracked example/dev value."
            failed=1
            ;;
    esac

    case "${DB_PASSWORD:-}" in
        secret|password|hievents)
            echo "PREFLIGHT: DB_PASSWORD is a tracked example/dev value."
            failed=1
            ;;
    esac

    if [ -z "${REDIS_PASSWORD:-}" ]; then
        echo "PREFLIGHT: REDIS_PASSWORD is empty."
        failed=1
    fi

    if [ "$failed" -ne 0 ]; then
        echo "============================================"
        echo "ERROR: Refusing to start with example or empty secrets."
        echo "Generate fresh values on the server and never copy them from tracked files:"
        echo "  APP_KEY:         echo base64:\$(openssl rand -base64 32)"
        echo "  JWT_SECRET:      openssl rand -base64 48"
        echo "  DB and Redis:    openssl rand -hex 24  (one each)"
        echo "See docs/DEPLOYMENT_RUNBOOK.md, section \"Secrets\"."
        echo "============================================"
        exit 1
    fi
}

preflight_secrets

if ! php artisan migrate --force; then
    echo "============================================"
    echo "ERROR: Migrations could not complete. Check the error above."
    echo "Ensure DATABASE_URL is set."
    echo "Aborting startup to avoid running a half-migrated application."
    echo "============================================"
    exit 1
fi

php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan storage:link

chown -R www-data:www-data /app/backend
chmod -R 775 /app/backend/storage /app/backend/bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisord.conf
