# Magento → Shopware migration toolkit (Magento side)

Scripts and a checklist for the **Magento side** of a Magento 2 → Shopware 6 migration with the
official [Shopware Migration Assistant](https://github.com/shopware/SwagMigrationAssistant) and
its [Magento profile](https://github.com/shopware/SwagMigrationMagento). They automate the parts
the Assistant leaves to a developer: preparing the target so the premapping has something to map
onto, filling the premapping, and resolving fixable errors in bulk instead of 25 rows at a time.

Built and used in a local lab that ran the Assistant against several real Magento shops. The
findings behind each script are written up in the article linked below.

## Who this is for

Agencies and developers doing Magento → Shopware migrations who already know Magento, and want
to stop clicking through the Assistant's UI for operations that should be scripted. It assumes a
**local gateway** migration (direct DB), the usual path for a large shop.

## What's here

| Path | What it does |
|---|---|
| `scripts/preflight-report.sh` | Reads the Magento DB and reports everything to create in Shopware first (currencies, tax, locales, statuses, payment/shipping, custom columns, data the tool never carries). Read-only. |
| `scripts/prepare-target.php` | Creates those currencies / tax rates / customer groups / payment + shipping methods in Shopware via the Admin API, and sets the default currency. Idempotent. |
| `scripts/create-target-languages.sh` | Creates Shopware `language` records for a list of locales, so multi-language store views have somewhere to map. |
| `scripts/auto-premap.php` | Fills the migration premapping by name-matching, prints every decision, flags the guesses. |
| `scripts/bulk-fix.php` | Resolves whole groups of fixable errors through the Admin API (`swag_migration_fix`), driven by a JSON plan. |
| `scripts/disable-first-run-wizard.sh` | Stops the First Run Wizard overlaying the admin after a reinstall. |
| `gdpr-dump/config.example.yaml` | Example anonymization config for [gdpr-dump](https://github.com/Smile-SA/gdpr-dump) that fills the gaps the built-in `magento2` template misses (PSP JSON payloads, custom PII tables), using faker for plausible values. |
| `audit-checklist.md` | The pre- and post-migration audit list the scripts support. |
| `examples/` | Example input JSON for `prepare-target.php` and `bulk-fix.php`. |

## Typical order

1. `preflight-report.sh <db>` → read what the source needs. **The output quotes real domains and
   brand names — keep it private.**
2. Fill `examples/prepare-plan.example.json` from that report; run `prepare-target.php`.
3. `create-target-languages.sh "zh-CN:Chinese" …` for every source locale.
4. Create the migration connection in the Shopware admin, select data, then `auto-premap.php`.
5. Run the migration. At Error resolution, describe the fixable groups in a bulk-fix plan and run
   `bulk-fix.php`.
6. Work through `audit-checklist.md`, especially the post-run reconciliation.

## Configuration

The scripts default to a [dockware](https://dockware.io) install (`admin`/`shopware`, DB
`root`/`root`, `http://localhost`). Override with env vars — `SW_BASE`, `SW_ADMIN_USER`,
`SW_ADMIN_PASS`, `SW_DB_*`, `SW_CONTAINER`, and for preflight `DB_HOST`/`DB_USER`/`DB_PASSWORD`.
Nothing is hardcoded to a specific shop.

## What this does NOT do

- **Not a one-click migrator.** It prepares and accelerates; the Migration Assistant does the
  actual data transfer, and a human still verifies every result.
- **Does not fix the Assistant's bugs.** Where the tool mangles data (e.g. script-subtag locales,
  `currencyFactor = 0`, doubled category image paths), these scripts work *around* it; the fixes
  themselves are being reported upstream.
- **Does not migrate what the tool never carries** — invoices, shipments, credit memos, carts,
  price rules, CMS, wishlists, marketplace/seller data ([full list in `audit-checklist.md`](audit-checklist.md)).
  It only helps you *find and price* them.
- **`auto-premap.php`'s rules are examples**, tuned to specific shops. Read its output and adjust
  the needles to yours; it flags every low-confidence guess.
- **No anonymization guarantee.** `gdpr-dump/config.example.yaml` is a starting point with
  placeholder table names — you must inventory your own custom tables and keep value formats valid.
- **Not an audit of your shop.** This is a shared toolkit and a checklist of common patterns; a
  specific shop's data quality, custom modules and edge cases still need looking at directly.
- **Tested on Shopware 6.7.2.2, Migration Assistant 18.x, Magento profile 13.0.0.** Behaviour on
  other versions (and the upstream bugs above, once fixed) may differ — verify against your own.

## Known tool issues this repo works around

Reported upstream; the scripts and checklist work around them until they are fixed. (Issue links
are filled in once the reports are filed.)

| Issue | What breaks | Worked around by |
|---|---|---|
| [#18901](https://github.com/shopware/shopware/issues/18901) | Script-subtag locale (`zh_Hans_CN`) has no base-code fallback → language, channel and scoped entities dropped | `create-target-languages.sh` + `bulk-fix.php` (`@language:` per store view) |
| [#18902](https://github.com/shopware/shopware/issues/18902) | Unreachable media source: Downloading does not converge, migration cannot reach Done | checklist: confirm the media source is reachable before the run |
| [#18903](https://github.com/shopware/shopware/issues/18903) | Category image path built twice (`…/category//…/category/…`) → images 404 silently | checklist: verify category images after the run |
| [#18904](https://github.com/shopware/shopware/issues/18904) | `shopware/core: ~6.7` too loose vs a DBAL 4.4+ reader call → fatal on a 6.7 patch with DBAL < 4.4 | checklist: verify the plugin runs on your exact patch before a full read |
| [#18905](https://github.com/shopware/shopware/issues/18905) | `currencyFactor` copied as `0` for single-currency sources | checklist: verify `currencyFactor` after the run |

## Links

- Article (methodology + findings): [LINK: article]

## License

MIT — see [LICENSE](LICENSE).
