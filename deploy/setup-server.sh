#!/usr/bin/env bash
# Digitz PMS — one-time server setup for Ubuntu 24.04 (run as root on a fresh VPS).
#
#   curl -fsSL https://raw.githubusercontent.com/<you>/digitz-pms/develop/deploy/setup-server.sh -o setup.sh
#   sudo DOMAIN=test.example.ae EMAIL=you@example.ae ENV_NAME=test bash setup.sh
#
# Variables:
#   DOMAIN     (required) domain pointed at this server's IP (A record)
#   EMAIL      (required) for the free Let's Encrypt HTTPS certificate
#   ENV_NAME   test | production            (default: test)
#   OTP_DEV    1 = show sign-up codes on screen (test only)  (default: 1 on test, 0 on production)
#   FRONTEND_ORIGINS  comma-separated sites allowed to call the API, e.g. https://digitz-test.netlify.app
#   COMPANY, CA_COMPANY, CA_USERNAME, CA_PASSWORD, CA_MAX, WA_PHONE_ID, WA_TOKEN, MAIL_FROM  (optional)
set -euo pipefail

: "${DOMAIN:?Set DOMAIN, e.g. DOMAIN=test.example.ae}"
: "${EMAIL:?Set EMAIL for the HTTPS certificate}"
ENV_NAME="${ENV_NAME:-test}"
if [ "$ENV_NAME" = "production" ]; then OTP_DEV="${OTP_DEV:-0}"; else OTP_DEV="${OTP_DEV:-1}"; fi
APP_DIR=/var/www/digitz
BRANCH=develop; [ "$ENV_NAME" = "production" ] && BRANCH=main
DEPLOY_USER=deploy

echo "==> Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q nginx mariadb-server php-fpm php-mysql php-curl php-mbstring php-xml php-cli \
  certbot python3-certbot-nginx rsync ufw unattended-upgrades fail2ban
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

echo "==> Firewall"
ufw allow OpenSSH >/dev/null; ufw allow 'Nginx Full' >/dev/null; ufw --force enable >/dev/null

echo "==> Deploy user + key for GitHub Actions"
id "$DEPLOY_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$DEPLOY_USER"
usermod -aG www-data "$DEPLOY_USER"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /home/$DEPLOY_USER/.ssh
if [ ! -f /root/digitz-deploy-key ]; then
  ssh-keygen -t ed25519 -N "" -C "github-actions-digitz-$ENV_NAME" -f /root/digitz-deploy-key >/dev/null
fi
grep -qF "$(cat /root/digitz-deploy-key.pub)" /home/$DEPLOY_USER/.ssh/authorized_keys 2>/dev/null || cat /root/digitz-deploy-key.pub >> /home/$DEPLOY_USER/.ssh/authorized_keys
chown $DEPLOY_USER:$DEPLOY_USER /home/$DEPLOY_USER/.ssh/authorized_keys; chmod 600 /home/$DEPLOY_USER/.ssh/authorized_keys

echo "==> App folder"
install -d -m 2775 -o "$DEPLOY_USER" -g www-data "$APP_DIR"

echo "==> Database"
if [ ! -f /root/digitz-db.pass ]; then openssl rand -hex 24 > /root/digitz-db.pass; chmod 600 /root/digitz-db.pass; fi
DBPASS=$(cat /root/digitz-db.pass)
mysql -e "CREATE DATABASE IF NOT EXISTS digitz_pms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'digitz'@'localhost' IDENTIFIED BY '$DBPASS';
ALTER USER 'digitz'@'localhost' IDENTIFIED BY '$DBPASS';
GRANT ALL ON digitz_pms.* TO 'digitz'@'localhost'; FLUSH PRIVILEGES;"

echo "==> Settings for the first deploy (used once, then deleted)"
install -d -m 750 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /etc/digitz
if [ ! -f "$APP_DIR/config.php" ]; then
  CA_PASSWORD="${CA_PASSWORD:-$(openssl rand -base64 12 | tr -d '/+=' )9a}"
  cat > /etc/digitz/install.env <<ENV
DB_NAME=digitz_pms
DB_USER=digitz
DB_PASS=$DBPASS
APP_URL=https://$DOMAIN
COMPANY=${COMPANY:-Digitz PMS}
OTP_DEV=$OTP_DEV
WA_PHONE_ID=${WA_PHONE_ID:-}
WA_TOKEN=${WA_TOKEN:-}
MAIL_FROM=${MAIL_FROM:-Digitz PMS <no-reply@$DOMAIN>}
MAIL_ADMIN=$EMAIL
CA_COMPANY=${CA_COMPANY:-Default Agency}
CA_USERNAME=${CA_USERNAME:-AGENCY1}
CA_PASSWORD=$CA_PASSWORD
CA_MAX=${CA_MAX:-100}
FRONTEND_ORIGINS=${FRONTEND_ORIGINS:-}
ENV
  chown $DEPLOY_USER:$DEPLOY_USER /etc/digitz/install.env; chmod 600 /etc/digitz/install.env
fi

echo "==> Nginx"
ROBOTS=""; [ "$ENV_NAME" != "production" ] && ROBOTS='add_header X-Robots-Tag "noindex, nofollow" always;'
cat > /etc/nginx/sites-available/digitz <<NGINX
server {
    listen 80;
    server_name $DOMAIN;
    root $APP_DIR;
    index index.html;
    client_max_body_size 2m;            # API accepts JSON only, max 1 MB
    server_tokens off;

    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Permissions-Policy "camera=(self), geolocation=(), microphone=()" always;
    $ROBOTS

    # Never serve secrets, schema, internals
    location ~ ^/(config\.php|config\.sample\.php|schema\.sql|install\.lock|install\.php|README\.md|DEPLOY\.md)$ { return 404; }
    location ~ ^/(api/lib|cron|deploy)/ { return 404; }
    location ~ /\. { return 404; }

    location = /sw.js         { add_header Cache-Control "no-cache"; add_header Service-Worker-Allowed "/"; }
    location = /index.html    { add_header Cache-Control "no-cache"; }
    location = /manifest.json { default_type application/manifest+json; add_header Cache-Control "no-cache"; }
    location ~ \.wasm\$        { default_type application/wasm; }
    location ~ \.traineddata\.gz\$ { default_type application/octet-stream; gzip off; }

    location ~ ^/api/[a-z]+\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php$PHPV-fpm.sock;
        fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
    }
    location ~ \.php\$ { return 404; }
    location / { try_files \$uri \$uri/ /index.html; }
}
NGINX
ln -sf /etc/nginx/sites-available/digitz /etc/nginx/sites-enabled/digitz
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> HTTPS certificate"
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect || \
  echo "!! Certificate failed. Check that $DOMAIN points to this server, then run: certbot --nginx -d $DOMAIN"

echo "==> PHP hardening"
sed -i 's/^expose_php = .*/expose_php = Off/' /etc/php/$PHPV/fpm/php.ini
systemctl restart php$PHPV-fpm

echo "==> Reminder job (every 30 minutes)"
( crontab -u $DEPLOY_USER -l 2>/dev/null | grep -v digitz; echo "*/30 * * * * php $APP_DIR/cron/reminders.php >> /home/$DEPLOY_USER/reminders.log 2>&1 # digitz" ) | crontab -u $DEPLOY_USER -

IP=$(curl -s https://api.ipify.org || hostname -I | awk '{print $1}')
cat <<DONE

==================================================================
 Server ready ($ENV_NAME) — add these to GitHub:
 Repo > Settings > Environments > "$ENV_NAME" (create it)

   Secrets:
     SSH_HOST     = $IP
     SSH_USER     = $DEPLOY_USER
     DEPLOY_PATH  = $APP_DIR
     SSH_KEY      = (the whole private key below, including BEGIN/END lines)
   Variables:
     APP_URL      = https://$DOMAIN

$(cat /root/digitz-deploy-key)

 Then push to the $BRANCH branch to deploy.
 First login after deploy:  SUPERADMIN / Superadmin@123  (you must change it)
 Default agency login:      ${CA_USERNAME:-AGENCY1} / $(grep CA_PASSWORD /etc/digitz/install.env 2>/dev/null | cut -d= -f2)
==================================================================
DONE
