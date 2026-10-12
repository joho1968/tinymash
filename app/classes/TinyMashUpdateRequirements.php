<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashUpdateFailure.php';

final class TinyMashUpdateRequirements {

    protected const EXTENSIONS = [
        'curl' => 'update.curl_missing',
        'sodium' => 'update.sodium_missing',
        'zip' => 'update.zip_missing',
    ];

    public static function getRequiredExtensions() : array {
        return( array_keys( self::EXTENSIONS ) );
    }

    public static function inspect( ?array $loaded_extensions = null, bool $require_curl = true ) : array {
        $loaded_extensions ??= get_loaded_extensions();
        $loaded = [];
        foreach ( $loaded_extensions as $extension ) {
            $extension = strtolower( trim( (string) $extension ) );
            if ( $extension !== '' ) {
                $loaded[$extension] = true;
            }
        }

        $checks = [];
        foreach ( self::EXTENSIONS as $extension => $failure_code ) {
            $required = $extension !== 'curl' || $require_curl;
            $is_loaded = isset( $loaded[$extension] );
            $checks[] = [
                'extension' => $extension,
                'required' => $required,
                'loaded' => $is_loaded,
                'satisfied' => ! $required || $is_loaded,
                'failure_code' => $failure_code,
            ];
        }

        $missing = array_values(
            array_filter(
                $checks,
                static fn( array $check ) : bool => empty( $check['satisfied'] )
            )
        );
        return( [
            'ok' => $missing === [],
            'checks' => $checks,
            'missing' => $missing,
        ] );
    }

    public static function assertAvailable( ?array $loaded_extensions = null, bool $require_curl = true ) : void {
        $inspection = self::inspect( $loaded_extensions, $require_curl );
        $first_missing = $inspection['missing'][0] ?? null;
        if ( is_array( $first_missing ) ) {
            throw new TinyMashUpdateFailure( (string) $first_missing['failure_code'] );
        }
    }
}
