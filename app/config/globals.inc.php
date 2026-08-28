<?php

const APP_NAME          = 'tinymash';
const APP_VERSION       = '0.98.1';
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

if ( ! defined( 'TINYMASH_RUNTIME_TEMP_HINT_FILENAME' ) ) {
    define(
        'TINYMASH_RUNTIME_TEMP_HINT_FILENAME',
        dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '.tinymash-runtime-temp.json'
    );
}

if ( ! function_exists( 'tinymash_get_configured_runtime_temp_candidates' ) ) {
    function tinymash_get_configured_runtime_temp_candidates() : array {
        $candidates = [];

        $system_temp_directory = trim( (string) ini_get( 'sys_temp_dir' ) );
        if ( $system_temp_directory !== '' ) {
            $candidates[] = $system_temp_directory;
        }

        foreach ( [ 'TMP', 'TMPDIR', 'TEMP' ] as $environment_variable ) {
            $value = trim( (string) getenv( $environment_variable ) );
            if ( $value !== '' ) {
                $candidates[] = $value;
            }
        }

        $project_temp_directory = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'tmp';
        $candidates[] = $project_temp_directory;

        return( array_values( array_unique( $candidates ) ) );
    }
}

if ( ! function_exists( 'tinymash_get_runtime_temp_project_key' ) ) {
    function tinymash_get_runtime_temp_project_key() : string {
        $project_root = dirname( __DIR__, 2 );
        $real_project_root = realpath( $project_root );
        if ( is_string( $real_project_root ) && $real_project_root !== '' ) {
            $project_root = $real_project_root;
        }

        return( hash( 'sha256', str_replace( '\\', '/', rtrim( $project_root, DIRECTORY_SEPARATOR ) ) ) );
    }
}

if ( ! function_exists( 'tinymash_get_runtime_temp_process_uid' ) ) {
    function tinymash_get_runtime_temp_process_uid() : ?int {
        if ( ! function_exists( 'posix_geteuid' ) ) {
            return( null );
        }

        $uid = posix_geteuid();
        return( is_int( $uid ) ? $uid : null );
    }
}

if ( ! function_exists( 'tinymash_canonical_runtime_temp_directory' ) ) {
    function tinymash_canonical_runtime_temp_directory( string $directory ) : string {
        $directory = rtrim( trim( $directory ), DIRECTORY_SEPARATOR );
        if ( $directory === '' || str_contains( $directory, "\0" ) || is_link( $directory ) ) {
            return( '' );
        }
        if (
            ! str_starts_with( $directory, DIRECTORY_SEPARATOR )
            && preg_match( '/^[a-z]:[\\/\\\\]/i', $directory ) !== 1
        ) {
            return( '' );
        }

        $real_directory = realpath( $directory );
        if ( ! is_string( $real_directory ) || $real_directory === '' || ! is_dir( $real_directory ) || ! is_writable( $real_directory ) ) {
            return( '' );
        }

        $normalized_directory = str_replace( '\\', '/', rtrim( $directory, DIRECTORY_SEPARATOR ) );
        $normalized_real_directory = str_replace( '\\', '/', rtrim( $real_directory, DIRECTORY_SEPARATOR ) );
        if ( PHP_OS_FAMILY === 'Windows' ) {
            $normalized_directory = strtolower( $normalized_directory );
            $normalized_real_directory = strtolower( $normalized_real_directory );
        }

        return( $normalized_directory === $normalized_real_directory ? $real_directory : '' );
    }
}

if ( ! function_exists( 'tinymash_read_runtime_temp_hint' ) ) {
    function tinymash_read_runtime_temp_hint() : string {
        $hint_filename = (string) TINYMASH_RUNTIME_TEMP_HINT_FILENAME;
        $process_uid = tinymash_get_runtime_temp_process_uid();
        if ( PHP_OS_FAMILY !== 'Windows' && ! is_int( $process_uid ) ) {
            return( '' );
        }

        $path_stat = @ lstat( $hint_filename );
        if ( ! is_array( $path_stat ) || ( (int) ( $path_stat['mode'] ?? 0 ) & 0170000 ) !== 0100000 ) {
            return( '' );
        }
        if ( is_int( $process_uid ) ) {
            if ( (int) ( $path_stat['uid'] ?? -1 ) !== $process_uid || ( (int) ( $path_stat['mode'] ?? 0 ) & 0077 ) !== 0 ) {
                return( '' );
            }
        }

        $handle = @ fopen( $hint_filename, 'rb' );
        if ( $handle === false ) {
            return( '' );
        }

        try {
            $handle_stat = fstat( $handle );
            if (
                ! is_array( $handle_stat )
                || (int) ( $handle_stat['dev'] ?? -1 ) !== (int) ( $path_stat['dev'] ?? -2 )
                || (int) ( $handle_stat['ino'] ?? -1 ) !== (int) ( $path_stat['ino'] ?? -2 )
                || ( (int) ( $handle_stat['mode'] ?? 0 ) & 0170000 ) !== 0100000
            ) {
                return( '' );
            }
            if ( is_int( $process_uid ) ) {
                if ( (int) ( $handle_stat['uid'] ?? -1 ) !== $process_uid || ( (int) ( $handle_stat['mode'] ?? 0 ) & 0077 ) !== 0 ) {
                    return( '' );
                }
            }

            $size = (int) ( $handle_stat['size'] ?? 0 );
            if ( $size < 2 || $size > 4096 ) {
                return( '' );
            }

            $encoded_hint = stream_get_contents( $handle, 4097 );
        } finally {
            fclose( $handle );
        }
        if ( ! is_string( $encoded_hint ) || $encoded_hint === '' ) {
            return( '' );
        }

        $hint = json_decode( $encoded_hint, true, 8 );
        if (
            ! is_array( $hint )
            || (string) ( $hint['format'] ?? '' ) !== 'tinymash-runtime-temp'
            || (int) ( $hint['format_version'] ?? 0 ) !== 1
            || (string) ( $hint['project_key'] ?? '' ) !== tinymash_get_runtime_temp_project_key()
        ) {
            return( '' );
        }
        if ( is_int( $process_uid ) && ( ! is_int( $hint['owner_uid'] ?? null ) || (int) $hint['owner_uid'] !== $process_uid ) ) {
            return( '' );
        }
        if ( ! is_int( $process_uid ) && ( $hint['owner_uid'] ?? null ) !== null ) {
            return( '' );
        }

        return( tinymash_canonical_runtime_temp_directory( (string) ( $hint['directory'] ?? '' ) ) );
    }
}

if ( ! function_exists( 'tinymash_get_runtime_temp_candidates' ) ) {
    function tinymash_get_runtime_temp_candidates() : array {
        $candidates = [];
        if ( PHP_SAPI === 'cli' ) {
            $hint_directory = tinymash_read_runtime_temp_hint();
            if ( $hint_directory !== '' ) {
                $candidates[] = $hint_directory;
            }
        }

        return(
            array_values(
                array_unique(
                    array_merge( $candidates, tinymash_get_configured_runtime_temp_candidates() )
                )
            )
        );
    }
}

if ( ! function_exists( 'tinymash_prepare_runtime_temp_directory' ) ) {
    function tinymash_prepare_runtime_temp_directory( string $directory, bool $private = false ) : bool {
        if ( is_link( $directory ) ) {
            return( false );
        }

        if ( ! is_dir( $directory ) ) {
            if ( ! @ mkdir( $directory, $private ? 0700 : 0775, true ) ) {
                return( false );
            }
        }
        if ( is_link( $directory ) ) {
            return( false );
        }
        if ( tinymash_canonical_runtime_temp_directory( $directory ) === '' ) {
            return( false );
        }
        if ( $private ) {
            $process_uid = tinymash_get_runtime_temp_process_uid();
            if ( is_int( $process_uid ) ) {
                $directory_owner_uid = @ fileowner( $directory );
                if ( ! is_int( $directory_owner_uid ) || $directory_owner_uid !== $process_uid ) {
                    return( false );
                }
            }
            if ( ! @ chmod( $directory, 0700 ) ) {
                return( false );
            }

            $permissions = @ fileperms( $directory );
            if ( ! is_int( $permissions ) || ( $permissions & 0077 ) !== 0 ) {
                return( false );
            }
        }

        return( is_dir( $directory ) && is_writable( $directory ) );
    }
}

if ( ! function_exists( 'tinymash_get_runtime_temp_directory' ) ) {
    function tinymash_get_runtime_temp_directory() : string {
        $project_temp_directory = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'tmp';

        foreach ( tinymash_get_runtime_temp_candidates() as $candidate ) {
            $candidate = rtrim( trim( (string) $candidate ), DIRECTORY_SEPARATOR );
            if ( $candidate === '' ) {
                continue;
            }

            if ( tinymash_prepare_runtime_temp_directory( $candidate ) ) {
                return( $candidate );
            }
        }

        return( $project_temp_directory );
    }
}

if ( ! function_exists( 'tinymash_sync_runtime_temp_hint' ) ) {
    function tinymash_sync_runtime_temp_hint() : bool {
        if ( ! class_exists( 'app\\classes\\TinyMashFilesystem', false ) ) {
            return( false );
        }

        $project_temp_directory = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'tmp';
        if ( ! tinymash_prepare_runtime_temp_directory( $project_temp_directory ) ) {
            return( false );
        }

        $hint_filename = (string) TINYMASH_RUNTIME_TEMP_HINT_FILENAME;
        if (
            dirname( $hint_filename ) !== $project_temp_directory
            || basename( $hint_filename ) === ''
            || is_link( $hint_filename )
        ) {
            return( false );
        }

        $process_uid = tinymash_get_runtime_temp_process_uid();
        if ( PHP_OS_FAMILY !== 'Windows' && ! is_int( $process_uid ) ) {
            return( false );
        }
        if ( is_file( $hint_filename ) && is_int( $process_uid ) ) {
            $hint_owner_uid = @ fileowner( $hint_filename );
            if ( ! is_int( $hint_owner_uid ) || $hint_owner_uid !== $process_uid ) {
                return( false );
            }
            if ( ! @ chmod( $hint_filename, 0600 ) ) {
                return( false );
            }
        }

        $selected_directory = '';
        foreach ( tinymash_get_configured_runtime_temp_candidates() as $candidate ) {
            $candidate = rtrim( trim( (string) $candidate ), DIRECTORY_SEPARATOR );
            if ( $candidate === '' || ! tinymash_prepare_runtime_temp_directory( $candidate ) ) {
                continue;
            }

            $selected_directory = tinymash_canonical_runtime_temp_directory( $candidate );
            if ( $selected_directory !== '' ) {
                break;
            }
        }
        if ( $selected_directory === '' ) {
            return( false );
        }

        $current_hint_directory = tinymash_read_runtime_temp_hint();
        if ( $current_hint_directory === $selected_directory ) {
            return( true );
        }

        $hint = [
            'format' => 'tinymash-runtime-temp',
            'format_version' => 1,
            'project_key' => tinymash_get_runtime_temp_project_key(),
            'directory' => $selected_directory,
            'owner_uid' => $process_uid,
            'updated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
        ];
        $encoded_hint = json_encode( $hint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded_hint ) || $encoded_hint === '' ) {
            return( false );
        }

        $previous_umask = umask( 0077 );
        try {
            $written = \app\classes\TinyMashFilesystem::writeFileAtomically( $hint_filename, $encoded_hint . PHP_EOL );
        } finally {
            umask( $previous_umask );
        }
        if ( ! $written || ! @ chmod( $hint_filename, 0600 ) ) {
            return( false );
        }

        clearstatcache( true, $hint_filename );
        return( tinymash_read_runtime_temp_hint() === $selected_directory );
    }
}

if ( ! function_exists( 'tinymash_get_runtime_temp_subdirectory' ) ) {
    function tinymash_get_runtime_temp_subdirectory( string $subdirectory ) : string {
        $subdirectory = trim( str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR, $subdirectory ), DIRECTORY_SEPARATOR );
        if ( $subdirectory === '' ) {
            return( tinymash_get_runtime_temp_directory() );
        }
        if ( preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $subdirectory ) !== 1 ) {
            throw new InvalidArgumentException( 'Invalid runtime temporary subdirectory.' );
        }

        foreach ( tinymash_get_runtime_temp_candidates() as $candidate ) {
            $base_directory = rtrim( trim( (string) $candidate ), DIRECTORY_SEPARATOR );
            if ( $base_directory === '' || ! tinymash_prepare_runtime_temp_directory( $base_directory ) ) {
                continue;
            }

            $target_directory = $base_directory . DIRECTORY_SEPARATOR . $subdirectory;
            if ( tinymash_prepare_runtime_temp_directory( $target_directory, true ) ) {
                return( $target_directory );
            }
        }

        return( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $subdirectory );
    }
}
