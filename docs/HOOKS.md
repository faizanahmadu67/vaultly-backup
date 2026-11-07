# Vaultly Backup: extension hooks

For add-on plugins (for example a "Vaultly Backup Pro" that copies finished backups to cloud storage).
This file lives outside `vaultly-backup/` on purpose: it is not shipped in the plugin zip.

An add-on is a normal, separate plugin. It must not edit this plugin's files. Everything below is called from the free plugin; the add-on only registers callbacks.

## Load order

| Hook | Type | Args | When |
|---|---|---|---|
| `vaultly_loaded` | action | `string $version` | on `plugins_loaded`, single-site only (the engine does nothing on multisite). Register your other hooks before this, at include time. |

Check for the engine with `defined( 'VAULTLY_VERSION' )` and `version_compare( VAULTLY_VERSION, '1.8.9', '>=' )`.

## Adding a step to a backup

A backup is a list of steps ("phases"): `db`, `list`, `files`, `final`. An add-on can **append** steps, for example an upload after `final`. It cannot remove or reorder the built-in ones (the engine ignores such a result and uses its own list).

### 1. Register the step: `vaultly_phase_handlers` (filter)

```php
add_filter( 'vaultly_phase_handlers', function ( array $h ): array {
	$h['cloud_up'] = array(
		'label'  => __( 'Uploading to cloud', 'my-addon' ), // shown in the progress popup
		'weight' => 5,                                       // share of the progress bar (db=3, files=8, final=1)
		'run'    => 'my_addon_upload_step',
	);
	return $h;
} );
```

`run` is any callable with the signature `function ( array &$job, float $deadline ): bool`. Rules:

- It is called again and again until it returns `true`. Each call must stop before `microtime( true ) > $deadline` (about 12 s of work per call at most).
- Keep your progress in `$job['s']` (an array; it is saved after every call and reset when the step finishes). A call may be repeated after a crash, so make it safe to re-run from the saved state.
- Optional: `$job['p']` = 0..1 progress of this step, `$job['detail']` = short text under the progress bar.
- The finished archive is `VAULTLY_Job::dir() . '/' . $job['out']` (after `final`). `$job['scope']` is `full`, `db` or `files`.
- Throw an exception to fail the job. The archive made so far stays in the backups list. If a failed upload must not fail the backup, catch it yourself, log it and return `true`.
- Do not call `set_time_limit()` / `ini_set()`; the WordPress.org review rejects it.

### 2. Put it in the backup: `vaultly_backup_phases` (filter)

```php
add_filter( 'vaultly_backup_phases', function ( array $phases, string $scope, string $type ): array {
	// $type: 'backup' = a real backup, 'restore' = the safety backup made before a restore
	return 'backup' === $type ? array_merge( $phases, array( 'cloud_up' ) ) : $phases;
}, 10, 3 );
```

Steps that are not registered through `vaultly_phase_handlers` are dropped. If the add-on is deactivated while a job is running, the job stops with a clear "Unknown step" message instead of a fatal error.

## Results

| Hook | Type | Args | When |
|---|---|---|---|
| `vaultly_job_done` | action | `string $type` (`backup`/`restore`/`verify`), `array $info` (`file`, `source`, `scope`, `by`) | the job finished (all steps, including yours). `file` is the new archive name for `backup`. |
| `vaultly_job_failed` | action | `string $type`, `string $message` | a job failed for good (after the engine's own retries). A scheduled backup that cannot even start does not fire it. |

## Settings

| Hook | Type | Args | Purpose |
|---|---|---|---|
| `vaultly_settings_fields` | action | `array $settings` | print extra inputs inside the *Schedule & settings* form, above the Save button. Escape your own output. Every input of the form is submitted. |
| `vaultly_clean_settings` | filter | `array $clean`, `array $raw` | validate and keep your keys: `$clean` already holds the built-in, validated keys (they always win); add yours from `$raw`. Keys you do not return are dropped. |

Settings are stored in `wp-content/vaultly-backups/settings-<site key>.json` (not in the database, so they survive a restore) and read with `VAULTLY_Job::settings()`.

## Other

| Hook | Type | Args | Purpose |
|---|---|---|---|
| `vaultly_exclude` | filter | `bool $skip`, `string $rel` | return `true` to leave a file or folder out of a backup. `$rel` is relative to `wp-content` (full/files scope) or to the WordPress root (files-only). |

## Rules for add-ons

- Backup file names are the access key: the folder is served by the web server. Never copy, link or log a full archive name where others can read it, and never store an archive under a guessable name.
- The free plugin makes no external requests. Everything that talks to a network belongs in the add-on.
- Keep secrets (API keys) out of `$job` (it is written to `.job.json`) and out of backup archives.
