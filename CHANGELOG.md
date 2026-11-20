# Changelog

## 1.9.0 — 20 Nov 2026
- The sheet is read as a proper CSV stream: cells may now contain line breaks (multi-paragraph descriptions no longer break the row).
- New filter `gs_woo_sync_skip_row( $skip, $sku, $row )` so another plugin can hold back a row (used by OMC Client Panel while a website change is waiting to be written to the sheet).
- Sale dates: new fields "Sale Start Date" / "Sale End Date" (auto-detected from headers such as "Date sale price starts" / "Date sale price ends", "Sale start", "Sale end"). Dates may be written 2026-12-24 or 24/12/2026; the sale runs from the start of the first day to the end of the last day (site time zone).
- When a sheet has sale date columns, it runs sales completely: an empty sale price ends the sale on the website. Sheets without date columns behave as before (an empty sale price is left alone). Override with filter `gs_woo_sync_sheet_runs_sales`.

## 1.8.0 — 19 Nov 2026
- Images column can hold several URLs separated by commas: the first is the main image, the rest become the product gallery. Gallery images are only downloaded when the list changes, and images already downloaded are reused. Sheets with one URL per product work as before.

## 1.7.0 — 19 Nov 2026
- Updates from GitHub: sites show "Update available" in wp-admin when a newer version is on the `main` branch, and update with one click (no zip uploads).
- `Update URI` header, so WordPress never confuses this plugin with one of the same name on wordpress.org.
- Webhook: a token sent as an array is rejected cleanly instead of causing a PHP error.

## 1.6.0
- Version running on b2b.jlautier.shop since 7 Nov 2026 (first version in this repo).
