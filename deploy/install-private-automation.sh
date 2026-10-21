#!/bin/bash
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then echo 'Run this installer as root.' >&2; exit 1; fi
app=/opt/staff-v2/app
sources=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
test -f "$app/bin/console"
# Check that code and additive migration are present before enabling the timer.
sudo -u www-data env APP_ENV=prod APP_DEBUG=0 php "$app/bin/console" app:private-automation --status --no-debug --no-interaction >/dev/null
install -o root -g root -m 0644 "$sources/staff-v2-private-automation.service" /etc/systemd/system/staff-v2-private-automation.service
install -o root -g root -m 0644 "$sources/staff-v2-private-automation.timer" /etc/systemd/system/staff-v2-private-automation.timer
systemd-analyze verify /etc/systemd/system/staff-v2-private-automation.service /etc/systemd/system/staff-v2-private-automation.timer
systemctl daemon-reload
systemctl enable --now staff-v2-private-automation.timer
echo 'Private scheduler installed; rules remain as configured, no rules were created or enabled.'
