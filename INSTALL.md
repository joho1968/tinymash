# Installing tinymash

This document covers the normal way to install tinymash on a server.

tinymash is a flat-file CMS and publishing platform for PHP 8.4+. It does not need a database server, but it does need a working PHP runtime, a web server pointed at `public/`, and writable runtime directories. The current development line is also smoke-tested with PHP 8.5.x.

The usual production path is:

1. start from a prepared tinymash runtime tree
2. copy it to the server
3. point the web server at `public/`
4. run `setup`
5. enable housekeeping from cron

## What you need

- PHP `8.4+`; PHP `8.5.x` is also smoke-tested
- a web server such as Nginx or Apache
- PHP-FPM or another supported PHP SAPI
- local filesystem write access for runtime data

Required PHP extensions:

- `mbstring`
- `json`
- `dom`
- `session`
- `openssl`
- `fileinfo`

Recommended PHP extensions for full feature coverage:

- `curl`
- `gd` or `imagick`
- `simplexml`
- `exif`
- `intl`
- `zip`

## 1. Prepare the runtime tree

If you already have a tinymash deploy package or prepared runtime tree, start there.

If you are building one yourself, create a clean deploy tree with:

```bash
php8.4 bin/tinymash.php deploy /tmp/tinymash-deploy
```

That produces a clean runtime tree with empty `data/`, `users/`, and `tmp/` directories instead of copying a live site. Existing source `tmp/` contents are never included.

## 2. Copy tinymash to the server

Copy the prepared runtime tree to the target host, for example:

```bash
rsync -a /tmp/tinymash-deploy/ /path/to/tinymash/
```

Do not layer a fresh install on top of an old tree full of stale `data/`, `users/`, or `tmp/` content unless you actually intend to preserve that site state.

## 3. Point the web server at `public/`

The document root must be:

```text
/path/to/tinymash/public
```

Do not point the web server at the repository root or runtime root.

A hosting account may keep the tinymash installation below a broader site-definition directory:

```text
/var/www/example/                  site definition with logs, temp, and host files
└── tinymash/                      installation root
    └── public/                    web document root
```

Only the installation root is covered by `deploy:audit` and in-app managed-file replacement. Site-definition siblings are not application files.

Use the included sample configurations as your starting point:

- `samples/server/nginx.sample.conf`
- `samples/server/apache.sample.conf`
- `samples/server/admin-http-basic-auth.md` for optional admin-only HTTP Basic authentication
- `samples/server/php-fpm.sample.conf`

Give each tinymash installation its own PHP session name. Use a short cookie-safe value that starts with a letter, and keep that value stable across upgrades:

```ini
php_value[session.name] = TINYMASHEXAMPLE
```

This avoids session-cookie name collisions when installations share a hostname. PHP's `session.gc_maxlifetime` setting controls how long an inactive server-side session remains available; set it globally or for the site's PHP-FPM pool according to your login policy. If several sites share Redis-backed sessions, also give each site an explicit Redis session prefix.

Give each PHP-FPM pool its own writable temporary root. tinymash prefers `sys_temp_dir`, then `TMP`, `TMPDIR`, and `TEMP`, with the installation's `tmp/` directory as the final fallback. `upload_tmp_dir` remains PHP's upload-staging setting. The included PHP-FPM sample has these settings.

For `open_basedir`, allow the complete installation root plus explicit site-level directories PHP actually uses, such as its temporary and PHP log directories. Nginx-only authentication files do not need PHP access.

tinymash can keep a site non-public while allowing selected guests through secret links. Do not protect the entire virtual host with HTTP Basic authentication if guests must use those links: the web server would reject `/s/<token>` and its destination before tinymash can authorize them.

For an additional HTTP Basic layer, use the included Apache/Nginx examples to limit it to the admin URL. Verify that public routes and routed media still reach tinymash. Secret-link paths contain bearer credentials, so redact them or suppress `/s/` request-path logging.

If you want explicit OPcache settings, use:

- `samples/server/php-opcache-production.ini`
- `samples/server/php-opcache-development.ini`

For project-local log rotation, adapt:

- `samples/server/tinymash.logrotate`

## 4. Set directory ownership and write permissions

The PHP/web-server user must be able to write to:

- `data/`
- `users/`
- `tmp/`

If login works but saving drafts, uploads, cache writes, or housekeeping fail, treat it as a filesystem-permissions problem first.

## 5. Run setup

### Browser setup

For a fresh installation served at the domain root, setup is available at `/install`. PHP needs cURL, HTTPS self-requests must reach this installation, and the server must protect files outside `public/`. HTTPS is required except for explicit loopback development. Whole-host HTTP Basic authentication or an unprovable proxy mapping requires CLI setup. Normal administration below a URL prefix still needs integration work; do not use this browser flow for a subpath installation yet.

Create these two files beside `app/`, `data/`, and `public/`, using the intended origin without a URL path:

```bash
cd /path/to/tinymash
umask 077
php8.4 -r 'echo bin2hex(random_bytes(16)), PHP_EOL;' > .tinymash-install-token
printf '%s\n' 'https://example.com' > .tinymash-install-origin
```

PHP must be able to select its installer session name and private file store. If the pool locks `session.name`, `session.save_path`, or a non-file `session.save_handler` with `php_admin_value`, use CLI setup or make those settings overridable with `php_value` during setup.

Uploading equivalent owner-readable files is also supported. The token may contain 1–64 visible characters; use at least 32 random characters. Never put either file below `public/`, include the token in a URL, or reuse an account password as the token.

Open `https://example.com/install` and enter the token from the host file. After the hosting checks pass, enter the site details and first administrator credentials. JavaScript is optional. Setup needs write access to `app/config/`, `users/`, and the runtime directories. It publishes the administrator and then the configuration, removes its two host files, expires its session, and redirects to ordinary sign-in without signing in automatically.

If host-file cleanup fails, the page identifies the remaining files; remove them on the host without repeating setup. Existing configuration closes every installer route with `404`. An interrupted installation containing an account but no configuration requires host inspection and, when eligible, explicit CLI recovery; never delete accounts or staging files merely to reopen the browser form.

### CLI setup

From the tinymash runtime root:

```bash
php8.4 bin/tinymash.php setup
```

The setup command asks for the baseline required values and creates the first superadmin user. It writes:

- `app/config/tinymash.json`
- `users/<admin>.json`

For unattended setup:

```bash
printf '%s' "$PASSWORD" | php8.4 bin/tinymash.php setup \
  --site-name="My Site" \
  --base-url=https://example.com \
  --timezone=Europe/Stockholm \
  --language=en \
  --admin-user=admin \
  --admin-email=admin@example.com \
  --password-stdin \
  --no-interaction
```

If `app/config/tinymash.json` already exists, setup warns. Interactive reruns ask for confirmation. Non-interactive reruns require `--update`. Existing config is preserved; setup updates only setup fields.

Leave SMTP/mail setup to the admin UI after login.

Prepared deploy packages still include:

```text
app/config/tinymash.json.example
```

Use it as reference only; do not overwrite an existing `app/config/tinymash.json` during upgrades or redeploys.

## Upgrading an existing installation

Do not copy a new release over the old application tree. Removed PHP, plugin, theme, vendor, or public files can remain executable after an overlay.

Installations on `0.98.2` or earlier need the manual sibling procedure below to reach `0.99.2`; their release artifact does not contain the guarded web updater. The immutable `0.99.0` archive also predates the updater.

The manual sibling procedure is also recommended for `0.99.1`, whose in-app updater retains its original recovery worker. After a site runs `0.99.2`, later compatible signed releases use the corrected recovery behavior from **System > Updates**.

Use a staged sibling directory and run the preparation command from the new release root:

1. create and verify a site backup
2. extract/build the new release into an empty sibling directory
3. run `upgrade:prepare --dry-run` and review missing extensions, modified managed files, discarded runtime paths, and unmanaged old-site paths
4. add compatible required plugin/theme code to the release package when needed and rerun the dry run
5. stop PHP-FPM and cron/queue writers for the old installation
6. run `upgrade:prepare` to copy and verify config, content, drafts, media, plugin data, and users
7. rename the old directory aside, move the staged release into place, restore permissions, and reload PHP-FPM
8. run `system:status`, clear/warm caches, and audit the final installation

```bash
cd /path/to/staged-release
php8.4 bin/tinymash.php upgrade:prepare /path/to/old-tinymash --dry-run

# Stop old-site PHP-FPM, cron, queues, and other writers before the real copy.
php8.4 bin/tinymash.php upgrade:prepare /path/to/old-tinymash
```

For unattended preparation, pass `--confirm-writes-stopped --no-interaction`. Missing active plugins or selected themes block preparation unless `--allow-missing-extensions` is supplied deliberately.

The command leaves `data/runtime`, `tmp`, logs, and the config lock behind. It reports but does not copy third-party extensions or unmanaged paths such as `_tmp`, `_uploads`, `uploads`, `_auth`, custom public directories, host configuration, or authentication files. Handle those paths manually according to their purpose.

```bash
php8.4 bin/tinymash.php system:status
php8.4 bin/tinymash.php cache:clear
php8.4 bin/tinymash.php deploy:audit /path/to/tinymash
```

`upgrade:prepare` does not stop services, perform the directory swap, restore ownership, reload PHP-FPM, or run future storage migrations. Review third-party `plugins/` and `themes/` separately. Prefer building compatible extensions into the new package so they are covered by its manifest. Keep the old installation and a verified backup until the upgraded site has been checked; after new writes begin, the old directory alone is no longer a complete rollback source.

## 6. Reload PHP-FPM

After copying new code or changing PHP-FPM pool settings, reload PHP-FPM.

Example:

```bash
sudo systemctl reload php8.4-fpm
```

Use the matching service name when running a different PHP branch, for example `php8.5-fpm`.

## 7. Set up housekeeping from cron

tinymash has a web-cron fallback, but real cron is still the better operational path.

Example:

```cron
*/15 * * * * cd /path/to/tinymash && /usr/bin/php8.4 bin/tinymash.php housekeeping:run >/dev/null 2>&1
```

If you use the Fediverse plugin actively, also schedule:

```cron
*/5 * * * * cd /path/to/tinymash && /usr/bin/php8.4 bin/tinymash.php fediverse run-queue >/dev/null 2>&1
```

There is also a sample cron file at `samples/cron/tinymash.cron`.

Run these cron entries under the same operating-system user as the PHP-FPM pool. Running mutating CLI commands as `root` requires confirmation or `--allow-root` and can leave runtime files owned by root.

## 8. First checks

Before you call the installation done, check these things:

1. the front page loads
2. `/admin/login` loads
3. the first admin user can log in
4. saving a draft works
5. uploaded media lands in `data/media/`
6. `php8.4 bin/tinymash.php housekeeping:status` runs cleanly

## Common mistakes

- wrong document root; it must be `public/`
- stale `data/`, `users/`, or `tmp/` left from an older site
- `site.base_url` not set correctly
- missing write permissions for `data/`, `users/`, or `tmp/`
- one temporary root shared by PHP-FPM pools running as different users
- treating web-cron fallback as the same thing as a real scheduler

## Installing from a source checkout

This is the developer/operator path, not the normal end-user install path.

If you are running tinymash directly from a source checkout, install PHP dependencies first:

```bash
composer install --no-dev --prefer-dist
```

After that, the runtime setup is the same:

- point the web server at `public/`
- run `php8.4 bin/tinymash.php setup`
- set write permissions
- enable housekeeping

## License

Copyright 2026 Joaquim Homrighausen; all rights reserved.

tinymash is licensed under `AGPL-3.0-or-later`. See `LICENSE`.
