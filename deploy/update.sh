#!/bin/bash
set -euo pipefail
app=/opt/staff-v2/app
tar -xzf /tmp/staff-v2-app.tar.gz -C "$app"
chown -R codex-maint:www-data "$app/src" "$app/templates" "$app/public" "$app/config" "$app/migrations" "$app/bin"
find "$app/src" "$app/templates" "$app/public" "$app/config" "$app/migrations" "$app/bin" -type d -exec chmod 0750 {} +
find "$app/src" "$app/templates" "$app/public" "$app/config" "$app/migrations" "$app/bin" -type f -exec chmod 0640 {} +
chown -R www-data:www-data "$app/var"
sudo -u www-data php "$app/bin/console" cache:clear --no-debug
sudo -u www-data env APP_ENV=qa php "$app/bin/console" cache:clear --no-debug
sudo -u www-data php "$app/bin/console" lint:twig "$app/templates" --no-debug
systemctl restart php8.4-fpm
echo 'Code and templates updated; schema and credentials retained.'
