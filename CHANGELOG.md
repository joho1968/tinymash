# Changelog

Notable changes in tinymash

## 0.99.4 - 2026-10-12

- Added Comments with moderation, guest verification and privacy links
- Added Subscriptions with double opt-in signup, site and author scopes
- Included private Comments and Subscriptions data in site backups even when either plugin is inactive
- Added ordered multi-image paste, drop, and selection in Composer, with partial-failure recovery.
- Expanded Photos galleries with image sorting, per-image replacement, compact caption and alt-text editing
- Made Scribe's header sticky by default, with a setting to let it scroll away.
- Improved gallery upload recovery, original-filename display, and cross-space media review
- Corrected EXIF orientation on new JPEG uploads and imports; added `media:orientation` to preview and repair older images.
- Added a read-only `audit:shortcodes` command and author-scoped remote-media audit
- Improved WordPress import with root-site targets, native Photos galleries, image captions and alignment
- Converted supported WordPress code, highlight, and Wikipedia shortcodes into readable native content
- Converted recognized WordPress and Blogger YouTube embeds to click-to-load shortcodes
- Improved accented Latin slug creation and Unicode text handling, matching, and ordering.
- Aligned public subscription, comment, password-reset, and account e-mail layouts
- Improved updater diagnostics for missing cURL, Sodium, and ZIP requirements
- Made RSS Reader resolve and pin vetted public addresses for each fetch and redirect
- Required configured trusted proxy ranges before accepting forwarded visitor IP headers
- Restored the System sections control and improved the top bar on narrow admin screens
- Several accessibility fixes in plugins and themes
- Updated components, including Flight `3.19.4` and Symfony Console `8.1.8`

## 0.99.3 - 2026-09-09

- Web based installer for new sites
- Better emoji picker alignment during content editing
- Updated Flight `3.19.3`, CommonMark `2.10.1`, Symfony Console and Mime `8.1.6`, and Symfony Service Contracts `3.7.3`
- Improved base URL compliance for hosting tinymash on sub-URLs

## 0.99.2 - 2026-09-07

- Included the updater-capable build that was absent from the immutable `0.99.0` release archive
- Added guarded browser installation
- Further hardening of updater recovery
- Blocked automatic updates with operator-installed extensions
- Clarified PHP-FPM examples for separate site-definition, tinymash installation, public document-root, temporary, log, and Nginx-only paths

## 0.99.0 - 2026-08-30

- Kept Content filters until Reset is used
- Included every fitting tag in Fediverse posts
- Removed expected missing-config warnings from first-time setup
- Gave detailed import guides distinct titles in Search
- Added signed update checks and private package preflight in System
- Hardened updater recovery

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

- Improved PHP session handling, and checking for invalid PHP session handling when using Redis

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
