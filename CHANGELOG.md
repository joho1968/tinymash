# Changelog

Notable changes in tinymash

## 0.98.2 - 2026-08-28

- Fixed publish dates reverting on duplicated posts in some browsers

## 0.98.1 - 2026-08-28

- Fixed duplicated posts restoring the original publish date after it was changed
- Kept crawler exclusions active when the SEO plugin is disabled
- Fixed typed emoji suggestions in Composer
- Made current-only horizontal Weather output compact

## 0.98.0 - 2026-08-26

- Added Markdown Sync for publishing/importing a Markdown page tree below a selected page
- Kept temporary files separate between installations and made CLI cleanup use the same location as the web app
- Fixed dynamic shortcode output being saved in the public page cache
- Improved DocsMatter theme scrolling, navigation, code blocks, and accessibility
- Fixed author spaces with underscores in their account names
- Improved link visibility in Blocks and Timeline
- Added public credits for bundled software and artwork
- Updated minor components and linked in-app help to the public manual
- Tightened CLI help and rejected invalid input earlier
- Added the source for the public manual, see https://tinymash.joho.se/docs

## 0.97.1 - 2026-08-24

- Fixed minor browser cache issue on non-public sites
- The Scribe theme now honors selected site or author-space content width

## 0.97.0 - 2026-08-24

- Added a Simple Composer mode for routine title/content/photo publishing with direct draft and publish actions
- Reworked CLI help
- Fixed CLI password resets to preserve an existing user's role instead of defaulting it to superadmin
- Standardized theme settings
- Deploy builds now create an empty `tmp/` runtime workspace
- Author-space content width now defaults to the configured site value
- Scribe author homes no longer repeat the masthead identity with a visible `Posts by …` heading by default.
- Pages rendered from a non-public site now always emit `noindex, nofollow` metadata
- Updated safe Composer components
- Updated Bootstrap, highlight.js, emoji picker, and emoji data assets
- Secret links can now grant either whole-site or author-space guest access on non-public sites
- Secret-link exchanges and private pages now use non-cacheable responses with crawler and referrer protections
- Author links are revoked on author deletion and suspended while author content is disabled.
- Browser-based Blogger, WordPress, and Bludit imports now recover cleanly from expired sessions and interrupted batches
- Blogger import now preserves recognized image alignment and UTF-8 text while localizing image markup

## 0.96.0 - 2026-08-17

- More admin cleanup and polish
- Added Scribe, a reading-led public theme for long-form writing and author spaces
- Added true scheduled publishing with explicit scheduling
- Component updates

## 0.95.0 - 2026-08-10

- Component updates
- Added Markdown help for `---` (horizontal ruler)
- Admin visual cleanup, including online help
- Themes rendering cleanup
- Better Light/Dark/Auto mode handling
- HTML meta-data cleanup
- Added Full/Focus modes for composer
- Improved static export for author spaces and whole public site
- Improved backup and restore functionality
- Improved on-disk content index and cache handling
- Improved use of accessibility elements and attributes

## 0.94.0 - 2026-07-27

- Fixed outbound e-mail display text so site-name shortcodes such as `:coffee:` render as Unicode emoji in subjects, sender display names, and system/test notification bodies
- Added RSS Reader plugin with superadmin-managed RSS/Atom sources
- Added static export for author spaces and whole public sites
- Added `tinymash system:themes` for public/admin theme manifest diagnostics

## 0.93.1 - 2026-07-01

- Added Links plugin for shortcode-rendered categorized link collections
- Fixed content summary rendering so plain summaries with punctuation stay compact and literal tags such as `<head></head>` are escaped instead of being interpreted as HTML
- Fixed duplicated content editor state so a copied post/page keeps its copied date/time initially but does not restore the original date/time if it's changed
- Added managed-file containment checks before serving media, Downloads files, and Font manager fonts

After installing, run php8.4 bin/tinymash.php cache:clear

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
- What's Up remote calendar requests now identify with the current platform version

## 0.91.0 - 2026-06-03

- Second public beta release

## 0.90.0 - 2026-05-19

- Initial public beta release
