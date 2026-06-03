<?php

use app\classes\TinyMashLockedJsonFile;
use Sabre\VObject\Reader;

class TinyMashWhatsUpService {

    protected const CACHE_PAST_DAYS = 90;
    protected const CACHE_FUTURE_DAYS = 400;
    protected const MAX_SOURCE_EVENTS = 2000;
    protected const MAX_ICS_BYTES = 2097152;
    protected const SOURCE_PALETTE = [
        'blue' => [ 'label' => 'Blue', 'hex' => '#0072B2' ],
        'orange' => [ 'label' => 'Orange', 'hex' => '#E69F00' ],
        'green' => [ 'label' => 'Green', 'hex' => '#009E73' ],
        'vermillion' => [ 'label' => 'Vermillion', 'hex' => '#D55E00' ],
        'purple' => [ 'label' => 'Purple', 'hex' => '#CC79A7' ],
        'sky' => [ 'label' => 'Sky blue', 'hex' => '#56B4E9' ],
        'yellow' => [ 'label' => 'Yellow', 'hex' => '#F0E442' ],
        'black' => [ 'label' => 'Black', 'hex' => '#000000' ],
    ];

    protected string $data_directory;
    protected string $settings_filename;
    protected string $cache_filename;
    protected string $sources_directory;
    protected string $default_timezone;

    public function __construct( string $data_directory, string $default_timezone = 'UTC' ) {
        $this->data_directory = rtrim( $data_directory, DIRECTORY_SEPARATOR );
        $this->settings_filename = $this->data_directory . DIRECTORY_SEPARATOR . 'settings.json';
        $this->cache_filename = $this->data_directory . DIRECTORY_SEPARATOR . 'events-cache.json';
        $this->sources_directory = $this->data_directory . DIRECTORY_SEPARATOR . 'sources';
        $this->default_timezone = $this->normalizeTimezone( $default_timezone );
    }

    public function getSettings() : array {
        $stored = TinyMashLockedJsonFile::read( $this->settings_filename );
        return( $this->normalizeSettings( is_array( $stored ) ? $stored : [] ) );
    }

    public function getSourcePalette() : array {
        return( self::SOURCE_PALETTE );
    }

    public function getCustomColorStylesheetUrl() : string {
        $css = $this->getCustomColorStylesheet();
        if ( $css === '' ) {
            return( '' );
        }

        return( '/plugin-custom-css/whats-up?v=' . substr( sha1( $css ), 0, 12 ) );
    }

    public function getCustomColorStylesheet() : string {
        $settings = $this->getSettings();
        $rules = [];
        if ( (string) ( $settings['now_color'] ?? 'primary' ) === 'custom' ) {
            $rules[] = '.tm-whats-up-now-custom { --tm-whats-up-now-color: ' . $this->nowColorValue( $settings ) . '; }';
        }
        foreach ( (array) ( $settings['sources'] ?? [] ) as $source ) {
            if ( ! is_array( $source ) || (string) ( $source['color'] ?? '' ) !== 'custom' ) {
                continue;
            }
            $source_key = $this->normalizeKey( (string) ( $source['key'] ?? '' ) );
            if ( $source_key !== '' ) {
                $rules[] = '.tm-whats-up-source-custom-' . $source_key . ' { --tm-whats-up-source-color: ' . $this->sourceColorValue( $source ) . '; }';
            }
        }

        return( empty( $rules ) ? '' : implode( "\n", $rules ) . "\n" );
    }

    public function saveSettings( array $settings ) : bool {
        $previous = $this->getSettings();
        $normalized = $this->normalizeSettings( $settings, true );
        if ( ! TinyMashLockedJsonFile::write( $this->settings_filename, $normalized ) ) {
            return( false );
        }
        $this->pruneUnusedLocalSourceFiles( $normalized );
        if ( ! $this->reconcileCacheWithSettings( $normalized, $previous ) ) {
            @ unlink( $this->cache_filename );
            return( false );
        }
        return( true );
    }

    public function getCache() : array {
        $stored = TinyMashLockedJsonFile::read( $this->cache_filename );
        $cache = is_array( $stored ) ? $stored : [];
        return(
            [
                'refreshed_at_utc' => trim( (string) ( $cache['refreshed_at_utc'] ?? '' ) ),
                'sources' => is_array( $cache['sources'] ?? null ) ? $cache['sources'] : [],
                'events' => array_values( array_filter( (array) ( $cache['events'] ?? [] ), 'is_array' ) ),
            ]
        );
    }

    public function storeUploadedSource( string $source_key, array $upload ) : string {
        $source_key = $this->normalizeKey( $source_key );
        if ( $source_key === '' ) {
            throw new \InvalidArgumentException( 'source_key' );
        }

        $error = (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE );
        if ( $error === UPLOAD_ERR_NO_FILE ) {
            return( '' );
        }
        if ( $error !== UPLOAD_ERR_OK ) {
            throw new \RuntimeException( 'upload' );
        }

        $temporary_filename = trim( (string) ( $upload['tmp_name'] ?? '' ) );
        if ( $temporary_filename === '' || ! is_file( $temporary_filename ) || ! is_readable( $temporary_filename ) ) {
            throw new \RuntimeException( 'upload' );
        }
        if ( PHP_SAPI !== 'cli' && ! is_uploaded_file( $temporary_filename ) ) {
            throw new \RuntimeException( 'upload' );
        }

        $bytes = (int) ( $upload['size'] ?? @ filesize( $temporary_filename ) ?: 0 );
        if ( $bytes <= 0 || $bytes > self::MAX_ICS_BYTES ) {
            throw new \RuntimeException( 'upload_size' );
        }
        $contents = @ file_get_contents( $temporary_filename );
        if ( ! is_string( $contents ) ) {
            throw new \RuntimeException( 'upload' );
        }

        return( $this->storeLocalSourceContents( $source_key, $contents ) );
    }

    public function storeLocalSourceContents( string $source_key, string $contents ) : string {
        $source_key = $this->normalizeKey( $source_key );
        if ( $source_key === '' || strlen( $contents ) === 0 || strlen( $contents ) > self::MAX_ICS_BYTES ) {
            throw new \InvalidArgumentException( 'source_upload' );
        }
        if ( stripos( $contents, 'BEGIN:VCALENDAR' ) === false || stripos( $contents, 'END:VCALENDAR' ) === false ) {
            throw new \InvalidArgumentException( 'source_ics' );
        }

        $directory = $this->sources_directory;
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'storage' );
        }
        $filename = $directory . DIRECTORY_SEPARATOR . $source_key . '.ics';
        $temporary_filename = $filename . '.tmp-' . bin2hex( random_bytes( 6 ) );
        if ( @ file_put_contents( $temporary_filename, $contents, LOCK_EX ) === false || ! @ rename( $temporary_filename, $filename ) ) {
            @ unlink( $temporary_filename );
            throw new \RuntimeException( 'storage' );
        }
        $this->invalidateCachedSources( [ $source_key ] );

        return( $filename );
    }

    public function getLocalSourceFilename( string $source_key ) : string {
        $source_key = $this->normalizeKey( $source_key );
        if ( $source_key === '' ) {
            return( '' );
        }

        return( $this->sources_directory . DIRECTORY_SEPARATOR . $source_key . '.ics' );
    }

    public function normalizeSourceKey( string $source_key ) : string {
        return( $this->normalizeKey( $source_key ) );
    }

    public function suggestUploadedSource( string $original_filename ) : array {
        $filename = basename( str_replace( '\\', '/', mb_trim( $original_filename ) ) );
        $label = mb_trim( (string) pathinfo( $filename, PATHINFO_FILENAME ) );
        if ( $label === '' ) {
            $label = 'Calendar';
        }
        $key = $this->normalizeKey( $label );
        return(
            [
                'key' => $key !== '' ? $key : 'calendar',
                'label' => mb_substr( $label, 0, 100 ),
            ]
        );
    }

    public function invalidateCachedSources( array $source_keys ) : void {
        if ( ! is_file( $this->cache_filename ) ) {
            return;
        }
        $invalidated = [];
        foreach ( $source_keys as $source_key ) {
            $source_key = $this->normalizeKey( (string) $source_key );
            if ( $source_key !== '' ) {
                $invalidated[$source_key] = true;
            }
        }
        if ( empty( $invalidated ) ) {
            return;
        }
        $cache = $this->getCache();
        $cache['events'] = array_values(
            array_filter(
                $cache['events'],
                static function( array $event ) use ( $invalidated ) : bool {
                    return( ! isset( $invalidated[(string) ( $event['source_key'] ?? '' )] ) );
                }
            )
        );
        foreach ( array_keys( $invalidated ) as $source_key ) {
            unset( $cache['sources'][$source_key] );
        }
        if ( ! TinyMashLockedJsonFile::write( $this->cache_filename, $cache ) ) {
            @ unlink( $this->cache_filename );
        }
    }

    public function refreshSources( ?array $only_keys = null ) : array {
        $settings = $this->getSettings();
        $cache = $this->getCache();
        $old_events_by_source = [];
        foreach ( (array) $cache['events'] as $event ) {
            $source_key = (string) ( $event['source_key'] ?? '' );
            if ( $source_key !== '' ) {
                $old_events_by_source[$source_key][] = $event;
            }
        }

        $keys = [];
        foreach ( (array) $only_keys as $key ) {
            $key = $this->normalizeKey( (string) $key );
            if ( $key !== '' ) {
                $keys[] = $key;
            }
        }
        $limited = ! empty( $keys );
        $source_status = is_array( $cache['sources'] ?? null ) ? $cache['sources'] : [];
        $events = [];
        $refreshed = 0;
        $failed = 0;
        $disabled = 0;

        foreach ( $settings['sources'] as $source ) {
            $source_key = (string) $source['key'];
            if ( empty( $source['enabled'] ) ) {
                $disabled++;
                $source_status[$source_key] = $this->buildSourceStatus( $source, 'disabled', 'Source is disabled.', [], (string) ( $source_status[$source_key]['last_success_at_utc'] ?? '' ) );
                continue;
            }
            if ( $limited && ! in_array( $source_key, $keys, true ) ) {
                foreach ( (array) ( $old_events_by_source[$source_key] ?? [] ) as $old_event ) {
                    $events[] = $old_event;
                }
                continue;
            }

            try {
                $contents = $source['type'] === 'remote'
                    ? $this->fetchRemoteSource( (string) $source['url'] )
                    : $this->readLocalSource( $source_key );
                $parsed_events = $this->parseCalendarEvents( $contents, $source );
                foreach ( $parsed_events as $event ) {
                    $events[] = $event;
                }
                $refreshed++;
                $source_status[$source_key] = $this->buildSourceStatus( $source, 'ok', 'Calendar refreshed.', $parsed_events, gmdate( 'Y-m-d\TH:i:s\Z' ) );
            } catch ( \Throwable $e ) {
                $failed++;
                foreach ( (array) ( $old_events_by_source[$source_key] ?? [] ) as $old_event ) {
                    $events[] = $old_event;
                }
                $source_status[$source_key] = $this->buildSourceStatus(
                    $source,
                    'error',
                    $this->publicRefreshErrorMessage( $e->getMessage() ),
                    (array) ( $old_events_by_source[$source_key] ?? [] ),
                    (string) ( $source_status[$source_key]['last_success_at_utc'] ?? '' )
                );
            }
        }

        usort(
            $events,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['start_at_utc'] ?? '' ), (string) ( $right['start_at_utc'] ?? '' ) ) );
            }
        );
        $next_cache = [
            'refreshed_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'sources' => $source_status,
            'events' => array_values( $events ),
        ];
        if ( ! TinyMashLockedJsonFile::write( $this->cache_filename, $next_cache ) ) {
            throw new \RuntimeException( 'cache_write' );
        }

        return(
            [
                'refreshed' => $refreshed,
                'failed' => $failed,
                'disabled' => $disabled,
                'event_count' => count( $events ),
                'message' => 'Refreshed ' . $refreshed . ' calendar source(s); cached ' . count( $events ) . ' event occurrence(s).' . ( $failed > 0 ? ' ' . $failed . ' source(s) failed and kept their previous cached events.' : '' ),
            ]
        );
    }

    public function renderAgendaHtml( array $attributes = [], ?\DateTimeImmutable $now = null ) : string {
        $context = $this->buildListDisplayContext( $attributes, $now );
        $settings = $context['settings'];
        $timezone = $context['timezone'];
        $now = $context['now'];
        $events = $context['events'];
        $show_location = $this->normalizeBoolean( $attributes['location'] ?? true );
        $show_description = $this->normalizeBoolean( $attributes['description'] ?? false );

        if ( empty( $events ) ) {
            return( $this->renderEmptyHtml( (string) $settings['empty_text'], 'tm-whats-up-agenda-view' ) );
        }

        $show_sources = ! empty( $settings['show_source_labels'] ) && $this->hasMultipleEventSources( $events );
        $html = '<div class="tm-whats-up tm-whats-up-agenda-view ' . $this->displayColorClass( $settings ) . '"><ol class="tm-whats-up-agenda">';
        $previous_date = '';
        foreach ( $events as $event ) {
            $start = ( new \DateTimeImmutable( (string) $event['start_at_utc'] ) )->setTimezone( $timezone );
            $end = ( new \DateTimeImmutable( (string) $event['end_at_utc'] ) )->setTimezone( $timezone );
            $date_label = $start->format( 'D, j M Y' );
            if ( $date_label !== $previous_date ) {
                $html .= '<li class="tm-whats-up-date"><span>' . $this->escape( $date_label ) . '</span></li>';
                $previous_date = $date_label;
            }
            $status = $this->eventStatus( $start, $end, $now );
            $time_label = ! empty( $event['all_day'] )
                ? 'All day'
                : $this->formatTimeRange( $start, $end, (string) $settings['time_format'] );
            $html .= '<li class="tm-whats-up-event tm-whats-up-event-' . $status . ' ' . $this->eventSourceClass( $event, $settings ) . '">';
            $html .= '<div class="tm-whats-up-event-time">' . $this->escape( $time_label ) . '</div>';
            $html .= '<div class="tm-whats-up-event-body"><div class="tm-whats-up-event-title"><span class="tm-whats-up-source-dot" aria-hidden="true"></span><span>' . $this->escape( (string) $event['title'] ) . '</span></div>';
            if ( $show_sources ) {
                $html .= $this->renderEventSourceLabel( $event, false );
            }
            if ( $show_location && mb_trim( (string) ( $event['location'] ?? '' ) ) !== '' ) {
                $html .= '<div class="tm-whats-up-event-location">' . $this->escape( (string) $event['location'] ) . '</div>';
            }
            if ( $show_description && mb_trim( (string) ( $event['description'] ?? '' ) ) !== '' ) {
                $html .= '<div class="tm-whats-up-event-description">' . $this->escape( (string) $event['description'] ) . '</div>';
            }
            $html .= '</div></li>';
        }
        $html .= '</ol></div>';
        return( $html );
    }

    public function renderWhatsUpHtml( array $attributes = [], ?\DateTimeImmutable $now = null ) : string {
        $attributes['past'] = $attributes['past'] ?? 1;
        $attributes['future'] = $attributes['future'] ?? 1;
        $context = $this->buildListDisplayContext( $attributes, $now );
        $settings = $context['settings'];
        $timezone = $context['timezone'];
        $now = $context['now'];
        $events = $context['events'];

        if ( empty( $events ) ) {
            return( $this->renderEmptyHtml( (string) $settings['empty_text'], 'tm-whats-up-compact-view' ) );
        }

        $show_sources = ! empty( $settings['show_source_labels'] ) && $this->hasMultipleEventSources( $events );
        $today = $now->format( 'Y-m-d' );
        $html = '<div class="tm-whats-up tm-whats-up-compact-view ' . $this->displayColorClass( $settings ) . '"><ol class="tm-whats-up-compact">';
        $previous_date = '';
        foreach ( $events as $event ) {
            $start = ( new \DateTimeImmutable( (string) $event['start_at_utc'] ) )->setTimezone( $timezone );
            $end = ( new \DateTimeImmutable( (string) $event['end_at_utc'] ) )->setTimezone( $timezone );
            $event_date = $start->format( 'Y-m-d' );
            if ( $event_date !== $previous_date ) {
                $date_label = match ( $event_date ) {
                    $now->modify( '-1 day' )->format( 'Y-m-d' ) => 'Yesterday',
                    $today => 'Today',
                    $now->modify( '+1 day' )->format( 'Y-m-d' ) => 'Tomorrow',
                    default => $start->format( 'D, j M' ),
                };
                $html .= '<li class="tm-whats-up-compact-date">' . $this->escape( $date_label ) . '</li>';
                $previous_date = $event_date;
            }
            $status = $this->eventStatus( $start, $end, $now );
            $time_label = ! empty( $event['all_day'] ) ? 'All day' : $this->formatTimeRange( $start, $end, (string) $settings['time_format'] );
            $html .= '<li class="tm-whats-up-compact-event tm-whats-up-event-' . $status . ' ' . $this->eventSourceClass( $event, $settings ) . '">';
            $html .= '<div class="tm-whats-up-compact-body"><div class="tm-whats-up-event-title"><span class="tm-whats-up-source-dot tm-whats-up-title-dot" aria-hidden="true"></span><span class="tm-whats-up-compact-title-text">' . $this->escape( (string) $event['title'] ) . '</span></div>';
            $html .= '<div class="tm-whats-up-event-time">' . $this->escape( $time_label ) . '</div>';
            if ( $show_sources ) {
                $html .= $this->renderEventSourceLabel( $event, false, true );
            }
            $html .= '</div></li>';
        }
        $html .= '</ol></div>';
        return( $html );
    }

    public function renderTimelineHtml( array $attributes = [], ?\DateTimeImmutable $now = null ) : string {
        $context = $this->buildListDisplayContext( $attributes, $now );
        $settings = $context['settings'];
        $timezone = $context['timezone'];
        $now = $context['now'];
        $events = $context['events'];

        if ( empty( $events ) ) {
            return( $this->renderEmptyHtml( (string) $settings['empty_text'], 'tm-whats-up-timeline-view' ) );
        }

        $show_sources = ! empty( $settings['show_source_labels'] ) && $this->hasMultipleEventSources( $events );
        $html = '<div class="tm-whats-up tm-whats-up-timeline-view ' . $this->displayColorClass( $settings ) . '"><ol class="tm-whats-up-timeline">';
        $previous_date = '';
        foreach ( $events as $event ) {
            $start = ( new \DateTimeImmutable( (string) $event['start_at_utc'] ) )->setTimezone( $timezone );
            $end = ( new \DateTimeImmutable( (string) $event['end_at_utc'] ) )->setTimezone( $timezone );
            $date_key = $start->format( 'Y-m-d' );
            $date_label = $date_key !== $previous_date ? $start->format( 'd-M-Y' ) : '';
            $weekday_label = $date_label !== '' ? '(' . $start->format( 'D' ) . ')' : '';
            $current_date_label = $date_label !== '' && $date_key === $now->format( 'Y-m-d' );
            $status = $this->eventStatus( $start, $end, $now );
            $time_label = ! empty( $event['all_day'] )
                ? 'All day'
                : $this->formatTimeRange( $start, $end, (string) $settings['time_format'] );
            $html .= '<li class="tm-whats-up-timeline-event' . ( $date_label === '' ? ' tm-whats-up-timeline-event-repeated' : '' ) . ' tm-whats-up-event-' . $status . ' ' . $this->eventSourceClass( $event, $settings ) . '">';
            $html .= '<div class="tm-whats-up-timeline-date' . ( $current_date_label ? ' tm-whats-up-timeline-date-current' : '' ) . '"><span class="tm-whats-up-timeline-date-main">' . $this->escape( $date_label ) . '</span><span class="tm-whats-up-timeline-date-weekday">' . $this->escape( $weekday_label ) . '</span></div>';
            $html .= '<span class="tm-whats-up-timeline-dot" aria-hidden="true"></span>';
            $html .= '<div class="tm-whats-up-event-time">' . $this->escape( $time_label ) . '</div>';
            $html .= '<div class="tm-whats-up-event-body"><div class="tm-whats-up-event-title">' . $this->escape( (string) $event['title'] ) . '</div>';
            if ( $show_sources ) {
                $html .= $this->renderEventSourceLabel( $event, false );
            }
            $html .= '</div></li>';
            $previous_date = $date_key;
        }
        $html .= '</ol></div>';
        return( $html );
    }

    public function renderCalendarHtml( array $attributes = [], ?\DateTimeImmutable $now = null ) : string {
        static $instance_number = 0;

        $settings = $this->getSettings();
        $timezone = new \DateTimeZone( (string) $settings['timezone'] );
        $now = $now instanceof \DateTimeImmutable ? $now->setTimezone( $timezone ) : new \DateTimeImmutable( 'now', $timezone );
        $month_value = mb_trim( (string) ( $attributes['month'] ?? '' ) );
        if ( preg_match( '/^\d{4}-\d{2}$/', $month_value ) !== 1 ) {
            $month_value = $now->format( 'Y-m' );
        }
        $month = \DateTimeImmutable::createFromFormat( '!Y-m', $month_value, $timezone );
        if ( ! $month instanceof \DateTimeImmutable ) {
            $month = $now->modify( 'first day of this month' )->setTime( 0, 0 );
        }
        $past_months = $this->boundedInt( $attributes['past_months'] ?? 0, 0, 12, 0 );
        $future_months = $this->boundedInt( $attributes['future_months'] ?? 0, 0, 12, 0 );
        $selected_sources = $this->requestedSourceKeys( $attributes );
        $all_events = $this->filterCachedEventsBySources( $this->getCache()['events'], $selected_sources );
        $instance_number++;
        $calendar_id = 'tm-whats-up-calendar-' . $instance_number;

        $months = [];
        for ( $offset = -$past_months; $offset <= $future_months; $offset++ ) {
            $visible_month = $month->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' months' );
            $months[] = [
                'month' => $visible_month,
                'events' => $this->filterEventsForRange(
                    $all_events,
                    $visible_month->setTime( 0, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) ),
                    $visible_month->modify( 'last day of this month' )->setTime( 23, 59, 59 )->setTimezone( new \DateTimeZone( 'UTC' ) )
                ),
            ];
        }

        $html = '<div class="tm-whats-up tm-whats-up-calendar-view ' . $this->displayColorClass( $settings ) . '" id="' . $calendar_id . '" data-tm-whats-up-calendar data-current-panel="' . $past_months . '">';
        if ( count( $months ) > 1 ) {
            $html .= '<div class="tm-whats-up-calendar-tools"><button class="btn btn-outline-secondary btn-sm" type="button" data-tm-calendar-prev aria-label="Previous month"><span class="bi bi-chevron-left" aria-hidden="true"></span></button><button class="btn btn-outline-secondary btn-sm" type="button" data-tm-calendar-next aria-label="Next month"><span class="bi bi-chevron-right" aria-hidden="true"></span></button></div>';
        }
        foreach ( $months as $panel_index => $panel ) {
            $panel_month = $panel['month'];
            $events = $panel['events'];
            $show_sources = ! empty( $settings['show_source_labels'] ) && $this->hasMultipleEventSources( $events );
            $html .= '<section class="tm-whats-up-calendar-panel" data-tm-calendar-panel="' . $panel_index . '"' . ( $panel_index !== $past_months ? ' hidden' : '' ) . '>';
            $html .= '<h3 class="tm-whats-up-calendar-heading">' . $this->escape( $panel_month->format( 'F Y' ) ) . '</h3>';
            $html .= '<div class="tm-whats-up-calendar-grid">';
            foreach ( [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ] as $weekday ) {
                $html .= '<div class="tm-whats-up-calendar-weekday">' . $weekday . '</div>';
            }
            $first_weekday = (int) $panel_month->format( 'N' );
            for ( $blank = 1; $blank < $first_weekday; $blank++ ) {
                $html .= '<div class="tm-whats-up-calendar-day tm-whats-up-calendar-day-empty" aria-hidden="true"></div>';
            }
            $days_in_month = (int) $panel_month->format( 't' );
            for ( $day_number = 1; $day_number <= $days_in_month; $day_number++ ) {
                $day = $panel_month->setDate( (int) $panel_month->format( 'Y' ), (int) $panel_month->format( 'm' ), $day_number );
                $day_events = $this->filterEventsForRange(
                    $events,
                    $day->setTime( 0, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) ),
                    $day->setTime( 23, 59, 59 )->setTimezone( new \DateTimeZone( 'UTC' ) )
                );
                $is_today = $day->format( 'Y-m-d' ) === $now->format( 'Y-m-d' );
                $html .= '<div class="tm-whats-up-calendar-day' . ( $is_today ? ' tm-whats-up-calendar-today' : '' ) . '"><div class="tm-whats-up-calendar-number">' . $day_number . '</div>';
                foreach ( array_slice( $day_events, 0, 3 ) as $event ) {
                    $html .= $this->renderCalendarEventHtml( $event, $settings, $show_sources );
                }
                if ( count( $day_events ) > 3 ) {
                    $html .= '<details class="tm-whats-up-calendar-overflow"><summary class="tm-whats-up-calendar-more">+' . ( count( $day_events ) - 3 ) . ' more</summary><div class="tm-whats-up-calendar-overflow-panel">';
                    foreach ( array_slice( $day_events, 3 ) as $event ) {
                        $html .= $this->renderCalendarEventHtml( $event, $settings, $show_sources );
                    }
                    $html .= '</div></details>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
            if ( $show_sources ) {
                $html .= $this->renderSourceLegend( $events, $settings );
            }
            $html .= '</section>';
        }
        $html .= '</div>';
        return( $html );
    }

    public function renderSidebarHtml() : string {
        $settings = $this->getSettings();
        $sidebar = is_array( $settings['sidebar'] ?? null ) ? $settings['sidebar'] : [];
        if ( empty( $sidebar['enabled'] ) ) {
            return( '' );
        }

        $attributes = [
            'limit' => (string) ( $sidebar['limit'] ?? 6 ),
        ];
        if ( ! empty( $sidebar['sources'] ) ) {
            $attributes['source'] = implode( ',', (array) $sidebar['sources'] );
        }
        $view = (string) ( $sidebar['view'] ?? 'whatsup' );
        $content = match ( $view ) {
            'agenda' => $this->renderAgendaHtml( $attributes ),
            'timeline' => $this->renderTimelineHtml( $attributes ),
            default => $this->renderWhatsUpHtml( $attributes ),
        };
        return( '<section class="tm-whats-up-sidebar"><div class="tm-whats-up-sidebar-heading">What\'s up</div>' . $content . '</section>' );
    }

    public function getPreviewEvents( int $limit = 12 ) : array {
        $cache = $this->getCache();
        $limit = max( 1, min( 50, $limit ) );
        $threshold = gmdate( 'Y-m-d\TH:i:s\Z', time() - 86400 );
        $useful_events = array_values(
            array_filter(
                $cache['events'],
                static function( array $event ) use ( $threshold ) : bool {
                    return( (string) ( $event['end_at_utc'] ?? '' ) >= $threshold );
                }
            )
        );
        if ( empty( $useful_events ) ) {
            $useful_events = array_slice( $cache['events'], -$limit );
        }
        return( array_slice( $useful_events, 0, $limit ) );
    }

    public function normalizeSettings( array $settings, bool $strict = false ) : array {
        $normalized = [
            'timezone' => $this->normalizeTimezone( (string) ( $settings['timezone'] ?? $this->default_timezone ) ),
            'time_format' => strtolower( trim( (string) ( $settings['time_format'] ?? '24h' ) ) ) === '12h' ? '12h' : '24h',
            'past_days' => $this->boundedInt( $settings['past_days'] ?? 0, 0, self::CACHE_PAST_DAYS, 0 ),
            'future_days' => $this->boundedInt( $settings['future_days'] ?? 30, 0, self::CACHE_FUTURE_DAYS, 30 ),
            'max_events' => $this->boundedInt( $settings['max_events'] ?? 12, 1, 100, 12 ),
            'empty_text' => mb_substr( mb_trim( (string) ( $settings['empty_text'] ?? 'No upcoming events.' ) ), 0, 160 ),
            'now_color' => 'primary',
            'now_custom_color' => '',
            'show_source_labels' => array_key_exists( 'show_source_labels', $settings ) ? ! empty( $settings['show_source_labels'] ) : true,
            'sources' => [],
            'sidebar' => [
                'enabled' => false,
                'view' => 'whatsup',
                'sources' => [],
                'limit' => 6,
            ],
        ];
        if ( $normalized['empty_text'] === '' ) {
            $normalized['empty_text'] = 'No upcoming events.';
        }

        $seen = [];
        $palette_keys = array_keys( self::SOURCE_PALETTE );
        $now_color = strtolower( mb_trim( (string) ( $settings['now_color'] ?? 'primary' ) ) );
        if ( $now_color !== 'primary' && ! isset( self::SOURCE_PALETTE[$now_color] ) && $now_color !== 'custom' ) {
            $now_color = 'primary';
        }
        $now_custom_color = $this->normalizeHexColor( (string) ( $settings['now_custom_color'] ?? '' ) );
        if ( $now_color === 'custom' && $now_custom_color === '' ) {
            if ( $strict ) {
                throw new \InvalidArgumentException( 'now_color' );
            }
            $now_color = 'primary';
        }
        $normalized['now_color'] = $now_color;
        $normalized['now_custom_color'] = $now_custom_color;
        foreach ( (array) ( $settings['sources'] ?? [] ) as $source_index => $source ) {
            if ( ! is_array( $source ) || ! empty( $source['remove'] ) ) {
                continue;
            }
            $key = $this->normalizeKey( (string) ( $source['key'] ?? '' ) );
            $label = mb_substr( mb_trim( (string) ( $source['label'] ?? '' ) ), 0, 100 );
            if ( $key === '' && $label === '' && mb_trim( (string) ( $source['url'] ?? '' ) ) === '' ) {
                continue;
            }
            if ( $key === '' || isset( $seen[$key] ) ) {
                if ( $strict ) {
                    throw new \InvalidArgumentException( 'source_key' );
                }
                continue;
            }
            if ( $label === '' ) {
                $label = ucfirst( str_replace( '-', ' ', $key ) );
            }
            $type = strtolower( trim( (string) ( $source['type'] ?? 'remote' ) ) ) === 'local' ? 'local' : 'remote';
            $url = $type === 'remote' ? mb_trim( (string) ( $source['url'] ?? '' ) ) : '';
            if ( $type === 'remote' && $strict && ! $this->isValidRemoteUrl( $url ) ) {
                throw new \InvalidArgumentException( 'source_url' );
            }
            $default_color = (string) $palette_keys[(int) $source_index % count( $palette_keys )];
            $color = strtolower( mb_trim( (string) ( $source['color'] ?? $default_color ) ) );
            if ( ! isset( self::SOURCE_PALETTE[$color] ) && $color !== 'custom' ) {
                $color = $default_color;
            }
            $custom_color = $this->normalizeHexColor( (string) ( $source['custom_color'] ?? '' ) );
            if ( $color === 'custom' && $custom_color === '' ) {
                if ( $strict ) {
                    throw new \InvalidArgumentException( 'source_color' );
                }
                $color = $default_color;
            }
            $normalized['sources'][] = [
                'key' => $key,
                'label' => $label,
                'enabled' => ! empty( $source['enabled'] ),
                'type' => $type,
                'url' => mb_substr( $url, 0, 2048 ),
                'color' => $color,
                'custom_color' => $custom_color,
            ];
            $seen[$key] = true;
        }

        $sidebar = is_array( $settings['sidebar'] ?? null ) ? $settings['sidebar'] : [];
        $sidebar_view = strtolower( mb_trim( (string) ( $sidebar['view'] ?? 'whatsup' ) ) );
        if ( ! in_array( $sidebar_view, [ 'whatsup', 'agenda', 'timeline' ], true ) ) {
            $sidebar_view = 'whatsup';
        }
        $sidebar_sources = [];
        foreach ( (array) ( $sidebar['sources'] ?? [] ) as $source_key ) {
            $source_key = $this->normalizeKey( (string) $source_key );
            if ( $source_key !== '' && isset( $seen[$source_key] ) ) {
                $sidebar_sources[$source_key] = $source_key;
            }
        }
        $normalized['sidebar'] = [
            'enabled' => ! empty( $sidebar['enabled'] ),
            'view' => $sidebar_view,
            'sources' => array_values( $sidebar_sources ),
            'limit' => $this->boundedInt( $sidebar['limit'] ?? 6, 1, 20, 6 ),
        ];

        return( $normalized );
    }

    protected function buildListDisplayContext( array $attributes, ?\DateTimeImmutable $now = null ) : array {
        $settings = $this->getSettings();
        $timezone = new \DateTimeZone( (string) $settings['timezone'] );
        $now = $now instanceof \DateTimeImmutable ? $now->setTimezone( $timezone ) : new \DateTimeImmutable( 'now', $timezone );
        $past_days = $this->boundedInt( $attributes['past'] ?? $settings['past_days'], 0, self::CACHE_PAST_DAYS, (int) $settings['past_days'] );
        $future_days = $this->boundedInt( $attributes['future'] ?? $settings['future_days'], 0, self::CACHE_FUTURE_DAYS, (int) $settings['future_days'] );
        $limit = $this->boundedInt( $attributes['limit'] ?? $settings['max_events'], 1, 100, (int) $settings['max_events'] );
        $from = $now->setTime( 0, 0 )->modify( '-' . $past_days . ' days' )->setTimezone( new \DateTimeZone( 'UTC' ) );
        $through = $now->setTime( 23, 59, 59 )->modify( '+' . $future_days . ' days' )->setTimezone( new \DateTimeZone( 'UTC' ) );
        $events = $this->filterCachedEventsBySources( $this->getCache()['events'], $this->requestedSourceKeys( $attributes ) );
        $events = $this->filterEventsForRange( $events, $from, $through );

        if ( count( $events ) > $limit ) {
            $now_utc = $now->setTimezone( new \DateTimeZone( 'UTC' ) );
            $past_events = [];
            $current_and_upcoming_events = [];
            foreach ( $events as $event ) {
                try {
                    $end = new \DateTimeImmutable( (string) ( $event['end_at_utc'] ?? '' ) );
                } catch ( \Throwable $e ) {
                    continue;
                }
                if ( $end <= $now_utc ) {
                    $past_events[] = $event;
                } else {
                    $current_and_upcoming_events[] = $event;
                }
            }
            if ( count( $current_and_upcoming_events ) >= $limit ) {
                $events = array_slice( $current_and_upcoming_events, 0, $limit );
            } else {
                $remaining = $limit - count( $current_and_upcoming_events );
                $events = array_merge( array_slice( $past_events, -$remaining ), $current_and_upcoming_events );
            }
        }

        return(
            [
                'settings' => $settings,
                'timezone' => $timezone,
                'now' => $now,
                'events' => array_values( $events ),
            ]
        );
    }

    protected function requestedSourceKeys( array $attributes ) : array {
        $raw = mb_trim( (string) ( $attributes['sources'] ?? $attributes['source'] ?? '' ) );
        if ( $raw === '' || strtolower( $raw ) === 'all' ) {
            return( [] );
        }

        $keys = [];
        foreach ( preg_split( '/\s*,\s*/', $raw ) ?: [] as $source_key ) {
            $source_key = $this->normalizeKey( (string) $source_key );
            if ( $source_key !== '' ) {
                $keys[$source_key] = true;
            }
        }
        return( array_keys( $keys ) );
    }

    protected function filterCachedEventsBySources( array $events, array $selected_sources ) : array {
        if ( empty( $selected_sources ) ) {
            return( array_values( $events ) );
        }
        $selected = array_fill_keys( $selected_sources, true );
        return(
            array_values(
                array_filter(
                    $events,
                    static function( array $event ) use ( $selected ) : bool {
                        return( isset( $selected[(string) ( $event['source_key'] ?? '' )] ) );
                    }
                )
            )
        );
    }

    protected function filterEventsForRange( array $events, \DateTimeImmutable $from, \DateTimeImmutable $through ) : array {
        return(
            array_values(
                array_filter(
                    $events,
                    static function( array $event ) use ( $from, $through ) : bool {
                        try {
                            $start = new \DateTimeImmutable( (string) ( $event['start_at_utc'] ?? '' ) );
                            $end = new \DateTimeImmutable( (string) ( $event['end_at_utc'] ?? '' ) );
                        } catch ( \Throwable $e ) {
                            return( false );
                        }
                        return( $end > $from && $start <= $through );
                    }
                )
            )
        );
    }

    protected function hasMultipleEventSources( array $events ) : bool {
        $sources = [];
        foreach ( $events as $event ) {
            $key = (string) ( $event['source_key'] ?? '' );
            if ( $key !== '' ) {
                $sources[$key] = true;
            }
        }
        return( count( $sources ) > 1 );
    }

    protected function eventSourceClass( array $event, array $settings ) : string {
        $source_key = (string) ( $event['source_key'] ?? '' );
        foreach ( (array) ( $settings['sources'] ?? [] ) as $source ) {
            if ( is_array( $source ) && (string) ( $source['key'] ?? '' ) === $source_key ) {
                $color = (string) ( $source['color'] ?? 'blue' );
                return( $color === 'custom' ? 'tm-whats-up-source-custom-' . $this->normalizeKey( $source_key ) : 'tm-whats-up-source-' . $color );
            }
        }
        return( 'tm-whats-up-source-blue' );
    }

    protected function displayColorClass( array $settings ) : string {
        $color = (string) ( $settings['now_color'] ?? 'primary' );
        return( 'tm-whats-up-now-' . ( $color === 'custom' || isset( self::SOURCE_PALETTE[$color] ) ? $color : 'primary' ) );
    }

    protected function nowColorValue( array $settings ) : string {
        $color = (string) ( $settings['now_color'] ?? 'primary' );
        if ( $color === 'custom' ) {
            $custom_color = $this->normalizeHexColor( (string) ( $settings['now_custom_color'] ?? '' ) );
            if ( $custom_color !== '' ) {
                return( $custom_color );
            }
        }
        if ( isset( self::SOURCE_PALETTE[$color] ) ) {
            return( (string) self::SOURCE_PALETTE[$color]['hex'] );
        }
        return( 'var(--bs-primary)' );
    }

    protected function sourceColorValue( array $source ) : string {
        $color = (string) ( $source['color'] ?? 'blue' );
        if ( $color === 'custom' ) {
            $custom_color = $this->normalizeHexColor( (string) ( $source['custom_color'] ?? '' ) );
            if ( $custom_color !== '' ) {
                return( $custom_color );
            }
        }
        return( (string) ( self::SOURCE_PALETTE[$color]['hex'] ?? self::SOURCE_PALETTE['blue']['hex'] ) );
    }

    protected function renderEventSourceLabel( array $event, bool $include_dot = true, bool $plain = false ) : string {
        $dot = $include_dot ? '<span class="tm-whats-up-source-dot" aria-hidden="true"></span>' : '';
        $class = $plain ? 'tm-whats-up-source-label tm-whats-up-source-label-plain' : 'tm-whats-up-source-label';
        return( '<div class="' . $class . '">' . $dot . $this->escape( (string) ( $event['source_label'] ?? '' ) ) . '</div>' );
    }

    protected function renderSourceLegend( array $events, array $settings ) : string {
        $sources = [];
        foreach ( $events as $event ) {
            $key = (string) ( $event['source_key'] ?? '' );
            if ( $key !== '' && ! isset( $sources[$key] ) ) {
                $sources[$key] = $event;
            }
        }
        if ( count( $sources ) < 2 ) {
            return( '' );
        }
        $html = '<div class="tm-whats-up-legend" aria-label="Calendar sources">';
        foreach ( $sources as $event ) {
            $html .= '<span class="tm-whats-up-legend-item ' . $this->eventSourceClass( $event, $settings ) . '"><span class="tm-whats-up-source-dot" aria-hidden="true"></span>' . $this->escape( (string) ( $event['source_label'] ?? '' ) ) . '</span>';
        }
        $html .= '</div>';
        return( $html );
    }

    protected function renderCalendarEventHtml( array $event, array $settings, bool $show_sources ) : string {
        $html = '<div class="tm-whats-up-calendar-event ' . $this->eventSourceClass( $event, $settings ) . '"><span class="tm-whats-up-source-dot" aria-hidden="true"></span><span>' . $this->escape( (string) $event['title'] ) . '</span>';
        if ( $show_sources ) {
            $html .= '<span class="visually-hidden"> (' . $this->escape( (string) ( $event['source_label'] ?? '' ) ) . ')</span>';
        }
        return( $html . '</div>' );
    }

    protected function renderEmptyHtml( string $empty_text, string $view_class ) : string {
        return( '<div class="tm-whats-up ' . $view_class . ' tm-whats-up-empty"><p class="mb-0">' . $this->escape( $empty_text ) . '</p></div>' );
    }

    protected function readLocalSource( string $source_key ) : string {
        $filename = $this->getLocalSourceFilename( $source_key );
        if ( $filename === '' || ! is_file( $filename ) || ! is_readable( $filename ) ) {
            throw new \RuntimeException( 'local_missing' );
        }
        $bytes = (int) @ filesize( $filename );
        if ( $bytes <= 0 || $bytes > self::MAX_ICS_BYTES ) {
            throw new \RuntimeException( 'source_size' );
        }
        $contents = @ file_get_contents( $filename );
        if ( ! is_string( $contents ) ) {
            throw new \RuntimeException( 'local_read' );
        }
        return( $contents );
    }

    protected function pruneUnusedLocalSourceFiles( array $settings ) : void {
        if ( ! is_dir( $this->sources_directory ) ) {
            return;
        }
        $retained = [];
        foreach ( (array) ( $settings['sources'] ?? [] ) as $source ) {
            if ( ! is_array( $source ) || (string) ( $source['type'] ?? '' ) !== 'local' ) {
                continue;
            }
            $key = $this->normalizeKey( (string) ( $source['key'] ?? '' ) );
            if ( $key !== '' ) {
                $retained[$key . '.ics'] = true;
            }
        }
        foreach ( glob( $this->sources_directory . DIRECTORY_SEPARATOR . '*.ics' ) ?: [] as $filename ) {
            if ( ! isset( $retained[basename( $filename )] ) ) {
                @ unlink( $filename );
            }
        }
    }

    protected function reconcileCacheWithSettings( array $settings, array $previous_settings ) : bool {
        if ( ! is_file( $this->cache_filename ) ) {
            return( true );
        }
        $previous_sources = [];
        foreach ( (array) ( $previous_settings['sources'] ?? [] ) as $source ) {
            if ( is_array( $source ) ) {
                $previous_sources[(string) ( $source['key'] ?? '' )] = $source;
            }
        }
        $enabled_keys = [];
        $labels = [];
        foreach ( (array) ( $settings['sources'] ?? [] ) as $source ) {
            if ( is_array( $source ) && ! empty( $source['enabled'] ) ) {
                $key = (string) ( $source['key'] ?? '' );
                $previous = is_array( $previous_sources[$key] ?? null ) ? $previous_sources[$key] : [];
                if (
                    empty( $previous )
                    || (string) ( $previous['type'] ?? '' ) !== (string) ( $source['type'] ?? '' )
                    || (string) ( $previous['url'] ?? '' ) !== (string) ( $source['url'] ?? '' )
                ) {
                    continue;
                }
                $enabled_keys[$key] = true;
                $labels[$key] = (string) ( $source['label'] ?? '' );
            }
        }
        $cache = $this->getCache();
        $cache['events'] = array_values(
            array_filter(
                $cache['events'],
                static function( array $event ) use ( $enabled_keys ) : bool {
                    return( isset( $enabled_keys[(string) ( $event['source_key'] ?? '' )] ) );
                }
            )
        );
        foreach ( $cache['events'] as &$event ) {
            $key = (string) ( $event['source_key'] ?? '' );
            if ( isset( $labels[$key] ) ) {
                $event['source_label'] = $labels[$key];
            }
        }
        unset( $event );
        $cache['sources'] = array_filter(
            $cache['sources'],
            static function( mixed $status, string $key ) use ( $enabled_keys ) : bool {
                return( isset( $enabled_keys[$key] ) );
            },
            ARRAY_FILTER_USE_BOTH
        );
        return( TinyMashLockedJsonFile::write( $this->cache_filename, $cache ) );
    }

    protected function fetchRemoteSource( string $url ) : string {
        if ( ! $this->isValidRemoteUrl( $url ) ) {
            throw new \RuntimeException( 'remote_url' );
        }
        if ( ! function_exists( 'curl_init' ) ) {
            throw new \RuntimeException( 'curl_missing' );
        }
        $parts = parse_url( $url );
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $ip = $this->resolvePublicRemoteHost( $host );
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
                CURLOPT_USERAGENT => 'tinymash-whats-up/0.91.0',
                CURLOPT_HTTPHEADER => [ 'Accept: text/calendar, text/plain;q=0.8' ],
                CURLOPT_RESOLVE => [ $host . ':443:' . $ip ],
                CURLOPT_WRITEFUNCTION => static function( mixed $handle, string $chunk ) use ( &$body, &$too_large ) : int {
                    if ( strlen( $body ) + strlen( $chunk ) > self::MAX_ICS_BYTES ) {
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
            throw new \RuntimeException( 'source_size' );
        }
        if ( $ok === false || $http_code < 200 || $http_code >= 300 ) {
            throw new \RuntimeException( 'remote_fetch' );
        }
        return( $body );
    }

    protected function parseCalendarEvents( string $contents, array $source ) : array {
        try {
            $calendar = Reader::read( $contents, Reader::OPTION_FORGIVING );
            $now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
            $start = $now->modify( '-' . self::CACHE_PAST_DAYS . ' days' );
            $end = $now->modify( '+' . self::CACHE_FUTURE_DAYS . ' days' );
            $expanded = $calendar->expand( $start, $end );
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( 'parse' );
        }

        $events = [];
        foreach ( $expanded->VEVENT as $component ) {
            if ( count( $events ) >= self::MAX_SOURCE_EVENTS ) {
                throw new \RuntimeException( 'source_event_limit' );
            }
            try {
                if ( ! isset( $component->DTSTART ) ) {
                    continue;
                }
                $start_at = \DateTimeImmutable::createFromInterface( $component->DTSTART->getDateTime() );
                $all_day = strtoupper( (string) ( $component->DTSTART['VALUE'] ?? '' ) ) === 'DATE';
                if ( isset( $component->DTEND ) ) {
                    $end_at = \DateTimeImmutable::createFromInterface( $component->DTEND->getDateTime() );
                } else {
                    $end_at = $all_day ? $start_at->modify( '+1 day' ) : $start_at->modify( '+1 hour' );
                }
                if ( $end_at <= $start_at ) {
                    $end_at = $all_day ? $start_at->modify( '+1 day' ) : $start_at->modify( '+1 hour' );
                }
                $events[] = [
                    'source_key' => (string) $source['key'],
                    'source_label' => (string) $source['label'],
                    'uid' => mb_substr( mb_trim( (string) ( $component->UID ?? '' ) ), 0, 255 ),
                    'title' => mb_substr( mb_trim( (string) ( $component->SUMMARY ?? 'Untitled event' ) ), 0, 200 ),
                    'location' => mb_substr( mb_trim( (string) ( $component->LOCATION ?? '' ) ), 0, 300 ),
                    'description' => mb_substr( mb_trim( preg_replace( '/\s+/u', ' ', (string) ( $component->DESCRIPTION ?? '' ) ) ?? '' ), 0, 500 ),
                    'start_at_utc' => $start_at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
                    'end_at_utc' => $end_at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
                    'all_day' => $all_day,
                ];
            } catch ( \Throwable $e ) {
                continue;
            }
        }
        return( $events );
    }

    protected function buildSourceStatus( array $source, string $status, string $message, array $events, string $last_success_at_utc ) : array {
        return(
            [
                'key' => (string) ( $source['key'] ?? '' ),
                'label' => (string) ( $source['label'] ?? '' ),
                'type' => (string) ( $source['type'] ?? '' ),
                'status' => $status,
                'message' => $message,
                'event_count' => count( $events ),
                'checked_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'last_success_at_utc' => $last_success_at_utc,
            ]
        );
    }

    protected function publicRefreshErrorMessage( string $code ) : string {
        return(
            [
                'local_missing' => 'Upload a local iCalendar file before refreshing this source.',
                'source_size' => 'The iCalendar source exceeds the 2 MB limit.',
                'remote_url' => 'Only public HTTPS calendar URLs are accepted.',
                'remote_host' => 'The remote calendar host is not public.',
                'curl_missing' => 'Remote calendar fetching is unavailable on this server.',
                'remote_fetch' => 'The remote calendar could not be fetched.',
                'parse' => 'The source could not be parsed as iCalendar data.',
                'source_event_limit' => 'The calendar expands to more than 2,000 cached occurrences in the supported window.',
            ][$code] ?? 'The calendar source could not be refreshed.'
        );
    }

    protected function isValidRemoteUrl( string $url ) : bool {
        $parts = parse_url( mb_trim( $url ) );
        return(
            is_array( $parts )
            && strtolower( (string) ( $parts['scheme'] ?? '' ) ) === 'https'
            && trim( (string) ( $parts['host'] ?? '' ) ) !== ''
            && empty( $parts['user'] )
            && empty( $parts['pass'] )
            && ( ! isset( $parts['port'] ) || (int) $parts['port'] === 443 )
        );
    }

    protected function resolvePublicRemoteHost( string $host ) : string {
        if ( $host === '' || $host === 'localhost' || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) {
            throw new \RuntimeException( 'remote_host' );
        }
        $addresses = filter_var( $host, FILTER_VALIDATE_IP ) !== false
            ? [ $host ]
            : ( gethostbynamel( $host ) ?: [] );
        if ( empty( $addresses ) ) {
            throw new \RuntimeException( 'remote_host' );
        }
        foreach ( $addresses as $address ) {
            if ( filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                throw new \RuntimeException( 'remote_host' );
            }
        }
        return( (string) $addresses[0] );
    }

    protected function formatTimeRange( \DateTimeImmutable $start, \DateTimeImmutable $end, string $time_format ) : string {
        $format = $time_format === '12h' ? 'g:i a' : 'H:i';
        $range = $start->format( $format );
        if ( $end->format( 'Y-m-d' ) !== $start->format( 'Y-m-d' ) ) {
            return( $range . ' - ' . $end->format( 'D, j M ' . $format ) );
        }
        return( $range . ' - ' . $end->format( $format ) );
    }

    protected function eventStatus( \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $now ) : string {
        if ( $start <= $now && $end > $now ) {
            return( 'current' );
        }
        return( $end < $now ? 'past' : 'upcoming' );
    }

    protected function normalizeTimezone( string $timezone ) : string {
        $timezone = mb_trim( $timezone );
        if ( $timezone !== '' ) {
            try {
                new \DateTimeZone( $timezone );
                return( $timezone );
            } catch ( \Throwable $e ) {
            }
        }
        return( 'UTC' );
    }

    protected function normalizeKey( string $key ) : string {
        $key = strtolower( trim( $key ) );
        return( trim( preg_replace( '/[^a-z0-9-]+/', '-', $key ) ?? '', '-' ) );
    }

    protected function normalizeHexColor( string $color ) : string {
        $color = strtoupper( mb_trim( $color ) );
        return( preg_match( '/^#[0-9A-F]{6}$/', $color ) === 1 ? $color : '' );
    }

    protected function boundedInt( mixed $value, int $minimum, int $maximum, int $default ) : int {
        if ( filter_var( $value, FILTER_VALIDATE_INT ) === false ) {
            return( $default );
        }
        return( max( $minimum, min( $maximum, (int) $value ) ) );
    }

    protected function normalizeBoolean( mixed $value ) : bool {
        return( in_array( strtolower( mb_trim( (string) $value ) ), [ '1', 'true', 'yes', 'on', 'show' ], true ) );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
