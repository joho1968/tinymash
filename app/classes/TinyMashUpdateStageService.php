<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashDeployAuditService.php';
require_once __DIR__ . '/TinyMashSafeZipExtractor.php';
require_once __DIR__ . '/TinyMashUpdateFailure.php';
require_once __DIR__ . '/TinyMashUpdateManifestVerifier.php';
require_once __DIR__ . '/TinyMashUpdateReleaseConfig.php';
require_once __DIR__ . '/TinyMashUpdateRequirements.php';
require_once __DIR__ . '/TinyMashUpgradePrepareService.php';

final class TinyMashUpdateStageService {

    protected const STATUS_FORMAT = 'tinymash-update-stage';
    protected const STATUS_SCHEMA = 2;
    protected const MAX_ENTRIES = 50000;
    protected const MAX_EXPANDED_BYTES = 8589934592;
    protected const MIN_EXPANDED_BYTES = 268435456;
    protected const EXPANSION_FACTOR = 20;
    protected const STATUS_MAX_BYTES = 1048576;
    protected const WORKSPACE_NAME_PATTERN = '/^release-(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-[a-f0-9]{16}$/D';

    protected string $workspace_root;
    protected string $lock_filename;
    protected string $installation_root;
    protected string $release_origin;
    protected $downloader;
    protected $clock;

    public function __construct( string $workspace_root, string $installation_root, ?callable $downloader = null, ?callable $clock = null, string $release_origin = TinyMashUpdateReleaseConfig::ORIGIN ) {
        $workspace_root = rtrim( trim( $workspace_root ), DIRECTORY_SEPARATOR );
        if ( $workspace_root === '' || str_contains( $workspace_root, "\0" ) || is_link( $workspace_root ) ) {
            throw new \InvalidArgumentException( 'The update workspace path is invalid.' );
        }
        $workspace_anchor = is_dir( $workspace_root ) ? $workspace_root : dirname( $workspace_root );
        $real_workspace_anchor = @ realpath( $workspace_anchor );
        $normalized_workspace_anchor = str_replace( '\\', '/', rtrim( $workspace_anchor, DIRECTORY_SEPARATOR ) );
        $normalized_real_workspace_anchor = is_string( $real_workspace_anchor )
            ? str_replace( '\\', '/', rtrim( $real_workspace_anchor, DIRECTORY_SEPARATOR ) )
            : '';
        if ( PHP_OS_FAMILY === 'Windows' ) {
            $normalized_workspace_anchor = strtolower( $normalized_workspace_anchor );
            $normalized_real_workspace_anchor = strtolower( $normalized_real_workspace_anchor );
        }
        if ( $normalized_real_workspace_anchor === ''
            || ! hash_equals( $normalized_workspace_anchor, $normalized_real_workspace_anchor )
            || ! is_dir( $real_workspace_anchor )
            || ! is_writable( $real_workspace_anchor ) ) {
            throw new \InvalidArgumentException( 'The update workspace path is invalid.' );
        }
        $installation_root = rtrim( trim( $installation_root ), DIRECTORY_SEPARATOR );
        $real_app_directory = @ realpath( $installation_root . DIRECTORY_SEPARATOR . 'app' );
        if ( $installation_root === '' || str_contains( $installation_root, "\0" ) || ! is_string( $real_app_directory ) || ! is_dir( $real_app_directory ) ) {
            throw new \InvalidArgumentException( 'The installation root is unavailable.' );
        }

        $this->installation_root = rtrim( dirname( $real_app_directory ), DIRECTORY_SEPARATOR );
        $this->workspace_root = $workspace_root;
        $this->lock_filename = $this->installation_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'update-stage.lock';
        $this->release_origin = rtrim( trim( $release_origin ), '/' );
        $this->downloader = $downloader;
        $this->clock = $clock;
        $this->assertReleaseUrl( $this->release_origin . '/stable/index.json' );
    }

    public function getPrerequisites( ?array $loaded_extensions = null ) : array {
        return( TinyMashUpdateRequirements::inspect( $loaded_extensions, ! is_callable( $this->downloader ) ) );
    }

    public function getStatus( array $availability ) : array {
        if ( ! is_dir( $this->workspace_root ) || is_link( $this->workspace_root ) ) {
            return( [] );
        }
        try {
            $lock_handle = $this->openWorkspaceLock( LOCK_SH, false, false );
        } catch ( \Throwable ) {
            return( [] );
        }
        if ( ! is_resource( $lock_handle ) ) {
            return( [] );
        }
        try {
            $release = $this->normalizeAvailableRelease( $availability );
            $workspace = $this->releaseWorkspace( $release );
            return( $this->readValidStatus( $workspace, $release ) );
        } catch ( \Throwable ) {
            return( [] );
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    public function cleanup( array $journal = [] ) : array {
        $result = [
            'directory_exists' => is_dir( $this->workspace_root ) && ! is_link( $this->workspace_root ),
            'removed_workspaces' => 0,
            'preserved_workspaces' => 0,
            'skipped_unknown' => 0,
            'skipped_symlinks' => 0,
            'errors' => 0,
            'removed_directory' => false,
            'busy' => false,
        ];
        if ( ! $result['directory_exists'] ) {
            return( $result );
        }

        try {
            $lock_handle = $this->openWorkspaceLock( LOCK_EX, true, true );
        } catch ( \Throwable ) {
            $result['errors']++;
            return( $result );
        }
        if ( ! is_resource( $lock_handle ) ) {
            $result['busy'] = true;
            return( $result );
        }
        $legacy_lock_handle = null;
        try {
            try {
                $this->ensurePrivateDirectory( $this->workspace_root );
            } catch ( \Throwable ) {
                $result['errors']++;
                return( $result );
            }
            $legacy_lock_handle = $this->openLegacyLock();
            if ( $legacy_lock_handle === false ) {
                $result['busy'] = true;
                return( $result );
            }
            $result = array_merge( $result, $this->cleanupWorkspacesLocked( '', $journal ) );
            $this->removeLegacyLock( $legacy_lock_handle );
            $legacy_lock_handle = null;
            $result['removed_directory'] = $this->removeWorkspaceRootIfEmpty();
            $result['directory_exists'] = is_dir( $this->workspace_root );
            return( $result );
        } finally {
            if ( is_resource( $legacy_lock_handle ) ) {
                @ flock( $legacy_lock_handle, LOCK_UN );
                fclose( $legacy_lock_handle );
            }
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    public function stage( array $availability ) : array {
        TinyMashUpdateRequirements::assertAvailable( null, ! is_callable( $this->downloader ) );
        try {
            $release = $this->normalizeAvailableRelease( $availability );
        } catch ( \Throwable $exception ) {
            throw new TinyMashUpdateFailure( 'update.release_information_invalid', $exception );
        }
        if ( @ is_link( $this->installation_root )
            || ! @ is_dir( $this->installation_root )
            || ! @ is_readable( $this->installation_root )
            || ! @ is_writable( $this->installation_root ) ) {
            throw new TinyMashUpdateFailure( 'update.installation_not_writable' );
        }
        try {
            $installed_audit = ( new TinyMashDeployAuditService( $this->installation_root ) )->audit();
        } catch ( \Throwable $exception ) {
            throw new TinyMashUpdateFailure( 'update.installation_audit_failed', $exception );
        }
        if ( empty( $installed_audit['ok'] ) ) {
            if ( $this->hasOperatorExtensionFiles( $installed_audit ) ) {
                throw new TinyMashUpdateFailure( 'update.operator_extensions' );
            }
            throw new TinyMashUpdateFailure( 'update.installed_files_changed' );
        }
        if ( ! hash_equals( $release['installed_version'], $this->readInstalledVersion() ) ) {
            throw new TinyMashUpdateFailure( 'update.installed_version_changed' );
        }

        $lock_handle = $this->openWorkspaceLock( LOCK_EX, true, true );
        if ( ! is_resource( $lock_handle ) ) {
            throw new TinyMashUpdateFailure( 'update.in_progress' );
        }

        try {
            $this->ensurePrivateDirectory( $this->workspace_root );
            $workspace = $this->releaseWorkspace( $release );
            $legacy_lock_handle = $this->openLegacyLock();
            if ( $legacy_lock_handle === false ) {
                throw new TinyMashUpdateFailure( 'update.in_progress' );
            }
            try {
                $this->cleanupWorkspacesLocked( $workspace );
                $this->removeLegacyLock( $legacy_lock_handle );
                $legacy_lock_handle = null;
            } finally {
                if ( is_resource( $legacy_lock_handle ) ) {
                    @ flock( $legacy_lock_handle, LOCK_UN );
                    fclose( $legacy_lock_handle );
                }
            }
            $existing = $this->readValidStatus( $workspace, $release, true );
            if ( $existing !== [] ) {
                $this->cleanupWorkspacesLocked( $workspace, [], false );
                return( $existing );
            }
            if ( file_exists( $workspace ) || is_link( $workspace ) ) {
                $this->removeOwnedTree( $workspace );
            }

            $expanded_limit = $this->expandedByteLimit( $release['archive']['maximum_bytes'] );
            $this->assertWorkspaceDiskSpace( $release['archive']['maximum_bytes'], $expanded_limit );
            $this->ensurePrivateDirectory( $workspace );
            $extract_directory = $workspace . DIRECTORY_SEPARATOR . 'release';
            $this->ensurePrivateDirectory( $extract_directory );
            $archive_part = $workspace . DIRECTORY_SEPARATOR . 'release.zip.part';
            $archive_filename = $workspace . DIRECTORY_SEPARATOR . 'release.zip';

            try {
                try {
                    $download = $this->downloadArchive( $release['archive']['url'], $archive_part, $release['archive']['maximum_bytes'] );
                } catch ( TinyMashUpdateFailure $exception ) {
                    throw $exception;
                } catch ( \Throwable $exception ) {
                    throw new TinyMashUpdateFailure( 'update.download_failed', $exception );
                }
                if ( (int) ( $download['bytes'] ?? 0 ) < 1
                    || (int) ( $download['bytes'] ?? 0 ) > $release['archive']['maximum_bytes']
                    || ! hash_equals( $release['archive']['sha256'], (string) ( $download['sha256'] ?? '' ) )
                    || ! @ rename( $archive_part, $archive_filename ) ) {
                    throw new TinyMashUpdateFailure( 'update.archive_invalid' );
                }
                @ chmod( $archive_filename, 0600 );

                try {
                    $extraction = ( new TinyMashSafeZipExtractor( self::MAX_ENTRIES, $expanded_limit, true ) )
                        ->extract( $archive_filename, $extract_directory );
                } catch ( \Throwable $exception ) {
                    throw new TinyMashUpdateFailure( 'update.archive_unsafe', $exception );
                }
                $deploy_manifest = $this->verifyDeployManifest( $extract_directory, $release );
                $release_audit = ( new TinyMashDeployAuditService( $extract_directory, '', true ) )->audit();
                if ( empty( $release_audit['ok'] ) ) {
                    throw new TinyMashUpdateFailure( 'update.extracted_release_invalid' );
                }

                $upgrade_inspection = ( new TinyMashUpgradePrepareService( $extract_directory, $this->installation_root, false, true ) )->inspect( false );
                $replacement_check = $this->inspectInPlaceReplacementCapability( $deploy_manifest, $this->loadInstalledDeployManifest() );
                $installation_space_check = $this->inspectInstallationDiskSpace(
                    (int) ( $extraction['expanded_bytes'] ?? 0 ),
                    (int) ( $upgrade_inspection['inventory']['bytes'] ?? 0 )
                );
                $final_installed_audit = ( new TinyMashDeployAuditService( $this->installation_root ) )->audit();
                if ( empty( $final_installed_audit['ok'] ) || ! hash_equals( $release['installed_version'], $this->readInstalledVersion() ) ) {
                    throw new TinyMashUpdateFailure( 'update.installed_files_changed_during_check' );
                }
                $preflight_ok = ! empty( $upgrade_inspection['ok'] ) && $replacement_check['ok'] && $installation_space_check['ok'];

                $status = [
                    'format' => self::STATUS_FORMAT,
                    'schema' => self::STATUS_SCHEMA,
                    'version' => $release['version'],
                    'archive_sha256' => $release['archive']['sha256'],
                    'archive_bytes' => (int) $download['bytes'],
                    'deploy_manifest_sha256' => $release['deploy_manifest_sha256'],
                    'checked_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $this->now() ),
                    'package_ok' => true,
                    'preflight_ok' => $preflight_ok,
                    'checks' => [
                        [ 'check' => 'Installed release', 'ok' => true, 'detail' => 'deploy:audit passed' ],
                        [ 'check' => 'Release archive', 'ok' => true, 'detail' => $this->formatBytes( (int) $download['bytes'] ) . ', SHA-256 verified' ],
                        [ 'check' => 'Archive extraction', 'ok' => true, 'detail' => number_format( (int) $extraction['files'] ) . ' files, ' . $this->formatBytes( (int) $extraction['expanded_bytes'] ) ],
                        [ 'check' => 'Release manifest', 'ok' => true, 'detail' => 'version ' . (string) $deploy_manifest['application']['version'] ],
                        [ 'check' => 'Extracted release', 'ok' => true, 'detail' => 'deploy:audit passed' ],
                        [ 'check' => 'Installation preflight', 'ok' => ! empty( $upgrade_inspection['ok'] ), 'detail' => ! empty( $upgrade_inspection['ok'] ) ? 'passed' : 'failed' ],
                        $replacement_check,
                        $installation_space_check,
                    ],
                    'errors' => array_values( array_unique( (array) ( $upgrade_inspection['errors'] ?? [] ) ) ),
                    'warnings' => array_values( array_unique( (array) ( $upgrade_inspection['warnings'] ?? [] ) ) ),
                    'missing_active_plugins' => array_values( (array) ( $upgrade_inspection['extensions']['missing_active_plugins'] ?? [] ) ),
                    'missing_selected_themes' => array_values( (array) ( $upgrade_inspection['extensions']['missing_selected_themes'] ?? [] ) ),
                ];
                $this->writeStatus( $workspace, $status );
                $this->cleanupWorkspacesLocked( $workspace, [], false );
                return( $status );
            } catch ( \Throwable $exception ) {
                $this->removeOwnedTree( $workspace );
                throw $exception;
            }
        } finally {
            $this->removeWorkspaceRootIfEmpty();
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    public function getPreparedContext( array $availability ) : array {
        return( $this->withPreparedContext( $availability, static fn( array $context ) : array => $context ) );
    }

    public function getRecoveryContext( array $availability ) : array {
        return( $this->withWorkspaceLock(
            fn() : array => $this->readRecoveryContext( $availability )
        ) );
    }

    public function withPreparedContext( array $availability, callable $callback ) : mixed {
        return( $this->withWorkspaceLock(
            function() use ( $availability, $callback ) : mixed {
                $context = $this->readRecoveryContext( $availability );
                $release = (array) $context['release'];
                $installed_audit = ( new TinyMashDeployAuditService( $this->installation_root ) )->audit();
                if ( empty( $installed_audit['ok'] ) || ! hash_equals( $release['installed_version'], $this->readInstalledVersion() ) ) {
                    throw new TinyMashUpdateFailure( 'update.installed_files_changed' );
                }
                $verify = fn() : array => $this->readRecoveryContext( $availability );
                return( $callback( $context, $verify ) );
            }
        ) );
    }

    protected function readRecoveryContext( array $availability ) : array {
        $release = $this->normalizeAvailableRelease( $availability );
        $workspace = $this->releaseWorkspace( $release );
        $status = $this->readValidStatus( $workspace, $release, true );
        if ( $status === [] || empty( $status['preflight_ok'] ) ) {
            throw new \RuntimeException( 'Download and check the release package before preparing the update.' );
        }
        return( [
            'workspace' => $workspace,
            'release_directory' => $workspace . DIRECTORY_SEPARATOR . 'release',
            'release' => $release,
            'status' => $status,
        ] );
    }

    protected function withWorkspaceLock( callable $callback ) : mixed {
        if ( ! is_dir( $this->workspace_root ) || is_link( $this->workspace_root ) ) {
            throw new \RuntimeException( 'Download and check the release package before preparing the update.' );
        }
        $lock_handle = $this->openWorkspaceLock( LOCK_SH, true, false );
        if ( ! is_resource( $lock_handle ) ) {
            throw new TinyMashUpdateFailure( 'update.in_progress' );
        }
        try {
            return( $callback() );
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    protected function normalizeAvailableRelease( array $availability ) : array {
        $release = is_array( $availability['release'] ?? null ) ? $availability['release'] : [];
        $version = trim( (string) ( $release['version'] ?? '' ) );
        $available_version = trim( (string) ( $availability['available_version'] ?? '' ) );
        $installed_version = trim( (string) ( $availability['installed_version'] ?? '' ) );
        $archive = is_array( $release['archive'] ?? null ) ? $release['archive'] : [];
        $maximum_bytes = $archive['maximum_bytes'] ?? null;
        $archive_sha256 = strtolower( trim( (string) ( $archive['sha256'] ?? '' ) ) );
        $deploy_manifest_sha256 = strtolower( trim( (string) ( $release['deploy_manifest_sha256'] ?? '' ) ) );
        if ( (string) ( $availability['status'] ?? '' ) !== 'available'
            || preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version ) !== 1
            || ! hash_equals( $version, $available_version )
            || preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $installed_version ) !== 1
            || version_compare( $version, $installed_version, '<=' )
            || ! is_int( $maximum_bytes )
            || $maximum_bytes < 1
            || $maximum_bytes > TinyMashUpdateManifestVerifier::MAX_ARCHIVE_BYTES
            || preg_match( '/^[a-f0-9]{64}$/D', $archive_sha256 ) !== 1
            || preg_match( '/^[a-f0-9]{64}$/D', $deploy_manifest_sha256 ) !== 1 ) {
            throw new \InvalidArgumentException( 'No verified compatible update is available.' );
        }
        $archive_url = trim( (string) ( $archive['url'] ?? '' ) );
        $this->assertReleaseUrl( $archive_url );
        return( [
            'version' => $version,
            'installed_version' => $installed_version,
            'archive' => [
                'url' => $archive_url,
                'maximum_bytes' => $maximum_bytes,
                'sha256' => $archive_sha256,
            ],
            'deploy_manifest_sha256' => $deploy_manifest_sha256,
        ] );
    }

    protected function downloadArchive( string $url, string $target_filename, int $maximum_bytes ) : array {
        if ( is_callable( $this->downloader ) ) {
            ( $this->downloader )( $url, $target_filename, $maximum_bytes );
            if ( ! is_file( $target_filename ) || is_link( $target_filename ) ) {
                throw new TinyMashUpdateFailure( 'update.download_failed' );
            }
            @ chmod( $target_filename, 0600 );
            $bytes = @ filesize( $target_filename );
            $sha256 = @ hash_file( 'sha256', $target_filename );
            if ( ! is_int( $bytes ) || $bytes < 1 || $bytes > $maximum_bytes || ! is_string( $sha256 ) ) {
                throw new TinyMashUpdateFailure( 'update.archive_invalid' );
            }
            return( [ 'bytes' => $bytes, 'sha256' => $sha256 ] );
        }
        return( $this->downloadRemoteArchive( $url, $target_filename, $maximum_bytes ) );
    }

    protected function downloadRemoteArchive( string $url, string $target_filename, int $maximum_bytes ) : array {
        if ( ! function_exists( 'curl_init' ) ) {
            throw new TinyMashUpdateFailure( 'update.curl_missing' );
        }
        $target_handle = @ fopen( $target_filename, 'xb' );
        if ( $target_handle === false ) {
            throw new TinyMashUpdateFailure( 'update.workspace_unavailable' );
        }
        @ chmod( $target_filename, 0600 );

        $bytes = 0;
        $too_large = false;
        $write_failed = false;
        $hash = hash_init( 'sha256' );
        $curl = curl_init( $url );
        if ( $curl === false ) {
            fclose( $target_handle );
            @ unlink( $target_filename );
            throw new TinyMashUpdateFailure( 'update.download_failed' );
        }
        curl_setopt_array( $curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'tinymash/' . ( defined( 'APP_VERSION' ) ? APP_VERSION : 'dev' ) . ' updater',
            CURLOPT_HTTPHEADER => [ 'Accept: application/zip' ],
            CURLOPT_WRITEFUNCTION => static function( mixed $handle, string $chunk ) use ( $target_handle, &$bytes, &$too_large, &$write_failed, $maximum_bytes, $hash ) : int {
                $length = strlen( $chunk );
                if ( $length > $maximum_bytes - $bytes ) {
                    $too_large = true;
                    return( 0 );
                }
                $written = 0;
                while ( $written < $length ) {
                    $result = fwrite( $target_handle, substr( $chunk, $written ) );
                    if ( ! is_int( $result ) || $result < 1 ) {
                        $write_failed = true;
                        return( 0 );
                    }
                    $written += $result;
                }
                hash_update( $hash, $chunk );
                $bytes += $length;
                return( $length );
            },
        ] );
        $ok = curl_exec( $curl );
        $http_code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        $effective_url = (string) curl_getinfo( $curl, CURLINFO_EFFECTIVE_URL );
        $primary_ip = (string) curl_getinfo( $curl, CURLINFO_PRIMARY_IP );
        curl_close( $curl );
        $flushed = fflush( $target_handle );
        fclose( $target_handle );

        if ( $too_large ) {
            @ unlink( $target_filename );
            throw new TinyMashUpdateFailure( 'update.archive_invalid' );
        }
        if ( $write_failed || ! $flushed || $ok === false || $http_code !== 200 || ! hash_equals( $url, $effective_url ) ) {
            @ unlink( $target_filename );
            throw new TinyMashUpdateFailure( 'update.download_failed' );
        }
        if ( filter_var( $primary_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            @ unlink( $target_filename );
            throw new TinyMashUpdateFailure( 'update.download_failed' );
        }
        return( [ 'bytes' => $bytes, 'sha256' => hash_final( $hash ) ] );
    }

    protected function verifyDeployManifest( string $release_directory, array $release ) : array {
        $filename = $release_directory . DIRECTORY_SEPARATOR . 'deploy-manifest.json';
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename ) ) {
            throw new TinyMashUpdateFailure( 'update.release_manifest_invalid' );
        }
        $sha256 = hash_file( 'sha256', $filename );
        if ( ! is_string( $sha256 ) || ! hash_equals( $release['deploy_manifest_sha256'], $sha256 ) ) {
            throw new TinyMashUpdateFailure( 'update.release_manifest_invalid' );
        }
        $size = @ filesize( $filename );
        if ( ! is_int( $size ) || $size < 1 || $size > 8388608 ) {
            throw new TinyMashUpdateFailure( 'update.release_manifest_invalid' );
        }
        try {
            $manifest = json_decode( (string) file_get_contents( $filename ), true, 64, JSON_THROW_ON_ERROR );
        } catch ( \Throwable $exception ) {
            throw new TinyMashUpdateFailure( 'update.release_manifest_invalid', $exception );
        }
        if ( ! is_array( $manifest )
            || (string) ( $manifest['format'] ?? '' ) !== 'tinymash-deploy'
            || (int) ( $manifest['format_version'] ?? 0 ) !== 2
            || ! hash_equals( $release['version'], trim( (string) ( $manifest['application']['version'] ?? '' ) ) ) ) {
            throw new TinyMashUpdateFailure( 'update.release_manifest_invalid' );
        }
        return( $manifest );
    }

    protected function readInstalledVersion() : string {
        $manifest = $this->loadInstalledDeployManifest();
        return( trim( (string) ( $manifest['application']['version'] ?? '' ) ) );
    }

    protected function loadInstalledDeployManifest() : array {
        $filename = $this->installation_root . DIRECTORY_SEPARATOR . 'deploy-manifest.json';
        $size = @ filesize( $filename );
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename ) || ! is_int( $size ) || $size < 1 || $size > 8388608 ) {
            return( [] );
        }
        $manifest = json_decode( (string) file_get_contents( $filename ), true, 32 );
        return( is_array( $manifest ) ? $manifest : [] );
    }

    protected function releaseWorkspace( array $release ) : string {
        return( $this->workspace_root . DIRECTORY_SEPARATOR . 'release-' . $release['version'] . '-' . substr( $release['archive']['sha256'], 0, 16 ) );
    }

    protected function readValidStatus( string $workspace, array $release, bool $audit_release = false ) : array {
        $status_filename = $workspace . DIRECTORY_SEPARATOR . 'status.json';
        if ( is_link( $workspace ) || ! is_dir( $workspace ) || is_link( $status_filename ) || ! is_file( $status_filename ) || ! is_readable( $status_filename ) ) {
            return( [] );
        }
        $size = @ filesize( $status_filename );
        if ( ! is_int( $size ) || $size < 2 || $size > self::STATUS_MAX_BYTES ) {
            return( [] );
        }
        $status = json_decode( (string) file_get_contents( $status_filename ), true, 32 );
        if ( ! is_array( $status )
            || (string) ( $status['format'] ?? '' ) !== self::STATUS_FORMAT
            || (int) ( $status['schema'] ?? 0 ) !== self::STATUS_SCHEMA
            || ! hash_equals( $release['version'], (string) ( $status['version'] ?? '' ) )
            || ! hash_equals( $release['archive']['sha256'], (string) ( $status['archive_sha256'] ?? '' ) )
            || ! hash_equals( $release['deploy_manifest_sha256'], (string) ( $status['deploy_manifest_sha256'] ?? '' ) )
            || empty( $status['package_ok'] ) ) {
            return( [] );
        }
        try {
            $this->verifyDeployManifest( $workspace . DIRECTORY_SEPARATOR . 'release', $release );
            if ( $audit_release && empty( ( new TinyMashDeployAuditService( $workspace . DIRECTORY_SEPARATOR . 'release', '', true ) )->audit()['ok'] ) ) {
                return( [] );
            }
        } catch ( \Throwable ) {
            return( [] );
        }
        return( $status );
    }

    protected function writeStatus( string $workspace, array $status ) : void {
        $encoded = json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
        $filename = $workspace . DIRECTORY_SEPARATOR . 'status.json';
        $handle = @ fopen( $filename, 'xb' );
        if ( $handle === false ) {
            throw new TinyMashUpdateFailure( 'update.status_store_failed' );
        }
        try {
            $written = fwrite( $handle, $encoded );
            $complete = is_int( $written ) && $written === strlen( $encoded ) && fflush( $handle );
        } finally {
            fclose( $handle );
        }
        @ chmod( $filename, 0600 );
        if ( ! $complete ) {
            @ unlink( $filename );
            throw new TinyMashUpdateFailure( 'update.status_store_failed' );
        }
    }

    protected function expandedByteLimit( int $archive_bytes ) : int {
        return( min( self::MAX_EXPANDED_BYTES, max( self::MIN_EXPANDED_BYTES, $archive_bytes * self::EXPANSION_FACTOR ) ) );
    }

    protected function assertWorkspaceDiskSpace( int $archive_bytes, int $expanded_bytes ) : void {
        $free = @ disk_free_space( $this->workspace_root );
        $required = $archive_bytes + $expanded_bytes + 67108864;
        if ( is_numeric( $free ) && (int) $free < $required ) {
            throw new TinyMashUpdateFailure( 'update.temporary_space' );
        }
    }

    protected function inspectInPlaceReplacementCapability( array $deploy_manifest, array $installed_manifest ) : array {
        $directories = [ $this->installation_root => true ];
        $managed_paths = array_unique( array_merge(
            array_keys( (array) ( $deploy_manifest['inventory']['files'] ?? [] ) ),
            array_keys( (array) ( $installed_manifest['inventory']['files'] ?? [] ) ),
            array_values( (array) ( $deploy_manifest['includes']['empty_directories'] ?? [] ) ),
            array_values( (array) ( $installed_manifest['includes']['empty_directories'] ?? [] ) )
        ) );
        foreach ( $managed_paths as $relative_path ) {
            $relative_path = str_replace( '/', DIRECTORY_SEPARATOR, (string) $relative_path );
            $directory = dirname( $this->installation_root . DIRECTORY_SEPARATOR . $relative_path );
            while ( ! is_dir( $directory ) && $directory !== $this->installation_root ) {
                $directory = dirname( $directory );
            }
            $directories[$directory] = true;
        }
        foreach ( array_keys( $directories ) as $directory ) {
            if ( @ is_link( $directory ) || ! @ is_dir( $directory ) || ! @ is_writable( $directory ) ) {
                return( [ 'check' => 'Managed-file replacement', 'ok' => false, 'detail' => 'A managed application directory is not writable.' ] );
            }
        }

        $token = bin2hex( random_bytes( 8 ) );
        $first = $this->installation_root . DIRECTORY_SEPARATOR . '.tinymash-update-probe-' . $token;
        $second = $first . '-renamed';
        $handle = @ fopen( $first, 'xb' );
        $written = is_resource( $handle ) && fwrite( $handle, 'tinymash' ) === 8 && fflush( $handle );
        if ( is_resource( $handle ) ) {
            fclose( $handle );
        }
        $ok = $written && @ rename( $first, $second ) && @ unlink( $second );
        @ unlink( $first );
        @ unlink( $second );
        return( [
            'check' => 'Managed-file replacement',
            'ok' => $ok,
            'detail' => $ok ? 'supported' : 'The server cannot replace application files in place.',
        ] );
    }

    protected function inspectInstallationDiskSpace( int $release_bytes, int $state_bytes ) : array {
        $free = @ disk_free_space( $this->installation_root );
        $recovery_bytes = ( $release_bytes * 2 ) + $state_bytes;
        $required = $recovery_bytes + max( 67108864, (int) ceil( $recovery_bytes * 0.1 ) );
        $ok = ! is_numeric( $free ) || (int) $free >= $required;
        return( [
            'check' => 'Installation disk space',
            'ok' => $ok,
            'detail' => $ok ? ( is_numeric( $free ) ? $this->formatBytes( (int) $free ) . ' free' : 'not reported' ) : 'insufficient space',
        ] );
    }

    protected function assertReleaseUrl( string $url ) : void {
        $url_parts = parse_url( $url );
        $origin_parts = parse_url( $this->release_origin );
        if ( filter_var( $url, FILTER_VALIDATE_URL ) === false
            || ! is_array( $url_parts )
            || ! is_array( $origin_parts )
            || strtolower( (string) ( $url_parts['scheme'] ?? '' ) ) !== 'https'
            || strtolower( (string) ( $origin_parts['scheme'] ?? '' ) ) !== 'https'
            || trim( (string) ( $origin_parts['host'] ?? '' ) ) === ''
            || strtolower( (string) ( $url_parts['host'] ?? '' ) ) !== strtolower( (string) ( $origin_parts['host'] ?? '' ) )
            || (int) ( $url_parts['port'] ?? 443 ) !== (int) ( $origin_parts['port'] ?? 443 )
            || isset( $url_parts['user'] )
            || isset( $url_parts['pass'] )
            || isset( $url_parts['query'] )
            || isset( $url_parts['fragment'] ) ) {
            throw new \InvalidArgumentException( 'The update endpoint is invalid.' );
        }
    }

    protected function hasOperatorExtensionFiles( array $audit ) : bool {
        foreach ( array_merge( (array) ( $audit['unknown_files'] ?? [] ), (array) ( $audit['unsafe_paths'] ?? [] ) ) as $relative_path ) {
            $relative_path = str_replace( '\\', '/', (string) $relative_path );
            if ( str_starts_with( $relative_path, 'plugins/' ) || str_starts_with( $relative_path, 'themes/' ) ) {
                return( true );
            }
        }
        return( false );
    }

    protected function ensurePrivateDirectory( string $directory ) : void {
        if ( is_link( $directory ) ) {
            throw new TinyMashUpdateFailure( 'update.workspace_not_private' );
        }
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
            throw new TinyMashUpdateFailure( 'update.workspace_unavailable' );
        }
        @ chmod( $directory, 0700 );
        $directory_stat = @ lstat( $directory );
        $real_directory = @ realpath( $directory );
        $normalized_directory = str_replace( '\\', '/', rtrim( $directory, DIRECTORY_SEPARATOR ) );
        $normalized_real_directory = is_string( $real_directory )
            ? str_replace( '\\', '/', rtrim( $real_directory, DIRECTORY_SEPARATOR ) )
            : '';
        if ( PHP_OS_FAMILY === 'Windows' ) {
            $normalized_directory = strtolower( $normalized_directory );
            $normalized_real_directory = strtolower( $normalized_real_directory );
        }
        $process_uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
        if ( ! is_array( $directory_stat )
            || ( (int) ( $directory_stat['mode'] ?? 0 ) & 0170000 ) !== 0040000
            || ( @ fileperms( $directory ) & 0777 ) !== 0700
            || ( is_int( $process_uid ) && (int) ( $directory_stat['uid'] ?? -1 ) !== $process_uid )
            || $normalized_real_directory === ''
            || ! hash_equals( $normalized_directory, $normalized_real_directory ) ) {
            throw new TinyMashUpdateFailure( 'update.workspace_not_private' );
        }
    }

    protected function openWorkspaceLock( int $operation, bool $create, bool $non_blocking ) : mixed {
        if ( is_link( $this->lock_filename ) ) {
            throw new TinyMashUpdateFailure( 'update.workspace_not_private' );
        }
        if ( ! $create && ! is_file( $this->lock_filename ) ) {
            return( null );
        }
        $handle = @ fopen( $this->lock_filename, $create ? 'c+b' : 'r+b' );
        if ( $handle === false ) {
            return( null );
        }
        $path_stat = @ lstat( $this->lock_filename );
        $handle_stat = @ fstat( $handle );
        $process_uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
        $valid = is_array( $path_stat )
            && is_array( $handle_stat )
            && ( (int) ( $path_stat['mode'] ?? 0 ) & 0170000 ) === 0100000
            && (int) ( $path_stat['dev'] ?? -1 ) === (int) ( $handle_stat['dev'] ?? -2 )
            && (int) ( $path_stat['ino'] ?? -1 ) === (int) ( $handle_stat['ino'] ?? -2 )
            && (int) ( $handle_stat['nlink'] ?? 0 ) === 1
            && ( ! is_int( $process_uid ) || (int) ( $handle_stat['uid'] ?? -1 ) === $process_uid )
            && @ chmod( $this->lock_filename, 0600 );
        if ( ! $valid ) {
            fclose( $handle );
            throw new TinyMashUpdateFailure( 'update.workspace_not_private' );
        }
        $lock_operation = $operation | ( $non_blocking ? LOCK_NB : 0 );
        if ( ! @ flock( $handle, $lock_operation ) ) {
            fclose( $handle );
            return( null );
        }
        return( $handle );
    }

    protected function openLegacyLock() : mixed {
        $filename = $this->workspace_root . DIRECTORY_SEPARATOR . 'stage.lock';
        if ( is_link( $filename ) || ! file_exists( $filename ) ) {
            return( is_link( $filename ) ? false : null );
        }
        if ( ! is_file( $filename ) ) {
            return( false );
        }
        $handle = @ fopen( $filename, 'r+b' );
        if ( $handle === false || ! @ flock( $handle, LOCK_EX | LOCK_NB ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
            return( false );
        }
        return( $handle );
    }

    protected function removeLegacyLock( mixed $handle ) : void {
        if ( ! is_resource( $handle ) ) {
            return;
        }
        @ unlink( $this->workspace_root . DIRECTORY_SEPARATOR . 'stage.lock' );
        @ flock( $handle, LOCK_UN );
        fclose( $handle );
    }

    protected function cleanupWorkspacesLocked( string $keep_workspace = '', array $journal = [], bool $preserve_valid = true ) : array {
        $result = [
            'removed_workspaces' => 0,
            'preserved_workspaces' => 0,
            'skipped_unknown' => 0,
            'skipped_symlinks' => 0,
            'errors' => 0,
        ];
        $items = @ scandir( $this->workspace_root );
        if ( ! is_array( $items ) ) {
            $result['errors']++;
            return( $result );
        }
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' || $item === 'stage.lock' ) {
                continue;
            }
            $path = $this->workspace_root . DIRECTORY_SEPARATOR . $item;
            if ( is_link( $path ) ) {
                $result['skipped_symlinks']++;
                continue;
            }
            if ( preg_match( self::WORKSPACE_NAME_PATTERN, $item ) !== 1 || ! is_dir( $path ) ) {
                $result['skipped_unknown']++;
                continue;
            }
            if ( $path === $keep_workspace || ( $preserve_valid && $this->shouldPreserveWorkspace( $path, $journal ) ) ) {
                $result['preserved_workspaces']++;
                continue;
            }
            $this->removeOwnedTree( $path );
            if ( ! file_exists( $path ) && ! is_link( $path ) ) {
                $result['removed_workspaces']++;
            } else {
                $result['errors']++;
            }
        }
        return( $result );
    }

    protected function shouldPreserveWorkspace( string $workspace, array $journal ) : bool {
        $status_filename = $workspace . DIRECTORY_SEPARATOR . 'status.json';
        $size = @ filesize( $status_filename );
        if ( is_link( $status_filename ) || ! is_file( $status_filename ) || ! is_readable( $status_filename )
            || ! is_int( $size ) || $size < 2 || $size > self::STATUS_MAX_BYTES ) {
            return( false );
        }
        $status = json_decode( (string) file_get_contents( $status_filename ), true, 32 );
        if ( ! is_array( $status ) ) {
            return( false );
        }
        $version = (string) ( $status['version'] ?? '' );
        $archive_sha256 = (string) ( $status['archive_sha256'] ?? '' );
        if ( (string) ( $status['format'] ?? '' ) !== self::STATUS_FORMAT
            || (int) ( $status['schema'] ?? 0 ) !== self::STATUS_SCHEMA
            || empty( $status['package_ok'] )
            || preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version ) !== 1
            || preg_match( '/^[a-f0-9]{64}$/D', $archive_sha256 ) !== 1
            || basename( $workspace ) !== 'release-' . $version . '-' . substr( $archive_sha256, 0, 16 ) ) {
            return( false );
        }
        $matches_terminal_journal = hash_equals( $version, (string) ( $journal['target_version'] ?? '' ) )
            && hash_equals( $archive_sha256, (string) ( $journal['archive_sha256'] ?? '' ) )
            && in_array( (string) ( $journal['phase'] ?? '' ), [ 'complete', 'rolled_back' ], true );
        return( ! $matches_terminal_journal );
    }

    protected function removeWorkspaceRootIfEmpty() : bool {
        if ( is_link( $this->workspace_root ) || ! is_dir( $this->workspace_root ) ) {
            return( false );
        }
        $items = @ scandir( $this->workspace_root );
        if ( ! is_array( $items ) || array_values( array_diff( $items, [ '.', '..' ] ) ) !== [] ) {
            return( false );
        }
        return( @ rmdir( $this->workspace_root ) );
    }

    protected function removeOwnedTree( string $path ) : void {
        if ( dirname( $path ) !== $this->workspace_root
            || preg_match( self::WORKSPACE_NAME_PATTERN, basename( $path ) ) !== 1 ) {
            throw new \RuntimeException( 'Refusing to remove an unmanaged update path.' );
        }
        if ( is_link( $path ) || is_file( $path ) ) {
            @ unlink( $path );
            return;
        }
        if ( ! is_dir( $path ) ) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( $item->isLink() || $item->isFile() ) {
                @ unlink( $item->getPathname() );
            } else {
                @ rmdir( $item->getPathname() );
            }
        }
        @ rmdir( $path );
    }

    protected function now() : int {
        return( is_callable( $this->clock ) ? (int) ( $this->clock )() : time() );
    }

    protected function formatBytes( int $bytes ) : string {
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
        $value = max( 0, $bytes );
        $unit = 0;
        while ( $value >= 1024 && $unit < count( $units ) - 1 ) {
            $value /= 1024;
            $unit++;
        }
        return( $unit === 0 ? (string) (int) $value . ' B' : number_format( $value, 1 ) . ' ' . $units[$unit] );
    }
}
