<?php

require_once __DIR__ . '/TinyMashAreWeOpenService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'are-we-open' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity ) {
        throw new \RuntimeException( 'Required services for the Are we open plugin are not available.' );
    }

    $service = new TinyMashAreWeOpenService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'are-we-open' . DIRECTORY_SEPARATOR . 'settings.json',
        $config->getLocaleTimezone()
    );

    \Flight::set( 'plugin.are_we_open.service', $service );

    $plugins->registerShortcode(
        $plugin_key,
        'areweopen',
        static function( array $shortcode ) use ( $service ) : array {
            return(
                [
                    'html' => $service->renderStatusHtml( is_array( $shortcode['attributes'] ?? null ) ? $shortcode['attributes'] : [] ),
                    'dynamic' => true,
                ]
            );
        },
        [
            'label' => 'Open status',
            'description' => 'Displays the current open or closed status.',
            'example' => '[areweopen]',
            'dynamic' => true,
        ]
    );

    $plugins->registerShortcode(
        $plugin_key,
        'areweopen-open',
        static function( array $shortcode ) use ( $service ) : array {
            $status = $service->getStatus();
            return(
                [
                    'markdown' => ! empty( $status['open'] ) ? (string) ( $shortcode['content'] ?? '' ) : '',
                    'dynamic' => true,
                ]
            );
        },
        [
            'label' => 'Open-only content',
            'description' => 'Shows the enclosed content only while the configured status is open.',
            'example' => '[areweopen-open]Open now.[/areweopen-open]',
            'block' => true,
            'dynamic' => true,
        ]
    );

    $plugins->registerShortcode(
        $plugin_key,
        'areweopen-closed',
        static function( array $shortcode ) use ( $service ) : array {
            $status = $service->getStatus();
            return(
                [
                    'markdown' => empty( $status['open'] ) ? (string) ( $shortcode['content'] ?? '' ) : '',
                    'dynamic' => true,
                ]
            );
        },
        [
            'label' => 'Closed-only content',
            'description' => 'Shows the enclosed content only while the configured status is closed.',
            'example' => '[areweopen-closed]Closed right now.[/areweopen-closed]',
            'block' => true,
            'dynamic' => true,
        ]
    );

    $plugins->registerShortcode(
        $plugin_key,
        'areweopen-hours',
        static function() use ( $service ) : array {
            return(
                [
                    'html' => $service->renderHoursHtml(),
                    'dynamic' => true,
                ]
            );
        },
        [
            'label' => 'Opening hours',
            'description' => 'Displays the configured weekly opening hours.',
            'example' => '[areweopen-hours]',
            'dynamic' => true,
        ]
    );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/are-we-open';
    $save_url = $plugin_url . '/save';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'are-we-open',
            'label' => 'Are we open',
            'url' => $plugin_url,
            'icon' => 'bi-clock',
            'order' => 77,
        ]
    );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-are-we-open',
        [
            'title' => 'Are we open',
            'summary' => 'Display local availability from configured hours.',
            'group' => 'Plugins',
            'order' => 142,
            'roles' => [ 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'Are we open renders open or closed availability from weekly hours, date exceptions, and an optional manual override.',
                ],
                [
                    'title' => 'Shortcodes',
                    'markdown' => 'Use `[areweopen]` for a status badge, `[areweopen-hours]` for weekly hours, `[areweopen-open]...[/areweopen-open]` for open-only content, and `[areweopen-closed]...[/areweopen-closed]` for closed-only content.',
                ],
                [
                    'title' => 'Scope',
                    'markdown' => 'This plugin is display-only. It does not handle bookings, reservations, ecommerce, staff scheduling, or appointments.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
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
                    'current_section' => 'are-we-open',
                    'current_location' => 'are-we-open',
                    'plugin_page_kicker' => 'Are we open',
                    'plugin_page_title' => 'Forbidden',
                    'plugin_page_summary' => 'Availability settings are limited to admins.',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You do not have permission to manage availability settings.' ],
                    'plugin_page_body_html' => '<p><a class="btn btn-outline-secondary" href="' . $escape( $admin_url ) . '">Return to dashboard</a></p>',
                ]
            );
            return( false );
        }

        return( true );
    };

    $render_select = static function( string $name, string $current, array $options, string $id = '' ) use ( $escape ) : string {
        $html = '<select class="form-select" name="' . $escape( $name ) . '"' . ( $id !== '' ? ' id="' . $escape( $id ) . '"' : '' ) . '>';
        foreach ( $options as $value => $label ) {
            $value = (string) $value;
            $html .= '<option value="' . $escape( $value ) . '"' . ( $value === $current ? ' selected' : '' ) . '>' . $escape( (string) $label ) . '</option>';
        }
        $html .= '</select>';
        return( $html );
    };

    $build_page = static function( array $notice = [] ) use ( $plugins, $security, $service, $save_url, $escape, $render_select ) : void {
        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'are-we-open.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }

        $settings = $service->getSettings();
        $status = $service->getStatus();
        $timezones = timezone_identifiers_list();
        $timezone_options = [];
        foreach ( $timezones as $timezone ) {
            $timezone_options[$timezone] = $timezone;
        }
        $weekdays = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

        $body_html = '<form method="post" action="' . $escape( $save_url ) . '" class="d-grid gap-4">';
        $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';

        $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
        $body_html .= '<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">';
        $body_html .= '<div><div class="small text-uppercase text-body-secondary mb-1">Current status</div><div class="h5 mb-0">' . ( ! empty( $status['open'] ) ? 'Open' : 'Closed' ) . '</div></div>';
        $body_html .= '<div class="badge text-bg-' . ( ! empty( $status['open'] ) ? 'success' : 'secondary' ) . '">' . $escape( (string) ( $status['source'] ?? 'schedule' ) ) . '</div>';
        $body_html .= '</div>';
        $body_html .= '<div class="row g-3">';
        $body_html .= '<div class="col-12 col-lg-4"><label class="form-label small mb-1" for="tm-awo-timezone">Timezone</label>' . $render_select( 'timezone', (string) $settings['timezone'], $timezone_options, 'tm-awo-timezone' ) . '</div>';
        $body_html .= '<div class="col-12 col-lg-4"><label class="form-label small mb-1" for="tm-awo-open-label">Open label</label><input class="form-control" id="tm-awo-open-label" name="open_label" type="text" maxlength="80" value="' . $escape( (string) $settings['open_label'] ) . '"></div>';
        $body_html .= '<div class="col-12 col-lg-4"><label class="form-label small mb-1" for="tm-awo-closed-label">Closed label</label><input class="form-control" id="tm-awo-closed-label" name="closed_label" type="text" maxlength="80" value="' . $escape( (string) $settings['closed_label'] ) . '"></div>';
        $body_html .= '</div>';
        $body_html .= '</section>';

        $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-3">Weekly hours</div>';
        $body_html .= '<div class="d-grid gap-2">';
        foreach ( $weekdays as $weekday ) {
            $row = is_array( $settings['weekly'][$weekday] ?? null ) ? $settings['weekly'][$weekday] : [];
            $id = 'tm-awo-day-' . $weekday;
            $body_html .= '<div class="row g-2 align-items-end">';
            $body_html .= '<div class="col-12 col-lg-2"><div class="form-check mb-2"><input class="form-check-input" id="' . $escape( $id ) . '" name="weekly[' . $escape( $weekday ) . '][enabled]" type="checkbox" value="1"' . ( ! empty( $row['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="' . $escape( $id ) . '">' . $escape( ucfirst( $weekday ) ) . '</label></div></div>';
            $body_html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1">Opens</label><input class="form-control font-monospace" name="weekly[' . $escape( $weekday ) . '][open_time]" type="time" value="' . $escape( (string) ( $row['open_time'] ?? '09:00' ) ) . '"></div>';
            $body_html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1">Closes</label><input class="form-control font-monospace" name="weekly[' . $escape( $weekday ) . '][close_time]" type="time" value="' . $escape( (string) ( $row['close_time'] ?? '17:00' ) ) . '"></div>';
            $body_html .= '<div class="col-12 col-lg-6"><label class="form-label small mb-1">Note</label><input class="form-control" name="weekly[' . $escape( $weekday ) . '][note]" type="text" maxlength="160" value="' . $escape( (string) ( $row['note'] ?? '' ) ) . '"></div>';
            $body_html .= '</div>';
        }
        $body_html .= '</div>';
        $body_html .= '</section>';

        $manual = is_array( $settings['manual_override'] ?? null ) ? $settings['manual_override'] : [];
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-3">Manual override</div>';
        $body_html .= '<div class="row g-3 align-items-end">';
        $body_html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="tm-awo-override-mode">Mode</label>' . $render_select( 'manual_override[mode]', (string) ( $manual['mode'] ?? 'auto' ), [ 'auto' => 'Use schedule', 'open' => 'Force open', 'closed' => 'Force closed' ], 'tm-awo-override-mode' ) . '</div>';
        $body_html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="tm-awo-override-until">Until</label><input class="form-control" id="tm-awo-override-until" name="manual_override[until]" type="datetime-local" value="' . $escape( (string) ( $manual['until'] ?? '' ) ) . '"></div>';
        $body_html .= '<div class="col-12 col-lg-6"><label class="form-label small mb-1" for="tm-awo-override-message">Message</label><input class="form-control" id="tm-awo-override-message" name="manual_override[message]" type="text" maxlength="160" value="' . $escape( (string) ( $manual['message'] ?? '' ) ) . '"></div>';
        $body_html .= '</div>';
        $body_html .= '<div class="form-text">Leave Until empty for an override that stays active until changed.</div>';
        $body_html .= '</section>';

        $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-3">Date exceptions</div>';
        $exceptions = array_values( is_array( $settings['exceptions'] ?? null ) ? $settings['exceptions'] : [] );
        $exceptions[] = [ 'enabled' => true, 'date' => '', 'mode' => 'closed', 'open_time' => '09:00', 'close_time' => '17:00', 'note' => '' ];
        foreach ( $exceptions as $index => $exception ) {
            $exception = is_array( $exception ) ? $exception : [];
            $body_html .= '<div class="row g-2 align-items-end mb-2">';
            $body_html .= '<div class="col-12 col-lg-2"><div class="form-check mb-2"><input class="form-check-input" id="tm-awo-exception-enabled-' . (int) $index . '" name="exceptions[' . (int) $index . '][enabled]" type="checkbox" value="1"' . ( ! empty( $exception['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-awo-exception-enabled-' . (int) $index . '">Enabled</label></div></div>';
            $body_html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1">Date</label><input class="form-control font-monospace" name="exceptions[' . (int) $index . '][date]" type="date" value="' . $escape( (string) ( $exception['date'] ?? '' ) ) . '"></div>';
            $body_html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1">Mode</label>' . $render_select( 'exceptions[' . (int) $index . '][mode]', (string) ( $exception['mode'] ?? 'closed' ), [ 'closed' => 'Closed', 'open' => 'Open hours' ] ) . '</div>';
            $body_html .= '<div class="col-6 col-lg-1"><label class="form-label small mb-1">Opens</label><input class="form-control font-monospace" name="exceptions[' . (int) $index . '][open_time]" type="time" value="' . $escape( (string) ( $exception['open_time'] ?? '09:00' ) ) . '"></div>';
            $body_html .= '<div class="col-6 col-lg-1"><label class="form-label small mb-1">Closes</label><input class="form-control font-monospace" name="exceptions[' . (int) $index . '][close_time]" type="time" value="' . $escape( (string) ( $exception['close_time'] ?? '17:00' ) ) . '"></div>';
            $body_html .= '<div class="col-12 col-lg-4"><label class="form-label small mb-1">Note</label><input class="form-control" name="exceptions[' . (int) $index . '][note]" type="text" maxlength="160" value="' . $escape( (string) ( $exception['note'] ?? '' ) ) . '"></div>';
            $body_html .= '</div>';
        }
        $body_html .= '<div class="form-text">Use the empty row to add another exception. Blank dates are ignored.</div>';
        $body_html .= '</section>';

        $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Shortcodes</div>';
        $body_html .= '<div class="row g-2">';
        foreach ( [ '[areweopen]', '[areweopen format="text"]', '[areweopen-hours]', '[areweopen-open]Open content[/areweopen-open]', '[areweopen-closed]Closed content[/areweopen-closed]' ] as $example ) {
            $body_html .= '<div class="col-12 col-lg-6"><code>' . $escape( $example ) . '</code></div>';
        }
        $body_html .= '</div>';
        $body_html .= '</section>';

        $body_html .= '<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">Save availability</button></div>';
        $body_html .= '</form>';

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Are we open',
                'current_section' => 'are-we-open',
                'current_location' => 'are-we-open',
                'help_contexts' => [ 'plugin-are-we-open' ],
                'plugin_page_kicker' => 'Are we open',
                'plugin_page_title' => 'Availability display',
                'plugin_page_summary' => 'Configure local opening hours, exceptions, and display shortcodes.',
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
            if ( ! $require_admin() ) {
                return;
            }

            $build_page();
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $save_url,
        static function() use ( $plugins, $security, $service, $plugin_url, $require_admin ) : void {
            if ( ! $require_admin() ) {
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash( 'are-we-open.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }

            $settings = [
                'timezone' => (string) ( $data['timezone'] ?? '' ),
                'open_label' => (string) ( $data['open_label'] ?? '' ),
                'closed_label' => (string) ( $data['closed_label'] ?? '' ),
                'weekly' => is_array( $data['weekly'] ?? null ) ? $data['weekly'] : [],
                'manual_override' => is_array( $data['manual_override'] ?? null ) ? $data['manual_override'] : [],
                'exceptions' => is_array( $data['exceptions'] ?? null ) ? $data['exceptions'] : [],
            ];

            if ( ! $service->saveSettings( $settings ) ) {
                $security->setFlash( 'are-we-open.notice', [ 'type' => 'danger', 'message' => 'Availability settings could not be saved.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }

            $public_page_cache = \Flight::app()->has( 'public.page_cache' ) ? \Flight::app()->get( 'public.page_cache' ) : null;
            if ( $public_page_cache instanceof TinyMashPublicPageCache ) {
                $public_page_cache->invalidateAll();
            }

            $security->setFlash( 'are-we-open.notice', [ 'type' => 'success', 'message' => 'Availability settings were saved.' ] );
            $plugins->redirect( $plugin_url );
        }
    );
};
