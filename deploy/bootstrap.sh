#!/bin/bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx mariadb-server php8.4-fpm php8.4-cli php8.4-mysql php8.4-gd php8.4-mbstring php8.4-xml php8.4-zip php8.4-curl php8.4-intl php8.4-sqlite3 composer unzip curl openssl fonts-dejavu-core
sed -i 's/^worker_cpu_affinity auto;/# CPU affinity is managed by the LXC host./' /etc/nginx/nginx.conf
timedatectl set-timezone Asia/Sakhalin
install -d -o codex-maint -g www-data -m 2750 /opt/staff-v2/app
install -d -o www-data -g www-data -m 0750 /opt/staff-v2/app/var /opt/staff-v2/app/var/log /opt/staff-v2/app/var/cache /opt/staff-v2/app/var/uploads /opt/staff-v2/app/var/sessions
install -d -o root -g root -m 0700 /etc/staff-v2 /var/backups/staff-v2
printf '%s\n' 'date.timezone = Asia/Sakhalin' 'expose_php = Off' 'memory_limit = 128M' 'upload_max_filesize = 8M' 'post_max_size = 10M' 'session.cookie_httponly = 1' 'session.cookie_samesite = Lax' 'session.cookie_secure = 1' 'opcache.memory_consumption = 96' 'opcache.validate_timestamps = 1' > /etc/php/8.4/mods-available/staff-v2.ini
phpenmod staff-v2
cat > /etc/mysql/mariadb.conf.d/70-staff-v2.cnf <<'EOF'
[mysqld]
bind-address=127.0.0.1
innodb_buffer_pool_size=320M
max_connections=30
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
EOF
cat > /etc/php/8.4/fpm/pool.d/staff-v2.conf <<'EOF'
[staff-v2]
user=www-data
group=www-data
listen=/run/php/staff-v2.sock
listen.owner=www-data
listen.group=www-data
pm=ondemand
pm.max_children=4
pm.process_idle_timeout=20s
pm.max_requests=500
clear_env=yes
catch_workers_output=yes
php_admin_value[memory_limit]=128M
php_admin_value[date.timezone]=Asia/Sakhalin
EOF
if [ ! -f /etc/staff-v2/server.key ]; then
  openssl req -x509 -newkey rsa:3072 -nodes -days 90 -keyout /etc/staff-v2/server.key -out /etc/staff-v2/server.crt -subj '/CN=192.168.110.202' -addext 'subjectAltName=IP:192.168.110.202,DNS:staff-v2'
  chmod 600 /etc/staff-v2/server.key
fi
systemctl enable nginx mariadb php8.4-fpm
systemctl restart mariadb php8.4-fpm
echo 'Staff V2 runtime installed; application pending.'
