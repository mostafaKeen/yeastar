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

echo "[1/7] Pulling latest code from origin/$BRANCH..."
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

echo "[2/7] Building and starting Docker containers..."
docker compose up -d --build

echo "[3/7] Waiting for yeastar_pbx_app to become healthy..."
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

echo "[4/7] Running database migrations and partition maintenance..."
docker compose exec -T yeastar-app php artisan migrate --force
docker compose exec -T yeastar-app php artisan partitions:maintain

echo "[5/7] Rebuilding Laravel caches and restarting workers..."
docker compose exec -T yeastar-app php artisan optimize:clear
docker compose exec -T yeastar-app php artisan config:cache
docker compose exec -T yeastar-app php artisan route:cache
docker compose exec -T yeastar-app php artisan view:cache
docker compose exec -T yeastar-app supervisorctl restart php-fpm
docker compose exec -T yeastar-app php artisan queue:restart

echo "[6/7] Running readiness check and cleaning dangling images..."
docker compose exec -T yeastar-app php artisan telephony:readiness-check
docker image prune -f >/dev/null 2>&1 || true

echo "[7/7] Configuring nginx-proxy virtual host and Let's Encrypt SSL..."
NGINX_PROXY_CONF="/root/whatsapp_qr/nginx.conf"
CERTBOT_CONF="/root/whatsapp_qr/certbot/conf"
CERTBOT_WWW="/root/whatsapp_qr/certbot/www"
DOMAIN="yeastar.keenenter.com"

if [ -f "$NGINX_PROXY_CONF" ] && docker ps --format '{{.Names}}' | grep -q "^nginx-proxy$"; then
    # 1. Add HTTP (port 80) ACME challenge vhost if not yet present
    if ! grep -q "server_name ${DOMAIN};" "$NGINX_PROXY_CONF"; then
        echo "Adding HTTP virtual host for ${DOMAIN} to ${NGINX_PROXY_CONF}..."
        cp "$NGINX_PROXY_CONF" "${NGINX_PROXY_CONF}.bak.$(date +%Y%m%d%H%M%S)"
        cat << 'EOF' >> "$NGINX_PROXY_CONF"

server {
    listen 80;
    server_name yeastar.keenenter.com;
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}
EOF
        docker exec nginx-proxy nginx -t
        docker exec nginx-proxy nginx -s reload
    fi

    # 2. Issue Let's Encrypt SSL certificate if not yet present
    if [ ! -f "${CERTBOT_CONF}/live/${DOMAIN}/fullchain.pem" ]; then
        echo "Requesting Let's Encrypt SSL certificate for ${DOMAIN}..."
        docker run --rm \
            -v "${CERTBOT_CONF}:/etc/letsencrypt" \
            -v "${CERTBOT_WWW}:/var/www/certbot" \
            certbot/certbot certonly \
            --webroot -w /var/www/certbot \
            -d "${DOMAIN}" \
            --email "admin@keenenter.com" \
            --agree-tos --non-interactive
    fi

    # 3. Add HTTPS (port 443) reverse proxy vhost if cert exists and block not yet present
    if [ -f "${CERTBOT_CONF}/live/${DOMAIN}/fullchain.pem" ] && ! grep -q "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" "$NGINX_PROXY_CONF"; then
        echo "Adding HTTPS (443) virtual host for ${DOMAIN} to ${NGINX_PROXY_CONF}..."
        cat << 'EOF' >> "$NGINX_PROXY_CONF"

server {
    listen 443 ssl;
    server_name yeastar.keenenter.com;
    ssl_certificate /etc/letsencrypt/live/yeastar.keenenter.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yeastar.keenenter.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    client_max_body_size 100M;

    location / {
        resolver 127.0.0.11 valid=30s ipv6=off;
        set $yeastar_upstream http://yeastar_pbx_app:80;
        proxy_pass $yeastar_upstream;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
EOF
    fi

    docker exec nginx-proxy nginx -t
    docker exec nginx-proxy nginx -s reload
    echo "nginx-proxy reloaded with ${DOMAIN}."
fi

echo "=================================================="
echo "Deployment complete: https://yeastar.keenenter.com"
echo "=================================================="
