# Changelog

All notable changes to JFB WC Quotes Advanced are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
the project loosely follows [Semantic Versioning](https://semver.org/).

## [1.27.0] - 2026-05-24

### Added
- **Two new settings sections** for the [Order Details Table] placeholder
  rendering, one per email type:
  - `Estimate Request Email - Order Details Table` (9 toggles, all
    OFF by default - the acknowledgement email stays free of pricing
    info unless the admin opts in)
  - `Prepared Quote Email - Order Details Table` (9 toggles, mostly
    ON by default - a prepared quote should include pricing)
  - Toggles: show product images, unit price column, line total
    column, subtotal row, shipping rows, fee rows, discount row,
    tax row, grand total row.
- **Fees and shipping render as table-body rows** (when their toggle
  is on) instead of only appearing in the totals footer. Each fee
  becomes its own line in the items table prefixed with "Fee:" in
  italics; each shipping method does the same with "Shipping:".
- New helper functions exposing the new rendering primitives so
  follow-up commits (v1.28 modal rebuild) can reuse them:
  - `jfbwqa_default_table_config()`
  - `jfbwqa_get_table_config_from_settings( 'estimate' | 'quote' )`
  - `jfbwqa_normalize_legacy_table_args( $show_prices, $show_grand, $disc )`
  - `jfbwqa_render_order_details_table( $order, $config )`
  - `jfbwqa_render_fee_rows_html( $order, $config )`
  - `jfbwqa_render_shipping_rows_html( $order, $config )`
  - `jfbwqa_render_synthetic_row_html( $name, $qty, $total, $cfg, $prefix )`

### Changed
- `jfbwqa_replace_email_placeholders()` 3rd argument now accepts an
  array (the new table_config dict) OR the legacy boolean shape
  (`$show_prices`, `$show_grand_total_with_tax`, `$display_discount`).
  Callers using the old positional signature still produce identical
  output; their values are translated via
  `jfbwqa_normalize_legacy_table_args()`.
- `jfbwqa_handle_order_action()` (Estimate Request order action) now
  reads the new estimate-side settings and passes the resolved config.
  Previously it called the placeholder replacer with no args, which
  meant fees, shipping, subtotal, and grand total could never appear
  even when present on the order.
- `jfbwqa_handle_send_prepared_quote_action()` (Prepared Quote AJAX
  send) now reads the new quote-side settings and overlays the modal
  checkboxes (Include Pricing / Grand Total w/ Tax / Display Discount)
  on top. Net effect: settings provide the defaults; modal still wins
  per-send. The legacy "subtotal/total appendix" block (a separate
  unstyled table appended after the body) was removed - that
  responsibility now lives entirely in the table footer, gated by the
  config.
- `woocommerce/emails/email-order-items.php` template updated to read
  `$jfbwqa_config` (new) instead of `$show_prices` (legacy). Image,
  unit price, and line total columns are now individually gated.
- The `payment_method` row is **always** filtered out of the totals
  footer in emails (it's UI scaffolding, not customer-facing info).

### Deprecated
- `display_discount_in_quote` setting is superseded by
  `quote_table_show_discount`. The old key is still read on legacy
  installs but the new section's toggle is the source of truth going
  forward. Field label updated to flag the deprecation.

### Migration notes
- Sites upgrading from v1.26 or earlier:
  - Visit `Settings -> JFB WC Quotes`. Two new sections will be
    visible at the bottom: "Estimate Request Email - Order Details
    Table" and "Prepared Quote Email - Order Details Table".
  - The estimate-side defaults to everything OFF; if your
    acknowledgement emails should now show pricing, opt in here.
  - The quote-side defaults are: images ON, unit price ON, line total
    ON, subtotal ON, shipping ON, fees ON, tax ON, grand total ON,
    discount OFF (matches old default).
- Existing modal checkboxes on the order edit screen still work and
  override the settings defaults per-send.
- Email body templates that include `[Order Details Table]` continue
  to work without changes.

---

## [1.26.0] - 2026-05-24

### Added
- **"Auto-derive from current mapping" button** on the JetEngine Meta
  Keys textarea (Settings -> JFB WC Quotes -> General Settings).
  Scans the saved `field-mapping.json` for any `*JE_meta*.<key>`
  targets and one-click merges the unique keys into the textarea.
  Client-side merge only; nothing persists until "Save All Settings"
  is clicked. The button is hidden when no JE-meta targets exist
  in the mapping yet, with a hint message instead.

### Why
The textarea drives the mapping dropdown options and the email
placeholder priority list. The mapping itself is the source of
truth for routing form values, so a key being mapped without being
listed in this textarea still works at submit time. But rebuilding
the mapping table or expanding `{[<key>]}` placeholders is faster /
more convenient when the textarea matches the mapping. This button
keeps the two in sync without forcing the admin to retype keys
they already used.

---

## [1.25.0] - 2026-05-24

### Security
- **Removed `plugin-config.json`** from the plugin folder. The file
  contained a live WooCommerce REST API consumer_key/consumer_secret
  pair in plaintext, deployed at world-readable permissions on disk
  and committed to the public `expansion` branch on GitHub. The leaked
  pair has been revoked. The plugin never read this file at runtime;
  settings live in `wp_options` under `jfbwqa_options`.
- Removed several development artifacts that were also being shipped
  with the plugin: `-advanced.php` (binary fragment),
  `.jfb-wc-quotes-advanced.php.swp` (Vim swap file), `Documents.lnk`,
  `New Text Document.txt`, and `ion` (a captured `less` help screen).
- Added `.gitignore` to prevent the same classes of files from being
  recommitted (editor swaps, OS junk, backup files, future
  `plugin-config.json` drops).

### Changed - HPOS compatibility (breaking for legacy-only assumptions)
The plugin is now HPOS-aware. Several silent breakages on
HPOS-enabled sites are fixed:

- Declared compatibility with `custom_order_tables` and
  `cart_checkout_blocks` via `FeaturesUtil::declare_compatibility()`.
  The plugin no longer shows up as "uncertain" in
  WooCommerce -> Settings -> Advanced -> Features.
- Custom-Email-Message meta box now registers against the correct
  screen ID on both legacy (`shop_order`) and HPOS
  (`woocommerce_page_wc-orders`) sites. Previously it silently never
  rendered on HPOS.
- Both order-meta savers (custom email message, prepared quote
  options) moved from `save_post_shop_order` (legacy-only) to
  `woocommerce_process_shop_order_meta`, the canonical hook that
  fires in both stores. Previously textarea data was silently lost
  on HPOS.
- Order-edit script enqueue now matches both screen contexts
  (`post.php` + `shop_order` post type, and the HPOS
  `woocommerce_page_wc-orders` screen). Previously the script
  silently failed to enqueue on HPOS.
- All order meta reads/writes outside the form handler switched
  from `update_post_meta()` / `get_post_meta()` to
  `WC_Order::update_meta_data()` / `get_meta()` so values land in
  the active order store regardless of HPOS state.
- Cache-busting on the admin script changed from
  `JFBWQA_VERSION . '-' . time()` to plain `JFBWQA_VERSION`. The
  previous value re-fetched the script on every admin page load.
- Modal HTML output uses a single shared HPOS-aware screen
  resolver (`jfbwqa_get_current_admin_order()`) and passes the
  resolved order_id explicitly to the inline JS, so the AJAX
  handler no longer reads from `#post_ID` (which doesn't exist on
  the HPOS order edit screen).

### Changed - Order creation no longer uses the WC REST API
The form-submission handler used to make a self-loopback HTTP
call to `/wp-json/wc/v3/orders` using stored consumer credentials.
This has been replaced with in-process `wc_create_order()` plus
native `WC_Order` setters.

Why:
- Removes the credential dependency entirely. No more
  `consumer_key` / `consumer_secret` settings, no more
  `plugin-config.json` style leak vector.
- Removes one HTTP roundtrip per submission.
- HPOS-safe by construction (uses CRUD methods rather than direct
  postmeta writes).

The mapping schema (`field-mapping.json`) is unchanged. All
existing target tokens still work: `billing.x`, `shipping.x`,
`customer_note`, `meta_data.x`, `*JE_meta*.x`, `*Cart items list*`.

The handler now returns one new error class
(`jfbwqa_create_error`) when `wc_create_order()` fails, and three
old error classes are no longer reachable
(`jfbwqa_config_error`, `jfbwqa_api_error`, `jfbwqa_wc_error`).

### Removed
- Settings page fields: `WooCommerce Consumer Key` and
  `WooCommerce Consumer Secret`.
- Defaults for `consumer_key` and `consumer_secret` in
  `jfbwqa_get_options()`.
- Sanitizer entries for those two keys in `jfbwqa_sanitize_options()`.
- The unconditional "Plugin file loaded" debug-log line that fired
  on every request even when `enable_debug` was off.

### Added
- One-time, dismissible admin notice on the JFBWQA settings page
  that surfaces any legacy `consumer_key` / `consumer_secret` left
  over in `wp_options` from a pre-1.25 install, with a button to
  remove them.
- Plugin header now declares `Requires Plugins: woocommerce, jetformbuilder`,
  `WC requires at least: 7.1`, and `WC tested up to: 10.7`.

### Migration notes
- After upgrading, visit `WooCommerce -> Settings -> Advanced -> REST API`
  and revoke any keys whose description was tied to this plugin.
  The plugin no longer needs them.
- If the dismissible "Legacy WooCommerce REST API credentials" notice
  appears on the settings page, click "Remove legacy credentials"
  to clear the values from this plugin's options.

---

## [1.24.1] - 2026-05-24

### Security
- Repo hygiene cleanup. See commit `e6ca5aa`.

---

## [1.24] and earlier

See git history. Headline changes:

- **1.24** - Display Discount Row checkbox in modal with per-quote
  override.
- **1.23** - Modal success animation JS error fixed.
- **1.22** - Modal cache-busting and debug info.
- **1.21** - Display discount row toggle, modal UX polish, "Quote Sent"
  status added.
- **1.14-1.20** - Mapping UI, Settings API rebuild, various
  email-template iterations.
- **1.13 and earlier** - Initial JFB-to-Woo bridge with hardcoded
  REST credentials.
