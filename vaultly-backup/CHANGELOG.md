# Changelog

## 1.8.9 - 2026-11-08
Fixes for the WordPress.org plugin review (round 3).
- **Backup file names are unguessable again.** Every archive name now ends in a random 64-bit id (`..._20261105-063028_3e19c277167f2a40.vlt`). Uploaded backups are stored under a fresh random name instead of the uploader's. Backups made by older versions (names made of host + time only) are renamed once, automatically, when the Backups page is opened with no job running; their old public links stop working.
- The settings, activity log and running-job state files now have secret names (`log-<key>.jsonl`, ...), and the log no longer contains a backup's random id. Before, on a web server that ignores `.htaccess` (nginx, WordPress Playground) `log.jsonl` was readable and listed every backup name. Old files are renamed automatically.
- The work folder of a running backup (raw database dump) now has deny rules and a 64-bit random name.
- **No PHP limits are changed.** Removed `set_time_limit()` / `ignore_user_abort()`. A scheduled backup works in slices of the host's own time limit; if it is not finished, a one-off cron event `vaultly_cron_continue` carries on.
- `fwrite()` results are checked for the file list, the archive magic and the in-memory entries.
- Added extension hooks for add-on plugins (`vaultly_phase_handlers`, `vaultly_backup_phases`, `vaultly_job_done`, `vaultly_job_failed`, `vaultly_settings_fields`, `vaultly_clean_settings`, `vaultly_loaded`). They change nothing on their own.
- Known trade-off: a single file that cannot be stored within the host's `max_execution_time` in one request fails (use *Skip single files larger than…*).

## 1.8.8 - 2026-11-05
- Fixed: the "Enable auto-updates" link was missing in the Plugins list until WordPress happened to run an update check; it now always shows (GitHub build).

## 1.8.7 - 2026-11-05
- Backup file names now include the site's sub-folder and drop "www." (e.g. `buildwithbs.com-kingsheatingac_20261105-063028.vlt`), so backups of sites on a shared domain are told apart.

## 1.8.6 - 2026-11-02
- Removed the PHP version requirement header. Added a GitHub updater (GitHub builds only, not in the WordPress.org zip) so updates and "Enable auto-updates" work from GitHub releases. README for GitHub.

## 1.8.5 - 2026-11-02
- Restore/backup: host-specific files (managed-hosting mu-plugins such as wp-stack-cache.php, and advanced-cache/object-cache/db drop-ins) are no longer packed or restored, so a backup moved to another server no longer fatals with "Failed opening required .../wp-stack-cache.php".

## 1.8.4 - 2026-10-30
- Restore: the archive check is resumable (short steps) so big backups no longer hit the host time limit (HTTP 500 at 0%).

## 1.8.3 - 2026-10-30
- Now requires PHP 8.0+ and WordPress 7.0+. Uploads keep their file name; safety backup before restore is off by default; restore fixes URLs on Windows (case-insensitive table names), permalinks and generated CSS caches.

## 1.8.2 - 2026-10-30
- Review fixes: enqueue the menu-icon CSS with wp_add_inline_style(), uploaded backups are stored under a random file name, translator comments, documented phpcs exceptions.

## 1.8.1 - 2026-10-29
- Restore and Delete confirmation now opens in the plugin's own popup (Restore / Cancel buttons) instead of the browser `confirm()` alert.

## 1.8.0 - 2026-10-29
Fixes for the WordPress.org automated plugin check.
- **Removed the "Plugin Updater" hook** (`site_transient_update_plugins`, added in 1.7.7): updaters are not allowed in plugins hosted on WordPress.org. The "Enable auto-updates" link and updates will come from WordPress.org itself once the plugin is listed.
- `readme.txt`: "Tested up to" 7.1.
- Direct-access guard `defined( 'ABSPATH' ) || exit;` in all four `includes/` files (the old guard had a test exception the scanner did not accept; tests now define ABSPATH).
- Translator comments for every string with placeholders; help texts are escaped with `wp_kses()` directly; `parse_url()` to `wp_parse_url()`; `unlink()` to `wp_delete_file()`.
- Not changed (needs a manual look by the reviewer): streaming file access with `fopen`/`fread`/`fwrite` (a backup of many GB cannot go through WP_Filesystem), exception messages, writing the backup folder in `wp-content`, and the text-domain warning that disappears when the slug is changed to `vaultly-backup`.

## 1.7.9 - 2026-10-29
- **Restore = same to same.** Replaces the 1.7.8 approach (renaming tables to this site's prefix). The database tables are imported exactly as in the backup, with the backup's own names and prefix (for example `VpQLe_`). When that prefix differs from this site, the `$table_prefix` line of `wp-config.php` is switched to it right after the import (the only change a restore makes to that file), and the rest of the restore runs on the new prefix. The roles and capabilities keys already carry that prefix inside the backup, so nothing is renamed and you stay logged in with admin rights.
- Safety: the prefix is checked (letters, digits, underscore) and `wp-config.php` must be writable and hold a plain `$table_prefix` line, checked in the validate phase before anything is changed; otherwise the restore stops with a clear message. Restoring the pre-restore safety backup switches the prefix back the same way.
- The tables of the previous prefix stay in the database, unused (drop them in phpMyAdmin if you want).
- Removed the rename code of 1.7.8. Tests: restore across prefixes switches wp-config.php and keeps the table names.

## 1.7.8 - 2026-10-29
- **Restore across table prefixes.** Before, restoring a backup with another prefix stopped with "Table prefix differs (backup: VpQLe_, this site: wp_)". Now the tables are renamed to this site's prefix while they are imported (every statement is still checked against the backup's own prefix first), and the keys that contain the prefix inside the data (`{prefix}user_roles` in options, `{prefix}capabilities`, `{prefix}user_level` and other `{prefix}*` usermeta keys) are renamed too, so you stay logged in with admin rights. Same idea as All-in-One WP Migration. This site's `wp-config.php` prefix is not changed.
- Tests: dump imported under another prefix, roles and usermeta keys renamed.

## 1.7.7 - 2026-10-29
- **Auto-updates option.** The Plugins screen now offers "Enable auto-updates" for Vaultly Backup (a `site_transient_update_plugins` filter adds the plugin to `no_update` when WordPress has no update info for it). Updates themselves arrive once the plugin is published on WordPress.org.

## 1.7.6 - 2026-10-29
- **Colourful icons.** New `assets/menu-icon.svg` (violet-blue gradient shield, white keyhole) and `assets/icon.svg`. The menu icon is now passed as a file URL instead of a `data:` URI, because WordPress repaints `data:` icons grey; a tiny `admin_head` style shows it at full strength.
- Readme: `.vlt` extension named correctly.

## 1.7.5 - 2026-10-29
- **Backup-finished dialog redesigned:** the full public link is shown (wrapped, not cut off) with **Copy link** and **Download** buttons next to it, plus Close.
- **Shorter extension:** full backups are saved as `.vlt` instead of `.vaultly`. Existing `.vaultly` files are still listed, verified and restored. The file name keeps its 16-hex random id.

## 1.7.4 - 2026-10-29
- **Real cause of the 403 on download links:** finished archives were chmod 0600 (owner only), so the web server could not read them. Now 0644. Existing backups are chmod-ed to 0644 when "Download" is pressed, so no need to re-create them.

## 1.7.3 - 2026-10-29
- **Download buttons download directly.** Both the table "Download" button and the "Download" button in the finished-backup dialog now start the file download from the public link at once; the link is still shown to copy.

## 1.7.2 - 2026-10-29
- **Fix 403 on download.** The backup folder `.htaccess` used "deny all, then allow .sql/.zip/.vaultly", which some hosts (LiteSpeed, hardened Apache) do not honour, so archives gave 403. It now allows everything by default and denies only `.json .jsonl .lst .bin .up .part .tmp`, and turns off directory listing. Old rules are rewritten automatically.
- **Activity tab** shows a "No records found." row when the log is empty.

## 1.7.1 - 2026-10-29
- **Admin UI refresh** (CSS only, no logic changed): soft indigo palette instead of the default sharp blue, pill-style tabs, card-style backup type options, rounded cards/table/dialog, softer badges and buttons (Delete turns red only on hover), blurred modal backdrop, mobile-friendly tabs.
- **New icons:** `assets/icon.svg` (soft indigo rounded square, white shield + keyhole) and `assets/menu-icon.svg` (single-colour keyhole shield, recoloured by WordPress).
- Plugin folder renamed `safekeep-backup` to `vaultly-backup`.

## 1.7.0 - 2026-10-28
- **Full backup = own binary format.** `.vaultly` is now a flat, append-only container (like `.wpress`): `VLTY1` header, records of name/mtime/size/CRC32 + data, database parts and a manifest at the end, a 12-byte footer pointing at the manifest. Not a zip. Backups made by 1.6 (zip-based) are not readable any more. `tools/wpress2vaultly.php` writes the new format (streams straight from the `.wpress`, no temp files).
- **Database only** is still one plain `.sql`; **Files only** is still a normal `.zip` of the whole WordPress folder.
- **Download = direct public file URL** (`wp-content/vaultly-backups/<name>`), not an admin-ajax link. Removed the download token files, 2-day expiry, `vaultly_download_ttl`, `VAULTLY_SENDFILE` and the PHP streaming endpoint (the web server serves the file, Range/resume included).
- **Security keys/tokens removed:** download tokens, the leftover `.key` cleanup and the folder exposure probe (`probe.txt` + warning). The backup folder's `.htaccess` / `web.config` now let only `.sql`, `.zip`, `.vaultly` through and still deny state/log/work files. Archive names carry a 16-hex random id. The per-job token of `vaultly_step` stays (restore replaces the users table mid-job).

## 1.6.0 - 2026-10-16
Renamed after the WordPress.org plugin review, plus new backup types.
- **Renamed** to *Bakuvo Backup* (slug `bakuvo-backup`, text domain, folder `wp-content/bakuvo-backups/`). Prefix is now `bakuvo_` / `BAKUVO_` (was 3 letters): classes, constants, hooks (`bakuvo_cron`, `wp_ajax_bakuvo_*`), handles, JS global, nonce, file names. Full archives use `.bakuvo` (was `.skb`).
- **Renamed again** to *Vaultly Backup* (slug `vaultly-backup`, prefix `vaultly_` / `VAULTLY_`, folder `wp-content/vaultly-backups/`, archives `.vaultly`, menu "Vaultly Backup"). New vault-shield logo and menu icon.
- **Database only** now writes one plain `.sql` file (header line, SQL, end marker). It can be verified and restored (URLs are rewritten like a full restore).
- **Files only** now writes a normal `.zip` of the whole WordPress root folder (core, wp-content, wp-config.php, everything), without manifest. Own backup folder and other tools' backup archives are left out. Restore extracts into the root folder and never overwrites `wp-config.php` or this plugin.
- **Removed** the signing key (`.key`, `BAKUVO_KEY`, HMAC signatures, "Trusted keys", "Only restore signed backups"). Backups are no longer signed or checked for a signature; existing `.key` files are deleted.
- **Download** now creates a *public* link (works without login) that expires after **2 days** (was 1 day; filter `bakuvo_download_ttl`). Clicking *Download* shows the link with *Copy link* and *Download now*; the success window after a backup shows the link as well.
- Review fixes: backup folder `index.php` is empty (0 bytes); `fwrite` result checked when copying database parts; `$_POST` / `$_GET` / `$_SERVER` input sanitized (`sanitize_text_field( wp_unslash() )`).
- Upload accepts `.bakuvo`, `.sql`, `.zip` and checks the content matches.
- Tests updated (`php tests/run.php`, `php tests/e2e.php`).

## 1.5.4 - 2026-10-13
- Download links are valid for **1 day** (was 2 hours) and expire by themselves after that. Housekeeping used to delete link files after 6 hours; it now removes a link only once it has really expired. Change with the `skb_download_ttl` filter.

## 1.5.3 - 2026-10-13
- Checked against a real All-in-One WP Migration backup of the same 20 GB site (file-by-file + all 161 database tables): identical except 16 tiny stub files in `backuply/` and `backups-dup-pro/` that were skipped together with the folder. Those folders are now included; only archives/dumps inside them (`.zip .tar .gz .sql .wpress .skb ...`) are left out. `ai1wm-backups` and our own folder stay excluded (AI1WM skips its own as well).

## 1.5.2 - 2026-10-13
- Backups now live in the fixed folder `wp-content/safekeep-backups/` (like `ai1wm-backups` for All-in-One WP Migration) instead of a random `skb-<hash>` folder. An existing `skb-*` folder is moved there automatically, so backups, signing key and settings are kept.

## 1.5.1 - 2026-10-13
- **Fix: "No active job." during backup.** The new keyset paging of the database dump saved the last primary-key value in the job state; tables with binary keys (Wordfence and similar) made `json_encode` fail and an empty `.job.json` was written. Key values are now stored base64-encoded, folder names in the file scan likewise, and the state file is never overwritten when encoding fails (a clear error is raised instead).
- Leftover work folders of a dead job are removed when the next job starts.
- New regression test with random-byte primary keys.

## 1.5.0 - 2026-10-13
Parity with All-in-One WP Migration + reliability.
- **Success window** after backup / restore / verify. Restore shows the two follow-up steps (save permalinks twice, clear caches); backup has a *Download backup* button.
- **Same contents as All-in-One WP Migration:** all of `wp-content` (uploads, plugins, themes, mu-plugins, languages, cache, drop-ins) and every table with the site prefix. Only our own folder and other tools' backup stores (`*backup*`, `ai1wm-*`, `updraft*`) are left out. Previously `cache`, `upgrade` and `debug.log` were skipped.
- Archive manifest now also carries the active plugins, theme and wp-content path (like `package.json`).
- **Restore rewrites URLs in every form** (http / https / protocol-relative, with/without www, URL-encoded, JSON-escaped, e-mail domain) **and the wp-content path** stored in the database.
- After restore, plugins that lock you out of a moved site (hide-login plugins; SSL plugins on http) are deactivated, like All-in-One WP Migration.
- **Self-healing backups:** a failed batch is retried up to 3 times with smaller steps (only phases that are safe to repeat); a step killed by the host (memory / time limit) makes the next steps smaller; memory limit is raised for admin tasks.
- New options (Schedule & settings -> What to back up): skip `cache`, skip spam comments, skip post revisions.

## 1.4.0 - 2026-10-13
Audit release.
- **Progress popup** now names the process (Creating backup / Restoring backup / Verifying backup) and shows a step checklist (done / current / pending) plus detail line, elapsed time and estimate.
- **Survives a page reload:** if the tab is reloaded or reopened while a job runs, the popup reappears and keeps driving it.
- **Respects server limits:** the file scan is now resumable (it used to be one long request that could hit `max_execution_time` on large sites); the database dump streams rows so memory stays flat even with huge rows.
- **Fix: database dump correctness.** Tables with composite or non-integer primary keys (e.g. `term_relationships`) were paged with `LIMIT/OFFSET` without an order, which could skip or duplicate rows. They are now paged by the full primary key in a stable order.
- **Fix:** backup folders of other plugins (`*backup*`, `ai1wm-*`, `updraft*`) are never included, so a 20 GB leftover backup is not packed into the new one.
- **Fix:** Cancel waits for the running step (it could be resurrected by it) and is refused once a restore is replacing the site (a half-way stop would break it); the button is hidden at that point.
- **Performance:** no disk access on front-end requests any more (schedule check moved to wp-admin); faster restore (folder checks cached).
- Tests now run every phase with a resume after each unit of work, and cover composite/varchar keys.

## 1.3.0 - 2026-10-13
- **New:** live progress popup while backing up / restoring / verifying / uploading: spinner, phase, percentage, files/size processed, elapsed time and estimate. Percentage also shows in the browser tab title.
- **Much faster backups:** archives are now written with a built-in append-only ZIP64 writer. Before, PHP's `ZipArchive` rewrote the entire archive on every batch, so backup time grew quadratically with site size.
- **Faster restore:** database import commits once per step instead of once per INSERT; larger dump batches. New setting to skip the pre-restore safety backup (default stays ON).
- **Renamed** the admin menu entry to **SafeKeep** (page title "SafeKeep Backup") with a new logo and menu icon.
- Progress steps are shorter (about 6 s) so the progress display updates smoothly.
- Added `readme.txt` (WordPress.org format) and this changelog.

## 1.2.0
- Signed archives, verify, scheduled backups with retention, activity log, resumable downloads/uploads, exposure self-test.
