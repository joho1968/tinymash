# tinymash

tinymash is a flat-file CMS and publishing platform for PHP 8.4.1+. It stores content on disk, does not require a database server, and ships with both a public site and an admin interface. The current development line is also smoke-tested with PHP 8.5.x.

It is built for self-hosted publishing: pages, posts, author spaces, themes, plugins, media, tags, feeds, maintenance tasks, and a CLI for day-to-day operations.

## What tinymash is for

tinymash is a good fit if you want:

- a database-free CMS
- a small self-hosted publishing stack
- pages and blog posts in the same system
- multiple authors without adding a lot of platform weight
- a PHP application that stays readable and easy to deploy

## What ships with it

The shipped runtime includes:

- a web-based admin interface
- flat-file content, draft, user, and media storage
- public themes and theme settings
- plugin support
- media uploads for editor and site imagery
- feeds, tags, menus, search, backup/export, imports, and housekeeping tooling
- a CLI for cache management, deploy builds, housekeeping, imports, backups, and other maintenance work

## Normal installation path

The normal production path is to run tinymash from a prepared runtime tree and point your web server at `public/`.

If you are holding a tinymash deploy package, the short version is:

1. copy the runtime tree to the server
2. point the web root at `public/`
3. make `data/`, `users/`, and `tmp/` writable by the PHP/web-server user
4. run `php8.4 bin/tinymash.php setup`
5. review system settings in the admin UI
6. set up housekeeping from cron

The full step-by-step version is in `INSTALL.md`.

## Runtime requirements

- PHP `8.4.1` or newer; PHP `8.5.x` is also smoke-tested
- a web server such as Nginx or Apache
- PHP-FPM or another supported PHP SAPI
- writable `data/`, `users/`, and `tmp/` directories

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

For ordinary public and admin use, tinymash can run with a small PHP memory limit; `16M` is enough for many small sites. Larger batch jobs such as imports, backups/exports, media cleanup, and big upload/import runs may need more memory. The CLI import commands also accept explicit memory-limit options where that matters.

## First setup

After copying a prepared runtime tree and pointing the web server at `public/`, run setup from the tinymash root:

```bash
php8.4 bin/tinymash.php setup
```

Setup creates `app/config/tinymash.json` when needed and creates the first superadmin user. Existing configs are preserved on reruns.

## Command line

Common operator commands are documented in [CLI.md](CLI.md).

## Sample files

Public sample files live under `samples/`:

- `samples/server/nginx.sample.conf`
- `samples/server/apache.sample.conf`
- `samples/server/php-fpm.sample.conf`
- `samples/server/php-opcache-production.ini`
- `samples/server/php-opcache-development.ini`
- `samples/server/tinymash.logrotate`
- `samples/cron/tinymash.cron`
- `samples/deploy/rsync-deploy.sh`

## Files and paths

- `public/`: web root
- `app/config/tinymash.json`: main runtime configuration; deploy packages ship `app/config/tinymash.json.example` as the safe starting point
- `data/`: content, drafts, media, caches, plugin/theme data
- `users/`: user records
- `tmp/`: temporary runtime files
- `bin/tinymash.php`: CLI entrypoint

## Source checkouts

If you are running tinymash directly from a source checkout instead of a prepared runtime tree, install the PHP dependencies first and treat that as a developer/operator workflow rather than the normal end-user install path.

## License

tinymash is licensed under `AGPL-3.0-or-later`. See `LICENSE`.

Copyright 2026 Joaquim Homrighausen; all rights reserved.

## Credits

tinymash uses a small set of bundled third-party packages and frontend assets, including:

- Bootstrap
- Bootstrap Icons
- highlight.js
- emoji-picker-element and bundled emoji picker data
- `flightphp/core`
- `flightphp/runway`
- `latte/latte`
- `league/commonmark`
- `symfony/mailer`
