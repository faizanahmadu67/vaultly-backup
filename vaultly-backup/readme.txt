=== Vaultly Backup ===
Contributors: faizanahmad67
Tags: backup, restore, migration, database, wp-content
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 1.8.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, resumable full-site backup and restore with live progress, verification, scheduling and an activity log.

== Description ==

Vaultly Backup backs up your database and `wp-content`, and restores them again, with a live progress display.

* Create, download, upload, verify, restore and delete backups (full site, database only, or files only)
* Live progress popup with percentage, files processed and time estimate
* Fast append-only archives; every phase is resumable after a timeout or dropped connection
* Verify button re-reads a backup and checks every checksum without touching your site
* Scheduled daily/weekly backups with retention and e-mail on failure
* Three backup types: Full (.vlt, Vaultly's own single-file format), Database only (one plain .sql file), Files only (a normal .zip of the whole WordPress folder)
* Download button gives a direct public link to the backup file (no login needed)
* Automatic safety backup before every restore (can be turned off)
* Activity log, no external requests, no telemetry

== Installation ==

1. Upload the `vaultly-backup` folder to `/wp-content/plugins/`.
2. Activate the plugin, then open **Vaultly Backup** in the admin menu.

== Frequently Asked Questions ==

= Does it support multisite? =
No, single-site only.

= Is wp-config.php included? =
Only in a Files only backup (it is a copy of the whole WordPress folder). A restore never overwrites the live wp-config.php. The one exception: if the backup's database uses a different table prefix, the prefix line in wp-config.php is switched to it so the site can read the restored tables.

== Changelog ==

= 1.8.9 =
* Security: every backup file name ends in a random 64-bit id again (the backup folder is web-served, so the name is the access key). Uploaded backups are stored under a new random name, not the name the uploader chose. Backups made by older versions are renamed once, automatically.
* The temporary work folder of a running backup (it holds the raw database dump) is now blocked from direct web access and has a longer random name.
* No PHP limits are changed any more (removed set_time_limit / ignore_user_abort). Scheduled backups now work in slices: if one cron request is not enough, a follow-up cron event continues.
* Writes to the archive and file list are now checked for failure (disk full).
* Developers: added hooks so a separate add-on plugin can add steps to a backup and its own settings. They do nothing on their own.

= 1.8.8 =
* Fixed: the "Enable auto-updates" link was missing in the Plugins list until WordPress happened to run an update check; it now always shows (GitHub build).

= 1.8.7 =
* Backup file names now include the site's sub-folder and drop "www." (e.g. buildwithbs.com-kingsheatingac_20261105-063028.vlt), so backups of sites on a shared domain are told apart.

= 1.8.6 =
* Removed the PHP version requirement header.

= 1.8.5 =
* Restore/backup: host-specific files (managed-hosting mu-plugins such as wp-stack-cache.php, and advanced-cache/object-cache/db drop-ins) are no longer packed or restored, so a backup moved to another server no longer fatals with "Failed opening required .../wp-stack-cache.php".

= 1.8.4 =
* Restore: the first "checking the backup" step now runs in short steps, so big backups no longer fail with HTTP 500 on hosts with a short time limit.

= 1.8.3 =
* Now requires PHP 8.0+ and WordPress 7.0+. Uploads keep their file name; safety backup before restore is off by default; restore fixes URLs on Windows (case-insensitive table names), permalinks and generated CSS caches.

= 1.8.2 =
* Review fixes: enqueue the menu-icon CSS with wp_add_inline_style(), uploaded backups are stored under a random file name, translator comments, documented phpcs exceptions.

= 1.8.1 =
* Restore and Delete now ask for confirmation in the plugin's own popup (like backup and download) instead of the browser alert.

= 1.8.0 =
* Changes for the WordPress.org automated check: removed the update-info hook (updates and the "Enable auto-updates" link come from WordPress.org once the plugin is listed), "Tested up to" 7.1, direct-access guard in every PHP file, translator comments, wp_parse_url() and wp_delete_file().

= 1.7.9 =
* Restore keeps the backup's database exactly as it was: tables are imported under the same names and prefix as in the backup file. If that prefix differs from this site, the prefix line in wp-config.php is switched to it (it must be writable; this is checked before anything changes).

= 1.7.8 =
* Restore now works when the backup comes from a site with a different database table prefix (for example a backup made on a site using VpQLe_ restored on a wp_ site). Tables are renamed to this site's prefix and the roles / capabilities keys are updated.

= 1.7.7 =
* The Plugins screen now shows the "Enable auto-updates" link for Vaultly Backup.

= 1.7.6 =
* Colourful menu and plugin icons (violet-blue shield) so the plugin is easy to spot in the admin menu.

= 1.7.5 =
* New backup dialog: the whole public link is shown with Copy link and Download buttons.
* Shorter file extension: full backups are now `.vlt` (was `.vaultly`). Older `.vaultly` backups still work.

= 1.7.4 =
* Fix: backup files were saved owner-only (0600), so the web server answered 403 Forbidden on the public link. They are now readable (0644), and older backups are fixed when you press Download.

= 1.7.3 =
* The Download buttons now start the download right away from the public file link (the link is still shown to copy).

= 1.7.2 =
* Fix: backup files (.sql, .vaultly, .zip) returned 403 Forbidden on some hosts. The folder rules now only block the internal state files instead of denying everything first. Activity tab shows "No records found." when empty.

= 1.7.1 =
* Refreshed admin look: soft indigo palette, pill tabs, card-style backup type options, rounded tables and dialog. New plugin and menu icons. Design only, no behaviour change.

= 1.7.0 =
* Full backups now use Vaultly's own binary `.vaultly` format (not a zip). Backups made by 1.6 cannot be restored by 1.7.
* Download gives a direct public file URL (no admin-ajax link, no token, no expiry). File names carry a longer random id.
* Removed the exposure self-test and the download-link token files; the backup folder now lets only `.sql`, `.zip` and `.vaultly` files through.

= 1.6.0 =
* Renamed to Vaultly Backup (new slug, 4+ character prefix `vaultly_`).
* Database only now creates one plain `.sql` file; Files only creates a normal `.zip` of the whole WordPress folder (restore never overwrites wp-config.php). Full backups are unchanged.
* Removed the signing key and archive signatures (and the "trusted keys" setting).
* Download now creates a public link that expires after 2 days.
* Review fixes: empty index.php in the backup folder, write check while copying database parts, sanitized request input.

= 1.5.4 =
* Download links now stay valid for 1 day (was 2 hours), so slow downloads of big backups can finish. Expired links are removed automatically.

= 1.5.3 =
* Other backup plugins' folders (backuply, duplicator, updraft...) are now included except for their archives and dumps, so their small stub/config files are no longer lost (matches All-in-One WP Migration).

= 1.5.2 =
* Backups are stored in a fixed, readable folder: wp-content/bakuvo-backups (an older random bakuvo-* folder is moved there automatically).

= 1.5.1 =
* Fix: backup stopped with "No active job" on databases that have binary primary keys (e.g. security-plugin tables). Job state is now always written safely and never replaced by an empty file.
* Leftovers of a dead job are cleaned when the next one starts.

= 1.5.0 =
* Success window after backup / restore / verify (restore explains the two follow-up steps); backup offers a Download button.
* Backs up everything All-in-One WP Migration does (all of wp-content incl. cache, mu-plugins, languages, drop-ins; all prefixed tables) plus package info (active plugins, theme, wp-content path).
* Restore rewrites URLs in every form (http/https/protocol-relative, www, URL-encoded, JSON, e-mail domain) and the wp-content path.
* After a restore, plugins that lock you out (hide-login, SSL on http) are deactivated, like All-in-One WP Migration.
* Self-healing: a failed batch is retried with smaller steps; a step killed by the host halves the work per step.
* New options: skip cache, spam comments, post revisions.

= 1.4.0 =
* Progress popup names the process and shows a step checklist; it survives a page reload.
* Resumable file scan and streamed database dump: stays inside server time and memory limits.
* Fix: tables with composite / non-integer primary keys could lose or duplicate rows in a backup.
* Fix: other plugins' backup folders are never packed into a backup.
* Fix: safer Cancel; no disk access on front-end requests.

= 1.3.0 =
* New: live progress popup for backup, restore, verify and upload.
* Much faster backups (append-only ZIP64 writer instead of rewriting the archive on every batch).
* Faster restore (batched database commits); optional skip of the pre-restore safety backup.
* Renamed menu to "Site Backup", new logo and icon.

= 1.2.0 =
* Signed archives, verify, scheduling with retention, activity log, resumable downloads and uploads.
