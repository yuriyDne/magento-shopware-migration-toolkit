#!/usr/bin/env bash
# Read a Magento 2 database and report what must exist in Shopware BEFORE the
# migration connection is created.
#
# Why this exists: Migration Assistant's premapping can only offer entities that
# already exist in the target. A currency the target does not know fails 100% of
# orders loudly; a tax rate it does not know is picked wrong silently. Both are
# cheap to prevent and expensive to discover afterwards.
#
# Read-only. Point it at your Magento database (directly, or a throwaway copy).
#
# ⚠️  OUTPUT IS NOT PUBLISHABLE. It quotes real base URLs, website/brand names and
# store-view codes straight from the source. Keep the generated file private.
#
# Config via env (defaults shown):
#   SOURCE=direct                 # "direct" (mysql on DB_HOST) or "container" (docker exec)
#   DB_HOST=127.0.0.1  DB_PORT=3306
#   DB_USER=root       DB_PASSWORD=            # export DB_PASSWORD, do not hardcode
#   MAGENTO_CONTAINER=magento                  # used only when SOURCE=container
#
# Usage:
#   DB_PASSWORD=secret preflight-report.sh <database> [output.md]
#   SOURCE=container MAGENTO_CONTAINER=magento preflight-report.sh magento out.md
set -euo pipefail

db="${1:?usage: preflight-report.sh <database> [output.md]}"
out="${2:-./preflight-$db.md}"      # keep this file private — it quotes real domains and brand names
source_kind="${SOURCE:-direct}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
MAGENTO_CONTAINER="${MAGENTO_CONTAINER:-magento}"

if [ "$source_kind" = "container" ]; then
  q() { docker exec "$MAGENTO_CONTAINER" mysql -u"$DB_USER" ${DB_PASSWORD:+-p"$DB_PASSWORD"} --table "$db" -e "$1" 2>/dev/null || true; }
  raw() { docker exec "$MAGENTO_CONTAINER" mysql -u"$DB_USER" ${DB_PASSWORD:+-p"$DB_PASSWORD"} -N -B -e "$1" 2>/dev/null || echo 0; }
else
  q() { MYSQL_PWD="$DB_PASSWORD" mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --table "$db" -e "$1" 2>/dev/null || true; }
  raw() { MYSQL_PWD="$DB_PASSWORD" mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -N -B -e "$1" 2>/dev/null || echo 0; }
fi

has() {
  local n
  n=$(raw "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db' AND table_name='$1'")
  [ "${n:-0}" -gt 0 ]
}

{
echo "# Pre-flight report"
echo
echo "What to create in Shopware before creating the migration connection."
echo "Generated from the source database. Read-only; nothing was changed."
echo

echo "## 1. Currencies — create every one of these first"
echo
echo "The order converter resolves currency by ISO code straight against the target"
echo "\`currency\` table. A missing currency fails every order that uses it."
echo
q "SELECT order_currency_code AS iso, COUNT(*) AS orders,
   MIN(created_at) AS first_order, MAX(created_at) AS last_order
   FROM sales_order GROUP BY order_currency_code ORDER BY orders DESC"
echo
echo "Base currencies in use (the shop's own accounting currency):"
q "SELECT base_currency_code AS iso, COUNT(*) AS orders FROM sales_order
   GROUP BY base_currency_code ORDER BY orders DESC"
echo
echo "Configured currency settings:"
q "SELECT scope, scope_id, path, value FROM core_config_data
   WHERE path LIKE 'currency/options/%' ORDER BY path, scope_id"

echo
echo "## 2. Tax — create these rates before mapping anything"
echo
echo "The tax premapping offers only rates that already exist in the target. On a"
echo "fresh install those are the German defaults, so without preparation the whole"
echo "catalogue lands on a wrong rate without a single error."
echo
q "SELECT COUNT(*) AS configured_rates, MIN(rate) AS min_rate, MAX(rate) AS max_rate
   FROM tax_calculation_rate"
echo
q "SELECT tax_country_id AS country, COUNT(*) AS rates,
   ROUND(MIN(rate),3) AS min_rate, ROUND(MAX(rate),3) AS max_rate
   FROM tax_calculation_rate GROUP BY tax_country_id ORDER BY rates DESC"
echo
echo "Product tax classes actually used, with product counts:"
q "SELECT tc.class_name, v.value AS class_id, COUNT(*) AS products
   FROM catalog_product_entity_int v
   JOIN eav_attribute ea ON ea.attribute_id = v.attribute_id AND ea.attribute_code = 'tax_class_id'
   LEFT JOIN tax_class tc ON tc.class_id = v.value
   WHERE v.store_id = 0 GROUP BY tc.class_name, v.value ORDER BY products DESC"
echo
echo "Tax actually charged on historical orders:"
q "SELECT tax_percent, COUNT(*) AS items, ROUND(SUM(tax_amount),2) AS tax_total
   FROM sales_order_item GROUP BY tax_percent ORDER BY items DESC LIMIT 10"
echo
echo "If the total above is 0 the shop never used Magento's tax engine. Historical"
echo "orders will migrate at 0% while the catalogue gets whatever rate you choose,"
echo "so history and catalogue will disagree by construction. Decide deliberately."

echo
echo "## 3. Order statuses — every one needs a Shopware state"
echo
echo "Shopware has a fixed set of order states. Each custom Magento status has to be"
echo "mapped onto one of them, and the premapping screen does not show how many"
echo "orders sit behind each choice. Here are the volumes."
echo
# the state lives in sales_order_status_state, not in sales_order_status
q "SELECT o.status, s.label, ss.state AS magento_state, COUNT(*) AS orders
   FROM sales_order o
   LEFT JOIN sales_order_status s ON s.status = o.status
   LEFT JOIN sales_order_status_state ss ON ss.status = o.status
   GROUP BY o.status, s.label, ss.state ORDER BY orders DESC"

echo
echo "## 4. Payment methods — each needs a target method"
echo
echo "Shopware's default list has no PayPal, no local gateways and no marketplace"
echo "payment. Anything without a plugin becomes a custom method or a wrong mapping."
echo
q "SELECT method, COUNT(*) AS orders FROM sales_order_payment
   GROUP BY method ORDER BY orders DESC"

echo
echo "## 5. Shipping methods"
echo
q "SELECT shipping_method, COUNT(*) AS orders FROM sales_order
   WHERE shipping_method IS NOT NULL GROUP BY shipping_method ORDER BY orders DESC"

echo
echo "## 6. Websites, stores and languages — one sales channel each"
echo
echo "Migration Assistant creates a sales channel per website and attaches products,"
echo "categories and SEO URLs to it — but it creates NO domain. Without a domain the"
echo "migrated shop cannot be opened, and the process still reports success."
echo
q "SELECT w.website_id, w.name AS website, g.name AS store_group,
   s.store_id, s.name AS store_view, s.code
   FROM store_website w
   LEFT JOIN store_group g ON g.website_id = w.website_id
   LEFT JOIN store s ON s.group_id = g.group_id
   WHERE w.website_id > 0 ORDER BY w.website_id, s.store_id"
echo
echo "Locales per scope — each distinct one needs a Shopware language:"
q "SELECT scope, scope_id, value AS locale FROM core_config_data
   WHERE path = 'general/locale/code' ORDER BY scope, scope_id"
echo
echo "Base URLs (the domains the migrated channels will need):"
q "SELECT scope, scope_id, path, value FROM core_config_data
   WHERE path IN ('web/unsecure/base_url','web/secure/base_url') ORDER BY scope_id"

echo
echo "## 7. Customer groups"
echo
q "SELECT cg.customer_group_id, cg.customer_group_code, COUNT(c.entity_id) AS customers
   FROM customer_group cg LEFT JOIN customer_entity c ON c.group_id = cg.customer_group_id
   GROUP BY cg.customer_group_id, cg.customer_group_code ORDER BY customers DESC"

echo
echo "## 8. Countries in use — activate these in Shopware"
echo
q "SELECT country_id, COUNT(*) AS addresses FROM customer_address_entity
   GROUP BY country_id ORDER BY addresses DESC LIMIT 20"

echo
echo "## 9. Product types — what needs an equivalent"
echo
q "SELECT type_id, COUNT(*) AS products FROM catalog_product_entity
   GROUP BY type_id ORDER BY products DESC"
echo
echo "Attributes used as configurable options become Shopware property groups:"
q "SELECT ea.attribute_code, ea.frontend_label, COUNT(DISTINCT sa.product_id) AS used_by
   FROM catalog_product_super_attribute sa
   JOIN eav_attribute ea ON ea.attribute_id = sa.attribute_id
   GROUP BY ea.attribute_code, ea.frontend_label ORDER BY used_by DESC"

echo
echo "## 10. Customers without an address"
echo
echo "Shopware requires a default billing and shipping address on every customer;"
echo "Magento does not. Each of these produces two blocking errors, and the fix"
echo "dialog can only offer addresses that already exist in the target."
echo
q "SELECT COUNT(*) AS customers_without_address FROM customer_entity c
   LEFT JOIN customer_address_entity a ON a.parent_id = c.entity_id
   WHERE a.entity_id IS NULL"

echo
echo "## 11. Data classes the tool never offers"
echo
echo "No data set covers these. They produce no errors and no log entries — they are"
echo "simply absent afterwards. Inventory them before quoting the project."
echo
q "SELECT 'invoices' AS entity, COUNT(*) AS rows_in_source FROM sales_invoice
   UNION ALL SELECT 'shipments', COUNT(*) FROM sales_shipment
   UNION ALL SELECT 'credit memos', COUNT(*) FROM sales_creditmemo
   UNION ALL SELECT 'payment transactions', COUNT(*) FROM sales_payment_transaction
   UNION ALL SELECT 'carts', COUNT(*) FROM quote
   UNION ALL SELECT 'cart price rules', COUNT(*) FROM salesrule
   UNION ALL SELECT 'coupons', COUNT(*) FROM salesrule_coupon
   UNION ALL SELECT 'catalog price rules', COUNT(*) FROM catalogrule
   UNION ALL SELECT 'CMS pages', COUNT(*) FROM cms_page
   UNION ALL SELECT 'CMS blocks', COUNT(*) FROM cms_block
   UNION ALL SELECT 'reviews', COUNT(*) FROM review
   UNION ALL SELECT 'wishlist items', COUNT(*) FROM wishlist_item
   UNION ALL SELECT 'gift messages', COUNT(*) FROM gift_message"

echo
echo "## 12. Custom columns on core tables — candidates for custom fields"
echo
echo "Anything here is data the profile does not know about. It has to become a"
echo "Shopware custom field or it is dropped without a word."
echo
for t in sales_order customer_entity quote sales_order_item; do
  if has "$t"; then
    echo
    echo "### $t"
    # common third-party vendor prefixes + telltale suffixes; a core column rarely
    # looks like this. Extend the prefix list with your own extensions' prefixes.
    q "SELECT column_name, data_type FROM information_schema.columns
       WHERE table_schema = '$db' AND table_name = '$t'
       AND (column_name REGEXP '(consent|token|_synced|_score|_level|custom_|_intent|_charge)'
            OR column_name REGEXP '^(kl_|mp_|qbo_|stripe_|carrier|wk_|amasty_|mageplaza_)')
       ORDER BY ordinal_position"
  fi
done
echo
echo "Review the full column list by hand as well — this filter only catches common"
echo "suffixes and will miss anything named unusually."

# This reflects the table layout of one common multi-vendor extension (a Webkul-style
# marketplace_* schema). Marketplace extensions vary — adjust the table names to whatever
# marketplace / multi-vendor module your shop actually uses.
if has "marketplace_userdata"; then
echo
echo "## 13. Marketplace — no Shopware equivalent exists"
echo
echo "Shopware 6 CE has no seller concept at all. Everything below has to be rebuilt,"
echo "bought as a plugin, or dropped by an explicit decision."
echo
q "SELECT COUNT(*) AS sellers FROM marketplace_userdata"
if has "marketplace_saleslist"; then
  q "SELECT COUNT(*) AS seller_sale_lines, ROUND(SUM(total_commission),2) AS total_commission,
     ROUND(SUM(actual_seller_amount),2) AS seller_payouts FROM marketplace_saleslist"
fi
if has "marketplace_sellertransaction"; then
  q "SELECT COUNT(*) AS payout_transactions FROM marketplace_sellertransaction"
fi
if has "marketplace_seller_taxrates"; then
  echo
  echo "Per-seller tax rates — Shopware taxes are shop-wide, not per seller:"
  q "SELECT COUNT(*) AS per_seller_tax_rates FROM marketplace_seller_taxrates"
fi
if has "marketplace_shipping_setting"; then
  echo
  echo "Per-seller shipping origins — Shopware ships from one origin per channel:"
  q "SELECT COUNT(*) AS seller_shipping_settings FROM marketplace_shipping_setting"
fi
if has "marketplace_stripe_seller"; then
  echo
  echo "Seller payment accounts (these hold live credentials — wipe before any dump):"
  q "SELECT COUNT(*) AS connect_accounts FROM marketplace_stripe_seller"
fi
fi

echo
echo "## Checklist produced by this report"
echo
echo "- [ ] Create every currency from section 1 in the target shop"
echo "- [ ] Create every tax rate from section 2, and decide what historical 0% orders mean"
echo "- [ ] Decide a Shopware state for each order status in section 3, knowing the volumes"
echo "- [ ] Install or build a payment method for each entry in section 4"
echo "- [ ] Install or build a shipping method for each entry in section 5"
echo "- [ ] Create a language for each locale in section 6"
echo "- [ ] Plan a domain for every sales channel the migration will create — it creates none"
echo "- [ ] Create the customer groups from section 7"
echo "- [ ] Activate the countries from section 8"
echo "- [ ] Decide what happens to the address-less customers in section 10"
echo "- [ ] Price the work for everything in section 11 — none of it migrates"
echo "- [ ] Model the custom columns from section 12 as custom fields, or drop them knowingly"
echo "- [ ] If section 13 exists, scope the marketplace rebuild separately from the migration"
} > "$out" 2>&1

echo "written: $out"
