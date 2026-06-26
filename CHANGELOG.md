# Changelog

Notable changes in tinymash

## 0.93.0 - 2026-06-26

- Reworked the CLI around Symfony Console and made `bin/tinymash.php` the only shipped CLI entrypoint
- Added `setup` for first-run configuration and first superadmin creation, with interactive prompts plus stdin/file password input for unattended setup.
- Added an early CLI PHP version guard so running the CLI with PHP below `8.4.1` exits with a clear message before Composer autoloading.
- All of tinymash has now been smoke-tested with PHP 8.4.x and PHP 8.5.x

## 0.92.2 - 2026-06-26

- Improved PHP session handling, and checking for invalid PHP session handling when using redis

## 0.92.1 - 2026-06-24

- Fixed regression/issue with `bin/tinymash.php user set-password` CLI command.
- Added rendering of `:emoji:` to Site title, Site slogan, and Login screen message.

## 0.92.0 - 2026-06-18

This should still be considered a beta version, albeit a reasonably stable beta version.

### Changed

- Composer save actions now use a split Save button with Save draft, Save and return, and Save and new options.
- Plugin administration and CLI diagnostics now show plugin boot health and errors.
- Plugin and theme manifests now report lightweight validation warnings in administration and plugin diagnostics.

### Added

- Font manager for local WOFF2/WOFF uploads, font families, generated `@font-face` CSS, and supported-theme role assignments.
- Social site/profile link management with local icons and `[social]` shortcodes.
- Weather plugin for cached Open-Meteo, MET Norway, and National Weather Service  current conditions and forecasts from named coordinate-based locations.
- Head tags now allows controlled injection of `<head></head>` meta and discovery link tags.

### Fixed

- Fediverse previews refresh when the Fediverse composer tab is selected, use Metadata Summary when present, and show clearer readiness badges.
- Search results now highlight matched terms in titles and summaries.
- What's Up remote calendar requests now identify with the current platform version instead of a stale plugin-specific version.

## 0.91.0 - 2026-06-03

- Second public beta release

## 0.90.0 - 2026-05-19

- Initial public beta release
