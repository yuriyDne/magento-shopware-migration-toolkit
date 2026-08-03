#!/usr/bin/env bash
# Create Shopware languages for a list of locale codes, before the migration
# connection is made.
#
# The store-view premapping can only map a Magento store view onto a Shopware
# language that ALREADY EXISTS. A fresh install has two languages; a 7-language
# source needs the other five created first, or those store views have nowhere
# to map and the migration cannot proceed.
#
# Shopware already ships the `locale` rows (zh-CN, ja-JP, ...); this only creates
# the `language` records that point at them. Idempotent: a locale that already
# has a language is skipped.
#
# Assumes a dockware-style container with MySQL on 127.0.0.1 (user root / pass root).
# Override with SW_CONTAINER / SW_DB_USER / SW_DB_PASS / SW_DB_NAME.
#
# Usage: create-target-languages.sh "zh-CN:Chinese" "ja-JP:Japanese" ...
#   each arg is  <shopware-locale-code>:<display name>
set -euo pipefail

container="${SW_CONTAINER:-shopware}"
dbuser="${SW_DB_USER:-root}"
dbpass="${SW_DB_PASS:-root}"
dbname="${SW_DB_NAME:-shopware}"

[ "$#" -gt 0 ] || { echo "usage: create-target-languages.sh <locale:name> ..."; exit 1; }

val() { docker exec "$container" mysql -h127.0.0.1 -u"$dbuser" -p"$dbpass" -N -B "$dbname" -e "$1" 2>/dev/null; }
sw()  { docker exec "$container" mysql -h127.0.0.1 -u"$dbuser" -p"$dbpass" --default-character-set=utf8mb4 "$dbname" -e "$1" 2>/dev/null; }

for pair in "$@"; do
  code="${pair%%:*}"
  name="${pair#*:}"

  locale_id=$(val "SELECT LOWER(HEX(id)) FROM locale WHERE code = '$code'")
  if [ -z "$locale_id" ]; then
    echo "skip $code — no such locale in Shopware"
    continue
  fi

  exists=$(val "SELECT COUNT(*) FROM language WHERE locale_id = UNHEX('$locale_id')")
  if [ "$exists" -gt 0 ]; then
    echo "exists: $name ($code)"
    continue
  fi

  id=$(val "SELECT LOWER(HEX(UNHEX(MD5(CONCAT('lang', '$code')))))")
  # translation_code_id points at the same locale — the snippet set to translate from
  sw "INSERT INTO language (id, name, locale_id, translation_code_id, created_at)
      VALUES (UNHEX('$id'), '$name', UNHEX('$locale_id'), UNHEX('$locale_id'), NOW(3))"
  echo "created: $name ($code)"
done

echo
docker exec "$container" mysql -h127.0.0.1 -u"$dbuser" -p"$dbpass" --table "$dbname" -e "
SELECT l.name, lo.code FROM language l JOIN locale lo ON lo.id = l.locale_id ORDER BY lo.code" 2>/dev/null
