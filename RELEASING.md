# Releasing a new version

Sites check the `main` branch on GitHub (at most every 12 hours) and offer the update in wp-admin.

1. Make the change on a branch and test it on a local or staging site.
2. In `gs-woo-sync.php`, raise **both** the `Version:` header and `GS_WOO_SYNC_VERSION`, e.g. 1.7.0 → 1.7.1.
3. Add a line to `CHANGELOG.md`.
4. Merge into `main`. That is the release.
5. On each site: Plugins › **Check again** under Dashboard › Updates (or wait), then **Update now**. Settings, mapping, logs and the webhook token are kept.

Only push to `main` what is ready for every site (J Lautier B2B, J Lautier retail, Top Technix).

To stop a site from updating automatically offered versions, add to its wp-config.php:
`define( 'GS_WOO_SYNC_DISABLE_UPDATER', true );`
