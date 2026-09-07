<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashDeployAuditService.php';
require_once __DIR__ . '/TinyMashAuthoritativeStateInventory.php';
require_once __DIR__ . '/TinyMashFilesystem.php';
require_once __DIR__ . '/TinyMashUpdateJournal.php';
require_once __DIR__ . '/TinyMashUpdateRecoveryArtifactService.php';
require_once __DIR__ . '/TinyMashUpdateStageService.php';

final class TinyMashUpdatePreparationService {

    protected const WORKER_RUNTIME_FILES = [
        'app/classes/TinyMashAuthoritativeStateInventory.php',
        'app/classes/TinyMashDeployAuditService.php',
        'app/classes/TinyMashDeployPolicy.php',
        'app/classes/TinyMashUpdateCliRecoveryService.php',
        'app/classes/TinyMashUpdateHealthService.php',
        'app/classes/TinyMashUpdateInstallerService.php',
        'app/classes/TinyMashUpdateJournal.php',
        'app/classes/TinyMashUpdateRecoveryArtifactService.php',
        'app/classes/TinyMashUpdateRecoveryEntryPoint.php',
        'app/classes/TinyMashUpdateRecoveryWorker.php',
        'app/classes/TinyMashWriterGate.php',
    ];

    protected TinyMashUpdateStageService $stage_service;
    protected TinyMashUpdateJournal $journal;
    protected TinyMashUpdateRecoveryArtifactService $recovery_artifacts;
    protected string $installation_root;
    protected string $recovery_root;
    protected $site_backup_builder;
    protected $site_backup_verifier;
    protected $clock;

    public function __construct(
        TinyMashUpdateStageService $stage_service,
        TinyMashUpdateJournal $journal,
        string $installation_root,
        string $recovery_root,
        callable $site_backup_builder,
        callable $site_backup_verifier,
        ?callable $clock = null
    ) {
        $installation_root = rtrim( trim( $installation_root ), DIRECTORY_SEPARATOR );
        $real_app_directory = @ realpath( $installation_root . DIRECTORY_SEPARATOR . 'app' );
        if ( $installation_root === '' || str_contains( $installation_root, "\0" ) || ! is_string( $real_app_directory ) ) {
            throw new \InvalidArgumentException( 'The installation root is unavailable.' );
        }
        $recovery_root = rtrim( trim( $recovery_root ), DIRECTORY_SEPARATOR );
        if ( $recovery_root === '' || str_contains( $recovery_root, "\0" ) || is_link( $recovery_root ) ) {
            throw new \InvalidArgumentException( 'The update recovery path is unavailable.' );
        }
        $this->stage_service = $stage_service;
        $this->journal = $journal;
        $this->installation_root = rtrim( dirname( $real_app_directory ), DIRECTORY_SEPARATOR );
        $this->recovery_root = $recovery_root;
        $this->recovery_artifacts = new TinyMashUpdateRecoveryArtifactService( $this->installation_root, $this->recovery_root );
        $this->site_backup_builder = $site_backup_builder;
        $this->site_backup_verifier = $site_backup_verifier;
        $this->clock = $clock;
    }

    public function getStatus() : array {
        return( $this->journal->read() );
    }

    public function verifyRecoveryArtifacts( array $journal ) : array {
        $artifacts = $this->recovery_artifacts->verify( $journal );
        $worker_report = $this->inspectWorkerRuntime( (string) $artifacts['transaction_directory'] . DIRECTORY_SEPARATOR . 'worker-runtime' );
        $site_report = $this->inspectSiteBackup( (string) $artifacts['site_backup_directory'] );
        if ( ! $this->stepMatchesReport( (array) ( $journal['steps']['worker_runtime'] ?? [] ), $worker_report )
            || ! $this->stepMatchesReport( (array) ( $journal['steps']['site_backup'] ?? [] ), $site_report ) ) {
            throw new \RuntimeException( 'The update recovery files could not be verified.' );
        }
        return( $artifacts );
    }

    public function prepare( array $availability ) : array {
        $context = $this->stage_service->getPreparedContext( $availability );
        return( $this->journal->mutate(
            function( array $journal, callable $save ) use ( $availability, $context ) : array {
                $release = (array) $context['release'];
                if ( $journal === [] ) {
                    $journal = TinyMashUpdateJournal::create(
                        (string) $release['installed_version'],
                        (string) $release['version'],
                        (string) $release['archive']['sha256'],
                        $this->now()
                    );
                    $save( $journal );
                } elseif ( ! $this->journalMatchesRelease( $journal, $release ) ) {
                    $journal = $this->rotateTerminalTransaction( $journal, $release, $save );
                }
                $this->assertMatchingJournal( $journal, $release );

                $this->ensurePrivateDirectory( $this->recovery_root );
                $transaction_directory = $this->recovery_root . DIRECTORY_SEPARATOR . 'transaction-' . (string) $journal['transaction_id'];
                $this->ensurePrivateDirectory( $transaction_directory );
                try {
                    $journal = $this->prepareCodeRollback( $journal, $save, $transaction_directory );
                    $journal = $this->prepareWorkerRuntime( $journal, $save, $transaction_directory );
                    $journal = $this->prepareTargetRelease( $journal, $save, $transaction_directory, (string) $context['release_directory'] );
                    $journal = $this->prepareSiteBackup(
                        $journal,
                        $save,
                        $transaction_directory
                    );
                    $this->stage_service->getPreparedContext( $availability );
                    $journal['phase'] = 'prepared';
                    $journal['error'] = '';
                    $journal['updated_at_utc'] = $this->timestamp();
                    $save( $journal );
                    return( $journal );
                } catch ( \Throwable $exception ) {
                    $journal['phase'] = 'failed';
                    $journal['error'] = 'Update preparation failed.';
                    $journal['updated_at_utc'] = $this->timestamp();
                    $save( $journal );
                    throw $exception;
                }
            }
        ) );
    }

    protected function prepareCodeRollback( array $journal, callable $save, string $transaction_directory ) : array {
        $target = $transaction_directory . DIRECTORY_SEPARATOR . 'code-rollback';
        if ( (string) ( $journal['steps']['code_rollback']['status'] ?? '' ) === 'complete' ) {
            $report = $this->recovery_artifacts->inspectManagedTree( $target );
            if ( $this->stepMatchesReport( (array) $journal['steps']['code_rollback'], $report ) ) {
                return( $journal );
            }
        }

        $journal['phase'] = 'preparing';
        $journal['steps']['code_rollback'] = [ 'status' => 'running' ];
        $journal['updated_at_utc'] = $this->timestamp();
        $save( $journal );
        $this->removeOwnedArtifact( $target, $transaction_directory );
        $report = $this->createCodeRollback( $target );
        $journal['steps']['code_rollback'] = array_merge( [ 'status' => 'complete' ], $report );
        $journal['updated_at_utc'] = $this->timestamp();
        $save( $journal );
        return( $journal );
    }

    protected function prepareWorkerRuntime( array $journal, callable $save, string $transaction_directory ) : array {
        $target = $transaction_directory . DIRECTORY_SEPARATOR . 'worker-runtime';
        if ( (string) ( $journal['steps']['worker_runtime']['status'] ?? '' ) === 'complete' ) {
            $report = $this->inspectWorkerRuntime( $target );
            if ( $this->stepMatchesReport( (array) $journal['steps']['worker_runtime'], $report ) ) {
                return( $journal );
            }
        }

        $journal['phase'] = 'preparing';
        $journal['steps']['worker_runtime'] = [ 'status' => 'running' ];
        $journal['updated_at_utc'] = $this->timestamp();
        $save( $journal );
        $this->removeOwnedArtifact( $target, $transaction_directory );
        $this->ensurePrivateDirectory( $target );
        try {
            $installed_manifest = $this->loadDeployManifest( $this->installation_root );
            $runtime_inventory = [];
            foreach ( self::WORKER_RUNTIME_FILES as $relative_path ) {
                $metadata = is_array( $installed_manifest['inventory']['files'][$relative_path] ?? null )
                    ? $installed_manifest['inventory']['files'][$relative_path]
                    : [];
                if ( $metadata === [] ) {
                    throw new \RuntimeException( 'The installed release does not contain the recovery worker runtime.' );
                }
                $this->copyManagedFile( $relative_path, $metadata, $this->installation_root, $target );
                $runtime_inventory[$relative_path] = [
                    'bytes' => (int) ( $metadata['bytes'] ?? 0 ),
                    'sha256' => (string) ( $metadata['sha256'] ?? '' ),
                ];
            }
            $this->writeWorkerRuntimeManifest( $target, $runtime_inventory );
            $report = $this->inspectWorkerRuntime( $target );
            if ( $report === [] ) {
                throw new \RuntimeException( 'The recovery worker runtime could not be verified.' );
            }
        } catch ( \Throwable $exception ) {
            $this->removeOwnedArtifact( $target, $transaction_directory );
            throw $exception;
        }
        $journal['steps']['worker_runtime'] = array_merge( [ 'status' => 'complete' ], $report );
        $journal['updated_at_utc'] = $this->timestamp();
        $save( $journal );
        return( $journal );
    }

    protected function prepareTargetRelease( array $journal, callable $save, string $transaction_directory, string $release_directory ) : array {
        $target = $transaction_directory . DIRECTORY_SEPARATOR . 'target-release';
        if ( (string) ( $journal['steps']['target_release']['status'] ?? '' ) === 'complete' ) {
            $report = $this->recovery_artifacts->inspectManagedTree( $target );
            if ( $this->stepMatchesReport( (array) $journal['steps']['target_release'], $report ) ) {
                return( $journal );
            }
        }

        $journal['phase'] = 'preparing';
        $journal['steps']['target_release'] = [ 'status' => 'running' ];
        $journal['updated_at_utc'] = $this->timestamp();
        $save( $journal );
        $this->removeOwnedArtifact( $target, $transaction_directory );
        $report = $this->createManagedCopy( $release_directory, $target );
        if ( ! hash_equals( (string) $journal['target_version'], (string) ( $report['version'] ?? '' ) ) ) {
            $this->removeOwnedArtifact( $target, $transaction_directory );
            throw new \RuntimeException( 'The checked release version changed during preparation.' );
        }
        $journal['steps']['target_release'] = array_merge( [ 'status' => 'complete' ], $report );
        $journal['updated_at_utc'] = $this->timestamp();
        $save( $journal );
        return( $journal );
    }

    protected function prepareSiteBackup( array $journal, callable $save, string $transaction_directory ) : array {
        $target = $transaction_directory . DIRECTORY_SEPARATOR . 'site-backup';
        if ( (string) ( $journal['steps']['site_backup']['status'] ?? '' ) === 'complete' ) {
            try {
                $report = $this->inspectSiteBackup( $target );
                $current_inventory = $this->authoritativeInventory();
            } catch ( \Throwable ) {
                $report = [];
                $current_inventory = [];
            }
            if ( $this->stepMatchesReport( (array) $journal['steps']['site_backup'], $report )
                && isset( $current_inventory['sha256'] )
                && hash_equals( (string) ( $journal['steps']['site_backup']['state_inventory_sha256'] ?? '' ), (string) $current_inventory['sha256'] ) ) {
                return( $journal );
            }
        }

        $journal['phase'] = 'preparing';
        $journal['steps']['site_backup'] = [ 'status' => 'running' ];
        $journal['updated_at_utc'] = $this->timestamp();
        $save( $journal );
        $this->removeOwnedArtifact( $target, $transaction_directory );
        $this->ensurePrivateDirectory( $target );

        try {
            $before = $this->authoritativeInventory();
            ( $this->site_backup_builder )( $target );
            $report = $this->inspectSiteBackup( $target );
            $after = $this->authoritativeInventory();
            if ( ! hash_equals( $before['sha256'], $after['sha256'] ) ) {
                throw new \RuntimeException( 'Site data changed while the update backup was being created.' );
            }
            $report['state_inventory_sha256'] = $after['sha256'];
            $report['state_files'] = $after['files'];
            $report['state_bytes'] = $after['bytes'];
        } catch ( \Throwable $exception ) {
            $this->removeOwnedArtifact( $target, $transaction_directory );
            throw $exception;
        }

        $journal['steps']['site_backup'] = array_merge( [ 'status' => 'complete' ], $report );
        $journal['updated_at_utc'] = $this->timestamp();
        $save( $journal );
        return( $journal );
    }

    protected function createCodeRollback( string $target ) : array {
        return( $this->createManagedCopy( $this->installation_root, $target ) );
    }

    protected function createManagedCopy( string $source_root, string $target ) : array {
        $audit = ( new TinyMashDeployAuditService( $source_root ) )->audit();
        if ( empty( $audit['ok'] ) ) {
            throw new \RuntimeException( 'An update recovery source changed before it could be copied.' );
        }
        $manifest = $this->loadDeployManifest( $source_root );
        $this->ensurePrivateDirectory( $target );
        $files = 0;
        $bytes = 0;
        try {
            foreach ( (array) ( $manifest['inventory']['files'] ?? [] ) as $relative_path => $metadata ) {
                $relative_path = $this->normalizeRelativePath( (string) $relative_path );
                $metadata = is_array( $metadata ) ? $metadata : [];
                $this->copyManagedFile( $relative_path, $metadata, $source_root, $target );
                $files++;
                $bytes += (int) ( $metadata['bytes'] ?? 0 );
            }
            foreach ( (array) ( $manifest['includes']['empty_directories'] ?? [] ) as $relative_path ) {
                $this->ensurePrivateDirectory( $target . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $this->normalizeRelativePath( (string) $relative_path ) ) );
            }
            $this->copyManifest( $source_root, $target );
            $rollback_audit = ( new TinyMashDeployAuditService( $target ) )->audit();
            if ( empty( $rollback_audit['ok'] ) ) {
                throw new \RuntimeException( 'An update recovery copy failed its deploy audit.' );
            }
        } catch ( \Throwable $exception ) {
            $this->removeOwnedArtifact( $target, dirname( $target ) );
            throw $exception;
        }
        return( [
            'files' => $files,
            'bytes' => $bytes,
            'deploy_manifest_sha256' => hash_file( 'sha256', $target . DIRECTORY_SEPARATOR . 'deploy-manifest.json' ),
            'version' => trim( (string) ( $manifest['application']['version'] ?? '' ) ),
        ] );
    }

    protected function inspectSiteBackup( string $target ) : array {
        if ( is_link( $target ) || ! is_dir( $target ) ) {
            return( [] );
        }
        $inspection = ( $this->site_backup_verifier )( $target );
        if ( ! is_array( $inspection ) || (string) ( $inspection['scope'] ?? '' ) !== 'site' ) {
            throw new \RuntimeException( 'The update backup could not be verified.' );
        }
        $manifest_filename = $target . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest_sha256 = @ hash_file( 'sha256', $manifest_filename );
        if ( ! is_string( $manifest_sha256 ) || preg_match( '/^[a-f0-9]{64}$/D', $manifest_sha256 ) !== 1 ) {
            throw new \RuntimeException( 'The update backup manifest could not be verified.' );
        }
        return( [
            'files' => max( 0, (int) ( $inspection['file_count'] ?? 0 ) ),
            'bytes' => max( 0, (int) ( $inspection['expanded_size_bytes'] ?? 0 ) ),
            'manifest_sha256' => $manifest_sha256,
        ] );
    }

    protected function inspectWorkerRuntime( string $target ) : array {
        $transaction_directory = dirname( $target );
        if ( dirname( $transaction_directory ) !== $this->recovery_root
            || preg_match( '/^transaction-[a-f0-9]{32}$/D', basename( $transaction_directory ) ) !== 1
            || basename( $target ) !== 'worker-runtime'
            || is_link( $target )
            || ! is_dir( $target ) ) {
            return( [] );
        }
        $manifest_filename = $target . DIRECTORY_SEPARATOR . 'manifest.json';
        $size = @ filesize( $manifest_filename );
        if ( is_link( $manifest_filename ) || ! is_file( $manifest_filename ) || ! is_readable( $manifest_filename )
            || ! is_int( $size ) || $size < 2 || $size > 65536 ) {
            return( [] );
        }
        try {
            $manifest = json_decode( (string) file_get_contents( $manifest_filename ), true, 32, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            return( [] );
        }
        $files = is_array( $manifest['files'] ?? null ) ? $manifest['files'] : [];
        if ( (string) ( $manifest['format'] ?? '' ) !== 'tinymash-update-worker-runtime'
            || (int) ( $manifest['schema'] ?? 0 ) !== 1
            || array_keys( $files ) !== self::WORKER_RUNTIME_FILES ) {
            return( [] );
        }
        $bytes = 0;
        foreach ( $files as $relative_path => $metadata ) {
            $metadata = is_array( $metadata ) ? $metadata : [];
            $expected_bytes = $metadata['bytes'] ?? null;
            $expected_sha256 = (string) ( $metadata['sha256'] ?? '' );
            $filename = $target . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
            if ( ! is_int( $expected_bytes )
                || $expected_bytes < 1
                || preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) !== 1
                || is_link( $filename )
                || ! is_file( $filename )
                || ! is_readable( $filename )
                || @ filesize( $filename ) !== $expected_bytes
                || ! hash_equals( $expected_sha256, (string) @ hash_file( 'sha256', $filename ) ) ) {
                return( [] );
            }
            $bytes += $expected_bytes;
        }
        return( [
            'files' => count( $files ),
            'bytes' => $bytes,
            'manifest_sha256' => (string) hash_file( 'sha256', $manifest_filename ),
        ] );
    }

    protected function writeWorkerRuntimeManifest( string $target, array $files ) : void {
        $payload = json_encode( [
            'format' => 'tinymash-update-worker-runtime',
            'schema' => 1,
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
        $filename = $target . DIRECTORY_SEPARATOR . 'manifest.json';
        $handle = @ fopen( $filename, 'xb' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'The recovery worker manifest could not be stored.' );
        }
        @ chmod( $filename, 0600 );
        try {
            $written = fwrite( $handle, $payload );
            $complete = is_int( $written ) && $written === strlen( $payload ) && fflush( $handle );
        } finally {
            fclose( $handle );
        }
        if ( ! $complete ) {
            @ unlink( $filename );
            throw new \RuntimeException( 'The recovery worker manifest could not be stored.' );
        }
    }

    protected function authoritativeInventory() : array {
        $inventory = ( new TinyMashAuthoritativeStateInventory( $this->installation_root ) )->inspect();
        return( [
            'sha256' => (string) $inventory['sha256'],
            'files' => max( 0, (int) ( $inventory['files'] ?? 0 ) ),
            'bytes' => max( 0, (int) ( $inventory['bytes'] ?? 0 ) ),
        ] );
    }

    protected function copyManagedFile( string $relative_path, array $metadata, string $source_root, string $target_root ) : void {
        $expected_bytes = $metadata['bytes'] ?? null;
        $expected_sha256 = strtolower( trim( (string) ( $metadata['sha256'] ?? '' ) ) );
        if ( ! is_int( $expected_bytes ) || $expected_bytes < 0 || preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) !== 1 ) {
            throw new \RuntimeException( 'The installed deploy manifest contains invalid file metadata.' );
        }
        $source = TinyMashFilesystem::resolveManagedReadableFile( $source_root, $relative_path, true );
        if ( $source === '' ) {
            throw new \RuntimeException( 'A managed application file is unavailable.' );
        }
        $target = $target_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
        $this->ensurePrivateDirectory( dirname( $target ) );
        $source_handle = @ fopen( $source, 'rb' );
        $target_handle = @ fopen( $target, 'xb' );
        if ( $source_handle === false || $target_handle === false ) {
            if ( is_resource( $source_handle ) ) {
                fclose( $source_handle );
            }
            if ( is_resource( $target_handle ) ) {
                fclose( $target_handle );
            }
            throw new \RuntimeException( 'A managed application file could not be copied.' );
        }
        @ chmod( $target, 0600 );
        $hash = hash_init( 'sha256' );
        $bytes = 0;
        try {
            while ( ! feof( $source_handle ) ) {
                $chunk = fread( $source_handle, 1048576 );
                if ( ! is_string( $chunk ) || ( $chunk === '' && ! feof( $source_handle ) ) ) {
                    throw new \RuntimeException( 'A managed application file could not be read.' );
                }
                if ( $chunk === '' ) {
                    break;
                }
                $offset = 0;
                while ( $offset < strlen( $chunk ) ) {
                    $written = fwrite( $target_handle, substr( $chunk, $offset ) );
                    if ( ! is_int( $written ) || $written < 1 ) {
                        throw new \RuntimeException( 'A managed application file could not be copied.' );
                    }
                    $offset += $written;
                }
                hash_update( $hash, $chunk );
                $bytes += strlen( $chunk );
            }
            $flushed = fflush( $target_handle );
        } finally {
            fclose( $source_handle );
            fclose( $target_handle );
        }
        if ( ! $flushed || $bytes !== $expected_bytes || ! hash_equals( $expected_sha256, hash_final( $hash ) ) ) {
            throw new \RuntimeException( 'A managed application file changed while it was being copied.' );
        }
    }

    protected function copyManifest( string $source_root, string $target_root ) : void {
        $source = $source_root . DIRECTORY_SEPARATOR . 'deploy-manifest.json';
        if ( is_link( $source ) || ! is_file( $source ) || ! is_readable( $source ) ) {
            throw new \RuntimeException( 'The installed deploy manifest is unavailable.' );
        }
        $target = $target_root . DIRECTORY_SEPARATOR . 'deploy-manifest.json';
        if ( ! @ copy( $source, $target ) ) {
            throw new \RuntimeException( 'The installed deploy manifest could not be copied.' );
        }
        @ chmod( $target, 0600 );
    }

    protected function loadDeployManifest( string $root ) : array {
        $filename = $root . DIRECTORY_SEPARATOR . 'deploy-manifest.json';
        $size = @ filesize( $filename );
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename ) || ! is_int( $size ) || $size < 2 || $size > 8388608 ) {
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

    protected function assertMatchingJournal( array $journal, array $release ) : void {
        if ( ! $this->journalMatchesRelease( $journal, $release ) ) {
            throw new \RuntimeException( 'Another update transaction is already recorded.' );
        }
    }

    protected function journalMatchesRelease( array $journal, array $release ) : bool {
        return( hash_equals( (string) $release['installed_version'], (string) ( $journal['installed_version'] ?? '' ) )
            && hash_equals( (string) $release['version'], (string) ( $journal['target_version'] ?? '' ) )
            && hash_equals( (string) $release['archive']['sha256'], (string) ( $journal['archive_sha256'] ?? '' ) ) );
    }

    protected function rotateTerminalTransaction( array $journal, array $release, callable $save ) : array {
        if ( ! in_array( (string) ( $journal['phase'] ?? '' ), [ 'complete', 'rolled_back' ], true ) ) {
            throw new \RuntimeException( 'Another update transaction is already recorded.' );
        }
        $maintenance_filename = $this->installation_root . DIRECTORY_SEPARATOR . 'data/runtime/update-maintenance.json';
        if ( file_exists( $maintenance_filename ) || is_link( $maintenance_filename ) ) {
            throw new \RuntimeException( 'The completed update still has maintenance state.' );
        }

        $transaction_id = (string) ( $journal['transaction_id'] ?? '' );
        $transaction_directory = $this->recovery_root . DIRECTORY_SEPARATOR . 'transaction-' . $transaction_id;
        $this->removeTerminalTransactionDirectory( $transaction_directory );

        $replacement = TinyMashUpdateJournal::create(
            (string) $release['installed_version'],
            (string) $release['version'],
            (string) $release['archive']['sha256'],
            $this->now()
        );
        $save( $replacement );
        return( $replacement );
    }

    protected function removeTerminalTransactionDirectory( string $transaction_directory ) : void {
        if ( dirname( $transaction_directory ) !== $this->recovery_root
            || preg_match( '/^transaction-[a-f0-9]{32}$/D', basename( $transaction_directory ) ) !== 1
            || is_link( $this->recovery_root )
            || is_link( $transaction_directory ) ) {
            throw new \RuntimeException( 'The completed update recovery path is unsafe.' );
        }
        if ( ! file_exists( $transaction_directory ) ) {
            return;
        }
        if ( ! is_dir( $transaction_directory ) ) {
            throw new \RuntimeException( 'The completed update recovery path is unsafe.' );
        }
        $real_root = @ realpath( $this->recovery_root );
        $real_transaction = @ realpath( $transaction_directory );
        if ( ! is_string( $real_root )
            || ! is_string( $real_transaction )
            || ! hash_equals( rtrim( $real_root, DIRECTORY_SEPARATOR ), $this->recovery_root )
            || ! hash_equals( rtrim( $real_transaction, DIRECTORY_SEPARATOR ), $transaction_directory ) ) {
            throw new \RuntimeException( 'The completed update recovery path is unsafe.' );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $transaction_directory, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            $removed = $item->isLink() || $item->isFile()
                ? @ unlink( $item->getPathname() )
                : @ rmdir( $item->getPathname() );
            if ( ! $removed ) {
                throw new \RuntimeException( 'The completed update recovery files could not be removed.' );
            }
        }
        if ( ! @ rmdir( $transaction_directory ) ) {
            throw new \RuntimeException( 'The completed update recovery files could not be removed.' );
        }
    }

    protected function stepMatchesReport( array $step, array $report ) : bool {
        if ( $report === [] ) {
            return( false );
        }
        foreach ( $report as $key => $value ) {
            if ( ! array_key_exists( $key, $step ) || (string) $step[$key] !== (string) $value ) {
                return( false );
            }
        }
        return( true );
    }

    protected function normalizeRelativePath( string $relative_path ) : string {
        $relative_path = str_replace( '\\', '/', trim( $relative_path ) );
        if ( $relative_path === ''
            || strlen( $relative_path ) > 4096
            || str_starts_with( $relative_path, '/' )
            || preg_match( '/^[A-Za-z]:\//', $relative_path ) === 1
            || preg_match( '/[\x00-\x1F\x7F]/', $relative_path ) === 1 ) {
            throw new \RuntimeException( 'The deploy manifest contains an unsafe path.' );
        }
        foreach ( explode( '/', $relative_path ) as $segment ) {
            if ( $segment === '' || $segment === '.' || $segment === '..' ) {
                throw new \RuntimeException( 'The deploy manifest contains an unsafe path.' );
            }
        }
        return( $relative_path );
    }

    protected function ensurePrivateDirectory( string $directory ) : void {
        if ( is_link( $directory ) ) {
            throw new \RuntimeException( 'Update recovery directories must not be links.' );
        }
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'An update recovery directory could not be created.' );
        }
        @ chmod( $directory, 0700 );
        if ( ( @ fileperms( $directory ) & 0777 ) !== 0700 ) {
            throw new \RuntimeException( 'An update recovery directory is not private.' );
        }
    }

    protected function removeOwnedArtifact( string $path, string $transaction_directory ) : void {
        if ( dirname( $path ) !== $transaction_directory || ! in_array( basename( $path ), [ 'code-rollback', 'worker-runtime', 'target-release', 'site-backup' ], true ) ) {
            throw new \RuntimeException( 'Refusing to remove an unmanaged update artifact.' );
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

    protected function timestamp() : string {
        return( gmdate( 'Y-m-d\TH:i:s\Z', $this->now() ) );
    }
}
