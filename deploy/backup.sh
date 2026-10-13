#!/bin/bash
set -euo pipefail
umask 077
exec 9>/run/lock/staff-v2-backup.lock
flock -n 9 || exit 0
backup_dir=/var/backups/staff-v2
stamp=$(date +%Y%m%d-%H%M%S)
file="$backup_dir/staff-v2-$stamp.sql.gz"
configuration="$backup_dir/staff-v2-$stamp.config.tar.gz"
mariadb-dump --single-transaction --routines --events staff_v2 | gzip > "$file.tmp"
gzip -t "$file.tmp"
mv "$file.tmp" "$file"
(cd "$backup_dir" && sha256sum "$(basename "$file")") > "$file.sha256"
# Protected runtime credentials and TLS are also needed to restore the service.
tar -czf "$configuration.tmp" -C / opt/staff-v2/app/.env.local etc/staff-v2/server.crt etc/staff-v2/server.key
gzip -t "$configuration.tmp"
mv "$configuration.tmp" "$configuration"
(cd "$backup_dir" && sha256sum "$(basename "$configuration")") > "$configuration.sha256"
find "$backup_dir" -maxdepth 1 -type f \( -name 'staff-v2-*.sql.gz*' -o -name 'staff-v2-*.config.tar.gz*' \) -mtime +14 -delete
printf '%s\n' "$file" > /etc/staff-v2/last-backup
external_ok=false
external_dir=$(php <<'PHP'
<?php
$db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=staff_v2','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$value=$db->query("SELECT value_json FROM app_settings WHERE setting_key='backup_external_path'")->fetchColumn();
$decoded=$value===false?'':json_decode($value,true);
if(is_string($decoded))echo $decoded;
PHP
)
if [ -n "$external_dir" ] && [ -d "$external_dir" ]; then
  resolved=$(realpath -e -- "$external_dir")
  root_source=$(findmnt -n -o SOURCE --target /)
  target_source=$(findmnt -n -o SOURCE --target "$resolved")
  case "$resolved" in /opt/staff-v2/app|/opt/staff-v2/app/*) echo 'External backup path must stay outside application directories.' >&2 ;;
  *)
    if [ "$target_source" != "$root_source" ]; then
      if cp -- "$file" "$file.sha256" "$configuration" "$configuration.sha256" "$resolved/"; then external_ok=true; else echo 'External copy failed; local snapshot retained.' >&2; fi
    else echo 'External backup path is on the LXC root filesystem; configure an independent mount.' >&2
    fi
  esac
fi
printf '{"last_backup_at":"%s","local":true,"external":%s}\n' "$(date --iso-8601=seconds)" "$external_ok" > /opt/staff-v2/app/var/backup-status.json
chown www-data:www-data /opt/staff-v2/app/var/backup-status.json
chmod 0640 /opt/staff-v2/app/var/backup-status.json
