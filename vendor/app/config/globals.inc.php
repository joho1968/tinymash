<?php

const APP_NAME          = 'tinymash';
const APP_VERSION       = '0.92.1';
const APP_TITLE_SEP     = ' - ';
const APP_ROOT_PATH     = __DIR__ . '/..';
const APP_VIEWS_PATH    = APP_ROOT_PATH . '/views';

if ( ! function_exists( 'tinymash_normalize_umask' ) ) {
    function tinymash_normalize_umask( mixed $mask, string $default = '0007' ) : string {
        $default = preg_match( '/^[0-7]{1,4}$/', $default ) === 1 ? str_pad( $default, 4, '0', STR_PAD_LEFT ) : '0007';

        if ( is_int( $mask ) ) {
            $mask = decoct( $mask );
        } elseif ( ! is_string( $mask ) ) {
            return( $default );
        }

        $mask = strtolower( trim( $mask ) );
        $mask = preg_replace( '/^0o/', '', $mask ) ?? $mask;
        if ( preg_match( '/^[0-7]{1,4}$/', $mask ) !== 1 ) {
            return( $default );
        }

        $mask = str_pad( $mask, 4, '0', STR_PAD_LEFT );
        return( octdec( $mask ) <= octdec( '0077' ) ? $mask : $default );
    }
}

if ( ! function_exists( 'tinymash_apply_runtime_umask' ) ) {
    function tinymash_apply_runtime_umask( mixed $mask, string $default = '0007' ) : string {
        $normalized_mask = tinymash_normalize_umask( $mask, $default );
        umask( octdec( $normalized_mask ) );
        return( $normalized_mask );
    }
}

tinymash_apply_runtime_umask( '0007' );

if ( ! function_exists( 'tinymash_get_runtime_temp_directory' ) ) {
    function tinymash_get_runtime_temp_directory() : string {
        $candidates = [];

        $upload_tmp_directory = trim( (string) ini_get( 'upload_tmp_dir' ) );
        if ( $upload_tmp_directory !== '' ) {
            $candidates[] = $upload_tmp_directory;
        }

        foreach ( [ 'TMPDIR', 'TMP', 'TEMP' ] as $environment_variable ) {
            $value = trim( (string) getenv( $environment_variable ) );
            if ( $value !== '' ) {
                $candidates[] = $value;
            }
        }

        $system_temp_directory = trim( (string) sys_get_temp_dir() );
        if ( $system_temp_directory !== '' ) {
            $candidates[] = $system_temp_directory;
        }

        $project_temp_directory = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'tmp';
        $candidates[] = $project_temp_directory;

        foreach ( array_values( array_unique( $candidates ) ) as $candidate ) {
            $candidate = rtrim( trim( (string) $candidate ), DIRECTORY_SEPARATOR );
            if ( $candidate === '' ) {
                continue;
            }

            if ( ! is_dir( $candidate ) && ! @ mkdir( $candidate, 0775, true ) && ! is_dir( $candidate ) ) {
                continue;
            }

            if ( is_dir( $candidate ) && is_writable( $candidate ) ) {
                return( $candidate );
            }
        }

        return( $project_temp_directory );
    }
}

if ( ! function_exists( 'tinymash_get_runtime_temp_subdirectory' ) ) {
    function tinymash_get_runtime_temp_subdirectory( string $subdirectory ) : string {
        $base_directory = tinymash_get_runtime_temp_directory();
        $subdirectory = trim( str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR, $subdirectory ), DIRECTORY_SEPARATOR );
        if ( $subdirectory === '' ) {
            return( $base_directory );
        }

        $target_directory = $base_directory . DIRECTORY_SEPARATOR . $subdirectory;
        if ( ! is_dir( $target_directory ) ) {
            @ mkdir( $target_directory, 0775, true );
        }

        return( is_dir( $target_directory ) && is_writable( $target_directory ) ? $target_directory : $base_directory );
    }
}
