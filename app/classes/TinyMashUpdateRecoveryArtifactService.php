<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashDeployAuditService.php';

final class TinyMashUpdateRecoveryArtifactService {

    protected const MANIFEST_MAX_BYTES = 8388608;

    protected string $installation_root;
    protected string $recovery_root;

    public function __construct( string $installation_root, string $recovery_root ) {
        $installation_root = rtrim( trim( $installation_root ), DIRECTORY_SEPARATOR );
        $real_app_directory = @ realpath( $installation_root . DIRECTORY_SEPARATOR . 'app' );
        if ( $installation_root === '' || str_contains( $installation_root, "\0" ) || ! is_string( $real_app_directory ) ) {
            throw new \InvalidArgumentException( 'The installation root is unavailable.' );
        }
        $this->installation_root = rtrim( dirname( $real_app_directory ), DIRECTORY_SEPARATOR );

        $recovery_root = rtrim( trim( $recovery_root ), DIRECTORY_SEPARATOR );
        $normalized_recovery_root = str_replace( '\\', '/', $recovery_root );
        $segments = explode( '/', $normalized_recovery_root );
        if ( $recovery_root === ''
            || str_contains( $recovery_root, "\0" )
            || in_array( '..', $segments, true )
            || in_array( '.', $segments, true )
            || ! str_starts_with( $recovery_root, $this->installation_root . DIRECTORY_SEPARATOR )
            || is_link( $recovery_root ) ) {
            throw new \InvalidArgumentException( 'The update recovery path is unavailable.' );
        }
        $this->recovery_root = $recovery_root;
    }

    public function verify( array $journal ) : array {
        $transaction_id = (string) ( $journal['transaction_id'] ?? '' );
        if ( preg_match( '/^[a-f0-9]{32}$/D', $transaction_id ) !== 1 ) {
            throw new \RuntimeException( 'The update recovery transaction is invalid.' );
        }
        $transaction_directory = $this->recovery_root . DIRECTORY_SEPARATOR . 'transaction-' . $transaction_id;
        $this->assertTransactionDirectory( $transaction_directory );

        $code_rollback = $transaction_directory . DIRECTORY_SEPARATOR . 'code-rollback';
        $target_release = $transaction_directory . DIRECTORY_SEPARATOR . 'target-release';
        $site_backup = $transaction_directory . DIRECTORY_SEPARATOR . 'site-backup';
        $code_report = $this->inspectManagedTree( $code_rollback );
        $target_report = $this->inspectManagedTree( $target_release );
        if ( ! hash_equals( (string) ( $journal['installed_version'] ?? '' ), (string) ( $code_report['version'] ?? '' ) )
            || ! hash_equals( (string) ( $journal['target_version'] ?? '' ), (string) ( $target_report['version'] ?? '' ) )
            || ! $this->stepMatchesReport( (array) ( $journal['steps']['code_rollback'] ?? [] ), $code_report )
            || ! $this->stepMatchesReport( (array) ( $journal['steps']['target_release'] ?? [] ), $target_report ) ) {
            throw new \RuntimeException( 'The update recovery files could not be verified.' );
        }
        $this->verifySiteBackupManifest( $site_backup, (array) ( $journal['steps']['site_backup'] ?? [] ) );

        return( [
            'transaction_directory' => $transaction_directory,
            'code_rollback_directory' => $code_rollback,
            'target_release_directory' => $target_release,
            'site_backup_directory' => $site_backup,
        ] );
    }

    public function inspectManagedTree( string $target ) : array {
        $this->assertOwnedArtifactPath( $target, [ 'code-rollback', 'target-release' ] );
        if ( is_link( $target ) || ! is_dir( $target ) ) {
            return( [] );
        }
        try {
            $audit = ( new TinyMashDeployAuditService( $target ) )->audit();
            $manifest = $this->loadDeployManifest( $target );
        } catch ( \Throwable ) {
            return( [] );
        }
        if ( empty( $audit['ok'] ) ) {
            return( [] );
        }
        return( [
            'files' => count( (array) ( $manifest['inventory']['files'] ?? [] ) ),
            'bytes' => array_sum( array_map( static fn( array $file ) : int => (int) ( $file['bytes'] ?? 0 ), (array) ( $manifest['inventory']['files'] ?? [] ) ) ),
            'deploy_manifest_sha256' => hash_file( 'sha256', $target . DIRECTORY_SEPARATOR . 'deploy-manifest.json' ),
            'version' => trim( (string) ( $manifest['application']['version'] ?? '' ) ),
        ] );
    }

    protected function verifySiteBackupManifest( string $site_backup, array $step ) : void {
        $this->assertOwnedArtifactPath( $site_backup, [ 'site-backup' ] );
        $manifest = $site_backup . DIRECTORY_SEPARATOR . 'manifest.json';
        $expected_sha256 = (string) ( $step['manifest_sha256'] ?? '' );
        $actual_sha256 = @ hash_file( 'sha256', $manifest );
        if ( (string) ( $step['status'] ?? '' ) !== 'complete'
            || preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) !== 1
            || is_link( $site_backup )
            || ! is_dir( $site_backup )
            || is_link( $manifest )
            || ! is_file( $manifest )
            || ! is_readable( $manifest )
            || ! is_string( $actual_sha256 )
            || ! hash_equals( $expected_sha256, $actual_sha256 ) ) {
            throw new \RuntimeException( 'The update backup manifest could not be verified.' );
        }
    }

    protected function loadDeployManifest( string $root ) : array {
        $filename = $root . DIRECTORY_SEPARATOR . 'deploy-manifest.json';
        $size = @ filesize( $filename );
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename )
            || ! is_int( $size ) || $size < 2 || $size > self::MANIFEST_MAX_BYTES ) {
            throw new \RuntimeException( 'The deploy manifest is unavailable.' );
        }
        try {
            $manifest = json_decode( (string) file_get_contents( $filename ), true, 64, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            throw new \RuntimeException( 'The deploy manifest is invalid.' );
        }
        if ( ! is_array( $manifest )
            || (string) ( $manifest['format'] ?? '' ) !== 'tinymash-deploy'
            || (int) ( $manifest['format_version'] ?? 0 ) !== 2
            || ! is_array( $manifest['inventory']['files'] ?? null ) ) {
            throw new \RuntimeException( 'The deploy manifest is invalid.' );
        }
        return( $manifest );
    }

    protected function assertTransactionDirectory( string $transaction_directory ) : void {
        if ( dirname( $transaction_directory ) !== $this->recovery_root
            || preg_match( '/^transaction-[a-f0-9]{32}$/D', basename( $transaction_directory ) ) !== 1
            || is_link( $this->recovery_root )
            || ! is_dir( $this->recovery_root )
            || is_link( $transaction_directory )
            || ! is_dir( $transaction_directory ) ) {
            throw new \RuntimeException( 'The update recovery files are unavailable.' );
        }
        $real_root = @ realpath( $this->recovery_root );
        $real_transaction = @ realpath( $transaction_directory );
        if ( ! is_string( $real_root )
            || ! is_string( $real_transaction )
            || ! hash_equals( rtrim( $real_root, DIRECTORY_SEPARATOR ), $this->recovery_root )
            || ! hash_equals( rtrim( $real_transaction, DIRECTORY_SEPARATOR ), $transaction_directory ) ) {
            throw new \RuntimeException( 'The update recovery files are unavailable.' );
        }
    }

    protected function assertOwnedArtifactPath( string $path, array $allowed_names ) : void {
        $transaction_directory = dirname( $path );
        if ( dirname( $transaction_directory ) !== $this->recovery_root
            || preg_match( '/^transaction-[a-f0-9]{32}$/D', basename( $transaction_directory ) ) !== 1
            || ! in_array( basename( $path ), $allowed_names, true ) ) {
            throw new \RuntimeException( 'The update recovery path is invalid.' );
        }
    }

    protected function stepMatchesReport( array $step, array $report ) : bool {
        if ( $report === [] || (string) ( $step['status'] ?? '' ) !== 'complete' ) {
            return( false );
        }
        foreach ( $report as $key => $value ) {
            if ( ! array_key_exists( $key, $step ) || (string) $step[$key] !== (string) $value ) {
                return( false );
            }
        }
        return( true );
    }
}
