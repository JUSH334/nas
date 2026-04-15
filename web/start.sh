#!/bin/bash
# Fix ownership on bind-mounted backups folder
chown -R www-data:www-data /var/www/backups 2>/dev/null || true

# Start cron daemon in background (log to cron.log)
cron
echo "$(date '+%Y-%m-%d %H:%M:%S') Cron daemon started" >> /var/log/cron.log

# Log startup
echo "$(date '+%Y-%m-%d %H:%M:%S') NAS web server starting" >> /var/log/cron.log

# Start Apache in foreground (default CMD)
apache2-foreground
