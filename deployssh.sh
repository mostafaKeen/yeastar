#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/yeastar"
BRANCH="${1:-main}"

echo "=================================================="
echo "Deploying Yeastar Bitrix24 Platform ($BRANCH)"
echo "=================================================="

cd "$APP_DIR"

if [ ! -f ".env" ]; then
    echo "ERROR: .env file not found in $APP_DIR"
    echo "Run: cp .env.production.example .env && nano .env"
    exit 1
fi

echo "[1/6] Pulling latest code from origin/$BRANCH..."
git fetch origin "$BRANCH"
git pull --ff-only origin "$BRANCH"

if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "Generating Application Encryption Key for .env..."
    RANDOM_KEY=$(openssl rand -base64 32)
    if grep -q "^APP_KEY=" .env; then
        sed -i "s|^APP_KEY=.*|APP_KEY=base64:${RANDOM_KEY}|" .env
    else
        echo "APP_KEY=base64:${RANDOM_KEY}" >> .env
    fi
fi

echo "[2/6] Building and starting Docker containers..."
docker compose up -d --build

echo "[3/6] Waiting for yeastar_pbx_app to become healthy..."
for i in $(seq 1 30); do
    STATUS=$(docker inspect --format='{{.State.Health.Status}}' yeastar_pbx_app 2>/dev/null || echo "starting")
    if [ "$STATUS" = "healthy" ]; then
        echo "Container is healthy."
        break
    fi
    if [ "$i" -eq 30 ]; then
        echo "ERROR: Container did not become healthy in time. Check logs: docker compose logs yeastar-app"
        exit 1
    fi
    sleep 2
done

echo "[4/6] Running database migrations and partition maintenance..."
docker compose exec -T yeastar-app php artisan migrate --force
docker compose exec -T yeastar-app php artisan partitions:maintain

echo "[5/6] Rebuilding Laravel caches and restarting workers..."
docker compose exec -T yeastar-app php artisan optimize:clear
docker compose exec -T yeastar-app php artisan config:cache
docker compose exec -T yeastar-app php artisan route:cache
docker compose exec -T yeastar-app php artisan view:cache
docker compose exec -T yeastar-app supervisorctl restart php-fpm
docker compose exec -T yeastar-app php artisan queue:restart

echo "[6/6] Running readiness check and cleaning dangling images..."
docker compose exec -T yeastar-app php artisan telephony:readiness-check
docker image prune -f >/dev/null 2>&1 || true

echo "=================================================="
echo "Deployment complete: https://yeastar.keenenter.com"
echo "=================================================="
