<?php
namespace app\classes;

class TinyMashDateFormatter {

    protected string $date_format;
    protected string $time_format;
    protected \DateTimeZone $timezone;

    public function __construct( string $date_format = 'd-M-Y', string $time_format = 'H:i (T)', string $timezone = 'UTC' ) {
        $this->date_format = trim( $date_format ) !== '' ? trim( $date_format ) : 'd-M-Y';
        $this->time_format = trim( $time_format ) !== '' ? trim( $time_format ) : 'H:i (T)';
        try {
            $this->timezone = new \DateTimeZone( trim( $timezone ) !== '' ? trim( $timezone ) : 'UTC' );
        } catch ( \Throwable ) {
            $this->timezone = new \DateTimeZone( 'UTC' );
        }
    }

    public function formatUtcDateTime( string $utc_datetime ) : string {
        return( $this->formatUtcDateTimeWithFormat( $utc_datetime, $this->date_format . ', ' . $this->time_format ) );
    }

    public function formatUtcDateTimeWithoutTimezone( string $utc_datetime ) : string {
        return( $this->formatUtcDateTimeWithFormat( $utc_datetime, $this->date_format . ', ' . $this->getTimeFormatWithoutTimezone() ) );
    }

    public function formatUtcDateTimeForLocalInput( string $utc_datetime ) : string {
        return( self::parseUtcDateTime( $utc_datetime ) instanceof \DateTimeImmutable
            ? $this->formatUtcDateTimeWithFormat( $utc_datetime, 'Y-m-d\TH:i' )
            : '' );
    }

    public function formatUtcDateTimePart( string $utc_datetime, string $format ) : string {
        return( self::parseUtcDateTime( $utc_datetime ) instanceof \DateTimeImmutable
            ? $this->formatUtcDateTimeWithFormat( $utc_datetime, $format )
            : '' );
    }

    public function normalizeLocalDateTimeToUtc( string $local_datetime, string $format = 'Y-m-d\TH:i' ) : string {
        $local_datetime = trim( $local_datetime );
        if ( $local_datetime === '' ) {
            return( '' );
        }

        $naive = \DateTimeImmutable::createFromFormat( '!' . $format, $local_datetime, new \DateTimeZone( 'UTC' ) );
        $errors = \DateTimeImmutable::getLastErrors();
        if ( ! $naive instanceof \DateTimeImmutable
            || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
            || $naive->format( $format ) !== $local_datetime ) {
            throw new \InvalidArgumentException( 'local_datetime_invalid' );
        }

        $naive_timestamp = $naive->getTimestamp();
        $offsets = [];
        $transitions = $this->timezone->getTransitions( $naive_timestamp - 259200, $naive_timestamp + 259200 );
        if ( is_array( $transitions ) ) {
            foreach ( $transitions as $transition ) {
                if ( is_array( $transition ) && isset( $transition['offset'] ) ) {
                    $offsets[(int) $transition['offset']] = true;
                }
            }
        }
        if ( $offsets === [] ) {
            $offsets[$this->timezone->getOffset( $naive )] = true;
        }

        $candidates = [];
        foreach ( array_keys( $offsets ) as $offset ) {
            $timestamp = $naive_timestamp - (int) $offset;
            $candidate = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $this->timezone );
            if ( $candidate->format( $format ) === $local_datetime ) {
                $candidates[$timestamp] = true;
            }
        }

        if ( $candidates === [] ) {
            throw new \InvalidArgumentException( 'local_datetime_nonexistent' );
        }
        if ( count( $candidates ) > 1 ) {
            throw new \InvalidArgumentException( 'local_datetime_ambiguous' );
        }

        return( gmdate( 'Y-m-d\TH:i:s\Z', (int) array_key_first( $candidates ) ) );
    }

    protected function formatUtcDateTimeWithFormat( string $utc_datetime, string $format ) : string {
        $utc_datetime = trim( $utc_datetime );
        if ( $utc_datetime === '' ) {
            return( '' );
        }

        $date_time = self::parseUtcDateTime( $utc_datetime );
        if ( ! $date_time instanceof \DateTimeImmutable ) {
            return( $utc_datetime );
        }

        return( $date_time->setTimezone( $this->timezone )->format( $format ) );
    }

    public static function parseUtcDateTime( string $utc_datetime ) : ?\DateTimeImmutable {
        $utc_datetime = trim( $utc_datetime );
        if ( $utc_datetime === ''
            || preg_match( '/^\d{4}-\d{2}-\d{2}(?:T| )\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/', $utc_datetime ) !== 1 ) {
            return( null );
        }

        try {
            $date_time = new \DateTimeImmutable( $utc_datetime, new \DateTimeZone( 'UTC' ) );
            $errors = \DateTimeImmutable::getLastErrors();
            if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
                return( null );
            }
            return( $date_time->setTimezone( new \DateTimeZone( 'UTC' ) ) );
        } catch ( \Throwable ) {
            return( null );
        }
    }

    protected function getTimeFormatWithoutTimezone() : string {
        $format = $this->time_format;
        $format = (string) preg_replace( '/\s*\((?:T|e|O|P)\)\s*$/', '', $format );
        $format = (string) preg_replace( '/\s+(?:T|e|O|P)\s*$/', '', $format );
        $format = trim( $format );

        return( $format !== '' ? $format : 'H:i' );
    }

    public function formatNow() : string {
        return( ( new \DateTimeImmutable( 'now', $this->timezone ) )->format( $this->date_format . ', ' . $this->time_format ) );
    }

    public function getTimezoneName() : string {
        return( $this->timezone->getName() );
    }

    public function getTimezoneAbbreviation() : string {
        return( ( new \DateTimeImmutable( 'now', $this->timezone ) )->format( 'T' ) );
    }

}
