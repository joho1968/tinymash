# tinymash samples

This directory holds public sample files that are safe to ship with deploy builds.

## Included

- `server/`
  Web-server, PHP-FPM, OPcache, and logrotate examples
- `cron/`
  Scheduler examples
- `deploy/`
  Simple deploy-shell examples

Adjust hostnames, paths, socket names, usernames, and PHP versions before using any sample on a real server.

The deploy sample is intentionally conservative: it preserves site-local config and runtime data by default. Review any delete or replacement strategy before using it against an existing installation.
