<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashUpdateFailure.php';

final class TinyMashUpdateErrorPolicy {

    protected const MAX_LOG_BYTES = 240;
    protected const FAILURES = [
        'update.archive_invalid' => 'The downloaded release did not match the signed release information. Try again later.',
        'update.archive_unsafe' => 'The release package contains unsupported or unsafe files. Do not install it.',
        'update.curl_missing' => 'Live Update requires PHP\'s cURL extension. Enable it before trying again.',
        'update.download_failed' => 'The release package could not be downloaded. Check outbound HTTPS access and try again.',
        'update.extracted_release_invalid' => 'The extracted release did not pass its integrity check. Do not install it.',
        'update.in_progress' => 'Another update check is already running. Wait for it to finish and try again.',
        'update.installation_audit_failed' => 'The installed tinymash files could not be verified. Run deploy:audit before trying again.',
        'update.installation_not_writable' => 'Live Update cannot write to the tinymash installation. Check filesystem permissions and PHP path restrictions.',
        'update.installed_files_changed' => 'Managed tinymash files have changed since deployment. Run deploy:audit and restore the reported files, or update manually.',
        'update.installed_files_changed_during_check' => 'Managed tinymash files changed while the package was being checked. Reload Updates and try again.',
        'update.installed_version_changed' => 'The installed tinymash version changed after the update check. Reload Updates and try again.',
        'update.operator_extensions' => 'Site-installed plugins or themes require a manual update.',
        'update.package_check_failed' => 'The release package could not be checked. Review the PHP log or update manually.',
        'update.release_information_invalid' => 'The verified release information is no longer valid. Reload Updates and try again.',
        'update.release_manifest_invalid' => 'The release package contains an invalid deployment manifest. Do not install it.',
        'update.sodium_missing' => 'Live Update requires PHP\'s Sodium extension. Enable it before trying again.',
        'update.status_store_failed' => 'The package passed its checks, but tinymash could not save the result. Check temporary-directory permissions.',
        'update.temporary_space' => 'There is not enough temporary disk space to check this release.',
        'update.workspace_not_private' => 'The update workspace does not have private permissions. Check the PHP temporary-directory configuration.',
        'update.workspace_unavailable' => 'Live Update cannot create its private temporary workspace. Check temporary-directory permissions.',
        'update.zip_missing' => 'Live Update requires PHP\'s ZIP extension. Enable it before trying again.',
    ];

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
        $detail = $exception instanceof TinyMashUpdateFailure
            ? $exception->getReasonCode()
            : get_debug_type( $exception );
        $detail = self::safeLabel( $detail, 96, 'Throwable' );
        $message = 'tinymash updater: ' . $event . ' [' . $detail . ']';
        return( substr( $message, 0, self::MAX_LOG_BYTES ) );
    }

    public static function describeFailure( \Throwable $exception ) : array {
        $reason_code = $exception instanceof TinyMashUpdateFailure
            ? $exception->getReasonCode()
            : 'update.package_check_failed';
        return( self::describeReason( $reason_code ) );
    }

    public static function describeReason( string $reason_code ) : array {
        if ( ! array_key_exists( $reason_code, self::FAILURES ) ) {
            $reason_code = 'update.package_check_failed';
        }
        return( [
            'code' => $reason_code,
            'message' => self::FAILURES[$reason_code],
        ] );
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
