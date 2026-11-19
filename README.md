# GS – Woo Sync

WordPress plugin that keeps WooCommerce products in sync with a live Google Sheet (CSV export).

- Sheet columns mapped to product fields and meta (Settings › Column Mapping)
- Hourly sync via WP-Cron, plus a token-protected webhook for Google Apps Script (changes reach the shop in about 2 minutes)
- Image sideloading (only when the image URL changes), sync log with per-run rollback, email notifications

Used on b2b.jlautier.shop, jlautier.shop and toptechnix.com.

## Requirements
WordPress 5.8+, WooCommerce, PHP 8.0+.

## Installing
Download the latest `gs-woo-sync.zip` from **Releases** and upload it in wp-admin › Plugins › Add New › Upload Plugin.
