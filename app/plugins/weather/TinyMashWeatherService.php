<?php

use app\classes\TinyMashLockedJsonFile;

class TinyMashWeatherService {

    protected const PROVIDER_OPEN_METEO = 'open-meteo';
    protected const PROVIDER_MET_NORWAY = 'met-norway';
    protected const PROVIDER_NWS = 'nws';
    protected const MAX_RESPONSE_BYTES = 262144;
    protected const MAX_FORECAST_DAYS = 7;

    protected string $data_directory;
    protected string $settings_filename;
    protected string $cache_filename;
    protected string $default_timezone;
    protected string $locale;

    public function __construct( string $data_directory, string $default_timezone = 'UTC', string $locale = 'en' ) {
        $this->data_directory = rtrim( $data_directory, DIRECTORY_SEPARATOR );
        $this->settings_filename = $this->data_directory . DIRECTORY_SEPARATOR . 'settings.json';
        $this->cache_filename = $this->data_directory . DIRECTORY_SEPARATOR . 'cache.json';
        $this->default_timezone = in_array( $default_timezone, timezone_identifiers_list(), true ) ? $default_timezone : 'UTC';
        $this->locale = $this->normalizeLocale( $locale );
    }

    public function getSettings() : array {
        return( $this->normalizeSettings( TinyMashLockedJsonFile::read( $this->settings_filename, $this->getDefaultSettings() ) ) );
    }

    public function getCache() : array {
        return( $this->normalizeCache( TinyMashLockedJsonFile::read( $this->cache_filename, $this->getDefaultCache() ) ) );
    }

    public function saveSettings( array $input ) : array {
        $settings = $this->normalizeSettings( $input );
        if ( ! TinyMashLockedJsonFile::write( $this->settings_filename, $settings ) ) {
            throw new \RuntimeException( 'settings_write' );
        }
        $this->trimCacheToSettings( $settings );
        return( $settings );
    }

    public function getDefaultSettings() : array {
        return(
            [
                'defaults' => [
                    'units' => 'metric',
                    'wind_units' => 'ms',
                    'forecast_days' => 3,
                    'layout' => 'compact',
                    'stale_hours' => 6,
                    'details' => [
                        'location' => true,
                        'icon' => true,
                        'temperature' => true,
                        'condition' => true,
                        'wind' => true,
                        'feels_like' => false,
                        'humidity' => false,
                    ],
                ],
                'slots' => [
                    'sidebar' => [
                        'enabled' => false,
                        'location' => '',
                        'provider' => self::PROVIDER_OPEN_METEO,
                        'forecast_days' => 2,
                        'layout' => 'compact',
                        'attribution' => true,
                    ],
                    'footer' => [
                        'enabled' => false,
                        'location' => '',
                        'provider' => self::PROVIDER_OPEN_METEO,
                        'forecast_days' => 0,
                        'layout' => 'compact',
                        'attribution' => true,
                    ],
                ],
                'locations' => [],
            ]
        );
    }

    public function getDefaultCache() : array {
        return(
            [
                'refreshed_at_utc' => '',
                'feeds' => [],
            ]
        );
    }

    public function normalizeSettings( array $input ) : array {
        $defaults = is_array( $input['defaults'] ?? null ) ? $input['defaults'] : [];
        $settings = $this->getDefaultSettings();
        $settings['defaults'] = [
            'units' => $this->normalizeUnits( (string) ( $defaults['units'] ?? $settings['defaults']['units'] ) ),
            'wind_units' => $this->normalizeWindUnits( (string) ( $defaults['wind_units'] ?? $settings['defaults']['wind_units'] ) ),
            'forecast_days' => $this->clampInt( $defaults['forecast_days'] ?? $settings['defaults']['forecast_days'], 0, self::MAX_FORECAST_DAYS ),
            'layout' => $this->normalizeLayout( (string) ( $defaults['layout'] ?? $settings['defaults']['layout'] ) ),
            'stale_hours' => $this->clampInt( $defaults['stale_hours'] ?? $settings['defaults']['stale_hours'], 1, 168 ),
            'details' => $this->normalizeDetails( is_array( $defaults['details'] ?? null ) ? $defaults['details'] : [] ),
        ];
        $settings['slots'] = $this->normalizeSlots( is_array( $input['slots'] ?? null ) ? $input['slots'] : [] );

        $locations = [];
        foreach ( is_array( $input['locations'] ?? null ) ? $input['locations'] : [] as $location ) {
            if ( ! is_array( $location ) ) {
                continue;
            }
            $normalized = $this->normalizeLocation( $location, $settings['defaults'] );
            if ( ! empty( $normalized ) ) {
                $locations[$normalized['key']] = $normalized;
            }
        }
        uasort(
            $locations,
            static fn( array $left, array $right ) : int => strcasecmp( (string) $left['label'], (string) $right['label'] )
        );
        $settings['locations'] = array_values( $locations );
        return( $settings );
    }

    public function normalizeCache( array $input ) : array {
        $feeds = [];
        foreach ( is_array( $input['feeds'] ?? null ) ? $input['feeds'] : [] as $feed ) {
            if ( ! is_array( $feed ) ) {
                continue;
            }
            $key = $this->feedCacheKey( (string) ( $feed['location_key'] ?? '' ), (string) ( $feed['provider'] ?? '' ) );
            if ( $key === '' ) {
                continue;
            }
            $feeds[$key] = [
                'location_key' => $this->normalizeKey( (string) ( $feed['location_key'] ?? '' ) ),
                'provider' => $this->normalizeProvider( (string) ( $feed['provider'] ?? '' ) ),
                'status' => in_array( (string) ( $feed['status'] ?? '' ), [ 'ok', 'error', 'disabled' ], true ) ? (string) $feed['status'] : 'error',
                'message' => mb_substr( mb_trim( (string) ( $feed['message'] ?? '' ) ), 0, 240 ),
                'checked_at_utc' => mb_substr( mb_trim( (string) ( $feed['checked_at_utc'] ?? '' ) ), 0, 40 ),
                'last_success_at_utc' => mb_substr( mb_trim( (string) ( $feed['last_success_at_utc'] ?? '' ) ), 0, 40 ),
                'attribution_label' => mb_substr( mb_trim( (string) ( $feed['attribution_label'] ?? 'Open-Meteo' ) ), 0, 80 ),
                'attribution_url' => $this->normalizeAttributionUrl( (string) ( $feed['attribution_url'] ?? 'https://open-meteo.com/' ) ),
                'current' => is_array( $feed['current'] ?? null ) ? $feed['current'] : [],
                'daily' => is_array( $feed['daily'] ?? null ) ? array_values( $feed['daily'] ) : [],
            ];
        }
        return(
            [
                'refreshed_at_utc' => mb_substr( mb_trim( (string) ( $input['refreshed_at_utc'] ?? '' ) ), 0, 40 ),
                'feeds' => $feeds,
            ]
        );
    }

    public function getLocationMap( ?array $settings = null ) : array {
        $settings = $settings ?? $this->getSettings();
        $map = [];
        foreach ( $settings['locations'] as $location ) {
            $map[(string) $location['key']] = $location;
        }
        return( $map );
    }

    public function refreshLocations( ?array $only_location_keys = null ) : array {
        $settings = $this->getSettings();
        $cache = $this->getCache();
        $limited_keys = [];
        foreach ( (array) $only_location_keys as $key ) {
            $key = $this->normalizeKey( (string) $key );
            if ( $key !== '' ) {
                $limited_keys[$key] = true;
            }
        }
        $limited = ! empty( $limited_keys );
        $next_feeds = $cache['feeds'];
        $refreshed = 0;
        $failed = 0;
        $disabled = 0;
        $failure_messages = [];

        foreach ( $settings['locations'] as $location ) {
            $location_key = (string) $location['key'];
            if ( $limited && ! isset( $limited_keys[$location_key] ) ) {
                continue;
            }
            foreach ( $location['providers'] as $provider => $provider_settings ) {
                $feed_key = $this->feedCacheKey( $location_key, (string) $provider );
                if ( empty( $location['enabled'] ) || empty( $provider_settings['enabled'] ) ) {
                    $disabled++;
                    $next_feeds[$feed_key] = $this->buildFeedStatus( $location, (string) $provider, 'disabled', 'Location or provider is disabled.', (array) ( $next_feeds[$feed_key] ?? [] ) );
                    continue;
                }
                try {
                    $feed = match ( (string) $provider ) {
                        self::PROVIDER_OPEN_METEO => $this->fetchOpenMeteoFeed( $location ),
                        self::PROVIDER_MET_NORWAY => $this->fetchMetNorwayFeed( $location ),
                        self::PROVIDER_NWS => $this->fetchNwsFeed( $location ),
                        default => throw new \RuntimeException( 'provider_unsupported' ),
                    };
                    $next_feeds[$feed_key] = $feed;
                    $refreshed++;
                } catch ( \Throwable $e ) {
                    $failed++;
                    $message = $this->publicRefreshErrorMessage( $e->getMessage() );
                    $next_feeds[$feed_key] = $this->buildFeedStatus(
                        $location,
                        (string) $provider,
                        'error',
                        $message,
                        (array) ( $next_feeds[$feed_key] ?? [] )
                    );
                    $failure_messages[] = $this->formatRefreshFailureMessage( $location, (string) $provider, $message );
                }
            }
        }

        $cache = [
            'refreshed_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'feeds' => $next_feeds,
        ];
        if ( ! TinyMashLockedJsonFile::write( $this->cache_filename, $this->normalizeCache( $cache ) ) ) {
            throw new \RuntimeException( 'cache_write' );
        }

        return(
            [
                'refreshed' => $refreshed,
                'failed' => $failed,
                'disabled' => $disabled,
                'feed_count' => count( $next_feeds ),
                'failures' => $failure_messages,
                'message' => $this->formatRefreshSummary( $refreshed, $failed, $failure_messages ),
            ]
        );
    }

    public function renderWeatherHtml( array $attributes = [], ?\DateTimeImmutable $now = null ) : string {
        $settings = $this->getSettings();
        $locations = $this->getLocationMap( $settings );
        $location_key = $this->normalizeKey( (string) ( $attributes['location'] ?? $attributes['service'] ?? '' ) );
        if ( $location_key === '' && count( $locations ) === 1 ) {
            $location_key = (string) array_key_first( $locations );
        }
        if ( $location_key === '' || empty( $locations[$location_key] ) ) {
            return( $this->renderUnavailableHtml( 'Weather location is not configured.' ) );
        }
        $location = $locations[$location_key];
        $provider = $this->normalizeProvider( (string) ( $attributes['provider'] ?? $location['default_provider'] ?? self::PROVIDER_OPEN_METEO ) );
        if ( empty( $location['providers'][$provider]['enabled'] ) ) {
            return( $this->renderUnavailableHtml( 'Weather provider is not enabled for this location.' ) );
        }
        $cache = $this->getCache();
        $feed = $cache['feeds'][$this->feedCacheKey( $location_key, $provider )] ?? null;
        if ( ! is_array( $feed ) || empty( $feed['current'] ) && empty( $feed['daily'] ) ) {
            return( $this->renderUnavailableHtml( 'Weather data has not been refreshed yet.' ) );
        }

        $now = $now ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
        $units = $this->normalizeUnits( (string) ( $attributes['units'] ?? $location['units'] ?? $settings['defaults']['units'] ) );
        $wind_units = $this->normalizeWindUnits( (string) ( $attributes['wind_units'] ?? $attributes['wind'] ?? $settings['defaults']['wind_units'] ?? 'ms' ) );
        $layout = $this->normalizeLayout( (string) ( $attributes['layout'] ?? $settings['defaults']['layout'] ) );
        $details = $this->resolveDetails( is_array( $settings['defaults']['details'] ?? null ) ? $settings['defaults']['details'] : [], $attributes );
        $show_current = $this->normalizeBoolean( $attributes['current'] ?? true );
        $forecast_days = $this->clampInt( $attributes['forecast'] ?? $settings['defaults']['forecast_days'], 0, self::MAX_FORECAST_DAYS );
        if ( ! $show_current && $forecast_days === 0 ) {
            $show_current = true;
        }
        $show_attribution = $this->normalizeBoolean( $attributes['attribution'] ?? true );
        $stale = $this->isFeedStale( $feed, (int) $settings['defaults']['stale_hours'], $now );
        $status = (string) ( $feed['status'] ?? '' );

        $admin_preview = $this->normalizeBoolean( $attributes['admin_preview'] ?? false );
        $slot = $this->normalizeSlotName( (string) ( $attributes['slot'] ?? '' ) );
        $classes = 'tm-weather tm-weather-' . $layout .
                   ( $slot !== '' ? ' tm-weather-slot tm-weather-slot-' . $slot : '' ) .
                   ( $stale ? ' tm-weather-stale' : '' ) .
                   ( $admin_preview ? ' tm-weather-admin-preview' : '' );
        $html = '<div class="' . $this->escape( $classes ) . '" data-weather-location="' . $this->escape( $location_key ) . '" data-weather-provider="' . $this->escape( $provider ) . '">';
        if ( ! empty( $details['location'] ) || $stale || $status === 'error' ) {
            $html .= '<div class="tm-weather-header">';
            if ( $admin_preview && ! empty( $details['location'] ) ) {
                $html .= '<div><div class="tm-weather-kicker">Weather</div>';
                $html .= '<h2 class="tm-weather-title">' . $this->escape( (string) $location['label'] ) . '</h2>' .
                         '<small class="tm-weather-admin-header-date">' .
                         $this->escape( $this->formatAdminHeaderDate( (string) ( $feed['current']['time'] ?? '' ), (string) ( $location['timezone'] ?? '' ) ) ) .
                         '</small></div>';
            } elseif ( ! empty( $details['location'] ) ) {
                $html .= '<div><div class="tm-weather-kicker">Weather</div>' .
                         '<h2 class="tm-weather-title">' . $this->escape( (string) $location['label'] ) . '<br/>' .
                         '<small class="tm-weather-admin-header-date">' .
                         $this->escape( $this->formatAdminHeaderDate( (string) ( $feed['current']['time'] ?? '' ), (string) ( $location['timezone'] ?? '' ) ) ) .
                         '</small>' .
                         '</h2></div>';
                // $html .= '<div><div class="tm-weather-kicker">Weather</div><h2 class="tm-weather-title">' . $this->escape( (string) $location['label'] ) . '</h2></div>';
            }
            if ( $stale || $status === 'error' ) {
                $html .= '<span class="tm-weather-status">' . $this->escape( $stale ? 'Stale' : 'Cached' ) . '</span>';
            }
            $html .= '</div>';
        }
        if ( $admin_preview ) {
            $html .= $this->renderAdminPreviewHtml( $feed, $units, $wind_units, $show_current, $forecast_days, (string) ( $location['timezone'] ?? '' ) );
            $html .= '</div>';
            return( $html );
        }
        if ( $show_current ) {
            $html .= $this->renderCurrentHtml( (array) $feed['current'], $units, $wind_units, $details, (string) ( $location['timezone'] ?? '' ) );
        }
        if ( $forecast_days > 0 ) {
            $current_time = $show_current ? (string) ( $feed['current']['time'] ?? '' ) : '';
            $html .=
                $this->renderForecastHtml(
                    $this->getForecastDaysForRender( (array) $feed['daily'], $forecast_days, $current_time ),
                    $units,
                    substr( $current_time, 0, 10 ),
                    $layout
                );
        }
        if ( $show_attribution ) {
            $label = (string) ( $feed['attribution_label'] ?? 'Open-Meteo' );
            $url = (string) ( $feed['attribution_url'] ?? 'https://open-meteo.com/' );
            $html .= '<div class="tm-weather-attribution">Source: <a href="' . $this->escape( $url ) . '" rel="nofollow noopener" target="_blank">' . $this->escape( $label ) . '</a></div>';
        }
        $html .= '</div>';
        return( $html );
    }

    public function renderSlotHtml( string $slot ) : string {
        $slot = $this->normalizeSlotName( $slot );
        if ( $slot === '' ) {
            return( '' );
        }
        $settings = $this->getSettings();
        $slot_settings = is_array( $settings['slots'][$slot] ?? null ) ? $settings['slots'][$slot] : $this->getDefaultSettings()['slots'][$slot];
        if ( empty( $slot_settings['enabled'] ) ) {
            return( '' );
        }

        $location_key = (string) ( $slot_settings['location'] ?? '' );
        if ( $location_key === '' ) {
            foreach ( $settings['locations'] as $location ) {
                if ( ! empty( $location['enabled'] ) ) {
                    $location_key = (string) ( $location['key'] ?? '' );
                    break;
                }
            }
        }
        if ( $location_key === '' ) {
            return( '' );
        }

        return( $this->renderWeatherHtml(
            [
                'location' => $location_key,
                'provider' => (string) ( $slot_settings['provider'] ?? self::PROVIDER_OPEN_METEO ),
                'forecast' => (int) ( $slot_settings['forecast_days'] ?? 0 ),
                'layout' => (string) ( $slot_settings['layout'] ?? 'compact' ),
                'attribution' => ! empty( $slot_settings['attribution'] ) ? 'true' : 'false',
                'slot' => $slot,
            ]
        ) );
    }

    protected function getForecastDaysForRender( array $daily, int $forecast_days, string $current_time = '' ) : array {
        if ( $forecast_days <= 0 ) {
            return( [] );
        }

        $current_date = substr( $current_time, 0, 10 );
        $days = [];
        foreach ( $daily as $day ) {
            if ( ! is_array( $day ) ) {
                continue;
            }
            $date = (string) ( $day['date'] ?? '' );
            if ( $current_date !== '' && $date === $current_date ) {
                continue;
            }
            $days[] = $day;
            if ( count( $days ) >= $forecast_days ) {
                break;
            }
        }

        return( $days );
    }

    protected function renderAdminPreviewHtml( array $feed, string $units, string $wind_units, bool $show_current, int $forecast_days, string $timezone ) : string {
        $current = is_array( $feed['current'] ?? null ) ? $feed['current'] : [];
        $current_date = substr( (string) ( $current['time'] ?? '' ), 0, 10 );
        $html = '<div class="tm-weather-admin-preview-body">';
        if ( $show_current && ! empty( $current ) ) {
            $html .= '<div class="text-center tm-weather-admin-current">';/**/
            $icon = $this->weatherIconUrl( (int) ( $current['weather_code'] ?? -1 ), ! empty( $current['is_day'] ) );
            $html .= '<div>';
            $html .= '<img class="tm-weather-admin-icon" src="' . $this->escape( $icon ) . '" alt="" aria-hidden="true">';
            $html .= '</div>';
            $html .= '<div class="text-start">';
            $html .= '<div class="tm-weather-admin-copy">';
            $html .= '<div class="tm-weather-admin-date">' . $this->escape( $this->formatCurrentPreviewLabel( (string) ( $current['time'] ?? '' ), $timezone ) ) . '</div>';
            $html .= '<div class="tm-weather-admin-summary">' . $this->escape( (string) ( $current['summary'] ?? 'Weather' ) ) . '</div>';
            $html .= '<div class="tm-weather-admin-temp">' . $this->escape( $this->formatTemperatureCompact( $current['temperature_c'] ?? null, $units ) ) . '</div>';
            $html .= '<div class="tm-weather-admin-detail">Wind ' . $this->escape( $this->formatWind( $current['wind_speed_kmh'] ?? null, $wind_units ) ) . '</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $days = [];
        foreach ( is_array( $feed['daily'] ?? null ) ? $feed['daily'] : [] as $day ) {
            if ( ! is_array( $day ) ) {
                continue;
            }
            $date = (string) ( $day['date'] ?? '' );
            if ( $current_date !== '' && $date === $current_date ) {
                continue;
            }
            $days[] = $day;
            if ( count( $days ) >= $forecast_days ) {
                break;
            }
        }

        foreach ( $days as $day ) {
            $html .= '<div class="text-center tm-weather-admin-day">';/**/
            $date = (string) ( $day['date'] ?? '' );
            $icon = $this->weatherIconUrl( (int) ( $day['weather_code'] ?? -1 ), true );
            $html .= '<div>';
            $html .= '<img class="tm-weather-admin-day-icon" src="' . $this->escape( $icon ) . '" alt="" aria-hidden="true">';
            $html .= '</div>';
            $html .= '<div class="text-start">';
            $html .= '<div class="tm-weather-admin-copy">';
            $html .= '<div class="tm-weather-admin-date">' . $this->escape( $this->formatPreviewDayLabel( $date, $current_date ) ) . '</div>';
            $html .= '<div class="tm-weather-admin-summary">' . $this->escape( (string) ( $day['summary'] ?? 'Weather' ) ) . ', ' . $this->escape( $this->formatPrecipitation( $day['precipitation_mm'] ?? null, $units ) ) . '</div>';
            $html .= '<div class="tm-weather-admin-detail">High ' . $this->escape( $this->formatTemperatureCompact( $day['temperature_max_c'] ?? null, $units ) ) . ', low ' . $this->escape( $this->formatTemperatureCompact( $day['temperature_min_c'] ?? null, $units ) ) . '</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return( $html );
    }

    public function buildSettingsFromPost( array $data ) : array {
        $raw_details = is_array( $data['details'] ?? null ) ? $data['details'] : [];
        $details = [];
        foreach ( array_keys( $this->getDefaultSettings()['defaults']['details'] ) as $detail_key ) {
            $details[$detail_key] = ! empty( $raw_details[$detail_key] );
        }
        $defaults = [
            'units' => (string) ( $data['default_units'] ?? 'metric' ),
            'wind_units' => (string) ( $data['default_wind_units'] ?? 'ms' ),
            'forecast_days' => (int) ( $data['default_forecast_days'] ?? 3 ),
            'layout' => (string) ( $data['default_layout'] ?? 'compact' ),
            'stale_hours' => (int) ( $data['stale_hours'] ?? 6 ),
            'details' => $details,
        ];
        $slots = $this->buildSlotsFromPost( is_array( $data['slots'] ?? null ) ? $data['slots'] : [] );
        $locations = [];
        foreach ( is_array( $data['locations'] ?? null ) ? $data['locations'] : [] as $location ) {
            if ( ! is_array( $location ) ) {
                continue;
            }
            $locations[] = [
                'key' => (string) ( $location['key'] ?? '' ),
                'label' => (string) ( $location['label'] ?? '' ),
                'latitude' => (string) ( $location['latitude'] ?? '' ),
                'longitude' => (string) ( $location['longitude'] ?? '' ),
                'timezone' => (string) ( $location['timezone'] ?? '' ),
                'units' => (string) ( $location['units'] ?? '' ),
                'enabled' => ! empty( $location['enabled'] ),
                'default_provider' => (string) ( $location['default_provider'] ?? self::PROVIDER_OPEN_METEO ),
                'providers' => [
                    self::PROVIDER_OPEN_METEO => [ 'enabled' => ! empty( $location['providers'][self::PROVIDER_OPEN_METEO]['enabled'] ) ],
                    self::PROVIDER_MET_NORWAY => [ 'enabled' => ! empty( $location['providers'][self::PROVIDER_MET_NORWAY]['enabled'] ) ],
                    self::PROVIDER_NWS => [ 'enabled' => ! empty( $location['providers'][self::PROVIDER_NWS]['enabled'] ) ],
                ],
            ];
        }
        return( [ 'defaults' => $defaults, 'slots' => $slots, 'locations' => $locations ] );
    }

    public function buildSettingsFromPostResult( array $data ) : array {
        $settings = $this->buildSettingsFromPost( $data );
        $errors = [];
        $seen_keys = [];
        foreach ( is_array( $data['locations'] ?? null ) ? $data['locations'] : [] as $index => $location ) {
            if ( ! is_array( $location ) ) {
                continue;
            }
            $raw_label = mb_trim( (string) ( $location['label'] ?? '' ) );
            $raw_key = mb_trim( (string) ( $location['key'] ?? '' ) );
            $raw_latitude = mb_trim( (string) ( $location['latitude'] ?? '' ) );
            $raw_longitude = mb_trim( (string) ( $location['longitude'] ?? '' ) );
            $has_data = $raw_label !== '' || $raw_key !== '' || $raw_latitude !== '' || $raw_longitude !== '';
            if ( ! $has_data ) {
                continue;
            }
            $row_label = $raw_label !== '' ? $raw_label : 'Location ' . ( (int) $index + 1 );
            $normalized_key = $this->normalizeKey( $raw_key !== '' ? $raw_key : $raw_label );
            if ( $raw_label === '' ) {
                $errors[] = $row_label . ' needs a label.';
            }
            if ( $normalized_key === '' ) {
                $errors[] = $row_label . ' needs a valid key or label.';
            } elseif ( isset( $seen_keys[$normalized_key] ) ) {
                $errors[] = $row_label . ' uses a duplicate key.';
            }
            $seen_keys[$normalized_key] = true;
            if ( $this->normalizeCoordinate( $raw_latitude, -90.0, 90.0 ) === null ) {
                $errors[] = $row_label . ' needs a valid latitude between -90 and 90.';
            }
            if ( $this->normalizeCoordinate( $raw_longitude, -180.0, 180.0 ) === null ) {
                $errors[] = $row_label . ' needs a valid longitude between -180 and 180.';
            }
        }
        return( [ 'settings' => $settings, 'errors' => $errors ] );
    }

    public function getProviderDefinitions() : array {
        return(
            [
                self::PROVIDER_OPEN_METEO => [
                    'key' => self::PROVIDER_OPEN_METEO,
                    'label' => 'Open-Meteo',
                    'attribution_label' => 'Open-Meteo',
                    'attribution_url' => 'https://open-meteo.com/',
                    'notes' => 'Global provider. Free API use is documented for non-commercial deployments with attribution and rate limits.',
                ],
                self::PROVIDER_MET_NORWAY => [
                    'key' => self::PROVIDER_MET_NORWAY,
                    'label' => 'MET Norway / Yr',
                    'attribution_label' => 'MET Norway',
                    'attribution_url' => 'https://api.met.no/',
                    'notes' => 'Open data provider with global Locationforecast coverage. Requires attribution and an identifying User-Agent.',
                ],
                self::PROVIDER_NWS => [
                    'key' => self::PROVIDER_NWS,
                    'label' => 'National Weather Service',
                    'attribution_label' => 'NOAA/NWS',
                    'attribution_url' => 'https://www.weather.gov/',
                    'notes' => 'US-focused open government weather provider. The points API only resolves supported US locations.',
                ],
            ]
        );
    }

    public function normalizeOpenMeteoResponse( array $payload, array $location ) : array {
        $current = is_array( $payload['current'] ?? null ) ? $payload['current'] : [];
        $daily = is_array( $payload['daily'] ?? null ) ? $payload['daily'] : [];
        $current_code = (int) ( $current['weather_code'] ?? -1 );
        $feed = [
            'location_key' => (string) $location['key'],
            'provider' => self::PROVIDER_OPEN_METEO,
            'status' => 'ok',
            'message' => 'Weather refreshed.',
            'checked_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'last_success_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'attribution_label' => 'Open-Meteo',
            'attribution_url' => 'https://open-meteo.com/',
            'current' => [
                'time' => mb_substr( mb_trim( (string) ( $current['time'] ?? '' ) ), 0, 40 ),
                'temperature_c' => $this->roundFloat( $current['temperature_2m'] ?? null, 1 ),
                'apparent_temperature_c' => $this->roundFloat( $current['apparent_temperature'] ?? null, 1 ),
                'humidity_percent' => $this->roundFloat( $current['relative_humidity_2m'] ?? null, 0 ),
                'wind_speed_kmh' => $this->roundFloat( $current['wind_speed_10m'] ?? null, 1 ),
                'weather_code' => $current_code,
                'summary' => $this->describeWeatherCode( $current_code ),
                'is_day' => ! empty( $current['is_day'] ),
            ],
            'daily' => [],
        ];

        $times = is_array( $daily['time'] ?? null ) ? $daily['time'] : [];
        foreach ( $times as $index => $date ) {
            if ( count( $feed['daily'] ) >= self::MAX_FORECAST_DAYS ) {
                break;
            }
            $code = (int) ( $daily['weather_code'][$index] ?? -1 );
            $feed['daily'][] = [
                'date' => mb_substr( mb_trim( (string) $date ), 0, 20 ),
                'weather_code' => $code,
                'summary' => $this->describeWeatherCode( $code ),
                'temperature_max_c' => $this->roundFloat( $daily['temperature_2m_max'][$index] ?? null, 1 ),
                'temperature_min_c' => $this->roundFloat( $daily['temperature_2m_min'][$index] ?? null, 1 ),
                'precipitation_mm' => $this->roundFloat( $daily['precipitation_sum'][$index] ?? null, 1 ),
                'precipitation_probability_percent' => $this->roundFloat( $daily['precipitation_probability_max'][$index] ?? null, 0 ),
                'wind_speed_max_kmh' => $this->roundFloat( $daily['wind_speed_10m_max'][$index] ?? null, 1 ),
            ];
        }
        if ( empty( $feed['current']['time'] ) && empty( $feed['daily'] ) ) {
            throw new \RuntimeException( 'parse' );
        }
        return( $feed );
    }

    public function normalizeMetNorwayResponse( array $payload, array $location ) : array {
        $timeseries = is_array( $payload['properties']['timeseries'] ?? null ) ? $payload['properties']['timeseries'] : [];
        $current_entry = is_array( $timeseries[0] ?? null ) ? $timeseries[0] : [];
        $current_details = is_array( $current_entry['data']['instant']['details'] ?? null ) ? $current_entry['data']['instant']['details'] : [];
        $current_symbol = $this->metNorwaySymbolForEntry( $current_entry );
        $current_code = $this->weatherCodeFromMetNorwaySymbol( $current_symbol );
        $timezone = (string) ( $location['timezone'] ?? $this->default_timezone );
        $feed = [
            'location_key' => (string) $location['key'],
            'provider' => self::PROVIDER_MET_NORWAY,
            'status' => 'ok',
            'message' => 'Weather refreshed.',
            'checked_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'last_success_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'attribution_label' => 'MET Norway',
            'attribution_url' => 'https://api.met.no/',
            'current' => [
                'time' => mb_substr( mb_trim( (string) ( $current_entry['time'] ?? '' ) ), 0, 40 ),
                'temperature_c' => $this->roundFloat( $current_details['air_temperature'] ?? null, 1 ),
                'apparent_temperature_c' => $this->roundFloat( $current_details['air_temperature'] ?? null, 1 ),
                'humidity_percent' => $this->roundFloat( $current_details['relative_humidity'] ?? null, 0 ),
                'wind_speed_kmh' => $this->roundFloat( is_numeric( $current_details['wind_speed'] ?? null ) ? (float) $current_details['wind_speed'] * 3.6 : null, 1 ),
                'weather_code' => $current_code,
                'summary' => $this->describeMetNorwaySymbol( $current_symbol, $current_code ),
                'is_day' => ! str_contains( $current_symbol, '_night' ),
            ],
            'daily' => [],
        ];

        $daily = [];
        foreach ( $timeseries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $entry_time = $this->dateTimeFromProviderTime( (string) ( $entry['time'] ?? '' ), $timezone );
            if ( $entry_time === null ) {
                continue;
            }
            $date = $entry_time->format( 'Y-m-d' );
            $details = is_array( $entry['data']['instant']['details'] ?? null ) ? $entry['data']['instant']['details'] : [];
            if ( ! isset( $daily[$date] ) ) {
                $daily[$date] = [
                    'date' => $date,
                    'weather_code' => 0,
                    'summary' => '',
                    'temperature_max_c' => null,
                    'temperature_min_c' => null,
                    'precipitation_mm' => 0.0,
                    'precipitation_probability_percent' => null,
                    'wind_speed_max_kmh' => null,
                    '_symbol' => '',
                    '_symbol_count' => [],
                ];
            }
            if ( is_numeric( $details['air_temperature'] ?? null ) ) {
                $temperature = (float) $details['air_temperature'];
                $daily[$date]['temperature_max_c'] = $daily[$date]['temperature_max_c'] === null ? $temperature : max( (float) $daily[$date]['temperature_max_c'], $temperature );
                $daily[$date]['temperature_min_c'] = $daily[$date]['temperature_min_c'] === null ? $temperature : min( (float) $daily[$date]['temperature_min_c'], $temperature );
            }
            if ( is_numeric( $details['wind_speed'] ?? null ) ) {
                $wind = (float) $details['wind_speed'] * 3.6;
                $daily[$date]['wind_speed_max_kmh'] = $daily[$date]['wind_speed_max_kmh'] === null ? $wind : max( (float) $daily[$date]['wind_speed_max_kmh'], $wind );
            }
            $precipitation = $this->metNorwayPrecipitationForEntry( $entry );
            if ( $precipitation !== null ) {
                $daily[$date]['precipitation_mm'] += $precipitation;
            }
            $symbol = $this->metNorwaySymbolForEntry( $entry );
            if ( $symbol !== '' ) {
                $base_symbol = $this->normalizeMetNorwaySymbol( $symbol );
                $daily[$date]['_symbol_count'][$base_symbol] = (int) ( $daily[$date]['_symbol_count'][$base_symbol] ?? 0 ) + 1;
                arsort( $daily[$date]['_symbol_count'] );
                $daily[$date]['_symbol'] = (string) array_key_first( $daily[$date]['_symbol_count'] );
            }
        }
        foreach ( array_values( $daily ) as $day ) {
            if ( count( $feed['daily'] ) >= self::MAX_FORECAST_DAYS ) {
                break;
            }
            $symbol = (string) ( $day['_symbol'] ?? '' );
            $code = $this->weatherCodeFromMetNorwaySymbol( $symbol );
            unset( $day['_symbol'], $day['_symbol_count'] );
            $day['weather_code'] = $code;
            $day['summary'] = $this->describeMetNorwaySymbol( $symbol, $code );
            $day['temperature_max_c'] = $this->roundFloat( $day['temperature_max_c'], 1 );
            $day['temperature_min_c'] = $this->roundFloat( $day['temperature_min_c'], 1 );
            $day['precipitation_mm'] = $this->roundFloat( $day['precipitation_mm'], 1 );
            $day['wind_speed_max_kmh'] = $this->roundFloat( $day['wind_speed_max_kmh'], 1 );
            $feed['daily'][] = $day;
        }
        if ( empty( $feed['current']['time'] ) && empty( $feed['daily'] ) ) {
            throw new \RuntimeException( 'parse' );
        }
        return( $feed );
    }

    public function normalizeNwsResponse( array $point_payload, array $hourly_payload, array $forecast_payload, array $location ) : array {
        $hourly_periods = is_array( $hourly_payload['properties']['periods'] ?? null ) ? $hourly_payload['properties']['periods'] : [];
        $forecast_periods = is_array( $forecast_payload['properties']['periods'] ?? null ) ? $forecast_payload['properties']['periods'] : [];
        $current_period = is_array( $hourly_periods[0] ?? null ) ? $hourly_periods[0] : [];
        $current_summary = (string) ( $current_period['shortForecast'] ?? $current_period['name'] ?? 'Weather' );
        $current_code = $this->weatherCodeFromSummary( $current_summary );
        $feed = [
            'location_key' => (string) $location['key'],
            'provider' => self::PROVIDER_NWS,
            'status' => 'ok',
            'message' => 'Weather refreshed.',
            'checked_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'last_success_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'attribution_label' => 'NOAA/NWS',
            'attribution_url' => 'https://www.weather.gov/',
            'current' => [
                'time' => mb_substr( mb_trim( (string) ( $current_period['startTime'] ?? '' ) ), 0, 40 ),
                'temperature_c' => $this->roundFloat( $this->nwsTemperatureToCelsius( $current_period['temperature'] ?? null, (string) ( $current_period['temperatureUnit'] ?? 'F' ) ), 1 ),
                'apparent_temperature_c' => $this->roundFloat( $this->nwsTemperatureToCelsius( $current_period['temperature'] ?? null, (string) ( $current_period['temperatureUnit'] ?? 'F' ) ), 1 ),
                'humidity_percent' => $this->roundFloat( $current_period['relativeHumidity']['value'] ?? null, 0 ),
                'wind_speed_kmh' => $this->roundFloat( $this->parseNwsWindSpeedKmh( (string) ( $current_period['windSpeed'] ?? '' ) ), 1 ),
                'weather_code' => $current_code,
                'summary' => $this->describeWeatherCode( $current_code ),
                'is_day' => array_key_exists( 'isDaytime', $current_period ) ? ! empty( $current_period['isDaytime'] ) : true,
            ],
            'daily' => [],
        ];

        $timezone = (string) ( $forecast_payload['properties']['timeZone'] ?? $point_payload['properties']['timeZone'] ?? $location['timezone'] ?? $this->default_timezone );
        $daily = [];
        foreach ( $forecast_periods as $period ) {
            if ( ! is_array( $period ) ) {
                continue;
            }
            $start = $this->dateTimeFromProviderTime( (string) ( $period['startTime'] ?? '' ), $timezone );
            if ( $start === null ) {
                continue;
            }
            $date = $start->format( 'Y-m-d' );
            if ( ! isset( $daily[$date] ) ) {
                $daily[$date] = [
                    'date' => $date,
                    'weather_code' => 0,
                    'summary' => '',
                    'temperature_max_c' => null,
                    'temperature_min_c' => null,
                    'precipitation_mm' => null,
                    'precipitation_probability_percent' => null,
                    'wind_speed_max_kmh' => null,
                    '_summary' => '',
                ];
            }
            $temperature = $this->nwsTemperatureToCelsius( $period['temperature'] ?? null, (string) ( $period['temperatureUnit'] ?? 'F' ) );
            if ( $temperature !== null ) {
                if ( ! empty( $period['isDaytime'] ) ) {
                    $daily[$date]['temperature_max_c'] = $daily[$date]['temperature_max_c'] === null ? $temperature : max( (float) $daily[$date]['temperature_max_c'], $temperature );
                } else {
                    $daily[$date]['temperature_min_c'] = $daily[$date]['temperature_min_c'] === null ? $temperature : min( (float) $daily[$date]['temperature_min_c'], $temperature );
                }
            }
            $probability = $period['probabilityOfPrecipitation']['value'] ?? null;
            if ( is_numeric( $probability ) ) {
                $daily[$date]['precipitation_probability_percent'] = $daily[$date]['precipitation_probability_percent'] === null ? (float) $probability : max( (float) $daily[$date]['precipitation_probability_percent'], (float) $probability );
            }
            $wind = $this->parseNwsWindSpeedKmh( (string) ( $period['windSpeed'] ?? '' ) );
            if ( $wind !== null ) {
                $daily[$date]['wind_speed_max_kmh'] = $daily[$date]['wind_speed_max_kmh'] === null ? $wind : max( (float) $daily[$date]['wind_speed_max_kmh'], $wind );
            }
            if ( $daily[$date]['_summary'] === '' || ! empty( $period['isDaytime'] ) ) {
                $daily[$date]['_summary'] = (string) ( $period['shortForecast'] ?? '' );
            }
        }
        foreach ( array_values( $daily ) as $day ) {
            if ( count( $feed['daily'] ) >= self::MAX_FORECAST_DAYS ) {
                break;
            }
            $summary = (string) ( $day['_summary'] ?? '' );
            $code = $this->weatherCodeFromSummary( $summary );
            unset( $day['_summary'] );
            $day['weather_code'] = $code;
            $day['summary'] = $this->describeWeatherCode( $code );
            $day['temperature_max_c'] = $this->roundFloat( $day['temperature_max_c'], 1 );
            $day['temperature_min_c'] = $this->roundFloat( $day['temperature_min_c'], 1 );
            $day['precipitation_probability_percent'] = $this->roundFloat( $day['precipitation_probability_percent'], 0 );
            $day['wind_speed_max_kmh'] = $this->roundFloat( $day['wind_speed_max_kmh'], 1 );
            $feed['daily'][] = $day;
        }
        if ( empty( $feed['current']['time'] ) && empty( $feed['daily'] ) ) {
            throw new \RuntimeException( 'parse' );
        }
        return( $feed );
    }

    protected function normalizeLocation( array $location, array $defaults ) : array {
        $label = mb_substr( mb_trim( (string) ( $location['label'] ?? '' ) ), 0, 100 );
        $key = $this->normalizeKey( (string) ( $location['key'] ?? '' ) );
        if ( $key === '' && $label !== '' ) {
            $key = $this->normalizeKey( $label );
        }
        if ( $key === '' || $label === '' ) {
            return( [] );
        }
        $latitude = $this->normalizeCoordinate( $location['latitude'] ?? null, -90.0, 90.0 );
        $longitude = $this->normalizeCoordinate( $location['longitude'] ?? null, -180.0, 180.0 );
        if ( $latitude === null || $longitude === null ) {
            return( [] );
        }
        $timezone = (string) ( $location['timezone'] ?? '' );
        if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
            $timezone = $this->default_timezone;
        }
        $providers = is_array( $location['providers'] ?? null ) ? $location['providers'] : [];
        return(
            [
                'key' => $key,
                'label' => $label,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'timezone' => $timezone,
                'units' => $this->normalizeUnits( (string) ( $location['units'] ?? $defaults['units'] ?? 'metric' ) ),
                'enabled' => ! empty( $location['enabled'] ),
                'default_provider' => $this->normalizeProvider( (string) ( $location['default_provider'] ?? self::PROVIDER_OPEN_METEO ) ) ?: self::PROVIDER_OPEN_METEO,
                'providers' => [
                    self::PROVIDER_OPEN_METEO => [ 'enabled' => ! empty( $providers[self::PROVIDER_OPEN_METEO]['enabled'] ) || ! empty( $location['open_meteo_enabled'] ) ],
                    self::PROVIDER_MET_NORWAY => [ 'enabled' => ! empty( $providers[self::PROVIDER_MET_NORWAY]['enabled'] ) ],
                    self::PROVIDER_NWS => [ 'enabled' => ! empty( $providers[self::PROVIDER_NWS]['enabled'] ) ],
                ],
            ]
        );
    }

    protected function fetchOpenMeteoFeed( array $location ) : array {
        if ( ! function_exists( 'curl_init' ) ) {
            throw new \RuntimeException( 'curl_missing' );
        }
        $query = http_build_query(
            [
                'latitude' => number_format( (float) $location['latitude'], 5, '.', '' ),
                'longitude' => number_format( (float) $location['longitude'], 5, '.', '' ),
                'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,weather_code,wind_speed_10m',
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max',
                'timezone' => (string) $location['timezone'],
                'forecast_days' => self::MAX_FORECAST_DAYS,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $url = 'https://api.open-meteo.com/v1/forecast?' . $query;
        $body = '';
        $too_large = false;
        $curl = curl_init( $url );
        if ( $curl === false ) {
            throw new \RuntimeException( 'remote_fetch' );
        }
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'tinymash-weather/' . ( defined( 'APP_VERSION' ) ? APP_VERSION : 'dev' ),
                CURLOPT_HTTPHEADER => [ 'Accept: application/json' ],
                CURLOPT_WRITEFUNCTION => static function( mixed $handle, string $chunk ) use ( &$body, &$too_large ) : int {
                    if ( strlen( $body ) + strlen( $chunk ) > self::MAX_RESPONSE_BYTES ) {
                        $too_large = true;
                        return( 0 );
                    }
                    $body .= $chunk;
                    return( strlen( $chunk ) );
                },
            ]
        );
        $ok = curl_exec( $curl );
        $http_code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        curl_close( $curl );
        if ( $too_large ) {
            throw new \RuntimeException( 'response_size' );
        }
        if ( $ok === false || $http_code < 200 || $http_code >= 300 ) {
            throw new \RuntimeException( 'remote_fetch' );
        }
        $payload = json_decode( $body, true, 64 );
        if ( ! is_array( $payload ) ) {
            throw new \RuntimeException( 'parse' );
        }
        return( $this->normalizeOpenMeteoResponse( $payload, $location ) );
    }

    protected function fetchMetNorwayFeed( array $location ) : array {
        $query = http_build_query(
            [
                'lat' => number_format( (float) $location['latitude'], 4, '.', '' ),
                'lon' => number_format( (float) $location['longitude'], 4, '.', '' ),
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $payload = $this->fetchJsonUrl( 'https://api.met.no/weatherapi/locationforecast/2.0/compact?' . $query, [ 'Accept: application/json' ] );
        return( $this->normalizeMetNorwayResponse( $payload, $location ) );
    }

    protected function fetchNwsFeed( array $location ) : array {
        $point_url = 'https://api.weather.gov/points/' .
                     number_format( (float) $location['latitude'], 4, '.', '' ) . ',' .
                     number_format( (float) $location['longitude'], 4, '.', '' );
        try {
            $point_payload = $this->fetchJsonUrl( $point_url, [ 'Accept: application/geo+json, application/json' ] );
        } catch ( \RuntimeException $e ) {
            if ( in_array( $e->getMessage(), [ 'remote_fetch_http_400', 'remote_fetch_http_404' ], true ) ) {
                throw new \RuntimeException( 'nws_location_unsupported' );
            }
            throw $e;
        }
        $forecast_url = $this->normalizeHttpsUrl( (string) ( $point_payload['properties']['forecast'] ?? '' ) );
        $hourly_url = $this->normalizeHttpsUrl( (string) ( $point_payload['properties']['forecastHourly'] ?? '' ) );
        if ( $forecast_url === '' || $hourly_url === '' ) {
            throw new \RuntimeException( 'parse' );
        }
        $hourly_payload = $this->fetchJsonUrl( $hourly_url, [ 'Accept: application/geo+json, application/json' ] );
        $forecast_payload = $this->fetchJsonUrl( $forecast_url, [ 'Accept: application/geo+json, application/json' ] );
        return( $this->normalizeNwsResponse( $point_payload, $hourly_payload, $forecast_payload, $location ) );
    }

    protected function fetchJsonUrl( string $url, array $headers = [] ) : array {
        if ( ! function_exists( 'curl_init' ) ) {
            throw new \RuntimeException( 'curl_missing' );
        }
        $url = trim( $url );
        if ( ! str_starts_with( strtolower( $url ), 'https://' ) ) {
            throw new \RuntimeException( 'remote_fetch' );
        }
        $body = '';
        $too_large = false;
        $curl = curl_init( $url );
        if ( $curl === false ) {
            throw new \RuntimeException( 'remote_fetch' );
        }
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => $this->weatherUserAgent(),
                CURLOPT_HTTPHEADER => ! empty( $headers ) ? $headers : [ 'Accept: application/json' ],
                CURLOPT_WRITEFUNCTION => static function( mixed $handle, string $chunk ) use ( &$body, &$too_large ) : int {
                    if ( strlen( $body ) + strlen( $chunk ) > self::MAX_RESPONSE_BYTES ) {
                        $too_large = true;
                        return( 0 );
                    }
                    $body .= $chunk;
                    return( strlen( $chunk ) );
                },
            ]
        );
        $ok = curl_exec( $curl );
        $http_code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        curl_close( $curl );
        if ( $too_large ) {
            throw new \RuntimeException( 'response_size' );
        }
        if ( $ok === false || $http_code < 200 || $http_code >= 300 ) {
            throw new \RuntimeException( $http_code > 0 ? 'remote_fetch_http_' . $http_code : 'remote_fetch' );
        }
        $payload = json_decode( $body, true, 64 );
        if ( ! is_array( $payload ) ) {
            throw new \RuntimeException( 'parse' );
        }
        return( $payload );
    }

    protected function weatherUserAgent() : string {
        return( 'tinymash-weather/' . ( defined( 'APP_VERSION' ) ? APP_VERSION : 'dev' ) . ' (https://tinymash.joho.se)' );
    }

    protected function renderCurrentHtml( array $current, string $units, string $wind_units, array $details, string $timezone ) : string {
        $temperature = $this->formatTemperature( $current['temperature_c'] ?? null, $units );
        $apparent = $this->formatTemperature( $current['apparent_temperature_c'] ?? null, $units );
        $wind = $this->formatWind( $current['wind_speed_kmh'] ?? null, $wind_units );
        $summary = (string) ( $current['summary'] ?? 'Weather' );
        $date_label = $this->formatCurrentPublicLabel( (string) ( $current['time'] ?? '' ), $timezone );
        $icon = $this->weatherIconUrl( (int) ( $current['weather_code'] ?? -1 ), ! empty( $current['is_day'] ) );
        $html = '<div class="tm-weather-current">';
        $html .= '<div class="tm-weather-current-main">';
        if ( ! empty( $details['icon'] ) ) {
            $html .= '<img class="tm-weather-icon bg-dark-subtle border border-secondary border-2 rounded-3" src="' . $this->escape( $icon ) . '" alt="" aria-hidden="true">';
        }
        if ( ! empty( $details['temperature'] ) || ! empty( $details['condition'] ) || ! empty( $details['feels_like'] ) || ! empty( $details['humidity'] ) || ! empty( $details['wind'] ) ) {
            $html .= '<div class="tm-weather-current-body">';
            $html .= '<div class="tm-weather-current-date">' . $this->escape( $date_label ) . '</div>';
            if ( ! empty( $details['condition'] ) ) {
                $html .= '<div class="tm-weather-summary">' . $this->escape( $summary ) . '</div>';
            }
            if ( ! empty( $details['temperature'] ) ) {
                $html .= '<div class="tm-weather-temp">' . $this->escape( $temperature ) . '</div>';
            }
            if ( ! empty( $details['feels_like'] ) ) {
                $html .= '<div class="tm-weather-meta">Feels like ' . $this->escape( $apparent ) . '</div>';
            }
            if ( ! empty( $details['humidity'] ) || ! empty( $details['wind'] ) ) {
                $html .= '<dl class="tm-weather-current-details">';
                if ( ! empty( $details['wind'] ) ) {
                    $html .= '<div><dt>Wind</dt><dd>' . $this->escape( $wind ) . '</dd></div>';
                }
                if ( ! empty( $details['humidity'] ) ) {
                    $html .= '<div><dt>Humidity</dt><dd>' . $this->escape( $this->formatPercent( $current['humidity_percent'] ?? null ) ) . '</dd></div>';
                }
                $html .= '</dl>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';
        return( $html );
    }

    protected function renderForecastHtml( array $days, string $units, string $current_date = '', string $layout = 'compact' ) : string {
        if ( empty( $days ) ) {
            return( '' );
        }
        $html = '<ol class="tm-weather-forecast">';
        foreach ( $days as $day ) {
            $date = $this->formatPublicForecastDateLabel( (string) ( $day['date'] ?? '' ), $current_date );
            $icon = $this->weatherIconUrl( (int) ( $day['weather_code'] ?? -1 ), true );
            $summary = (string) ( $day['summary'] ?? 'Weather' );
            $high = $this->formatTemperature( $day['temperature_max_c'] ?? null, $units );
            $low = $this->formatTemperature( $day['temperature_min_c'] ?? null, $units );
            $rain = $this->formatPrecipitation( $day['precipitation_mm'] ?? null, $units );
            $html .= '<li><div class="tm-weather-forecast-row d-flex align-items-center gap-3">';
            $html .= '<img class="tm-weather-forecast-icon bg-dark-subtle border border-secondary border-2 rounded-3" src="' .
                     $this->escape( $icon ) . '" alt="" aria-hidden="true">';
            $html .= '<div class="tm-weather-forecast-body min-w-0">';
            $html .= '<div class="tm-weather-forecast-date">' . $this->escape( $date ) . '</div>';
            if ( $layout === 'horizontal' ) {
                $html .= '<div class="tm-weather-forecast-summary">' . $this->escape( $summary ) . ', ' . $this->escape( $rain ) . '</div>';
                $html .= '<div class="tm-weather-forecast-compact-values text-body-secondary">High ' . $this->escape( $high ) . ', low ' . $this->escape( $low ) . '</div>';
            } else {
                $html .= '<div class="tm-weather-forecast-summary">' . $this->escape( $summary ) . '</div>';
                $html .= '<dl class="tm-weather-forecast-values">';
                $html .= '<div><dt>High</dt><dd>' . $this->escape( $high ) . '</dd></div>';
                $html .= '<div><dt>Low</dt><dd>' . $this->escape( $low ) . '</dd></div>';
                $html .= '<div><dt>Rain</dt><dd>' . $this->escape( $rain ) . '</dd></div>';
                $html .= '</dl>';
            }
            $html .= '</div></div></li>';
        }
        $html .= '</ol>';
        return( $html );
    }

    protected function renderUnavailableHtml( string $message ) : string {
        return( '<div class="tm-weather tm-weather-unavailable"><div class="tm-weather-kicker">Weather</div><p class="mb-0">' . $this->escape( $message ) . '</p></div>' );
    }

    protected function buildFeedStatus( array $location, string $provider, string $status, string $message, array $previous = [] ) : array {
        $provider_definition = $this->getProviderDefinitions()[$this->normalizeProvider( $provider )] ?? $this->getProviderDefinitions()[self::PROVIDER_OPEN_METEO];
        return(
            [
                'location_key' => (string) $location['key'],
                'provider' => $provider,
                'status' => $status,
                'message' => $message,
                'checked_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'last_success_at_utc' => (string) ( $previous['last_success_at_utc'] ?? '' ),
                'attribution_label' => (string) ( $previous['attribution_label'] ?? $provider_definition['attribution_label'] ?? 'Open-Meteo' ),
                'attribution_url' => (string) ( $previous['attribution_url'] ?? $provider_definition['attribution_url'] ?? 'https://open-meteo.com/' ),
                'current' => is_array( $previous['current'] ?? null ) ? $previous['current'] : [],
                'daily' => is_array( $previous['daily'] ?? null ) ? $previous['daily'] : [],
            ]
        );
    }

    protected function trimCacheToSettings( array $settings ) : void {
        $valid = [];
        foreach ( $settings['locations'] as $location ) {
            foreach ( $location['providers'] as $provider => $provider_settings ) {
                if ( ! empty( $location['enabled'] ) && ! empty( $provider_settings['enabled'] ) ) {
                    $valid[$this->feedCacheKey( (string) $location['key'], (string) $provider )] = true;
                }
            }
        }
        $cache = $this->getCache();
        $cache['feeds'] = array_intersect_key( $cache['feeds'], $valid );
        TinyMashLockedJsonFile::write( $this->cache_filename, $cache );
    }

    protected function isFeedStale( array $feed, int $stale_hours, \DateTimeImmutable $now ) : bool {
        $last_success = (string) ( $feed['last_success_at_utc'] ?? '' );
        if ( $last_success === '' ) {
            return( true );
        }
        try {
            $success_at = new \DateTimeImmutable( $last_success, new \DateTimeZone( 'UTC' ) );
        } catch ( \Throwable ) {
            return( true );
        }
        return( $success_at->modify( '+' . $stale_hours . ' hours' ) < $now->setTimezone( new \DateTimeZone( 'UTC' ) ) );
    }

    protected function publicRefreshErrorMessage( string $code ) : string {
        return(
            [
                'provider_unsupported' => 'This provider is not implemented yet.',
                'curl_missing' => 'Remote weather fetching is unavailable on this server.',
                'nws_location_unsupported' => 'NWS supports US locations only.',
                'remote_fetch' => 'The weather provider could not be reached.',
                'response_size' => 'The weather provider response exceeded the size limit.',
                'parse' => 'The weather provider response could not be parsed.',
                'settings_write' => 'Weather settings could not be saved.',
                'cache_write' => 'Weather cache could not be saved.',
            ][$code] ?? 'Weather could not be refreshed.'
        );
    }

    protected function formatRefreshFailureMessage( array $location, string $provider, string $message ) : string {
        $provider_definition = $this->getProviderDefinitions()[$this->normalizeProvider( $provider )] ?? [];
        $location_label = mb_trim( (string) ( $location['label'] ?? $location['key'] ?? 'Location' ) );
        $provider_label = mb_trim( (string) ( $provider_definition['label'] ?? $provider ) );
        return( ( $location_label !== '' ? $location_label : 'Location' ) . ' / ' . ( $provider_label !== '' ? $provider_label : $provider ) . ': ' . $message );
    }

    protected function formatRefreshSummary( int $refreshed, int $failed, array $failure_messages = [] ) : string {
        $message = 'Refreshed ' . $refreshed . ' weather feed(s).';
        if ( $failed <= 0 ) {
            return( $message );
        }
        $message .= ' ' . $failed . ' feed(s) failed and kept previous cached data when available.';
        $failure_messages = array_values( array_filter( array_map( 'strval', $failure_messages ) ) );
        if ( ! empty( $failure_messages ) ) {
            $message .= ' ' . implode( ' ', array_slice( $failure_messages, 0, 3 ) );
            if ( count( $failure_messages ) > 3 ) {
                $message .= ' More failures remain.';
            }
        }
        return( $message );
    }

    protected function normalizeKey( string $value ) : string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9]+/', '-', $value ) ?? '';
        return( trim( $value, '-' ) );
    }

    protected function normalizeProvider( string $value ) : string {
        $value = $this->normalizeKey( $value );
        if ( in_array( $value, [ 'met', 'metno', 'met-norway', 'yr', 'yr-no' ], true ) ) {
            return( self::PROVIDER_MET_NORWAY );
        }
        if ( in_array( $value, [ 'nws', 'weather-gov', 'national-weather-service' ], true ) ) {
            return( self::PROVIDER_NWS );
        }
        return( in_array( $value, [ self::PROVIDER_OPEN_METEO, self::PROVIDER_MET_NORWAY, self::PROVIDER_NWS ], true ) ? $value : '' );
    }

    protected function normalizeUnits( string $value ) : string {
        $value = strtolower( trim( $value ) );
        return( $value === 'imperial' ? 'imperial' : 'metric' );
    }

    protected function normalizeWindUnits( string $value ) : string {
        $value = strtolower( trim( $value ) );
        $value = str_replace( [ '/', ' ' ], '', $value );
        return( in_array( $value, [ 'ms', 'kmh', 'mph' ], true ) ? $value : 'ms' );
    }

    protected function normalizeDetails( array $input ) : array {
        $defaults = $this->getDefaultSettings()['defaults']['details'];
        $details = [];
        foreach ( $defaults as $key => $default ) {
            $details[$key] = array_key_exists( $key, $input ) ? $this->normalizeBoolean( $input[$key] ) : (bool) $default;
        }
        return( $details );
    }

    protected function normalizeSlots( array $input ) : array {
        $defaults = $this->getDefaultSettings()['slots'];
        $slots = [];
        foreach ( $defaults as $slot => $default_settings ) {
            $slots[$slot] = $this->normalizeSlotSettings( is_array( $input[$slot] ?? null ) ? $input[$slot] : [], $default_settings );
        }
        return( $slots );
    }

    protected function normalizeSlotSettings( array $input, array $defaults ) : array {
        $provider = $this->normalizeProvider( (string) ( $input['provider'] ?? $defaults['provider'] ?? self::PROVIDER_OPEN_METEO ) );
        return(
            [
                'enabled' => array_key_exists( 'enabled', $input ) ? ! empty( $input['enabled'] ) : ! empty( $defaults['enabled'] ),
                'location' => $this->normalizeKey( (string) ( $input['location'] ?? $defaults['location'] ?? '' ) ),
                'provider' => $provider !== '' ? $provider : self::PROVIDER_OPEN_METEO,
                'forecast_days' => $this->clampInt( $input['forecast_days'] ?? $defaults['forecast_days'] ?? 0, 0, self::MAX_FORECAST_DAYS ),
                'layout' => $this->normalizeLayout( (string) ( $input['layout'] ?? $defaults['layout'] ?? 'compact' ) ),
                'attribution' => array_key_exists( 'attribution', $input ) ? ! empty( $input['attribution'] ) : ! empty( $defaults['attribution'] ),
            ]
        );
    }

    protected function buildSlotsFromPost( array $input ) : array {
        $slots = [];
        foreach ( array_keys( $this->getDefaultSettings()['slots'] ) as $slot ) {
            $posted = is_array( $input[$slot] ?? null ) ? $input[$slot] : [];
            $slots[$slot] = [
                'enabled' => ! empty( $posted['enabled'] ),
                'location' => (string) ( $posted['location'] ?? '' ),
                'provider' => (string) ( $posted['provider'] ?? self::PROVIDER_OPEN_METEO ),
                'forecast_days' => (int) ( $posted['forecast_days'] ?? ( $slot === 'footer' ? 0 : 2 ) ),
                'layout' => (string) ( $posted['layout'] ?? 'compact' ),
                'attribution' => ! empty( $posted['attribution'] ),
            ];
        }
        return( $slots );
    }

    protected function normalizeSlotName( string $slot ) : string {
        $slot = strtolower( trim( $slot ) );
        return( in_array( $slot, [ 'sidebar', 'footer' ], true ) ? $slot : '' );
    }

    protected function resolveDetails( array $defaults, array $attributes ) : array {
        $details = $this->normalizeDetails( $defaults );
        if ( isset( $attributes['details'] ) ) {
            $requested = array_fill_keys( array_keys( $details ), false );
            foreach ( preg_split( '/\s*,\s*/', strtolower( (string) $attributes['details'] ) ) ?: [] as $key ) {
                $key = trim( str_replace( '-', '_', $key ) );
                if ( $key === 'graphics' ) {
                    $key = 'icon';
                }
                if ( isset( $requested[$key] ) ) {
                    $requested[$key] = true;
                }
            }
            $details = $requested;
        }
        foreach ( array_keys( $details ) as $key ) {
            $attribute_key = str_replace( '_', '-', $key );
            if ( array_key_exists( $key, $attributes ) ) {
                $details[$key] = $this->normalizeBoolean( $attributes[$key] );
            } elseif ( array_key_exists( $attribute_key, $attributes ) ) {
                $details[$key] = $this->normalizeBoolean( $attributes[$attribute_key] );
            }
        }
        if ( array_key_exists( 'graphics', $attributes ) ) {
            $details['icon'] = $this->normalizeBoolean( $attributes['graphics'] );
        }
        return( $details );
    }

    protected function normalizeLayout( string $value ) : string {
        $value = strtolower( trim( $value ) );
        return( in_array( $value, [ 'compact', 'vertical', 'horizontal' ], true ) ? $value : 'compact' );
    }

    protected function normalizeBoolean( mixed $value ) : bool {
        if ( is_bool( $value ) ) {
            return( $value );
        }
        $value = strtolower( trim( (string) $value ) );
        return( ! in_array( $value, [ '0', 'false', 'no', 'off' ], true ) );
    }

    protected function normalizeCoordinate( mixed $value, float $min, float $max ) : ?float {
        $coordinate = $this->parseCoordinateValue( $value );
        if ( $coordinate === null ) {
            return( null );
        }
        if ( $coordinate < $min || $coordinate > $max ) {
            return( null );
        }
        return( round( $coordinate, 5 ) );
    }

    protected function parseCoordinateValue( mixed $value ) : ?float {
        $raw = mb_trim( (string) $value );
        if ( $raw === '' ) {
            return( null );
        }

        $normalized = str_replace( ',', '.', $raw );
        if ( is_numeric( $normalized ) ) {
            return( (float) $normalized );
        }

        $upper = strtoupper( $normalized );
        $direction = '';
        if ( preg_match( '/([NSEW])\s*$/u', $upper, $direction_match ) === 1 ) {
            $direction = (string) $direction_match[1];
        } elseif ( preg_match( '/^\s*([NSEW])/u', $upper, $direction_match ) === 1 ) {
            $direction = (string) $direction_match[1];
        }

        $numeric = preg_replace( '/[NSEW]/iu', ' ', $normalized ) ?? '';
        $numeric = preg_replace( '/[^\d.+-]+/u', ' ', $numeric ) ?? '';
        $numeric = trim( preg_replace( '/\s+/u', ' ', $numeric ) ?? '' );
        if ( $numeric === '' ) {
            return( null );
        }
        $parts = explode( ' ', $numeric );
        if ( count( $parts ) > 3 || ! is_numeric( $parts[0] ) ) {
            return( null );
        }
        $degrees = (float) $parts[0];
        $sign = $degrees < 0 ? -1.0 : 1.0;
        $degrees = abs( $degrees );
        $minutes = isset( $parts[1] ) && is_numeric( $parts[1] ) ? (float) $parts[1] : 0.0;
        $seconds = isset( $parts[2] ) && is_numeric( $parts[2] ) ? (float) $parts[2] : 0.0;
        if ( $minutes < 0 || $minutes >= 60 || $seconds < 0 || $seconds >= 60 ) {
            return( null );
        }
        if ( in_array( $direction, [ 'S', 'W' ], true ) ) {
            $sign = -1.0;
        } elseif ( in_array( $direction, [ 'N', 'E' ], true ) ) {
            $sign = 1.0;
        }
        return( $sign * ( $degrees + ( $minutes / 60 ) + ( $seconds / 3600 ) ) );
    }

    protected function normalizeAttributionUrl( string $url ) : string {
        $url = trim( $url );
        return( str_starts_with( strtolower( $url ), 'https://' ) ? mb_substr( $url, 0, 300 ) : 'https://open-meteo.com/' );
    }

    protected function normalizeHttpsUrl( string $url ) : string {
        $url = trim( $url );
        return( str_starts_with( strtolower( $url ), 'https://' ) ? mb_substr( $url, 0, 500 ) : '' );
    }

    protected function clampInt( mixed $value, int $min, int $max ) : int {
        $int = is_numeric( $value ) ? (int) $value : $min;
        return( max( $min, min( $max, $int ) ) );
    }

    protected function roundFloat( mixed $value, int $precision ) : ?float {
        return( is_numeric( $value ) ? round( (float) $value, $precision ) : null );
    }

    protected function feedCacheKey( string $location_key, string $provider ) : string {
        $location_key = $this->normalizeKey( $location_key );
        $provider = $this->normalizeProvider( $provider );
        return( $location_key !== '' && $provider !== '' ? $location_key . ':' . $provider : '' );
    }

    protected function formatTemperature( mixed $value, string $units ) : string {
        if ( ! is_numeric( $value ) ) {
            return( 'n/a' );
        }
        $temperature = (float) $value;
        if ( $units === 'imperial' ) {
            return( $this->formatDecimal( $temperature * 9 / 5 + 32, 0 ) . ' °F' );
        }
        return( $this->formatDecimal( $temperature, 0 ) . ' °C' );
    }

    protected function formatTemperatureCompact( mixed $value, string $units ) : string {
        if ( ! is_numeric( $value ) ) {
            return( 'n/a' );
        }
        $temperature = (float) $value;
        if ( $units === 'imperial' ) {
            return( $this->formatDecimal( $temperature * 9 / 5 + 32, 0 ) . 'F' );
        }
        return( $this->formatDecimal( $temperature, 0 ) . 'C' );
    }

    protected function formatWind( mixed $value, string $wind_units ) : string {
        if ( ! is_numeric( $value ) ) {
            return( 'n/a' );
        }
        $wind = (float) $value;
        if ( $wind_units === 'mph' ) {
            return( $this->formatDecimal( $wind * 0.621371, 0 ) . ' mph' );
        }
        if ( $wind_units === 'ms' ) {
            return( $this->formatDecimal( $wind / 3.6, 1 ) . ' m/s' );
        }
        return( $this->formatDecimal( $wind, 0 ) . ' km/h' );
    }

    protected function formatPrecipitation( mixed $value, string $units ) : string {
        if ( ! is_numeric( $value ) ) {
            return( 'n/a' );
        }
        $amount = (float) $value;
        if ( $units === 'imperial' ) {
            return( $this->formatDecimal( $amount / 25.4, 2 ) . ' in' );
        }
        return( $this->formatDecimal( $amount, 1 ) . ' mm' );
    }

    protected function formatPercent( mixed $value ) : string {
        return( is_numeric( $value ) ? $this->formatDecimal( (float) $value, 0 ) . '%' : 'n/a' );
    }

    protected function formatDecimal( float $value, int $fraction_digits ) : string {
        if ( class_exists( \NumberFormatter::class ) ) {
            $formatter = new \NumberFormatter( $this->locale, \NumberFormatter::DECIMAL );
            $formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, $fraction_digits );
            $formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, $fraction_digits );
            $formatted = $formatter->format( $value );
            if ( is_string( $formatted ) && $formatted !== '' ) {
                return( $formatted );
            }
        }

        return( number_format( $value, $fraction_digits, $this->getDecimalSeparator(), $this->getThousandsSeparator() ) );
    }

    protected function getDecimalSeparator() : string {
        $language = strtolower( strtok( $this->locale, '_' ) ?: $this->locale );
        return( in_array( $language, [ 'sv', 'da', 'de', 'es', 'fi', 'fr', 'it', 'nl', 'no', 'pl', 'pt' ], true ) ? ',' : '.' );
    }

    protected function getThousandsSeparator() : string {
        $language = strtolower( strtok( $this->locale, '_' ) ?: $this->locale );
        return( in_array( $language, [ 'sv', 'fi', 'fr', 'no' ], true ) ? ' ' : ',' );
    }

    protected function normalizeLocale( string $locale ) : string {
        $locale = trim( str_replace( '-', '_', $locale ) );
        $locale = preg_replace( '/[^A-Za-z0-9_]/', '', $locale ) ?? '';
        return( $locale !== '' ? $locale : 'en' );
    }

    protected function formatDateLabel( string $date ) : string {
        try {
            return( ( new \DateTimeImmutable( $date ) )->format( 'D, j M' ) );
        } catch ( \Throwable ) {
            return( $date !== '' ? $date : 'Forecast' );
        }
    }

    protected function formatCurrentDateLabel( string $time ) : string {
        $date = substr( $time, 0, 10 );
        if ( $date === '' ) {
            return( 'Now' );
        }
        /*return( 'Now, ' . $this->formatDateLabel( $date ) );*/
        return( 'Now' );
    }

    protected function formatCurrentPublicLabel( string $time, string $timezone ) : string {
        $date_time = $this->dateTimeFromProviderTime( $time, $timezone );
        return( $date_time !== null ? 'Now (' . $date_time->format( 'H:i T' ) . ')' : 'Now' );
    }

    protected function formatAdminHeaderDate( string $time, string $timezone ) : string {
        $date_time = $this->dateTimeFromProviderTime( $time, $timezone );
        return( $date_time !== null ? $date_time->format( 'D, d-M-Y' ) : 'Forecast' );
    }

    protected function formatCurrentPreviewLabel( string $time, string $timezone ) : string {
        $date_time = $this->dateTimeFromProviderTime( $time, $timezone );
        return( $date_time !== null ? 'Now (' . $date_time->format( 'H:i, T' ) . ')' : 'Now' );
    }

    protected function formatPublicForecastDateLabel( string $date, string $current_date ) : string {
        try {
            $day = new \DateTimeImmutable( $date );
            if ( $current_date !== '' && $day->format( 'Y-m-d' ) === ( new \DateTimeImmutable( $current_date ) )->modify( '+1 day' )->format( 'Y-m-d' ) ) {
                return( 'Tomorrow' );
            }
            return( $this->formatDateLabel( $date ) );
        } catch ( \Throwable ) {
            return( $date !== '' ? $date : 'Forecast' );
        }
    }

    protected function formatPreviewDayLabel( string $date, string $current_date ) : string {
        try {
            $day = new \DateTimeImmutable( $date );
            if ( $current_date !== '' && $day->format( 'Y-m-d' ) === ( new \DateTimeImmutable( $current_date ) )->modify( '+1 day' )->format( 'Y-m-d' ) ) {
                return( 'Tomorrow' );
            }
            return( $day->format( 'd-M' ) );
        } catch ( \Throwable ) {
            return( $date !== '' ? $date : 'Forecast' );
        }
    }

    protected function dateTimeFromProviderTime( string $time, string $timezone ) : ?\DateTimeImmutable {
        $time = trim( $time );
        if ( $time === '' ) {
            return( null );
        }
        $timezone = in_array( $timezone, timezone_identifiers_list(), true ) ? $timezone : $this->default_timezone;
        try {
            return( ( new \DateTimeImmutable( $time, new \DateTimeZone( $timezone ) ) )->setTimezone( new \DateTimeZone( $timezone ) ) );
        } catch ( \Throwable ) {
            return( null );
        }
    }

    protected function metNorwaySymbolForEntry( array $entry ) : string {
        foreach ( [ 'next_1_hours', 'next_6_hours', 'next_12_hours' ] as $period_key ) {
            $symbol = (string) ( $entry['data'][$period_key]['summary']['symbol_code'] ?? '' );
            if ( $symbol !== '' ) {
                return( $symbol );
            }
        }
        return( '' );
    }

    protected function metNorwayPrecipitationForEntry( array $entry ) : ?float {
        foreach ( [ 'next_1_hours', 'next_6_hours', 'next_12_hours' ] as $period_key ) {
            $amount = $entry['data'][$period_key]['details']['precipitation_amount'] ?? null;
            if ( is_numeric( $amount ) ) {
                return( (float) $amount );
            }
        }
        return( null );
    }

    protected function normalizeMetNorwaySymbol( string $symbol ) : string {
        $symbol = strtolower( trim( $symbol ) );
        return( preg_replace( '/_(day|night|polartwilight)$/', '', $symbol ) ?? $symbol );
    }

    protected function weatherCodeFromMetNorwaySymbol( string $symbol ) : int {
        $symbol = $this->normalizeMetNorwaySymbol( $symbol );
        return( match ( true ) {
            $symbol === 'clearsky' => 0,
            $symbol === 'fair' => 1,
            $symbol === 'partlycloudy' => 2,
            $symbol === 'cloudy' => 3,
            $symbol === 'fog' => 45,
            str_contains( $symbol, 'thunder' ) && str_contains( $symbol, 'snow' ) => 95,
            str_contains( $symbol, 'thunder' ) => 95,
            str_contains( $symbol, 'heavysnow' ) => 75,
            str_contains( $symbol, 'lightsnow' ) => 71,
            str_contains( $symbol, 'snow' ) => 73,
            str_contains( $symbol, 'sleet' ) => 66,
            str_contains( $symbol, 'heavyrain' ) => 65,
            str_contains( $symbol, 'lightrain' ) => 61,
            str_contains( $symbol, 'rainshowers' ) => 80,
            str_contains( $symbol, 'rain' ) => 63,
            default => 0,
        } );
    }

    protected function describeMetNorwaySymbol( string $symbol, int $code ) : string {
        $symbol = $this->normalizeMetNorwaySymbol( $symbol );
        return(
            [
                'clearsky' => 'Clear sky',
                'fair' => 'Mainly clear',
                'partlycloudy' => 'Partly cloudy',
                'cloudy' => 'Overcast',
                'fog' => 'Fog',
                'lightrain' => 'Slight rain',
                'rain' => 'Moderate rain',
                'heavyrain' => 'Heavy rain',
                'lightsnow' => 'Slight snow',
                'snow' => 'Moderate snow',
                'heavysnow' => 'Heavy snow',
                'sleet' => 'Light freezing rain',
            ][$symbol] ?? $this->describeWeatherCode( $code )
        );
    }

    protected function weatherCodeFromSummary( string $summary ) : int {
        $summary = strtolower( trim( $summary ) );
        return( match ( true ) {
            $summary === '' => 0,
            str_contains( $summary, 'thunder' ) && str_contains( $summary, 'hail' ) => 96,
            str_contains( $summary, 'thunder' ) => 95,
            str_contains( $summary, 'heavy snow' ) || str_contains( $summary, 'blizzard' ) => 75,
            str_contains( $summary, 'snow' ) => 71,
            str_contains( $summary, 'sleet' ) || str_contains( $summary, 'freezing rain' ) => 66,
            str_contains( $summary, 'heavy rain' ) => 65,
            str_contains( $summary, 'rain') || str_contains( $summary, 'showers' ) => 61,
            str_contains( $summary, 'drizzle' ) => 51,
            str_contains( $summary, 'fog' ) => 45,
            str_contains( $summary, 'partly' ) || str_contains( $summary, 'mostly sunny' ) || str_contains( $summary, 'mostly clear' ) => 2,
            str_contains( $summary, 'overcast' ) || str_contains( $summary, 'cloudy' ) => 3,
            str_contains( $summary, 'clear' ) || str_contains( $summary, 'sunny' ) => 0,
            default => 0,
        } );
    }

    protected function nwsTemperatureToCelsius( mixed $temperature, string $unit ) : ?float {
        if ( ! is_numeric( $temperature ) ) {
            return( null );
        }
        $temperature = (float) $temperature;
        return( strtoupper( trim( $unit ) ) === 'C' ? $temperature : ( $temperature - 32 ) * 5 / 9 );
    }

    protected function parseNwsWindSpeedKmh( string $wind_speed ) : ?float {
        if ( preg_match_all( '/\d+(?:\.\d+)?/', $wind_speed, $matches ) < 1 || empty( $matches[0] ) ) {
            return( null );
        }
        $speed = max( array_map( 'floatval', $matches[0] ) );
        $lower = strtolower( $wind_speed );
        if ( str_contains( $lower, 'km/h' ) || str_contains( $lower, 'kmh' ) ) {
            return( $speed );
        }
        if ( str_contains( $lower, 'm/s' ) ) {
            return( $speed * 3.6 );
        }
        return( $speed * 1.609344 );
    }

    protected function describeWeatherCode( int $code ) : string {
        return(
            [
                0 => 'Clear sky',
                1 => 'Mainly clear',
                2 => 'Partly cloudy',
                3 => 'Overcast',
                45 => 'Fog',
                48 => 'Depositing rime fog',
                51 => 'Light drizzle',
                53 => 'Moderate drizzle',
                55 => 'Dense drizzle',
                56 => 'Light freezing drizzle',
                57 => 'Dense freezing drizzle',
                61 => 'Slight rain',
                63 => 'Moderate rain',
                65 => 'Heavy rain',
                66 => 'Light freezing rain',
                67 => 'Heavy freezing rain',
                71 => 'Slight snow',
                73 => 'Moderate snow',
                75 => 'Heavy snow',
                77 => 'Snow grains',
                80 => 'Slight rain showers',
                81 => 'Moderate rain showers',
                82 => 'Violent rain showers',
                85 => 'Slight snow showers',
                86 => 'Heavy snow showers',
                95 => 'Thunderstorm',
                96 => 'Thunderstorm with slight hail',
                99 => 'Thunderstorm with heavy hail',
            ][$code] ?? 'Weather'
        );
    }

    protected function weatherIconUrl( int $code, bool $is_day ) : string {
        $suffix = $is_day ? 'day' : 'night';
        $icon = match ( true ) {
            $code === 0 => 'clear-' . $suffix,
            $code === 1 => 'mostly-clear-' . $suffix,
            $code === 2 => 'partly-cloudy-' . $suffix,
            $code === 3 => 'overcast-' . $suffix,
            in_array( $code, [ 45, 48 ], true ) => 'fog-' . $suffix,
            in_array( $code, [ 51, 53, 55, 56, 57 ], true ) => 'drizzle',
            in_array( $code, [ 61, 63, 65, 80, 81, 82 ], true ) => 'rain',
            in_array( $code, [ 66, 67 ], true ) => 'sleet',
            in_array( $code, [ 71, 73, 75, 77, 85, 86 ], true ) => 'snow',
            in_array( $code, [ 95 ], true ) => 'thunderstorms-' . $suffix,
            in_array( $code, [ 96, 99 ], true ) => 'thunderstorms-' . $suffix . '-hail',
            default => 'not-available',
        };
        return( '/plugins/weather/icons/meteocons/' . $icon . '.svg' );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
