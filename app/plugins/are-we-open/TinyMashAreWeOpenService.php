<?php

use app\classes\TinyMashLockedJsonFile;

class TinyMashAreWeOpenService {

    protected const WEEKDAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    protected string $settings_filename;
    protected string $default_timezone;

    public function __construct( string $settings_filename, string $default_timezone = 'UTC' ) {
        $this->settings_filename = $settings_filename;
        $this->default_timezone = $this->normalizeTimezone( $default_timezone );
    }

    public function getSettings() : array {
        $stored_settings = TinyMashLockedJsonFile::read( $this->settings_filename );
        return( $this->normalizeSettings( is_array( $stored_settings ) ? $stored_settings : [] ) );
    }

    public function saveSettings( array $settings ) : bool {
        return( TinyMashLockedJsonFile::write( $this->settings_filename, $this->normalizeSettings( $settings ) ) );
    }

    public function getStatus( ?\DateTimeImmutable $now = null ) : array {
        $settings = $this->getSettings();
        $timezone = new \DateTimeZone( (string) $settings['timezone'] );
        $now = $now instanceof \DateTimeImmutable ? $now->setTimezone( $timezone ) : new \DateTimeImmutable( 'now', $timezone );

        $manual = is_array( $settings['manual_override'] ?? null ) ? $settings['manual_override'] : [];
        $manual_mode = (string) ( $manual['mode'] ?? 'auto' );
        $manual_until = trim( (string) ( $manual['until'] ?? '' ) );
        if ( in_array( $manual_mode, [ 'open', 'closed' ], true ) && ( $manual_until === '' || $now <= new \DateTimeImmutable( $manual_until, $timezone ) ) ) {
            return( $this->buildStatus( $manual_mode === 'open', $settings, 'manual', $manual['message'] ?? '', $now ) );
        }

        $today = $now->format( 'Y-m-d' );
        foreach ( (array) ( $settings['exceptions'] ?? [] ) as $exception ) {
            if ( ! is_array( $exception ) || (string) ( $exception['date'] ?? '' ) !== $today || empty( $exception['enabled'] ) ) {
                continue;
            }

            $mode = (string) ( $exception['mode'] ?? 'closed' );
            if ( $mode === 'closed' ) {
                return( $this->buildStatus( false, $settings, 'exception', $exception['note'] ?? '', $now ) );
            }
            if ( $mode === 'open' ) {
                return( $this->buildStatus( $this->timeWindowIsOpen( $now, (string) ( $exception['open_time'] ?? '' ), (string) ( $exception['close_time'] ?? '' ) ), $settings, 'exception', $exception['note'] ?? '', $now ) );
            }
        }

        $weekly = is_array( $settings['weekly'] ?? null ) ? $settings['weekly'] : [];
        $weekday = strtolower( $now->format( 'l' ) );
        $today_row = is_array( $weekly[$weekday] ?? null ) ? $weekly[$weekday] : [];
        if ( ! empty( $today_row['enabled'] ) && $this->timeWindowIsOpen( $now, (string) ( $today_row['open_time'] ?? '' ), (string) ( $today_row['close_time'] ?? '' ) ) ) {
            return( $this->buildStatus( true, $settings, 'weekly', $today_row['note'] ?? '', $now ) );
        }

        $yesterday = strtolower( $now->modify( '-1 day' )->format( 'l' ) );
        $yesterday_row = is_array( $weekly[$yesterday] ?? null ) ? $weekly[$yesterday] : [];
        if ( ! empty( $yesterday_row['enabled'] ) && $this->overnightTimeWindowIsOpen( $now, (string) ( $yesterday_row['open_time'] ?? '' ), (string) ( $yesterday_row['close_time'] ?? '' ) ) ) {
            return( $this->buildStatus( true, $settings, 'weekly', $yesterday_row['note'] ?? '', $now ) );
        }

        return( $this->buildStatus( false, $settings, 'weekly', '', $now ) );
    }

    public function renderStatusHtml( array $attributes = [] ) : string {
        $status = $this->getStatus();
        $is_open = ! empty( $status['open'] );
        $format = strtolower( trim( (string) ( $attributes['format'] ?? 'badge' ) ) );
        $open_text = trim( (string) ( $attributes['open'] ?? $status['open_label'] ?? 'Open' ) );
        $closed_text = trim( (string) ( $attributes['closed'] ?? $status['closed_label'] ?? 'Closed' ) );
        $label = $is_open ? $open_text : $closed_text;
        if ( $label === '' ) {
            $label = $is_open ? 'Open' : 'Closed';
        }

        $escaped_label = htmlspecialchars( $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        if ( $format === 'text' ) {
            return( '<span class="tm-are-we-open-status">' . $escaped_label . '</span>' );
        }

        return( '<span class="badge text-bg-' . ( $is_open ? 'success' : 'secondary' ) . ' tm-are-we-open-status">' . $escaped_label . '</span>' );
    }

    public function renderHoursHtml() : string {
        $settings = $this->getSettings();
        $weekly = is_array( $settings['weekly'] ?? null ) ? $settings['weekly'] : [];
        $html = '<div class="tm-are-we-open-hours"><dl class="row mb-0">';
        foreach ( self::WEEKDAYS as $weekday ) {
            $row = is_array( $weekly[$weekday] ?? null ) ? $weekly[$weekday] : [];
            $label = ucfirst( $weekday );
            $value = ! empty( $row['enabled'] )
                ? ( (string) ( $row['open_time'] ?? '' ) . '-' . (string) ( $row['close_time'] ?? '' ) )
                : 'Closed';
            $html .= '<dt class="col-5 col-sm-4">' . htmlspecialchars( $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</dt>';
            $html .= '<dd class="col-7 col-sm-8">' . htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</dd>';
        }
        $html .= '</dl></div>';
        return( $html );
    }

    public function normalizeSettings( array $settings ) : array {
        $normalized = [
            'timezone' => $this->normalizeTimezone( (string) ( $settings['timezone'] ?? $this->default_timezone ) ),
            'open_label' => mb_substr( trim( (string) ( $settings['open_label'] ?? 'Open' ) ), 0, 80 ),
            'closed_label' => mb_substr( trim( (string) ( $settings['closed_label'] ?? 'Closed' ) ), 0, 80 ),
            'weekly' => [],
            'exceptions' => [],
            'manual_override' => [
                'mode' => 'auto',
                'until' => '',
                'message' => '',
            ],
        ];

        foreach ( self::WEEKDAYS as $weekday ) {
            $row = is_array( $settings['weekly'][$weekday] ?? null ) ? $settings['weekly'][$weekday] : [];
            $normalized['weekly'][$weekday] = [
                'enabled' => ! empty( $row['enabled'] ),
                'open_time' => $this->normalizeTime( (string) ( $row['open_time'] ?? '09:00' ), '09:00' ),
                'close_time' => $this->normalizeTime( (string) ( $row['close_time'] ?? '17:00' ), '17:00' ),
                'note' => mb_substr( trim( (string) ( $row['note'] ?? '' ) ), 0, 160 ),
            ];
        }

        foreach ( (array) ( $settings['exceptions'] ?? [] ) as $exception ) {
            if ( ! is_array( $exception ) ) {
                continue;
            }
            $date = $this->normalizeDate( (string) ( $exception['date'] ?? '' ) );
            if ( $date === '' ) {
                continue;
            }
            $mode = strtolower( trim( (string) ( $exception['mode'] ?? 'closed' ) ) );
            if ( ! in_array( $mode, [ 'open', 'closed' ], true ) ) {
                $mode = 'closed';
            }
            $normalized['exceptions'][] = [
                'enabled' => ! array_key_exists( 'enabled', $exception ) || ! empty( $exception['enabled'] ),
                'date' => $date,
                'mode' => $mode,
                'open_time' => $this->normalizeTime( (string) ( $exception['open_time'] ?? '09:00' ), '09:00' ),
                'close_time' => $this->normalizeTime( (string) ( $exception['close_time'] ?? '17:00' ), '17:00' ),
                'note' => mb_substr( trim( (string) ( $exception['note'] ?? '' ) ), 0, 160 ),
            ];
        }

        usort(
            $normalized['exceptions'],
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['date'] ?? '' ), (string) ( $right['date'] ?? '' ) ) );
            }
        );

        $manual = is_array( $settings['manual_override'] ?? null ) ? $settings['manual_override'] : [];
        $manual_mode = strtolower( trim( (string) ( $manual['mode'] ?? 'auto' ) ) );
        if ( ! in_array( $manual_mode, [ 'auto', 'open', 'closed' ], true ) ) {
            $manual_mode = 'auto';
        }
        $normalized['manual_override'] = [
            'mode' => $manual_mode,
            'until' => $this->normalizeLocalDateTime( (string) ( $manual['until'] ?? '' ) ),
            'message' => mb_substr( trim( (string) ( $manual['message'] ?? '' ) ), 0, 160 ),
        ];

        if ( $normalized['open_label'] === '' ) {
            $normalized['open_label'] = 'Open';
        }
        if ( $normalized['closed_label'] === '' ) {
            $normalized['closed_label'] = 'Closed';
        }

        return( $normalized );
    }

    protected function buildStatus( bool $open, array $settings, string $source, mixed $message, \DateTimeImmutable $now ) : array {
        return(
            [
                'open' => $open,
                'source' => $source,
                'message' => trim( (string) $message ),
                'open_label' => (string) ( $settings['open_label'] ?? 'Open' ),
                'closed_label' => (string) ( $settings['closed_label'] ?? 'Closed' ),
                'checked_at' => $now->format( 'Y-m-d H:i:s T' ),
            ]
        );
    }

    protected function timeWindowIsOpen( \DateTimeImmutable $now, string $open_time, string $close_time ) : bool {
        $open_time = $this->normalizeTime( $open_time, '' );
        $close_time = $this->normalizeTime( $close_time, '' );
        if ( $open_time === '' || $close_time === '' ) {
            return( false );
        }

        $open_at = new \DateTimeImmutable( $now->format( 'Y-m-d' ) . ' ' . $open_time, $now->getTimezone() );
        $close_at = new \DateTimeImmutable( $now->format( 'Y-m-d' ) . ' ' . $close_time, $now->getTimezone() );
        if ( $close_at <= $open_at ) {
            $close_at = $close_at->modify( '+1 day' );
        }

        return( $now >= $open_at && $now < $close_at );
    }

    protected function overnightTimeWindowIsOpen( \DateTimeImmutable $now, string $open_time, string $close_time ) : bool {
        $open_time = $this->normalizeTime( $open_time, '' );
        $close_time = $this->normalizeTime( $close_time, '' );
        if ( $open_time === '' || $close_time === '' || $close_time > $open_time ) {
            return( false );
        }

        $open_at = new \DateTimeImmutable( $now->modify( '-1 day' )->format( 'Y-m-d' ) . ' ' . $open_time, $now->getTimezone() );
        $close_at = new \DateTimeImmutable( $now->format( 'Y-m-d' ) . ' ' . $close_time, $now->getTimezone() );
        return( $now >= $open_at && $now < $close_at );
    }

    protected function normalizeTimezone( string $timezone ) : string {
        $timezone = trim( $timezone );
        if ( $timezone !== '' ) {
            try {
                new \DateTimeZone( $timezone );
                return( $timezone );
            } catch ( \Throwable $e ) {
            }
        }

        return( 'UTC' );
    }

    protected function normalizeDate( string $date ) : string {
        $date = trim( $date );
        return( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) === 1 ? $date : '' );
    }

    protected function normalizeLocalDateTime( string $date_time ) : string {
        $date_time = trim( $date_time );
        return( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $date_time ) === 1 ? $date_time : '' );
    }

    protected function normalizeTime( string $time, string $default ) : string {
        $time = trim( $time );
        return( preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) === 1 ? $time : $default );
    }
}
