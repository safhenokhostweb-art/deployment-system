#!/bin/bash

APP="/var/www/deployment-system/service"

chown -R www-data:www-data "$APP/storage" "$APP/bootstrap/cache" "$APP/database"

chmod -R ug+rwX "$APP/storage" "$APP/bootstrap/cache" "$APP/database"
