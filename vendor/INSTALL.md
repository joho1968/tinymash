# Installing tinymash

This document covers the normal way to install tinymash on a server.

tinymash is a flat-file CMS and publishing platform for PHP 8.4.1+. It does not need a database server, but it does need a working PHP runtime, a web server pointed at `public/`, and writable runtime directories.

The usual production path is:

1. start from a prepared tinymash runtime tree
2. copy it to the server
3. point the web server at `public/`
4. configure the site
5. create the first admin user
6. enable housekeeping from cron

## What you need

- PHP `8.4.1` or newer
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

That produces a clean runtime tree with empty `data/`, `users/`, and runtime directories instead of copying a live site.

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

Use the shipped sample configs as your starting point:

- `samples/server/nginx.sample.conf`
- `samples/server/apache.sample.conf`
- `samples/server/php-fpm.sample.conf`

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

## 5. Create and review the main config

The main runtime config file is:

```text
app/config/tinymash.json
```

Prepared deploy packages ship a sample config at:

```text
app/config/tinymash.json.example
```

For a new install, copy the example to the runtime config path:

```bash
cp app/config/tinymash.json.example app/config/tinymash.json
```

Do not overwrite an existing `app/config/tinymash.json` during upgrades or redeploys. That file is site-local runtime state.

Check at least:

- `site.base_url`
- `site.name`
- `site.default_language`
- `locale.timezone`
- `smtp` settings if you want password reset, notifications, or plugin mail

If the tree was created with `tinymash deploy`, expect `tinymash.json.example` to contain a sanitized empty-site config. That is deliberate.

## 6. Create the first admin user

Before you can use `/admin/login`, create at least one local admin user:

```bash
php8.4 bin/tinymash.php user set-password admin strong-password-here superadmin
```

The command creates the user if it does not exist, or updates the password if it does.

## 7. Reload PHP-FPM

After copying new code or changing PHP-FPM pool settings, reload PHP-FPM.

Example:

```bash
sudo systemctl reload php8.4-fpm
```

## 8. Set up housekeeping from cron

tinymash has a web-cron fallback, but real cron is still the better operational path.

Example:

```cron
*/15 * * * * cd /path/to/tinymash && /usr/bin/php8.4 bin/tinymash.php housekeeping run >/dev/null 2>&1
```

If you use the Fediverse plugin actively, also schedule:

```cron
*/5 * * * * cd /path/to/tinymash && /usr/bin/php8.4 bin/tinymash.php fediverse run-queue >/dev/null 2>&1
```

There is also a sample cron file at `samples/cron/tinymash.cron`.

Run these cron entries as the tinymash runtime user. Running mutating CLI commands as `root` requires confirmation or `--allow-root` and can leave runtime files owned by root.

## 9. First checks

Before you call the installation done, check these things:

1. the front page loads
2. `/admin/login` loads
3. the first admin user can log in
4. saving a draft works
5. uploaded media lands in `data/media/`
6. `php8.4 bin/tinymash.php housekeeping status` runs cleanly

## Common mistakes

- wrong document root; it must be `public/`
- stale `data/`, `users/`, or `tmp/` left from an older site
- `site.base_url` not set correctly
- missing write permissions for `data/`, `users/`, or `tmp/`
- treating web-cron fallback as the same thing as a real scheduler

## Installing from a source checkout

This is the developer/operator path, not the normal end-user install path.

If you are running tinymash directly from a source checkout, install PHP dependencies first:

```bash
composer install --no-dev --prefer-dist
```

After that, the runtime setup is the same:

- point the web server at `public/`
- copy `app/config/tinymash.json.example` to `app/config/tinymash.json` if needed, then configure it
- create an admin user
- set write permissions
- enable housekeeping

## License

Copyright 2026 Joaquim Homrighausen; all rights reserved.

tinymash is licensed under `AGPL-3.0-or-later`. See `LICENSE`.
