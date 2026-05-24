# Changelog

All notable changes to JFB WC Quotes Advanced are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
the project loosely follows [Semantic Versioning](https://semver.org/).

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
