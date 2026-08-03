#!/usr/bin/env bash
# Turn off Shopware's First Run Wizard.
#
# `system:install --force` resets the FRW to "not completed", so after every
# reinstall the wizard reappears and overlays the whole admin — including the
# Migration Assistant — until it is dismissed step by step. Setting
# core.frw.completedAt makes frwShouldRun() return false, so it never shows.
#
# Idempotent. Safe to run any time.
#
# Assumes a dockware-style container. Override the container name with SW_CONTAINER.
set -euo pipefail

container="${SW_CONTAINER:-shopware}"

docker exec "$container" php /var/www/html/bin/console system:config:set \
  core.frw.completedAt "2024-01-01T00:00:00+00:00" >/dev/null

echo "first run wizard disabled (core.frw.completedAt set)"
