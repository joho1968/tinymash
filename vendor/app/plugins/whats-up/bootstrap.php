<?php

require_once __DIR__ . '/TinyMashWhatsUpService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'whats-up' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    if ( ! $config instanceof TinyMashConfig ) {
        throw new \RuntimeException( 'Required services for the What\'s Up plugin are not available.' );
    }

    $service = new TinyMashWhatsUpService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'whats-up',
        $config->getLocaleTimezone()
    );
    \Flight::app()->set( 'plugin.whats_up.service', $service );

    $plugins->registerHousekeepingTask(
        $plugin_key,
        static function( array $context = [] ) use ( $service ) : string {
            if ( (string) ( $context['trigger'] ?? '' ) === 'web_fallback' ) {
                return( 'Skipped calendar refresh during web fallback; use CLI or admin refresh.' );
            }
            return( (string) $service->refreshSources()['message'] );
        },
        'refresh',
        'Refresh What\'s Up calendars'
    );

    if ( $plugins->isCliRuntime() ) {
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'whats-up',
                'usage' => 'tinymash whats-up refresh',
                'summary' => 'Refresh configured What\'s Up calendar sources.',
                'order' => 245,
                'handler' => static function( array $arguments ) use ( $service ) : void {
                    if ( strtolower( trim( (string) ( $arguments[0] ?? '' ) ) ) !== 'refresh' ) {
                        throw new \RuntimeException( 'usage: tinymash whats-up refresh' );
                    }
                    $result = $service->refreshSources();
                    fwrite( STDOUT, 'refreshed: ' . (int) ( $result['refreshed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'failed: ' . (int) ( $result['failed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'disabled: ' . (int) ( $result['disabled'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'cached_events: ' . (int) ( $result['event_count'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'message: ' . (string) ( $result['message'] ?? '' ) . PHP_EOL );
                },
            ]
        );
        return;
    }

    if ( ! $security instanceof TinyMashSecurity ) {
        throw new \RuntimeException( 'Required services for the What\'s Up plugin are not available.' );
    }

    $plugins->registerPublicAsset( $plugin_key, 'css', '/plugins/whats-up/whats-up.css' );
    $custom_color_stylesheet_url = $service->getCustomColorStylesheetUrl();
    if ( $custom_color_stylesheet_url !== '' ) {
        $plugins->registerPublicAsset( $plugin_key, 'css', $custom_color_stylesheet_url );
    }
    $plugins->registerPublicAsset( $plugin_key, 'js', '/plugins/whats-up/whats-up.js' );
    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/plugin-custom-css/whats-up',
        static function() use ( $service ) : void {
            $css = $service->getCustomColorStylesheet();
            if ( $css === '' ) {
                \Flight::app()->notFound();
                return;
            }
            $etag = '"' . sha1( $css ) . '"';
            if ( trim( (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) ) === $etag ) {
                \Flight::app()->response()->status( 304 );
                header( 'ETag: ' . $etag );
                header( 'Cache-Control: public, max-age=3600' );
                return;
            }
            header( 'Content-Type: text/css; charset=utf-8' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'ETag: ' . $etag );
            echo $css;
        }
    );
    foreach (
        [
            'whatsup' => [ 'method' => 'renderWhatsUpHtml', 'label' => 'What\'s up', 'description' => 'Displays a compact yesterday, today, and tomorrow view.', 'example' => '[whatsup]' ],
            'agenda' => [ 'method' => 'renderAgendaHtml', 'label' => 'Agenda', 'description' => 'Displays cached events as a chronological agenda.', 'example' => '[agenda future="30" limit="10"]' ],
            'timeline' => [ 'method' => 'renderTimelineHtml', 'label' => 'Timeline', 'description' => 'Displays cached events on a visual timeline.', 'example' => '[timeline future="30" limit="10"]' ],
            'calendar' => [ 'method' => 'renderCalendarHtml', 'label' => 'Calendar', 'description' => 'Displays a month grid with optional bounded month navigation.', 'example' => '[calendar past_months="1" future_months="1"]' ],
        ] as $shortcode_name => $definition
    ) {
        $plugins->registerShortcode(
            $plugin_key,
            $shortcode_name,
            static function( array $shortcode ) use ( $service, $definition ) : array {
                $method = (string) $definition['method'];
                return(
                    [
                        'html' => $service->{$method}( is_array( $shortcode['attributes'] ?? null ) ? $shortcode['attributes'] : [] ),
                        'dynamic' => true,
                    ]
                );
            },
            [
                'label' => (string) $definition['label'],
                'description' => (string) $definition['description'],
                'example' => (string) $definition['example'],
                'dynamic' => true,
            ]
        );
    }
    if ( ! empty( $service->getSettings()['sidebar']['enabled'] ) ) {
        $plugins->registerPublicSlotRenderer(
            $plugin_key,
            'sidebar',
            static function() use ( $service ) : string {
                return( $service->renderSidebarHtml() );
            },
            true
        );
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/whats-up';
    $save_url = $plugin_url . '/save';
    $refresh_url = $plugin_url . '/refresh';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'whats-up',
            'label' => 'What\'s Up',
            'url' => $plugin_url,
            'icon' => 'bi-calendar3',
            'order' => 78,
        ]
    );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-whats-up',
        [
            'title' => 'What\'s Up',
            'summary' => 'Display cached iCalendar events in public content.',
            'group' => 'Plugins',
            'order' => 143,
            'roles' => [ 'admin' ],
            'sections' => [
                [
                    'title' => 'Sources and refresh',
                    'markdown' => 'Configure public HTTPS calendar URLs or upload local `.ics` files. Refresh occurs only through this admin page or the `tinymash whats-up refresh` CLI command; public page rendering reads cached events only.',
                ],
                [
                    'title' => 'Shortcodes',
                    'markdown' => 'Use `[whatsup]` for a compact three-day view, `[agenda]` for a list, `[timeline]` for visual chronology, and `[calendar]` for a month grid. List attributes include `source="calendar-key"` (or comma-separated keys), `past="0"`, `future="30"`, and `limit="10"`. Calendar navigation uses `past_months` and `future_months`.',
                ],
                [
                    'title' => 'Scope',
                    'markdown' => 'What\'s Up is a calendar display plugin. It does not create content entries or handle bookings, reservations, scheduling, appointments, or ecommerce.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };
    $invalidate_public_cache = static function() : void {
        $public_page_cache = \Flight::app()->has( 'public.page_cache' ) ? \Flight::app()->get( 'public.page_cache' ) : null;
        if ( $public_page_cache instanceof TinyMashPublicPageCache ) {
            $public_page_cache->invalidateAll();
        }
    };
    $require_admin = static function() use ( $plugins, $security, $login_url, $admin_url, $escape ) : bool {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return( false );
        }
        if ( ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            $plugins->renderAdminPage(
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'current_section' => 'whats-up',
                    'current_location' => 'whats-up',
                    'plugin_page_kicker' => 'What\'s Up',
                    'plugin_page_title' => 'Forbidden',
                    'plugin_page_summary' => 'Calendar source management is limited to admins.',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You do not have permission to manage calendar sources.' ],
                    'plugin_page_body_html' => '<p><a class="btn btn-outline-secondary" href="' . $escape( $admin_url ) . '">Return to dashboard</a></p>',
                ]
            );
            return( false );
        }
        return( true );
    };

    $build_page = static function( array $notice = [] ) use ( $plugins, $security, $service, $save_url, $refresh_url, $escape ) : void {
        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'whats-up.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }
        $settings = $service->getSettings();
        $cache = $service->getCache();
        $timezones = timezone_identifiers_list();
        $timezone_html = '';
        foreach ( $timezones as $timezone ) {
            $timezone_html .= '<option value="' . $escape( $timezone ) . '"' . ( $timezone === (string) $settings['timezone'] ? ' selected' : '' ) . '>' . $escape( $timezone ) . '</option>';
        }
        $palette = $service->getSourcePalette();
        $now_color = (string) ( $settings['now_color'] ?? 'primary' );
        $now_swatches = '<input class="btn-check" id="tm-wu-now-color-primary" name="now_color" type="radio" value="primary" autocomplete="off"' . ( $now_color === 'primary' ? ' checked' : '' ) . '><label class="btn btn-outline-secondary p-1" for="tm-wu-now-color-primary" title="Theme primary color"><span class="d-block rounded-1 border" aria-hidden="true" style="width:1.5rem;height:1.5rem;background-color:var(--bs-primary);"></span><span class="visually-hidden">Theme primary color</span></label>';
        foreach ( $palette as $palette_key => $swatch ) {
            $color_id = 'tm-wu-now-color-' . $escape( (string) $palette_key );
            $color_label = (string) ( $swatch['label'] ?? $palette_key ) . ' ' . (string) ( $swatch['hex'] ?? '' );
            $now_swatches .= '<input class="btn-check" id="' . $color_id . '" name="now_color" type="radio" value="' . $escape( (string) $palette_key ) . '" autocomplete="off"' . ( $now_color === $palette_key ? ' checked' : '' ) . '><label class="btn btn-outline-secondary p-1" for="' . $color_id . '" title="' . $escape( $color_label ) . '"><span class="d-block rounded-1 border" aria-hidden="true" style="width:1.5rem;height:1.5rem;background-color:' . $escape( (string) ( $swatch['hex'] ?? '' ) ) . ';"></span><span class="visually-hidden">' . $escape( $color_label ) . '</span></label>';
        }
        $now_swatches .= '<input class="btn-check" id="tm-wu-now-color-custom" name="now_color" type="radio" value="custom" autocomplete="off"' . ( $now_color === 'custom' ? ' checked' : '' ) . '><label class="btn btn-outline-secondary btn-sm" for="tm-wu-now-color-custom">Custom</label>';

        $body_html = '<div class="d-grid gap-4">';
        $body_html .= '<form method="post" action="' . $escape( $save_url ) . '" enctype="multipart/form-data" class="d-grid gap-4">';
        $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="small text-uppercase text-body-secondary mb-3">Display defaults</div><div class="row g-3">';
        $body_html .= '<div class="col-12 col-xl-4"><label class="form-label small mb-1" for="tm-wu-timezone">Timezone</label><select class="form-select" id="tm-wu-timezone" name="timezone">' . $timezone_html . '</select></div>';
        $body_html .= '<div class="col-6 col-xl-2"><label class="form-label small mb-1" for="tm-wu-format">Time format</label><select class="form-select" id="tm-wu-format" name="time_format"><option value="24h"' . ( $settings['time_format'] === '24h' ? ' selected' : '' ) . '>24-hour</option><option value="12h"' . ( $settings['time_format'] === '12h' ? ' selected' : '' ) . '>AM/PM</option></select></div>';
        $body_html .= '<div class="col-6 col-xl-2"><label class="form-label small mb-1" for="tm-wu-past">Past days</label><input class="form-control" id="tm-wu-past" name="past_days" type="number" min="0" max="90" value="' . (int) $settings['past_days'] . '"></div>';
        $body_html .= '<div class="col-6 col-xl-2"><label class="form-label small mb-1" for="tm-wu-future">Future days</label><input class="form-control" id="tm-wu-future" name="future_days" type="number" min="0" max="400" value="' . (int) $settings['future_days'] . '"></div>';
        $body_html .= '<div class="col-6 col-xl-2"><label class="form-label small mb-1" for="tm-wu-limit">Max events</label><input class="form-control" id="tm-wu-limit" name="max_events" type="number" min="1" max="100" value="' . (int) $settings['max_events'] . '"></div>';
        $body_html .= '<div class="col-12"><label class="form-label small mb-1" for="tm-wu-empty">Empty-state text</label><input class="form-control" id="tm-wu-empty" name="empty_text" type="text" maxlength="160" value="' . $escape( (string) $settings['empty_text'] ) . '"></div>';
        $body_html .= '<div class="col-12"><div class="form-check"><input class="form-check-input" id="tm-wu-source-labels" name="show_source_labels" type="checkbox" value="1"' . ( ! empty( $settings['show_source_labels'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-wu-source-labels">Show calendar names in combined public views</label></div><div class="form-text">When disabled, source colors remain visible but event labels and the calendar legend are omitted.</div></div>';
        $body_html .= '<div class="col-12 col-lg-8"><div class="form-label small mb-1">Now accent</div><div class="d-flex flex-wrap gap-2 align-items-center">' . $now_swatches . '</div><div class="form-text">Highlights an event happening now and the current calendar day. Theme primary follows the active light/dark theme.</div></div>';
        $body_html .= '<div class="col-12 col-sm-5 col-lg-4"><label class="form-label small mb-1">Custom Now hex color</label><input class="form-control font-monospace" name="now_custom_color" type="text" maxlength="7" placeholder="#0072B2" value="' . $escape( (string) ( $settings['now_custom_color'] ?? '' ) ) . '"></div>';
        $body_html .= '</div></section>';

        $palette_keys = array_keys( $palette );
        $sources = array_values( (array) $settings['sources'] );
        $sources[] = [ 'key' => '', 'label' => '', 'enabled' => true, 'type' => 'remote', 'url' => '', 'color' => (string) $palette_keys[count( $sources ) % count( $palette_keys )], 'custom_color' => '' ];
        $status_rows = (array) $cache['sources'];
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"><div><div class="small text-uppercase text-body-secondary">Calendar sources</div><div class="form-text mt-1">Select a local .ics file to create an uploaded source automatically; key and label are optional for uploads. Source colors identify calendars in combined views. Remote calendars require a key and a public HTTPS URL.</div></div></div><div class="row g-3">';
        foreach ( $sources as $index => $source ) {
            $source_key = (string) ( $source['key'] ?? '' );
            $source_status = is_array( $status_rows[$source_key] ?? null ) ? $status_rows[$source_key] : [];
            $status = (string) ( $source_status['status'] ?? '' );
            $status_badge = $status === 'ok' ? 'success' : ( $status === 'error' ? 'danger' : 'secondary' );
            $color = (string) ( $source['color'] ?? 'blue' );
            $color_swatches = '';
            foreach ( $palette as $palette_key => $swatch ) {
                $color_id = 'tm-wu-color-' . (int) $index . '-' . $escape( (string) $palette_key );
                $color_label = (string) ( $swatch['label'] ?? $palette_key ) . ' ' . (string) ( $swatch['hex'] ?? '' );
                $color_swatches .= '<input class="btn-check" id="' . $color_id . '" name="sources[' . (int) $index . '][color]" type="radio" value="' . $escape( (string) $palette_key ) . '" autocomplete="off"' . ( $color === $palette_key ? ' checked' : '' ) . '><label class="btn btn-outline-secondary p-1" for="' . $color_id . '" title="' . $escape( $color_label ) . '"><span class="d-block rounded-1 border" aria-hidden="true" style="width:1.5rem;height:1.5rem;background-color:' . $escape( (string) ( $swatch['hex'] ?? '' ) ) . ';"></span><span class="visually-hidden">' . $escape( $color_label ) . '</span></label>';
            }
            $custom_color_id = 'tm-wu-color-' . (int) $index . '-custom';
            $color_swatches .= '<input class="btn-check" id="' . $custom_color_id . '" name="sources[' . (int) $index . '][color]" type="radio" value="custom" autocomplete="off"' . ( $color === 'custom' ? ' checked' : '' ) . '><label class="btn btn-outline-secondary btn-sm" for="' . $custom_color_id . '">Custom</label>';
            $body_html .= '<div class="col-12 col-lg-6"><article class="h-100 p-3 bg-body rounded-3 border"><div class="row g-2 align-items-end">';
            $body_html .= '<div class="col-12 col-md-5"><label class="form-label small mb-1">Key <span class="text-body-secondary">(optional for upload)</span></label><input class="form-control font-monospace" name="sources[' . (int) $index . '][key]" type="text" maxlength="80" placeholder="events" value="' . $escape( $source_key ) . '"></div>';
            $body_html .= '<div class="col-12 col-md-7"><label class="form-label small mb-1">Label</label><input class="form-control" name="sources[' . (int) $index . '][label]" type="text" maxlength="100" placeholder="Events" value="' . $escape( (string) ( $source['label'] ?? '' ) ) . '"></div>';
            $body_html .= '<div class="col-12"><div class="form-label small mb-1">Color</div><div class="d-flex flex-wrap gap-2 align-items-center">' . $color_swatches . '</div></div>';
            $body_html .= '<div class="col-12 col-sm-5"><label class="form-label small mb-1">Custom hex color</label><input class="form-control font-monospace" name="sources[' . (int) $index . '][custom_color]" type="text" maxlength="7" placeholder="#0072B2" value="' . $escape( (string) ( $source['custom_color'] ?? '' ) ) . '"></div>';
            $body_html .= '<div class="col-7"><label class="form-label small mb-1">Source type</label><select class="form-select tm-wu-source-type" data-tm-wu-source-index="' . (int) $index . '" name="sources[' . (int) $index . '][type]"><option value="remote"' . ( ( $source['type'] ?? 'remote' ) === 'remote' ? ' selected' : '' ) . '>Remote URL</option><option value="local"' . ( ( $source['type'] ?? '' ) === 'local' ? ' selected' : '' ) . '>Uploaded file</option></select></div>';
            $body_html .= '<div class="col-5"><div class="form-check mb-2"><input class="form-check-input" id="tm-wu-enabled-' . (int) $index . '" name="sources[' . (int) $index . '][enabled]" type="checkbox" value="1"' . ( ! empty( $source['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-wu-enabled-' . (int) $index . '">Enabled</label></div></div>';
            $body_html .= '<div class="col-12"><label class="form-label small mb-1">Remote HTTPS URL</label><input class="form-control" name="sources[' . (int) $index . '][url]" type="url" maxlength="2048" placeholder="https://example.org/events.ics" value="' . $escape( (string) ( $source['url'] ?? '' ) ) . '"></div>';
            $body_html .= '<div class="col-12"><label class="form-label small mb-1">Local .ics upload</label><input class="form-control tm-wu-source-upload" data-tm-wu-source-index="' . (int) $index . '" name="source_upload[' . (int) $index . ']" type="file" accept=".ics,text/calendar"><div class="form-text">Selecting a file sets Source type to Uploaded file and refreshes it when saved.</div>';
            if ( $source_key !== '' && is_file( $service->getLocalSourceFilename( $source_key ) ) ) {
                $body_html .= '<div class="form-text">A local file is stored for this key. Upload another file to replace it.</div>';
            }
            $body_html .= '</div>';
            $body_html .= '<div class="col-12">';
            if ( $source_key !== '' && $status !== '' ) {
                $body_html .= '<div class="small mb-1"><span class="badge text-bg-' . $status_badge . '">' . $escape( ucfirst( $status ) ) . '</span> <span class="text-body-secondary">' . (int) ( $source_status['event_count'] ?? 0 ) . ' cached event(s)</span></div>';
                $body_html .= '<div class="small text-body-secondary">' . $escape( (string) ( $source_status['message'] ?? '' ) ) . '</div>';
            } else {
                $body_html .= '<div class="small text-body-secondary">Save the source, then refresh calendars to populate its cache.</div>';
            }
            $body_html .= '</div>';
            $body_html .= '<div class="col-12"><div class="form-check"><input class="form-check-input" id="tm-wu-remove-' . (int) $index . '" name="sources[' . (int) $index . '][remove]" type="checkbox" value="1"><label class="form-check-label text-danger" for="tm-wu-remove-' . (int) $index . '">Remove source</label></div></div>';
            $body_html .= '</div></article></div>';
        }
        $body_html .= '</div></section>';
        $sidebar = is_array( $settings['sidebar'] ?? null ) ? $settings['sidebar'] : [];
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="small text-uppercase text-body-secondary mb-3">Public sidebar</div><div class="row g-3 align-items-end">';
        $body_html .= '<div class="col-12 col-lg-3"><div class="form-check mb-2"><input class="form-check-input" id="tm-wu-sidebar-enabled" name="sidebar[enabled]" type="checkbox" value="1"' . ( ! empty( $sidebar['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-wu-sidebar-enabled">Show calendar in theme sidebar</label></div></div>';
        $body_html .= '<div class="col-6 col-lg-3"><label class="form-label small mb-1">View</label><select class="form-select" name="sidebar[view]"><option value="whatsup"' . ( ( $sidebar['view'] ?? '' ) === 'whatsup' ? ' selected' : '' ) . '>What\'s up</option><option value="agenda"' . ( ( $sidebar['view'] ?? '' ) === 'agenda' ? ' selected' : '' ) . '>Agenda</option><option value="timeline"' . ( ( $sidebar['view'] ?? '' ) === 'timeline' ? ' selected' : '' ) . '>Timeline</option></select></div>';
        $body_html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1">Max events</label><input class="form-control" name="sidebar[limit]" type="number" min="1" max="20" value="' . (int) ( $sidebar['limit'] ?? 6 ) . '"></div>';
        $body_html .= '<div class="col-12 col-lg-4"><div class="small mb-1">Calendars <span class="text-body-secondary">(none selected means all)</span></div><div class="d-flex flex-wrap gap-3">';
        foreach ( (array) $settings['sources'] as $source ) {
            $sidebar_key = (string) ( $source['key'] ?? '' );
            $body_html .= '<div class="form-check"><input class="form-check-input" id="tm-wu-sidebar-source-' . $escape( $sidebar_key ) . '" name="sidebar[sources][]" type="checkbox" value="' . $escape( $sidebar_key ) . '"' . ( in_array( $sidebar_key, (array) ( $sidebar['sources'] ?? [] ), true ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-wu-sidebar-source-' . $escape( $sidebar_key ) . '">' . $escape( (string) ( $source['label'] ?? $sidebar_key ) ) . '</label></div>';
        }
        $body_html .= '</div></div></div><div class="form-text mt-2">Sidebar placement is rendered where the selected public theme supports plugin sidebar content.</div></section>';
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="small text-uppercase text-body-secondary mb-2">Shortcodes</div><div class="d-flex flex-wrap gap-3"><code>[whatsup]</code><code>[agenda future="30" limit="10"]</code><code>[agenda source="calendar-key" future="30" limit="10"]</code><code>[timeline future="30"]</code><code>[calendar past_months="1" future_months="1"]</code></div></section>';
        $body_html .= '<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">Save calendar settings</button></div></form>';
        $body_html .= '<script>(function(){document.querySelectorAll(".tm-wu-source-upload").forEach(function(input){input.addEventListener("change",function(){if(!input.files||input.files.length===0){return;}var select=document.querySelector(".tm-wu-source-type[data-tm-wu-source-index=\\"" + input.dataset.tmWuSourceIndex + "\\"]");if(select){select.value="local";}});});}());</script>';

        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3"><div><div class="small text-uppercase text-body-secondary mb-1">Cached event preview</div><div class="small text-body-secondary">Last refresh: ' . $escape( (string) ( $cache['refreshed_at_utc'] ?: 'Never' ) ) . '</div></div><form method="post" action="' . $escape( $refresh_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><button class="btn btn-outline-primary" type="submit"><span class="bi bi-arrow-clockwise me-1" aria-hidden="true"></span>Refresh calendars</button></form></div>';
        $preview = $service->getPreviewEvents();
        if ( empty( $preview ) ) {
            $body_html .= '<p class="mb-0 text-body-secondary">No cached events. Add a source and refresh calendars.</p>';
        } else {
            $timezone = new \DateTimeZone( (string) $settings['timezone'] );
            $body_html .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Start</th><th>Event</th><th>Source</th><th>Location</th></tr></thead><tbody>';
            foreach ( $preview as $event ) {
                try {
                    $start = ( new \DateTimeImmutable( (string) ( $event['start_at_utc'] ?? '' ) ) )->setTimezone( $timezone );
                    $date_label = ! empty( $event['all_day'] ) ? $start->format( 'Y-m-d' ) . ' (all day)' : $start->format( 'Y-m-d H:i' );
                } catch ( \Throwable $e ) {
                    $date_label = '';
                }
                $body_html .= '<tr><td class="text-nowrap">' . $escape( $date_label ) . '</td><td>' . $escape( (string) ( $event['title'] ?? '' ) ) . '</td><td>' . $escape( (string) ( $event['source_label'] ?? '' ) ) . '</td><td>' . $escape( (string) ( $event['location'] ?? '' ) ) . '</td></tr>';
            }
            $body_html .= '</tbody></table></div>';
        }
        $body_html .= '</section></div>';

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'What\'s Up',
                'current_section' => 'whats-up',
                'current_location' => 'whats-up',
                'help_contexts' => [ 'plugin-whats-up' ],
                'plugin_page_kicker' => 'What\'s Up',
                'plugin_page_title' => 'Calendar agenda',
                'plugin_page_summary' => 'Configure calendar sources and render cached events in content.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $plugin_url,
        static function() use ( $require_admin, $build_page ) : void {
            if ( $require_admin() ) {
                $build_page();
            }
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $save_url,
        static function() use ( $plugins, $security, $service, $plugin_url, $require_admin, $invalidate_public_cache ) : void {
            if ( ! $require_admin() ) {
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'whats-up.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            try {
                $submitted_sources = is_array( $data['sources'] ?? null ) ? array_values( $data['sources'] ) : [];
                $files = is_array( $_FILES['source_upload'] ?? null ) ? $_FILES['source_upload'] : [];
                $existing_sources = [];
                foreach ( (array) ( $service->getSettings()['sources'] ?? [] ) as $existing_source ) {
                    if ( ! is_array( $existing_source ) ) {
                        continue;
                    }
                    $existing_key = $service->normalizeSourceKey( (string) ( $existing_source['key'] ?? '' ) );
                    if ( $existing_key !== '' ) {
                        $existing_sources[$existing_key] = $existing_source;
                    }
                }
                foreach ( $submitted_sources as $index => $source ) {
                    if ( ! is_array( $source ) || ! empty( $source['remove'] ) ) {
                        continue;
                    }
                    $source_key = $service->normalizeSourceKey( (string) ( $source['key'] ?? '' ) );
                    $existing_source = is_array( $existing_sources[$source_key] ?? null ) ? $existing_sources[$source_key] : [];
                    if (
                        (string) ( $existing_source['type'] ?? '' ) === 'local'
                        && strtolower( trim( (string) ( $source['type'] ?? '' ) ) ) === 'remote'
                        && mb_trim( (string) ( $source['url'] ?? '' ) ) === ''
                        && is_file( $service->getLocalSourceFilename( $source_key ) )
                    ) {
                        $submitted_sources[$index]['type'] = 'local';
                        $submitted_sources[$index]['url'] = '';
                    }
                }
                $uploaded_indexes = [];
                $used_source_keys = [];
                foreach ( $submitted_sources as $source ) {
                    if ( is_array( $source ) && empty( $source['remove'] ) ) {
                        $source_key = $service->normalizeSourceKey( (string) ( $source['key'] ?? '' ) );
                        if ( $source_key !== '' ) {
                            $used_source_keys[$source_key] = true;
                        }
                    }
                }
                foreach ( (array) ( $files['error'] ?? [] ) as $index => $upload_error ) {
                    if ( (int) $upload_error === UPLOAD_ERR_NO_FILE ) {
                        continue;
                    }
                    $source = is_array( $submitted_sources[$index] ?? null ) ? $submitted_sources[$index] : [];
                    if ( ! empty( $source['remove'] ) ) {
                        continue;
                    }
                    $suggested = $service->suggestUploadedSource( (string) ( $files['name'][$index] ?? '' ) );
                    $source_key = $service->normalizeSourceKey( (string) ( $source['key'] ?? '' ) );
                    if ( $source_key === '' ) {
                        $base_key = (string) $suggested['key'];
                        $source_key = $base_key;
                        $suffix = 2;
                        while ( isset( $used_source_keys[$source_key] ) ) {
                            $source_key = $base_key . '-' . $suffix;
                            $suffix++;
                        }
                        $source['key'] = $source_key;
                    }
                    $used_source_keys[$source_key] = true;
                    if ( mb_trim( (string) ( $source['label'] ?? '' ) ) === '' ) {
                        $source['label'] = (string) $suggested['label'];
                    }
                    $source['type'] = 'local';
                    $source['url'] = '';
                    if ( ! array_key_exists( 'enabled', $source ) && ! isset( $submitted_sources[$index] ) ) {
                        $source['enabled'] = '1';
                    }
                    $submitted_sources[$index] = $source;
                    $uploaded_indexes[(int) $index] = true;
                }
                $settings = [
                    'timezone' => (string) ( $data['timezone'] ?? '' ),
                    'time_format' => (string) ( $data['time_format'] ?? '' ),
                    'past_days' => $data['past_days'] ?? 0,
                    'future_days' => $data['future_days'] ?? 30,
                    'max_events' => $data['max_events'] ?? 12,
                    'empty_text' => (string) ( $data['empty_text'] ?? '' ),
                    'now_color' => (string) ( $data['now_color'] ?? 'primary' ),
                    'now_custom_color' => (string) ( $data['now_custom_color'] ?? '' ),
                    'show_source_labels' => ! empty( $data['show_source_labels'] ),
                    'sources' => $submitted_sources,
                    'sidebar' => is_array( $data['sidebar'] ?? null ) ? $data['sidebar'] : [],
                ];
                $settings = $service->normalizeSettings( $settings, true );
                $uploaded_source_keys = [];
                foreach ( array_keys( $uploaded_indexes ) as $index ) {
                    $source = is_array( $submitted_sources[$index] ?? null ) ? $submitted_sources[$index] : [];
                    $source_key = $service->normalizeSourceKey( (string) ( $source['key'] ?? '' ) );
                    if ( $source_key === '' ) {
                        continue;
                    }
                    $service->storeUploadedSource(
                        $source_key,
                        [
                            'name' => (string) ( $files['name'][$index] ?? '' ),
                            'tmp_name' => (string) ( $files['tmp_name'][$index] ?? '' ),
                            'size' => (int) ( $files['size'][$index] ?? 0 ),
                            'error' => (int) ( $files['error'][$index] ?? UPLOAD_ERR_NO_FILE ),
                        ]
                    );
                    $uploaded_source_keys[] = $source_key;
                }
                if ( ! $service->saveSettings( $settings ) ) {
                    throw new \RuntimeException( 'storage' );
                }
                $invalidate_public_cache();
                if ( ! empty( $uploaded_source_keys ) ) {
                    $refresh = $service->refreshSources( array_values( array_unique( $uploaded_source_keys ) ) );
                    $invalidate_public_cache();
                    if ( (int) ( $refresh['failed'] ?? 0 ) > 0 ) {
                        $security->setFlash( 'whats-up.notice', [ 'type' => 'warning', 'message' => 'The uploaded calendar source was saved, but it could not be cached. Check its status below.' ] );
                    } else {
                        $security->setFlash( 'whats-up.notice', [ 'type' => 'success', 'message' => 'Uploaded calendar source saved and refreshed; cached ' . (int) ( $refresh['event_count'] ?? 0 ) . ' event occurrence(s).' ] );
                    }
                } else {
                    $security->setFlash( 'whats-up.notice', [ 'type' => 'success', 'message' => 'Calendar settings were saved. Refresh calendars to update public agenda output.' ] );
                }
            } catch ( \Throwable $e ) {
                $messages = [
                    'source_key' => 'Each calendar source needs a unique key.',
                    'source_url' => 'Remote calendar sources require a public HTTPS URL.',
                    'source_color' => 'A custom source color must be a six-digit hex value such as #0072B2.',
                    'now_color' => 'A custom Now accent must be a six-digit hex value such as #0072B2.',
                    'upload_size' => 'Uploaded calendars must be no larger than 2 MB.',
                    'source_upload' => 'Choose a non-empty iCalendar file no larger than 2 MB.',
                    'source_ics' => 'The uploaded file is not an iCalendar file.',
                ];
                $security->setFlash( 'whats-up.notice', [ 'type' => 'danger', 'message' => $messages[$e->getMessage()] ?? 'Calendar settings could not be saved.' ] );
            }
            $plugins->redirect( $plugin_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $refresh_url,
        static function() use ( $plugins, $security, $service, $plugin_url, $require_admin, $invalidate_public_cache ) : void {
            if ( ! $require_admin() ) {
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'whats-up.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            try {
                $result = $service->refreshSources();
                $invalidate_public_cache();
                $security->setFlash(
                    'whats-up.notice',
                    [
                        'type' => (int) ( $result['failed'] ?? 0 ) > 0 ? 'warning' : 'success',
                        'message' => (string) ( $result['message'] ?? 'Calendar sources were refreshed.' ),
                    ]
                );
            } catch ( \Throwable $e ) {
                $security->setFlash( 'whats-up.notice', [ 'type' => 'danger', 'message' => 'Calendar refresh could not be completed.' ] );
            }
            $plugins->redirect( $plugin_url );
        }
    );
};
