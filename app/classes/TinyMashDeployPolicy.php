<?php
namespace app\classes;

final class TinyMashDeployPolicy {

    public const UPGRADE_PRESERVE_PATHS = [
        'app/config/tinymash.json',
        'data/content',
        'data/drafts',
        'data/media',
        'data/plugins',
        'users',
    ];

    public const UPGRADE_DISCARD_PATHS = [
        'app/config/tinymash.json.lock',
        'data/runtime',
        'tmp',
        'logs',
    ];

    public const UPGRADE_REVIEW_PATHS = [
        'plugins',
        'themes',
    ];

    public const AUDIT_IGNORED_EXACT_PATHS = [
        'app/config/tinymash.json',
        'app/config/tinymash.json.lock',
        'deploy-manifest.json',
    ];

    public const AUDIT_IGNORED_PATH_PREFIXES = [
        'data',
        'logs',
        'tmp',
        'users',
    ];

    public static function isAuditIgnoredPath( string $relative_path ) : bool {
        $relative_path = trim( str_replace( '\\', '/', $relative_path ), '/' );
        if ( in_array( $relative_path, self::AUDIT_IGNORED_EXACT_PATHS, true ) ) {
            return( true );
        }

        foreach ( self::AUDIT_IGNORED_PATH_PREFIXES as $prefix ) {
            if ( $relative_path === $prefix || str_starts_with( $relative_path, $prefix . '/' ) ) {
                return( true );
            }
        }

        return( false );
    }
}
