# Changelog

## 1.8.0 — 19 Nov 2026
- Images column can hold several URLs separated by commas: the first is the main image, the rest become the product gallery. Gallery images are only downloaded when the list changes, and images already downloaded are reused. Sheets with one URL per product work as before.

## 1.7.0 — 19 Nov 2026
- Updates from GitHub: sites show "Update available" in wp-admin when a newer version is on the `main` branch, and update with one click (no zip uploads).
- `Update URI` header, so WordPress never confuses this plugin with one of the same name on wordpress.org.
- Webhook: a token sent as an array is rejected cleanly instead of causing a PHP error.

## 1.6.0
- Version running on b2b.jlautier.shop since 7 Nov 2026 (first version in this repo).
