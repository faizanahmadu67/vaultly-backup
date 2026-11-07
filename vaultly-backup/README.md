# Vaultly Backup

Full-site backup for WordPress (database + `wp-content`): **create, verify, schedule, download, upload, restore**.
GPL-2.0-or-later. Needs WordPress 7.0+ (64-bit), `zip` + `mysqli`, single-site.

## Install
Copy `vaultly-backup/` to `wp-content/plugins/`, activate, open **Vaultly Backup** in the admin menu.
Nothing to configure. No keys, no passwords; it never edits `wp-config.php`.

See [CHANGELOG.md](CHANGELOG.md).

## What makes it different
| | |
|---|---|
| **Verify button** | Re-reads the entire archive and checks every CRC/SHA-256 without touching the site. Know a backup is good *before* you need it. |
| **Scheduled backups + retention** | Daily/weekly (~03:00), keep newest N, e-mail on failure. Manual backups are never auto-deleted. |
| **Scope** | Full site / database only / files only; restore honours the archive's scope. |
| **Automatic pre-restore backup** | Restore first snapshots what it is about to replace. |
| **Activity log** | Who backed up, downloaded, restored, deleted, when (kept in a file so it survives a restore). |
| **Own file format** | Full backups are a single `.vlt` binary file (like `.wpress`): not a zip, `wp-content` + database in one file. Database only = one `.sql`; files only = a normal `.zip` of the whole WordPress folder. |
| **Resumable everything** | Database is dumped in ≤32 MB parts; every phase resumes after a timeout/network drop; uploads resume after a closed tab; downloads are resumable (HTTP Range, If-Range, ETag, HEAD). |

## Download
*Download* shows a **direct public link** to the file (`…/wp-content/vaultly-backups/<random name>.sql|.zip|.vlt`), no admin / login involved. The web server serves it, so pause/resume, IDM, `wget -c` and `curl -C -` all work.

## Large files / limits
- No PHP upload limit: uploads are chunked (auto-shrinks the chunk if a proxy rejects it).
- No archive size limit; no step depends on archive size, except **one single file**: it is stored (not compressed) in one request, so a file of several GB needs the host to allow ~1 min per request. Use *Settings → Skip single files larger than…* to exclude monsters.
- 32-bit PHP is refused (cannot handle >2 GB).

## Security notes
- Admin only (`manage_options`) + nonce on every action. Backup files are public by URL on purpose (random 64-bit name in each file name); anyone who has a link can download that file. Delete the backup to revoke it.
- The only no-login endpoint is `vaultly_step`, accepted **only** for the last restore phases (when restore has just replaced the users table) and only with the job's random 128-bit token.
- Archive entries validated (`..`, absolute, `\`, `:`, control chars); writes re-checked with `realpath`; SQL restricted to `DROP/CREATE/INSERT` on this site's prefix; `unserialize` never instantiates classes.
- Backup folder: `wp-content/vaultly-backups/`. `.htaccess` / `web.config` deny the state / log / work files (everything else, incl. `.sql` `.zip` `.vlt`, is served) (Apache, IIS). **nginx** ignores both files: add `location ~ ^/wp-content/vaultly-backups/.*\.(json|jsonl|lst|bin|up|part)$ { deny all; }`. Even without that rule nothing there gives away a backup link: archives end in a random 64-bit id, and the settings / log / job-state files have secret names (derived from `wp-config.php`) and never contain an id.
- No telemetry. The GitHub build makes one request (latest release check) to api.github.com; the WordPress.org build makes none.

## Limits (deliberate)
`wp-config.php` not included (only its `$table_prefix` line is switched when a backup has another table prefix); restore adds/overwrites files but does not delete extras; DB export is not a point-in-time snapshot on a very busy site; URLs are rewritten (serialized-safe) but filesystem paths and serialized *objects* are not; UI is English only.

## Tests
`php tests/run.php` (units + scratch-DB round trip) · `php tests/e2e.php` (full backup/verify/restore flows). Need local MySQL (`root`, empty password, or `VAULTLY_DB_USER/PASS/HOST`).

## Install from GitHub / updates
1. Download `vaultly-backup.zip` from the latest [Release](https://github.com/brandixsoft/vaultly-backup/releases/latest) and install it via **Plugins → Add New → Upload Plugin**.
2. Updates appear in **Plugins** like any other plugin (and **Enable auto-updates** works): the GitHub build checks this repo's latest release every 6 hours (`includes/class-vaultly-updater.php`; the only external request).
3. Publishing a release: bump the version in `vaultly-backup.php` + `readme.txt`, tag `vX.Y.Z`, attach `vaultly-backup.zip` (a zip whose top folder is `vaultly-backup/`).

The WordPress.org build is the same code without `includes/class-vaultly-updater.php` (WordPress.org provides updates itself).
