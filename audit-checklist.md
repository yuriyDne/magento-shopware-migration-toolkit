# Magento → Shopware migration audit checklist

A pre-migration audit list for the **Magento side** of a Magento 2 → Shopware 6 move,
using the official Shopware Migration Assistant (with the Magento profile). It is built
from repeated observations across real shops; every item is a thing that is cheaper to
catch before the run than to discover on the "Done" screen.

It pairs with the scripts in `scripts/`:
`preflight-report.sh` (measure the source), `prepare-target.php` (create what the target
lacks), `create-target-languages.sh`, `auto-premap.php`, `bulk-fix.php`.

## The one rule that explains half the list

**The premapping can only offer entities that already exist in the target.** A currency,
tax rate, language, payment or shipping method that is missing in Shopware *before* the
migration connection is created cannot be mapped onto. Missing currency fails loudly (every
order using it); missing tax rate fails *silently* (a wrong rate is chosen). So: create
everything the source needs in the target first. `preflight-report.sh` lists what that is.

## Before the run

- [ ] **Currencies.** Every `order_currency_code` and `base_currency_code` in the source
      exists in the target `currency` table. Missing → 100% of orders in that currency fail.
- [ ] **Default currency matches the source.** Order amounts are copied raw; they only mean
      the right thing if the target default currency is the source's. A fresh install is EUR.
- [ ] **`currencyFactor` on single-currency shops.** The converter copies Magento's
      `store_to_order_rate`, which is `0` when order currency == store currency — so migrated
      orders can carry `currencyFactor = 0`. Verify after the run; it fails no validation.
- [ ] **Tax rates.** Every rate the catalogue and orders use exists in the target. If the shop
      never used Magento's tax engine (historical `tax_percent` all 0), history migrates at 0%
      while the catalogue gets whatever rate you pick — decide deliberately.
- [ ] **Locales / languages.** A Shopware `language` exists for every store-view locale BEFORE
      connecting. Watch **script-subtag locales**: Magento `zh_Hans_CN` / `zh_Hant_TW` become
      `zh-Hans-CN` / `zh-Hant-TW`, which Shopware's `locale` table does not have (it uses
      `zh-CN` / `zh-TW`), and the converter does not fall back to the base code — the language,
      its sales channel, and everything scoped to it can fail. Same class: `sr-Latn`, `az-Latn`.
- [ ] **Order statuses.** Each custom Magento status maps to one of Shopware's fixed states;
      know the order volume behind each before choosing.
- [ ] **Payment & shipping methods.** A target method exists for each source method (Shopware
      has no PayPal / local gateways / marketplace payment out of the box).
- [ ] **Customer groups, countries, salutations** present in the target.
- [ ] **Plugin ↔ platform version.** Confirm the Migration Assistant + Magento profile actually
      run on your exact Shopware patch release, not just "~6.7" per composer — a loose constraint
      can pull a Doctrine DBAL where a reader call fatals only after a full read pass.

## Data the tool does not carry (price it separately)

- [ ] **Sales-channel domains.** The migration creates a sales channel per website but **no
      domain**. The migrated storefront cannot be opened until a domain is attached by hand, and
      the run still reports success. Plan a domain per channel.
- [ ] **Invoices, shipments, credit memos, payment transactions** — not migrated.
- [ ] **Carts/quotes, cart price rules, coupons, catalog price rules** — not migrated.
- [ ] **CMS pages/blocks, wishlists, gift messages** — not migrated.
- [ ] **Marketplace / multi-vendor** (seller accounts, commissions, per-seller tax/shipping) —
      Shopware CE has no seller concept; rebuild, buy a plugin, or drop by decision.
- [ ] **Custom columns on core tables** (extension data on `sales_order`, `customer_entity`,
      `quote`, …) — become Shopware custom fields or are dropped silently. `preflight-report.sh`
      section 12 finds candidates.
- [ ] **Two enumerated-but-unimplemented entities** (`product_multi_select_text_property`,
      `product_child_multi_select_text_property`) raise "reader/converter not found" every run —
      nothing to fix in the source.

## Failure modes to expect during the run

- [ ] **"Done" is not "success".** The final screen shows all steps green next to a log summary
      that can hold tens of thousands of errors and thousands of unwritten rows. Read the summary,
      not the ticks. Reconcile source vs target counts for every entity.
- [ ] **Address-less customers deadlock.** Shopware requires a default billing/shipping address;
      Magento does not. Each such customer raises two blocking errors, and the fix dialog can only
      offer addresses that already exist in the target (none, early on). Decide the data-cleanup
      before the run; a placeholder address unblocks it in the lab (`bulk-fix.php`).
- [ ] **Error resolution does not scale.** The screen fixes ~25 rows at a time and there is no
      CLI. At tens of thousands of fixable errors the UI is the obstacle; the supported bulk path
      is the Admin API (`bulk-fix.php`). Some "fixable" errors have no valid target value yet
      (they reference entities created only in the Writing step).
- [ ] **Unreachable media source stalls Downloading.** If media files cannot be fetched
      (source host not reachable from the Shopware container), the step re-enqueues them and does
      not converge; the migration cannot reach Done. Confirm the media source is reachable first.
- [ ] **Category images 404.** Category media paths can be built with the media directory doubled
      (`/media/catalog/category//media/catalog/category/…`); a missing image is not an error.

## After the run

- [ ] Source-vs-target counts reconciled per entity (products, customers, orders, order line
      items, newsletter, categories, SEO URLs). Explain every gap (e.g. guest orders create a
      customer each in Shopware; a configurable parent+children collapse differently).
- [ ] Every sales channel has a domain and opens.
- [ ] Spot-check order totals, `currencyFactor`, tax on both historical orders and a new order.
- [ ] Category and product images render in the storefront.
