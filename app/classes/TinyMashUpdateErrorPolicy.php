<?php
namespace app\classes;

final class TinyMashUpdateErrorPolicy {

    protected const MAX_LOG_BYTES = 240;

    public static function logFailure( string $event, \Throwable $exception ) : void {
        error_log( self::formatFailure( $event, $exception ) );
    }

    public static function formatFailure( string $event, \Throwable $exception ) : string {
        $event = match ( $event ) {
            'check' => 'Update check failed',
            'handoff' => 'Update handoff failed',
            'package' => 'Update package check failed',
            default => 'Update failed',
        };
        $type = self::safeLabel( get_debug_type( $exception ), 64, 'Throwable' );
        $message = 'tinymash updater: ' . $event . ' [' . $type . ']';
        return( substr( $message, 0, self::MAX_LOG_BYTES ) );
    }

    protected static function safeLabel( string $value, int $maximum_bytes, string $fallback ) : string {
        $value = preg_replace( '/[^A-Za-z0-9_.\\ -]+/', '', $value );
        $value = is_string( $value ) ? trim( preg_replace( '/\s+/', ' ', $value ) ?? '' ) : '';
        if ( $value === '' ) {
            return( $fallback );
        }
        return( substr( $value, 0, $maximum_bytes ) );
    }
}
