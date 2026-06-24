<?php
namespace app\classes;

class TinyMashDateFormatter {

    protected string $date_format;
    protected string $time_format;
    protected \DateTimeZone $timezone;

    public function __construct( string $date_format = 'd-M-Y', string $time_format = 'H:i (T)', string $timezone = 'UTC' ) {
        $this->date_format = trim( $date_format ) !== '' ? trim( $date_format ) : 'd-M-Y';
        $this->time_format = trim( $time_format ) !== '' ? trim( $time_format ) : 'H:i (T)';
        $this->timezone = new \DateTimeZone( trim( $timezone ) !== '' ? trim( $timezone ) : 'UTC' );
    }

    public function formatUtcDateTime( string $utc_datetime ) : string {
        return( $this->formatUtcDateTimeWithFormat( $utc_datetime, $this->date_format . ', ' . $this->time_format ) );
    }

    public function formatUtcDateTimeWithoutTimezone( string $utc_datetime ) : string {
        return( $this->formatUtcDateTimeWithFormat( $utc_datetime, $this->date_format . ', ' . $this->getTimeFormatWithoutTimezone() ) );
    }

    protected function formatUtcDateTimeWithFormat( string $utc_datetime, string $format ) : string {
        $utc_datetime = trim( $utc_datetime );
        if ( $utc_datetime === '' ) {
            return( '' );
        }

        try {
            $date_time = new \DateTimeImmutable( $utc_datetime, new \DateTimeZone( 'UTC' ) );
        } catch ( \Throwable $e ) {
            return( $utc_datetime );
        }

        return( $date_time->setTimezone( $this->timezone )->format( $format ) );
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

}// TinyMashDateFormatter
