<?php

require_once __DIR__ . '/TinyMashWeatherService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'weather' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    if ( ! $config instanceof TinyMashConfig ) {
        throw new \RuntimeException( 'Required services for the Weather plugin are not available.' );
    }

    $service = new TinyMashWeatherService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'weather',
        $config->getLocaleTimezone(),
        $config->getDefaultLanguage()
    );
    \Flight::set( 'plugin.weather.service', $service );

    $plugins->registerHousekeepingTask(
        $plugin_key,
        static function( array $context = [] ) use ( $service ) : string {
            if ( (string) ( $context['trigger'] ?? '' ) === 'web_fallback' ) {
                return( 'Skipped weather refresh during web fallback; use CLI or admin refresh.' );
            }
            return( (string) $service->refreshLocations()['message'] );
        },
        'refresh',
        'Refresh Weather feeds'
    );

    if ( $plugins->isCliRuntime() ) {
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'weather',
                'usage' => 'tinymash weather refresh',
                'summary' => 'Refresh configured Weather provider feeds.',
                'order' => 246,
                'handler' => static function( array $arguments ) use ( $service ) : void {
                    if ( strtolower( trim( (string) ( $arguments[0] ?? '' ) ) ) !== 'refresh' ) {
                        throw new \RuntimeException( 'usage: tinymash weather refresh' );
                    }
                    $result = $service->refreshLocations();
                    fwrite( STDOUT, 'refreshed: ' . (int) ( $result['refreshed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'failed: ' . (int) ( $result['failed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'disabled: ' . (int) ( $result['disabled'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'cached_feeds: ' . (int) ( $result['feed_count'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'message: ' . (string) ( $result['message'] ?? '' ) . PHP_EOL );
                },
            ]
        );
        return;
    }

    if ( ! $security instanceof TinyMashSecurity ) {
        throw new \RuntimeException( 'Required services for the Weather plugin are not available.' );
    }

    $plugins->registerPublicAsset( $plugin_key, 'css', '/plugins/weather/weather.css' );
    $plugins->registerShortcode(
        $plugin_key,
        'weather',
        static function( array $shortcode ) use ( $service ) : array {
            return(
                [
                    'html' => $service->renderWeatherHtml( is_array( $shortcode['attributes'] ?? null ) ? $shortcode['attributes'] : [] ),
                    'dynamic' => true,
                ]
            );
        },
        [
            'label' => 'Weather',
            'description' => 'Displays cached current weather and forecast for a configured location.',
            'example' => '[weather location="home" current="true" forecast="3"]',
            'dynamic' => true,
        ]
    );
    $weather_settings = $service->getSettings();
    if ( ! empty( $weather_settings['slots']['sidebar']['enabled'] ) ) {
        $plugins->registerPublicSlotRenderer(
            $plugin_key,
            'sidebar',
            static function() use ( $service ) : string {
                return( $service->renderSlotHtml( 'sidebar' ) );
            },
            true
        );
    }
    if ( ! empty( $weather_settings['slots']['footer']['enabled'] ) ) {
        $plugins->registerPublicSlotRenderer(
            $plugin_key,
            'footer',
            static function() use ( $service ) : string {
                return( $service->renderSlotHtml( 'footer' ) );
            },
            true
        );
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/weather';
    $save_url = $plugin_url . '/save';
    $refresh_url = $plugin_url . '/refresh';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'weather',
            'label' => 'Weather',
            'url' => $plugin_url,
            'icon' => 'bi-cloud-sun',
            'order' => 79,
        ]
    );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-weather',
        [
            'title' => 'Weather',
            'summary' => 'Display cached weather from configured provider feeds.',
            'group' => 'Plugins',
            'order' => 144,
            'roles' => [ 'admin' ],
            'sections' => [
                [
                    'title' => 'Sources and privacy',
                    'markdown' => 'Weather fetches provider data through admin refresh, CLI refresh, or scheduled housekeeping. Public pages render cached data only; visitors do not call weather providers or expose browser geolocation. Location coordinates may be entered as decimal degrees or pasted in degrees/minutes/seconds notation such as `60° 08\' 30.52" N`. OpenStreetMap and GeoNames are practical open coordinate lookup sources.',
                ],
                [
                    'title' => 'Providers',
                    'markdown' => 'The built-in providers are Open-Meteo, MET Norway / Yr, and the US National Weather Service. MET Norway / Yr uses the Locationforecast API and requires attribution plus an identifying User-Agent. NWS is US-focused and only works for locations resolved by `api.weather.gov`. Future commercial key-based providers should remain explicit because pricing, attribution, redistribution, and quota terms vary.',
                ],
                [
                    'title' => 'Shortcode',
                    'markdown' => 'Use `[weather location="home"]` for compact output. If exactly one location is configured, `[weather]` uses that location. Useful variants include `[weather location="home" forecast="0"]`, `[weather location="home" forecast="7" layout="horizontal"]`, and `[weather location="home" details="location,icon,temperature,condition,wind"]`. Attributes include `provider="open-meteo"`, `current="true"`, `forecast="3"`, `layout="compact|vertical|horizontal"`, `units="metric|imperial"`, `wind_units="ms|kmh|mph"`, `details="location,icon,temperature,condition,wind"`, and `attribution="true"`.',
                ],
                [
                    'title' => 'Theme slots',
                    'markdown' => 'The sidebar and footer slot options render cached Weather where the active public theme supports plugin slots. Slot output uses the same cached data as `[weather]` and does not call provider APIs during public page rendering.',
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
                    'current_section' => 'weather',
                    'current_location' => 'weather',
                    'plugin_page_kicker' => 'Weather',
                    'plugin_page_title' => 'Forbidden',
                    'plugin_page_summary' => 'Weather source management is limited to admins.',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You do not have permission to manage weather sources.' ],
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

    $default_timezone = $config->getLocaleTimezone();

    $build_page = static function( array $notice = [], ?array $form_settings = null ) use ( $plugins, $security, $service, $save_url, $refresh_url, $escape, $render_select, $default_timezone ) : void {
        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'weather.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }
        $settings = $form_settings ?? $service->getSettings();
        $settings = array_replace_recursive( $service->getDefaultSettings(), $settings );
        $cache = $service->getCache();
        $timezones = timezone_identifiers_list();
        $timezone_options = array_combine( $timezones, $timezones );
        $unit_options = [ 'metric' => 'Metric', 'imperial' => 'Imperial' ];
        $wind_unit_options = [ 'ms' => 'm/s', 'kmh' => 'km/h', 'mph' => 'mph' ];
        $layout_options = [ 'compact' => 'Compact', 'vertical' => 'Vertical', 'horizontal' => 'Horizontal' ];
        $provider_definitions = $service->getProviderDefinitions();
        $provider_options = [];
        foreach ( $provider_definitions as $provider_key => $provider_definition ) {
            $provider_options[(string) $provider_key] = (string) ( $provider_definition['label'] ?? $provider_key );
        }
        $detail_options = [
            'location' => 'Location',
            'icon' => 'Graphics',
            'temperature' => 'Temperature',
            'condition' => 'Condition',
            'wind' => 'Wind speed',
            'feels_like' => 'Feels like',
            'humidity' => 'Humidity',
        ];
        $locations = array_values( (array) $settings['locations'] );
        $location_options = [ '' => 'First active location' ];
        foreach ( $locations as $configured_location ) {
            $configured_location_key = (string) ( $configured_location['key'] ?? '' );
            if ( $configured_location_key === '' ) {
                continue;
            }
            $location_options[$configured_location_key] = (string) ( $configured_location['label'] ?? $configured_location_key ) . ' (' . $configured_location_key . ')';
        }
        $locations[] = [ 'key' => '', 'label' => '', 'latitude' => '', 'longitude' => '', 'timezone' => (string) ( $settings['locations'][0]['timezone'] ?? $default_timezone ), 'units' => (string) $settings['defaults']['units'], 'enabled' => true, 'default_provider' => 'open-meteo', 'providers' => [ 'open-meteo' => [ 'enabled' => true ], 'met-norway' => [ 'enabled' => false ], 'nws' => [ 'enabled' => false ] ] ];

        $body_html = '<div class="d-grid gap-4">';
        $body_html .= '<form method="post" action="' . $escape( $save_url ) . '" class="d-grid gap-4">';
        $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="small text-uppercase text-body-secondary mb-3">Display defaults</div><div class="row g-3 align-items-end">';
        $body_html .= '<div class="col-12 col-sm-6 col-xl-2"><label class="form-label small mb-1" for="tm-weather-default-units">Temperature</label>' . $render_select( 'default_units', (string) $settings['defaults']['units'], $unit_options, 'tm-weather-default-units' ) . '</div>';
        $body_html .= '<div class="col-12 col-sm-6 col-xl-2"><label class="form-label small mb-1" for="tm-weather-default-wind-units">Wind</label>' . $render_select( 'default_wind_units', (string) ( $settings['defaults']['wind_units'] ?? 'ms' ), $wind_unit_options, 'tm-weather-default-wind-units' ) . '</div>';
        $body_html .= '<div class="col-12 col-sm-6 col-xl-2"><label class="form-label small mb-1" for="tm-weather-default-layout">Layout</label>' . $render_select( 'default_layout', (string) $settings['defaults']['layout'], $layout_options, 'tm-weather-default-layout' ) . '</div>';
        $body_html .= '<div class="col-6 col-xl-2"><label class="form-label small mb-1" for="tm-weather-forecast-days">Forecast days</label><input class="form-control" id="tm-weather-forecast-days" name="default_forecast_days" type="number" min="0" max="7" value="' . (int) $settings['defaults']['forecast_days'] . '"></div>';
        $body_html .= '<div class="col-6 col-xl-2"><label class="form-label small mb-1" for="tm-weather-stale-hours">Stale after hours</label><input class="form-control" id="tm-weather-stale-hours" name="stale_hours" type="number" min="1" max="168" value="' . (int) $settings['defaults']['stale_hours'] . '"></div>';
        $body_html .= '<div class="col-12 col-xl-2"><button class="btn btn-primary" type="submit">Save weather</button></div>';
        $body_html .= '<div class="col-12"><div class="form-label small mb-1">Rendered details</div><div class="d-flex flex-wrap gap-3">';
        foreach ( $detail_options as $detail_key => $detail_label ) {
            $detail_id = 'tm-weather-detail-' . $escape( (string) $detail_key );
            $body_html .= '<div class="form-check"><input class="form-check-input" id="' . $detail_id . '" name="details[' . $escape( (string) $detail_key ) . ']" type="checkbox" value="1"' . ( ! empty( $settings['defaults']['details'][$detail_key] ) ? ' checked' : '' ) . '><label class="form-check-label" for="' . $detail_id . '">' . $escape( (string) $detail_label ) . '</label></div>';
        }
        $body_html .= '</div></div>';
        $body_html .= '</div><div class="form-text mt-3">Public pages render cached weather only. Refresh from this page, CLI, or scheduled housekeeping.</div></section>';

        $slots = is_array( $settings['slots'] ?? null ) ? $settings['slots'] : [];
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="small text-uppercase text-body-secondary mb-3">Public theme slots</div>';
        foreach ( [ 'sidebar' => 'Sidebar', 'footer' => 'Footer' ] as $slot_key => $slot_label ) {
            $slot = is_array( $slots[$slot_key] ?? null ) ? $slots[$slot_key] : [];
            $prefix = 'slots[' . $slot_key . ']';
            $body_html .= '<div class="row g-3 align-items-end' . ( $slot_key === 'footer' ? ' mt-2 pt-3 border-top' : '' ) . '">';
            $body_html .= '<div class="col-12 col-lg-2"><div class="form-check mb-2"><input class="form-check-input" id="tm-weather-slot-' . $escape( $slot_key ) . '-enabled" name="' . $escape( $prefix ) . '[enabled]" type="checkbox" value="1"' . ( ! empty( $slot['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-weather-slot-' . $escape( $slot_key ) . '-enabled">Show in ' . strtolower( $escape( $slot_label ) ) . ' slot</label></div></div>';
            $body_html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1">' . $escape( $slot_label ) . ' location</label>' . $render_select( $prefix . '[location]', (string) ( $slot['location'] ?? '' ), $location_options ) . '</div>';
            $body_html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1">Provider</label>' . $render_select( $prefix . '[provider]', (string) ( $slot['provider'] ?? 'open-meteo' ), $provider_options ) . '</div>';
            $body_html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1">Layout</label>' . $render_select( $prefix . '[layout]', (string) ( $slot['layout'] ?? 'compact' ), $layout_options ) . '</div>';
            $body_html .= '<div class="col-6 col-lg-1"><label class="form-label small mb-1">Days</label><input class="form-control" name="' . $escape( $prefix ) . '[forecast_days]" type="number" min="0" max="7" value="' . (int) ( $slot['forecast_days'] ?? ( $slot_key === 'footer' ? 0 : 2 ) ) . '"></div>';
            $body_html .= '<div class="col-12 col-lg-2"><div class="form-check mb-2"><input class="form-check-input" id="tm-weather-slot-' . $escape( $slot_key ) . '-attribution" name="' . $escape( $prefix ) . '[attribution]" type="checkbox" value="1"' . ( ! empty( $slot['attribution'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-weather-slot-' . $escape( $slot_key ) . '-attribution">Attribution</label></div></div>';
            $body_html .= '</div>';
        }
        $body_html .= '<div class="form-text mt-3">Slot placement depends on the active public theme. Weather slot output uses cached provider data and the global rendered-detail choices above.</div></section>';

        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"><div><div class="small text-uppercase text-body-secondary">Locations</div><div class="form-text mt-1">Use WGS84 coordinates as decimal degrees or degrees/minutes/seconds. <a href="https://openstreetmap.org" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> is useful for checking place coordinates. Open-Meteo is the first provider; later providers can be enabled per location without changing shortcode locations.</div></div><button class="btn btn-outline-primary" type="submit" formmethod="post" formaction="' . $escape( $refresh_url ) . '"><span class="bi bi-arrow-clockwise me-1" aria-hidden="true"></span>Refresh weather</button></div><div class="d-grid gap-3">';
        foreach ( $locations as $index => $location ) {
            $location_key = (string) ( $location['key'] ?? '' );
            $default_provider = (string) ( $location['default_provider'] ?? 'open-meteo' );
            if ( ! isset( $provider_options[$default_provider] ) ) {
                $default_provider = 'open-meteo';
            }
            $preview_provider = $default_provider;
            if ( empty( $location['providers'][$preview_provider]['enabled'] ) ) {
                foreach ( $provider_options as $provider_key => $provider_label ) {
                    if ( ! empty( $location['providers'][$provider_key]['enabled'] ) ) {
                        $preview_provider = (string) $provider_key;
                        break;
                    }
                }
            }
            $feed_key = $location_key !== '' ? $location_key . ':' . $preview_provider : '';
            $feed = $feed_key !== '' && is_array( $cache['feeds'][$feed_key] ?? null ) ? $cache['feeds'][$feed_key] : [];
            $status = (string) ( $feed['status'] ?? 'new' );
            $badge = $status === 'ok' ? 'success' : ( $status === 'error' ? 'danger' : ( $status === 'disabled' ? 'secondary' : 'secondary' ) );
            $prefix = 'locations[' . (int) $index . ']';
            $location_label = (string) ( $location['label'] ?? '' );
            $location_latitude = (string) ( $location['latitude'] ?? '' );
            $location_longitude = (string) ( $location['longitude'] ?? '' );
            $location_has_data = $location_key !== '' || $location_label !== '' || $location_latitude !== '' || $location_longitude !== '';
            $location_required = $location_has_data ? ' required' : '';
            $body_html .= '<article class="p-3 bg-body rounded-3" data-tm-weather-location-row><div class="row g-3 align-items-start"><div class="col-12 col-lg-6"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><div class="small text-uppercase text-body-secondary">Location</div><h2 class="h6 mb-0">' . $escape( (string) ( $location_label ?: 'New location' ) ) . '</h2></div><span class="badge text-bg-' . $badge . '">' . $escape( $status ) . '</span></div><div class="row g-3">';
            $body_html .= '<div class="col-12 col-md-5"><label class="form-label small mb-1">Key</label><input class="form-control font-monospace" name="' . $escape( $prefix ) . '[key]" type="text" maxlength="80" placeholder="home" value="' . $escape( $location_key ) . '" data-tm-weather-location-trigger></div>';
            $body_html .= '<div class="col-12 col-md-7"><label class="form-label small mb-1">Label</label><input class="form-control" name="' . $escape( $prefix ) . '[label]" type="text" maxlength="100" placeholder="Home" value="' . $escape( $location_label ) . '" data-tm-weather-location-trigger data-tm-weather-location-required' . $location_required . '></div>';
            $body_html .= '<div class="col-6 col-md-4"><label class="form-label small mb-1">Latitude</label><input class="form-control" name="' . $escape( $prefix ) . '[latitude]" type="text" inputmode="decimal" maxlength="40" placeholder="60.14181 or 60° 08\' 30.52&quot; N" value="' . $escape( $location_latitude ) . '" data-tm-weather-location-trigger data-tm-weather-location-required' . $location_required . '></div>';
            $body_html .= '<div class="col-6 col-md-4"><label class="form-label small mb-1">Longitude</label><input class="form-control" name="' . $escape( $prefix ) . '[longitude]" type="text" inputmode="decimal" maxlength="40" placeholder="15.41416 or 15° 24\' 50.98&quot; E" value="' . $escape( $location_longitude ) . '" data-tm-weather-location-trigger data-tm-weather-location-required' . $location_required . '></div>';
            $body_html .= '<div class="col-12 col-md-4"><label class="form-label small mb-1">Units</label>' . $render_select( $prefix . '[units]', (string) ( $location['units'] ?? $settings['defaults']['units'] ), $unit_options ) . '</div>';
            $body_html .= '<div class="col-12"><label class="form-label small mb-1">Timezone</label>' . $render_select( $prefix . '[timezone]', (string) ( $location['timezone'] ?? '' ), $timezone_options ) . '</div>';
            $body_html .= '<div class="col-12 col-md-6"><div class="form-check"><input class="form-check-input" id="tm-weather-enabled-' . (int) $index . '" name="' . $escape( $prefix ) . '[enabled]" type="checkbox" value="1"' . ( ! empty( $location['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-weather-enabled-' . (int) $index . '">Active</label></div></div>';
            $body_html .= '<div class="col-12 col-md-6"><label class="form-label small mb-1">Default provider</label>' . $render_select( $prefix . '[default_provider]', $default_provider, $provider_options ) . '</div>';
            $body_html .= '<div class="col-12"><div class="form-label small mb-1">Enabled providers</div><div class="d-flex flex-wrap gap-3">';
            foreach ( $provider_options as $provider_key => $provider_label ) {
                $provider_id = 'tm-weather-provider-' . $escape( (string) $provider_key ) . '-' . (int) $index;
                $body_html .= '<div class="form-check"><input class="form-check-input" id="' . $provider_id . '" name="' . $escape( $prefix ) . '[providers][' . $escape( (string) $provider_key ) . '][enabled]" type="checkbox" value="1"' . ( ! empty( $location['providers'][$provider_key]['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="' . $provider_id . '">' . $escape( (string) $provider_label ) . '</label></div>';
            }
            $body_html .= '</div></div>';
            if ( $location_key !== '' ) {
                $body_html .= '<div class="col-12"><div class="small text-body-secondary">Shortcode: <code>[weather location=&quot;' . $escape( $location_key ) . '&quot;]</code> <code>[weather location=&quot;' . $escape( $location_key ) . '&quot; forecast=&quot;0&quot;]</code> <code>[weather location=&quot;' . $escape( $location_key ) . '&quot; forecast=&quot;7&quot; layout=&quot;horizontal&quot;]</code></div></div>';
            }
            if ( ! empty( $feed ) ) {
                $body_html .= '<div class="col-12"><div class="small text-body-secondary">Preview provider: ' . $escape( (string) ( $provider_options[$preview_provider] ?? $preview_provider ) ) . '. Last success: ' . $escape( (string) ( $feed['last_success_at_utc'] ?: 'Never' ) ) . '. ' . $escape( (string) ( $feed['message'] ?? '' ) ) . '</div></div>';
            }
            $body_html .= '</div></div><div class="col-12 col-lg-6"><div class="small text-uppercase text-body-secondary mb-2">Preview</div>';
            if ( $location_key === '' ) {
                $body_html .= '<div class="tm-weather tm-weather-admin-preview tm-weather-unavailable"><div class="tm-weather-kicker">Weather</div><p class="mb-0">Save this location and refresh weather to preview it.</p></div>';
            } else {
                $body_html .= $service->renderWeatherHtml( [ 'location' => $location_key, 'provider' => $preview_provider, 'layout' => 'compact', 'forecast' => 1, 'admin_preview' => true ] );
            }
            $body_html .= '</div></div></article>';
        }
        $body_html .= '</div><div class="small text-body-secondary mt-3">Last refresh: ' . $escape( (string) ( $cache['refreshed_at_utc'] ?: 'Never' ) ) . '</div></section></form>';

        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="small text-uppercase text-body-secondary mb-2">Shortcodes</div><div class="d-flex flex-wrap gap-3"><code>[weather location=&quot;home&quot;]</code><code>[weather location=&quot;home&quot; forecast=&quot;0&quot;]</code><code>[weather location=&quot;home&quot; forecast=&quot;7&quot; layout=&quot;horizontal&quot;]</code><code>[weather location=&quot;home&quot; details=&quot;location,icon,temperature,condition,wind&quot;]</code></div></section>';
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3"><div class="small text-uppercase text-body-secondary mb-2">Provider terms</div><div class="d-grid gap-2">';
        foreach ( $provider_definitions as $provider_definition ) {
            $body_html .= '<p class="mb-0 small text-body-secondary"><strong>' . $escape( (string) ( $provider_definition['label'] ?? '' ) ) . ':</strong> ' . $escape( (string) ( $provider_definition['notes'] ?? '' ) ) . '</p>';
        }
        $body_html .= '</div></section>';
        $body_html .= '<script>(()=>{const sync=(row)=>{const used=Array.from(row.querySelectorAll("[data-tm-weather-location-trigger]")).some((field)=>String(field.value||"").trim()!=="");row.querySelectorAll("[data-tm-weather-location-required]").forEach((field)=>{field.required=used;});};document.querySelectorAll("[data-tm-weather-location-row]").forEach((row)=>{sync(row);row.addEventListener("input",()=>sync(row));row.addEventListener("change",()=>sync(row));});})();</script>';
        $body_html .= '</div>';

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Weather',
                'current_section' => 'weather',
                'current_location' => 'weather',
                'plugin_page_kicker' => 'Weather',
                'plugin_page_title' => 'Weather',
                'plugin_page_summary' => 'Display cached weather for named locations.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
                'help_contexts' => [ 'plugin-weather' ],
                'admin_theme_css_urls' => [
                    '/ext/bs/css/bootstrap.min.css',
                    '/ext/bsi/bootstrap-icons.min.css',
                    '/css/tinymash.css',
                    '/plugins/weather/weather.css',
                ],
            ]
        );
    };

    $validate_post = static function( array $data ) use ( $security ) : bool {
        return( $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) );
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
        static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $invalidate_public_cache, $build_page ) : void {
            if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
                $plugins->setResponseStatus( 403 );
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $validate_post( $data ) ) {
                $security->setFlash( 'weather.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            try {
                $result = $service->buildSettingsFromPostResult( $data );
                if ( ! empty( $result['errors'] ) ) {
                    $message = 'Weather settings were not saved: ' . implode( ' ', array_slice( (array) $result['errors'], 0, 4 ) );
                    if ( count( (array) $result['errors'] ) > 4 ) {
                        $message .= ' More errors remain.';
                    }
                    $build_page( [ 'type' => 'danger', 'message' => $message ], (array) $result['settings'] );
                    return;
                }
                $service->saveSettings( (array) $result['settings'] );
                $invalidate_public_cache();
                $security->setFlash( 'weather.notice', [ 'type' => 'success', 'message' => 'Weather settings saved.' ] );
            } catch ( \Throwable $e ) {
                $security->setFlash( 'weather.notice', [ 'type' => 'danger', 'message' => 'Weather settings could not be saved.' ] );
            }
            $plugins->redirect( $plugin_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $refresh_url,
        static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $invalidate_public_cache ) : void {
            if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
                $plugins->setResponseStatus( 403 );
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $validate_post( $data ) ) {
                $security->setFlash( 'weather.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            try {
                $result = $service->refreshLocations();
                $invalidate_public_cache();
                $security->setFlash(
                    'weather.notice',
                    [
                        'type' => (int) ( $result['failed'] ?? 0 ) > 0 ? 'warning' : 'success',
                        'message' => (string) ( $result['message'] ?? 'Weather feeds were refreshed.' ),
                    ]
                );
            } catch ( \Throwable $e ) {
                $security->setFlash( 'weather.notice', [ 'type' => 'danger', 'message' => 'Weather refresh could not be completed.' ] );
            }
            $plugins->redirect( $plugin_url );
        }
    );
};
