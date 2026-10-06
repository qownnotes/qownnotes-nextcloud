#!/bin/sh
# Enables the app from the mounted repository on every start of the container
set -eu

php /var/www/html/occ app:enable qownnotes
php /var/www/html/occ config:system:set debug --value=true --type=boolean
php /var/www/html/occ app:disable firstrunwizard || true
