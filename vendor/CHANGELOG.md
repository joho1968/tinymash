# Changelog

Notable changes in tinymash

## 0.92.1 - 2026-06-24

- Fixed regression/issue with `bin/tinymash.php user set-password` CLI command.
- Added rendering of :emoji: to Site title, Site slogan, and Login screen message.

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

### Fixed

- Fediverse previews refresh when the Fediverse composer tab is selected, use Metadata Summary when present, and show clearer readiness badges.
- Search results now highlight matched terms in titles and summaries.
- What's Up remote calendar requests now identify with the current platform version instead of a stale plugin-specific version.

## 0.91.0 - 2026-06-03

This should still be considered a beta version, albeit a reasonably stable beta version.

### Added

- Deleted content (Trash) management for saved entries
- Plugin content shortcodes
- Media library usage management
- Panel Magazine public theme
- Publication title style controls for Baseline, Blocks, Timeline, and Panel Magazine.
- Are we open availability display plugin
- What's Up cached calendar agenda display plugin
- Signage display plugin with loops for pages, images, and dynamic slides

### Fixed

- Clarified Fediverse previews when outgoing text has already been shortened to fit the configured limit.
- Preserved content routes whose slugs match public asset-directory names such as `/plugins`.
- Replaced the Downloads oversized-upload browser alert with an inline notice.
- Housekeeping catches up content and Notes revision retention after the configured per-item limit is reduced.
- Core CLI output uses consistent headings, grouped sections, aligned labels, and readable task rows.
- `system status` reports the current tinymash platform version.
- Added a WCAG 2.2 AA accessibility baseline with skip links, main landmarks, keyboard-operable password visibility controls, and tighter DocsMatter disabled-navigation behavior.
- Tightened light/dark contrast for shared Bootstrap-based surfaces, calendar past events, and Panel Magazine metadata; labeled Content batch controls for assistive technology.
- Updated safe Composer dependencies, including the `symfony/polyfill-intl-idn` security fix, Symfony Mailer `8.1.x`, and `sabre/vobject` `4.6.x`.
- Declared the project AGPLv3-or-later license in Composer metadata and documented the resolved PHP `8.4.1+` runtime requirement.
- Corrected PHP-FPM OPcache guidance and added a project-local logrotate sample.
- Reduced Content and Trash listing work by calculating revision counts only for visible rows.
- Fediverse automatic post text preserves readable paragraph/list line breaks and applies the configured character limit without a hidden shorter excerpt cap
- Nested pages selected under another child page render in page-tree navigation
- Markdown image labels no longer conflict with content shortcodes
- Search handles hash-numbered terms such as `#17`.
- A disabled page-list section no longer remains visible in the compact Browse panel.
- Nested public menus no longer repeat submenu parents or expand every compact-navigation branch by default.
- Updated Symfony Mailer/Mime dependencies to patched releases.
- New-content slugs are not reverted by stale availability responses while titles are being typed.
- Baseline-derived themes render configured plugin sidebar content such as What's Up.

## 0.90.0 - 2026-05-19

### Added

- Initial public beta release
