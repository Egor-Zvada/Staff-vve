#!/bin/bash
set -euo pipefail
app=/opt/staff-v2/app
mkdir -p /tmp/staff-v2-deploy
tar -xzf /tmp/staff-v2-app.tar.gz -C "$app"
php /tmp/staff-v2-deploy/setup.php
install -m 0644 /tmp/staff-v2-deploy/nginx-staff-v2.conf /etc/nginx/sites-available/staff-v2
ln -sf /etc/nginx/sites-available/staff-v2 /etc/nginx/sites-enabled/staff-v2
if [ -L /etc/nginx/sites-enabled/default ]; then unlink /etc/nginx/sites-enabled/default; fi
cp /usr/share/fonts/truetype/dejavu/DejaVuSans.ttf "$app/public/assets/DejaVuSans.ttf"
cp /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf "$app/public/assets/DejaVuSans-Bold.ttf"
chown -R codex-maint:www-data "$app/src" "$app/templates" "$app/public" "$app/config" "$app/migrations" "$app/vendor"
find "$app/src" "$app/templates" "$app/public" "$app/config" "$app/migrations" "$app/vendor" -type d -exec chmod 0750 {} +
find "$app/src" "$app/templates" "$app/public" "$app/config" "$app/migrations" "$app/vendor" -type f -exec chmod 0640 {} +
chown -R www-data:www-data "$app/var"
sudo -u www-data php "$app/bin/console" cache:clear --no-debug
nginx -t
systemctl reload nginx
systemctl restart php8.4-fpm
install -m 0750 /tmp/staff-v2-deploy/backup.sh /usr/local/sbin/staff-v2-backup
install -m 0644 /tmp/staff-v2-deploy/staff-v2-backup.service /etc/systemd/system/staff-v2-backup.service
install -m 0644 /tmp/staff-v2-deploy/staff-v2-backup.timer /etc/systemd/system/staff-v2-backup.timer
systemctl daemon-reload
systemctl enable --now staff-v2-backup.timer
/usr/local/sbin/staff-v2-backup
echo 'Staff V2 activated.'
