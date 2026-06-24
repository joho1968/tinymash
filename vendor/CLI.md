# tinymash CLI

tinymash includes a command-line tool for operational tasks such as cache maintenance, housekeeping, deployment builds, imports, exports, media checks, and user administration.

Run commands from the tinymash runtime root:

```bash
php8.4 bin/tinymash.php help
```

If your install includes the executable wrapper, this is equivalent:

```bash
bin/tinymash help
```

The examples below use the explicit PHP command because it works in both source checkouts and prepared runtime trees.

## Root User Guard

Run tinymash CLI commands as the runtime owner, not as `root`.

When the effective user or group is `root`, read-only commands print a warning and continue. Mutating commands ask for confirmation before they continue. Non-interactive root runs must either use the correct runtime user or pass the explicit override:

```bash
php8.4 bin/tinymash.php --allow-root cache clear
```

Use `--allow-root` only when root-owned output is intentional.

## Help

Show the available core commands and any commands registered by active plugins:

```bash
php8.4 bin/tinymash.php help
```

Plugin commands are only listed when the plugin is active and available to the CLI runtime.

## Runtime Status

Show a small runtime summary:

```bash
php8.4 bin/tinymash.php system status
```

Check maintenance mode:

```bash
php8.4 bin/tinymash.php maintenance status
```

Enable or disable maintenance mode:

```bash
php8.4 bin/tinymash.php maintenance on
php8.4 bin/tinymash.php maintenance off
```

## Users

List local users:

```bash
php8.4 bin/tinymash.php user list
```

Create or update a local user password:

```bash
php8.4 bin/tinymash.php user set-password <username> <password> [role]
```

For the first administrator, use:

```bash
php8.4 bin/tinymash.php user set-password admin strong-password-here superadmin
```

## Cache

Clear compiled Latte cache files, the persistent content index cache, and cached public page responses:

```bash
php8.4 bin/tinymash.php cache clear
```

Warm public listing caches and prune expired public response cache files:

```bash
php8.4 bin/tinymash.php cache warm
php8.4 bin/tinymash.php cache warm --base-url=https://example.com
php8.4 bin/tinymash.php cache warm --base-url=https://example.com --author=joho --entries
```

Useful options:

- `--base-url=<url>`: fetch public pages through a real URL
- `--userpass=<user:pass>`: HTTP Basic Auth credentials for protected test/staging sites
- `--author=<slug>`: include an author home
- `--entries`: include entry pages
- `--entry-limit=<n>`: limit warmed entries

## Housekeeping

Show housekeeping policy and last-run state:

```bash
php8.4 bin/tinymash.php housekeeping status
```

Run core housekeeping and active plugin housekeeping tasks:

```bash
php8.4 bin/tinymash.php housekeeping run
```

Run only core housekeeping:

```bash
php8.4 bin/tinymash.php housekeeping run --no-plugins
```

Cron is the recommended way to run housekeeping in production. See `INSTALL.md` for an example crontab entry.

Core housekeeping includes stale-draft cleanup, Trash retention cleanup, content revision catch-up pruning, media-thumbnail maintenance, expired notification/password-reset cleanup, media-import temporary cleanup, and cache refresh work. Content entry saves prune their own revision snapshots immediately; the housekeeping catch-up also applies a reduced revision limit to entries that have not been edited again.

## What's Up Calendars

Refresh configured `What's Up` iCalendar sources and rebuild cached agenda events:

```bash
php8.4 bin/tinymash.php whats-up refresh
```

Public shortcode rendering never retrieves remote calendars. Run this command from scheduled housekeeping/cron, or use the plugin admin refresh action after changing a source.

## Media

Report stored media usage:

```bash
php8.4 bin/tinymash.php media usage
php8.4 bin/tinymash.php media usage --owner=joho
php8.4 bin/tinymash.php media usage --unused-only --limit=50
```

The usage report checks current content, drafts, site/profile settings, and known media IDs in stored plugin settings. It also reports stored direct attachment markers. A direct marker is counted as a real usage reference only when it points at an existing entry or draft; otherwise it is reported separately as `direct_marker_only`.

Inspect media records and backfill missing display derivatives:

```bash
php8.4 bin/tinymash.php media cleanup --dry-run
php8.4 bin/tinymash.php media cleanup --generate-missing-derivatives
php8.4 bin/tinymash.php media cleanup --dry-run --owner=joho --limit=25
```

Report likely unused media through the cleanup command without deleting anything:

```bash
php8.4 bin/tinymash.php media cleanup --report-unused
php8.4 bin/tinymash.php media cleanup --report-unused --owner=joho --limit=50
```

Current limitation: `media cleanup` can report likely unused records and generate missing display derivatives, but it does not delete media, rewrite originals, or automatically relink content.

## Deployment Builds

Build a deployable runtime tree:

```bash
php8.4 bin/tinymash.php deploy /path/to/deploy-tree
```

The deploy command builds from the explicit deploy manifest. It creates a safe `app/config/tinymash.json.example` in the target tree and does not write a live `app/config/tinymash.json`.

See `docs/DEPLOY.md` for deployment details.

## Export And Import

Export the whole site:

```bash
php8.4 bin/tinymash.php export site /path/to/export
php8.4 bin/tinymash.php export site /path/to/export --with-plugins
```

Export one author:

```bash
php8.4 bin/tinymash.php export author <username> /path/to/export
php8.4 bin/tinymash.php export author <username> /path/to/export --with-plugins
```

Import a site export:

```bash
php8.4 bin/tinymash.php import site /path/to/export
php8.4 bin/tinymash.php import site /path/to/export --replace-existing
```

Import an author export and assign a new login password:

```bash
php8.4 bin/tinymash.php import author /path/to/export new-password-here
php8.4 bin/tinymash.php import author /path/to/export new-password-here --replace-existing
```

Plugin data is included only when the export/import command is run with `--with-plugins` and the relevant plugin supports that path.

## Audits And Benchmarks

Scan stored content for remote image URLs that may indicate import misses:

```bash
php8.4 bin/tinymash.php audit remote-media
php8.4 bin/tinymash.php audit remote-media --author=joho
php8.4 bin/tinymash.php audit remote-media --author=joho --include-unpublished
```

Benchmark public routes with curl timing data:

```bash
php8.4 bin/tinymash.php benchmark public --base-url=https://example.com --repeat=3
php8.4 bin/tinymash.php benchmark public --base-url=https://example.com --author=joho --entry=post-slug --repeat=3
```

Useful options:

- `--userpass=<user:pass>`: HTTP Basic Auth credentials
- `--login-user=<username>` and `--login-pass=<password>`: benchmark a logged-in public path
- `--author=<slug>`: include an author home and page 2
- `--entry=<slug>`: include an entry page
- `--repeat=<n>`: repeat each request

## Component Checks

Run Composer update/advisory checks and write the cached component report:

```bash
php8.4 bin/tinymash.php check-updates
php8.4 bin/tinymash.php check-updates --notify
```

This command needs Composer to be available in the source/runtime environment. `--notify` sends an e-mail only when update notifications are configured and updates are found.

## Plugin Commands

Active plugins may add their own CLI commands. Use:

```bash
php8.4 bin/tinymash.php help
```

to see the commands available in the current install.

Common examples in a default-capable install include importer commands and Fediverse queue delivery, depending on which plugins are active.

## Exit Codes

Commands return `0` on success and a non-zero status on failure. For scheduled jobs, redirect normal output to a log or to `/dev/null` according to your operating policy.
