# Vaultly Backup

WordPress plugin (GPL-2.0-or-later): full-site backup/restore (database + `wp-content`) with create, verify, schedule, download, upload and restore. Single-site only, WordPress 7.0+, 64-bit PHP 8+, needs `ZipArchive` + `mysqli`. Current version: see `VAULTLY_VERSION` in `vaultly-backup/vaultly-backup.php`.

## Repo layout

The git root holds the *repo*; the *plugin* is the `vaultly-backup/` subfolder (this is what gets zipped and released).

```
README.md                       GitHub readme (user-facing)
vaultly-backup/
  vaultly-backup.php            Plugin header, VAULTLY_Admin: admin page HTML, menu, AJAX endpoints
  includes/
    class-vaultly-job.php       VAULTLY_Job: the step-driven engine (start/step/phases/settings/log/scheduler)
    class-vaultly-db.php        VAULTLY_DB: resumable SQL dump, whitelisted import, serialized-safe search/replace
    class-vaultly-pack.php      VAULTLY_Pack: own `.vlt` binary container (read/write)
    class-vaultly-zip.php       VAULTLY_Zip: append-only streaming ZIP64 writer (files-only backups)
    class-vaultly-updater.php   GitHub updater (GitHub build ONLY, never in the WordPress.org zip)
  assets/admin.js, admin.css    Progress modal, uploads, tabs; icons (icon.svg, menu-icon.svg)
  readme.txt                    WordPress.org readme (keep in sync with version/changelog)
  CHANGELOG.md                  Newest first: `## X.Y.Z - YYYY-MM-DD`
  uninstall.php
```

`.gitignore` excludes `dev-only/` and `*.zip`. The tests mentioned in README (`tests/run.php`, `tests/e2e.php`) are **not in this repo**; there is no test suite, linter or build step here. Verify changes by running the plugin in a local WordPress (XAMPP) and exercising backup / verify / restore.

## Architecture (the parts that are easy to get wrong)

- **Job engine, not one long request.** `VAULTLY_Job::start()` builds a list of *phases*; the browser (or cron, in `run_scheduled()`) calls `step()` repeatedly. Each phase is a private `p_<name>( array &$j, float $deadline ): bool` method that returns `true` when finished and must be **resumable**: persist progress in `$j['s']`, and on resume truncate output files back to the last saved offset (see `p_list`, `p_files`). A new phase needs: a `p_` method, an entry in `label()`, and an entry in `WEIGHT` (progress bar).
- **Phases:** backup = `db`, `list`, `files`, `final`; verify = `validate`, `dbcopy`, `vfiles`; restore = `validate`, `dbcopy`, [safety-backup phases], `extract`, `import`, `replace`, `finish`.
- **State lives in files, never the DB**, because restore replaces the DB. Everything is under `wp-content/vaultly-backups/` (`.job.json`, `settings.json`, `log.jsonl`, `*.meta.json`, `work-<id>/`, `.lock`). Don't add `wp_options` storage for anything that must survive a restore.
- **Concurrency/robustness:** `flock` on `.lock` allows one step at a time; a `.inflight` flag left by a crashed step halves the per-step time budget; failures in safe phases retry up to 3 times with smaller budgets; `import`/`replace` are never retried.
- **Auth model:** all AJAX actions go through `VAULTLY_Admin::guard()` (nonce `vaultly` + `manage_options`), except `vaultly_step`, which also has a `nopriv` hook because restore replaces the users/sessions table mid-job. It is authorized by the job's random 128-bit token, and without a session only the `LATE` phases (`import`, `replace`, `finish`) are accepted. Don't widen this.
- **Archive formats** (chosen by file extension via `VAULTLY_Job::fmt()`): `.vlt` full backup (own format, manifest `format: vaultly-3`; legacy `.vaultly` still read), `.sql` database only, `.zip` files only (whole WordPress root; never overwrites `wp-config.php`). `.vlt` entries are `content/...`, `db/NNNN.sql`, `manifest.json`; the manifest is the last record and the 12-byte footer points to it.
- **Restore = same-to-same.** Tables are imported with the backup's own prefix; if it differs, only the `$table_prefix` line of `wp-config.php` is rewritten (`PREFIX_RE`, `config_prefix()`). URL/path rewriting goes through `url_pairs()` / `path_pairs()` and `VAULTLY_DB::replace_value()` (serialized-safe, never instantiates objects).
- **Exclusions** are centralized in `VAULTLY_Job::excluded()` and `host_file()` (own folder, other backup plugins' archives, optional `cache`, host-specific mu-plugins and drop-ins). A filter `vaultly_exclude` (`$skip`, `$rel`) exists for extra exclusions.
- **Archive names are the access key.** The backup folder is web-served, so every archive name must end in `_<16 hex>` from `random_bytes()` (`file_name()`, `secret_name()` for uploads, `SECRET_RE`); never store an archive under a host/time-only or uploader-chosen name. `secure_names()` renames old guessable files once when idle. The per-job `work-<id>/` folder (raw DB dump) has its own deny rules.
- **Never call `set_time_limit()`, `ignore_user_abort()` or `ini_set()`** (WordPress.org review rejects them, even inside one function). Long work is made of short resumable steps; scheduled backups run in slices and re-arm the one-off cron event `vaultly_cron_continue` (`drive_scheduled()`).
- **Download** is a *direct public URL* to the archive in `wp-content/vaultly-backups/` (random name is the secret), not an admin-ajax stream. `dir()` writes `.htaccess` / `web.config` / empty `index.php` that deny `json|jsonl|lst|bin|up|part|tmp` only. Archive files must stay chmod `0644` so the web server can read them.
- **UI:** `VAULTLY_Admin::page()` renders server-side; `admin.js` drives the job loop and modal. JS strings come from `VAULTLY.i18n` (localized in `assets()`), so a new JS message needs an entry there.

## Conventions

- Class prefix `VAULTLY_`, text domain `vaultly-backup`, AJAX actions `vaultly_*`, tabs for indentation, WordPress-style spacing (`if ( $x ) {`). PHP 8 typed signatures are used.
- Every file starts with `defined( 'ABSPATH' ) || exit;` (WordPress.org plugin check requires it in all `includes/` files).
- Wrap all user-facing strings in `__()` / `esc_html__()` with the text domain, plus a `/* translators: */` comment when they have placeholders. Escape output; HTML in translated help text goes through `wp_kses()` with `VAULTLY_Admin::HTML`.
- Direct `fopen`/`fwrite`/`unlink`-style file and `$wpdb->dbh` access is intentional (multi-GB streaming that `WP_Filesystem` can't do); the file-level `phpcs:disable` comment documents it. Keep that header when adding files; prefer `wp_delete_file()` and `wp_parse_url()`.
- No telemetry. The only external request is the GitHub release check in `class-vaultly-updater.php`.
- Single-site only: `init()` bails out with a notice on multisite.

## Extension hooks

Add-on plugins (e.g. a paid "Pro" that uploads backups to cloud storage) extend the engine through hooks only; see `docs/HOOKS.md` (outside the plugin folder, so it is not shipped). The engine can only be *added to*: `backup_phases()` ignores a filter result that removes or reorders built-in steps or names an unregistered step. Never put paid-feature gating in this plugin's code: WordPress.org requires the hosted plugin to be fully functional.

## Releasing

0. `./build.sh` runs the checks (version consistency, `php -l`, no PHP-limit calls, ABSPATH guards) and builds `dist/vaultly-backup-wporg-<ver>.zip` (upload to WordPress.org, no updater) and `dist/vaultly-backup.zip` (GitHub asset). Use `./build.sh --check` to only check. Do not zip by hand.
1. Bump the version in **three** places: `Version:` header and `VAULTLY_VERSION` in `vaultly-backup.php`, and `Stable tag:` in `readme.txt`.
2. Add a `CHANGELOG.md` entry (and the matching `== Changelog ==` entry in `readme.txt`).
3. Commit as `Vaultly Backup X.Y.Z: <short summary>` (matches history), tag `vX.Y.Z`.
4. (`build.sh` does this.) GitHub release asset must be named `vaultly-backup.zip` and contain the top-level folder `vaultly-backup/` (the updater looks for exactly that name). The WordPress.org build is the same code **without** `includes/class-vaultly-updater.php`; `vaultly-backup.php` already loads it only if readable.
