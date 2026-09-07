<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashDeployAuditService.php';
require_once __DIR__ . '/TinyMashAuthoritativeStateInventory.php';
require_once __DIR__ . '/TinyMashUpdateJournal.php';
require_once __DIR__ . '/TinyMashWriterGate.php';

final class TinyMashUpdateInstallerService {

    protected const MANIFEST_MAX_BYTES = 8388608;
    protected const CRITICAL_PATHS = [
        'app/classes/TinyMashWriterGate.php' => 80,
        'app/config/bootstrap.php' => 90,
        'public/index.php' => 100,
    ];

    protected TinyMashUpdateJournal $journal;
    protected TinyMashWriterGate $writer_gate;
    protected string $installation_root;
    protected $recovery_artifacts;
    protected $prepare_update;
    protected $clock;
    protected TinyMashAuthoritativeStateInventory $state_inventory;

    public function __construct(
        TinyMashUpdateJournal $journal,
        TinyMashWriterGate $writer_gate,
        string $installation_root,
        callable $recovery_artifacts,
        ?callable $prepare_update = null,
        ?callable $clock = null
    ) {
        $installation_root = rtrim( trim( $installation_root ), DIRECTORY_SEPARATOR );
        $real_app_directory = @ realpath( $installation_root . DIRECTORY_SEPARATOR . 'app' );
        if ( $installation_root === '' || str_contains( $installation_root, "\0" ) || ! is_string( $real_app_directory ) ) {
            throw new \InvalidArgumentException( 'The installation root is unavailable.' );
        }
        $this->journal = $journal;
        $this->writer_gate = $writer_gate;
        $this->installation_root = rtrim( dirname( $real_app_directory ), DIRECTORY_SEPARATOR );
        $this->recovery_artifacts = $recovery_artifacts;
        $this->prepare_update = $prepare_update;
        $this->clock = $clock;
        $this->state_inventory = new TinyMashAuthoritativeStateInventory( $this->installation_root );
    }

    public function replaceManagedFiles( array $availability = [], ?callable $checkpoint = null ) : array {
        $journal = $this->journal->read();
        $this->assertForwardState( $journal );
        $transaction_id = (string) $journal['transaction_id'];
        $lease = $this->writer_gate->acquireExclusive( $transaction_id, true, $availability !== [] );

        try {
            $journal = $this->journal->read();
            $this->assertTransaction( $journal, $transaction_id );
            $this->assertForwardState( $journal );
            $managed = (array) ( $journal['steps']['managed_files'] ?? [] );
            if ( (string) ( $managed['status'] ?? '' ) === 'waiting' ) {
                if ( $availability === [] || ! is_callable( $this->prepare_update ) ) {
                    throw new \RuntimeException( 'Verified release details are required to start managed-file replacement.' );
                }
                $journal = ( $this->prepare_update )( $availability );
                if ( ! is_array( $journal ) ) {
                    throw new \RuntimeException( 'Update preparation returned an invalid result.' );
                }
                $inventory = $this->state_inventory->inspect();
                $journal = $this->recordBackupRecheck( $journal, (string) $inventory['sha256'] );
            }
            return( $this->installManagedFiles( $journal, $checkpoint ) );
        } catch ( \Throwable $exception ) {
            $this->recordFailure( $transaction_id, 'Managed-file replacement failed.' );
            throw $exception;
        } finally {
            $lease->release();
        }
    }

    public function startPreparedManagedFiles( ?callable $checkpoint = null ) : array {
        $journal = $this->journal->read();
        $this->assertPreparedStartState( $journal );
        $transaction_id = (string) $journal['transaction_id'];
        $lease = $this->writer_gate->acquireExclusive( $transaction_id );
        $reopened = false;

        try {
            $journal = $this->journal->read();
            $this->assertPreparedStartState( $journal );
            try {
                $inventory = $this->state_inventory->inspect();
                $journal = $this->recordBackupRecheck( $journal, (string) $inventory['sha256'] );
            } catch ( \Throwable $exception ) {
                $message = $exception->getMessage() === 'Site data changed after the update backup was created.'
                    ? $exception->getMessage()
                    : 'Site data could not be verified against the update backup.';
                $this->recordPreparedStartFailure( $transaction_id, $message );
                $this->writer_gate->finishMaintenance( $lease, $transaction_id );
                $reopened = true;
                throw $exception;
            }
            return( $this->installManagedFiles( $journal, $checkpoint ) );
        } catch ( \Throwable $exception ) {
            if ( ! $reopened ) {
                $this->recordFailure( $transaction_id, 'Managed-file replacement failed.' );
            }
            throw $exception;
        } finally {
            $lease->release();
        }
    }

    public function rollbackManagedFiles( ?callable $checkpoint = null ) : array {
        $journal = $this->journal->read();
        $this->assertRollbackState( $journal );
        if ( (string) $journal['phase'] === 'rolled_back' && $this->writer_gate->getMaintenanceStatus() === [] ) {
            return( $journal );
        }
        $transaction_id = (string) $journal['transaction_id'];
        $lease = $this->writer_gate->acquireExclusive( $transaction_id, true, false );

        try {
            $journal = $this->journal->read();
            $this->assertTransaction( $journal, $transaction_id );
            $this->assertRollbackState( $journal );
            $artifacts = ( $this->recovery_artifacts )( $journal );
            if ( ! is_array( $artifacts ) ) {
                throw new \RuntimeException( 'The update recovery files are unavailable.' );
            }
            $old_root = (string) $artifacts['code_rollback_directory'];
            $new_root = (string) $artifacts['target_release_directory'];
            $this->assertAuditedTree( $old_root, 'The code rollback copy is invalid.' );
            $this->assertAuditedTree( $new_root, 'The checked release is invalid.' );

            $plan = $this->buildPlan( $new_root, $old_root );
            $journal = $this->initializePlan( $journal, $plan, 'rollback' );
            $journal = $this->runPlan( $journal, $plan, $old_root, $checkpoint );
            $this->removeOperationArtifacts( $plan, $transaction_id );
            $audit = ( new TinyMashDeployAuditService( $this->installation_root ) )->audit();
            if ( empty( $audit['ok'] ) ) {
                throw new \RuntimeException( 'The restored application failed deploy:audit.' );
            }
            $journal = $this->recordOpcacheInvalidation( $journal, $old_root, 'installed' );
            $journal = $this->journal->mutate(
                function( array $current, callable $save ) use ( $transaction_id, $plan ) : array {
                    $this->assertTransaction( $current, $transaction_id );
                    $this->assertPlanState( $current, $plan, 'rollback' );
                    $current['phase'] = 'rolled_back';
                    $current['steps']['managed_files']['status'] = 'complete';
                    $current['steps']['managed_files']['current'] = [];
                    $current['steps']['final_audit'] = [ 'status' => 'complete', 'release' => 'installed' ];
                    $current['error'] = '';
                    $current['updated_at_utc'] = $this->timestamp();
                    $save( $current );
                    return( $current );
                }
            );
            $this->writer_gate->finishMaintenance( $lease, $transaction_id );
            $this->checkpoint( $checkpoint, 'rolled_back', count( $plan ), [ 'action' => 'audit', 'path' => '' ] );
            return( $journal );
        } catch ( \Throwable $exception ) {
            $this->recordFailure( $transaction_id, 'Managed-file rollback failed.' );
            throw $exception;
        } finally {
            $lease->release();
        }
    }

    protected function installManagedFiles( array $journal, ?callable $checkpoint ) : array {
        $transaction_id = (string) $journal['transaction_id'];
        $artifacts = ( $this->recovery_artifacts )( $journal );
        if ( ! is_array( $artifacts ) ) {
            throw new \RuntimeException( 'The update recovery files are unavailable.' );
        }
        $old_root = (string) $artifacts['code_rollback_directory'];
        $new_root = (string) $artifacts['target_release_directory'];
        $this->assertAuditedTree( $old_root, 'The code rollback copy is invalid.' );
        $this->assertAuditedTree( $new_root, 'The checked release is invalid.' );

        $plan = $this->buildPlan( $old_root, $new_root );
        $journal = $this->initializePlan( $journal, $plan, 'forward' );
        $journal = $this->runPlan( $journal, $plan, $new_root, $checkpoint );
        $audit = ( new TinyMashDeployAuditService( $this->installation_root ) )->audit();
        if ( empty( $audit['ok'] ) ) {
            throw new \RuntimeException( 'The updated application failed deploy:audit.' );
        }
        $journal = $this->recordOpcacheInvalidation( $journal, $new_root, 'target' );
        $journal = $this->journal->mutate(
            function( array $current, callable $save ) use ( $transaction_id, $plan ) : array {
                $this->assertTransaction( $current, $transaction_id );
                $this->assertPlanState( $current, $plan, 'forward' );
                $current['phase'] = 'files_replaced';
                $current['steps']['managed_files']['status'] = 'complete';
                $current['steps']['managed_files']['current'] = [];
                $current['steps']['final_audit'] = [ 'status' => 'complete', 'release' => 'target' ];
                $current['error'] = '';
                $current['updated_at_utc'] = $this->timestamp();
                $save( $current );
                return( $current );
            }
        );
        $this->checkpoint( $checkpoint, 'complete', count( $plan ), [ 'action' => 'audit', 'path' => '' ] );
        return( $journal );
    }

    protected function recordBackupRecheck( array $journal, string $current_state_sha256 ) : array {
        $transaction_id = (string) $journal['transaction_id'];
        $state_sha256 = (string) ( $journal['steps']['site_backup']['state_inventory_sha256'] ?? '' );
        if ( preg_match( '/^[a-f0-9]{64}$/D', $state_sha256 ) !== 1
            || preg_match( '/^[a-f0-9]{64}$/D', $current_state_sha256 ) !== 1 ) {
            throw new \RuntimeException( 'The update backup state is invalid.' );
        }
        if ( ! hash_equals( $state_sha256, $current_state_sha256 ) ) {
            throw new \RuntimeException( 'Site data changed after the update backup was created.' );
        }
        return( $this->journal->mutate(
            function( array $current, callable $save ) use ( $transaction_id, $state_sha256 ) : array {
                $this->assertTransaction( $current, $transaction_id );
                $current['steps']['backup_recheck'] = [
                    'status' => 'complete',
                    'state_inventory_sha256' => $state_sha256,
                ];
                $current['updated_at_utc'] = $this->timestamp();
                $save( $current );
                return( $current );
            }
        ) );
    }

    protected function initializePlan( array $journal, array $plan, string $direction ) : array {
        $transaction_id = (string) $journal['transaction_id'];
        $plan_sha256 = $this->planSha256( $plan );
        $managed = (array) ( $journal['steps']['managed_files'] ?? [] );
        if ( (string) ( $managed['status'] ?? '' ) !== 'waiting'
            && (string) ( $managed['direction'] ?? '' ) === $direction ) {
            $this->assertPlanState( $journal, $plan, $direction );
            return( $journal );
        }
        return( $this->journal->mutate(
            function( array $current, callable $save ) use ( $transaction_id, $direction, $plan_sha256, $plan ) : array {
                $this->assertTransaction( $current, $transaction_id );
                $current['phase'] = $direction === 'forward' ? 'installing' : 'rolling_back';
                $current['steps']['managed_files'] = [
                    'status' => 'running',
                    'direction' => $direction,
                    'plan_sha256' => $plan_sha256,
                    'total' => count( $plan ),
                    'next_index' => 0,
                    'current' => [],
                ];
                $current['steps']['final_audit'] = [ 'status' => 'waiting' ];
                $current['steps']['opcache'] = [ 'status' => 'waiting' ];
                $current['error'] = '';
                $current['updated_at_utc'] = $this->timestamp();
                $save( $current );
                return( $current );
            }
        ) );
    }

    protected function runPlan( array $journal, array $plan, string $source_root, ?callable $checkpoint ) : array {
        $transaction_id = (string) $journal['transaction_id'];
        $direction = (string) $journal['steps']['managed_files']['direction'];
        $next_index = (int) $journal['steps']['managed_files']['next_index'];
        while ( $next_index < count( $plan ) ) {
            $operation = $plan[$next_index];
            $journal = $this->journal->mutate(
                function( array $current, callable $save ) use ( $transaction_id, $direction, $plan, $next_index, $operation ) : array {
                    $this->assertTransaction( $current, $transaction_id );
                    $this->assertPlanState( $current, $plan, $direction );
                    if ( (int) $current['steps']['managed_files']['next_index'] !== $next_index ) {
                        throw new \RuntimeException( 'The managed-file update position changed.' );
                    }
                    $current['phase'] = $direction === 'forward' ? 'installing' : 'rolling_back';
                    $current['steps']['managed_files']['status'] = 'running';
                    $current['steps']['managed_files']['current'] = [
                        'index' => $next_index,
                        'action' => (string) $operation['action'],
                        'path' => (string) $operation['path'],
                    ];
                    $current['updated_at_utc'] = $this->timestamp();
                    $save( $current );
                    return( $current );
                }
            );
            $this->checkpoint( $checkpoint, 'before', $next_index, $operation );
            $this->applyOperation( $operation, $source_root, $transaction_id );
            $this->checkpoint( $checkpoint, 'after', $next_index, $operation );
            $journal = $this->journal->mutate(
                function( array $current, callable $save ) use ( $transaction_id, $direction, $plan, $next_index ) : array {
                    $this->assertTransaction( $current, $transaction_id );
                    $this->assertPlanState( $current, $plan, $direction );
                    if ( (int) $current['steps']['managed_files']['next_index'] !== $next_index ) {
                        throw new \RuntimeException( 'The managed-file update position changed.' );
                    }
                    $current['steps']['managed_files']['next_index'] = $next_index + 1;
                    $current['steps']['managed_files']['current'] = [];
                    $current['updated_at_utc'] = $this->timestamp();
                    $save( $current );
                    return( $current );
                }
            );
            $next_index++;
        }
        return( $journal );
    }

    protected function buildPlan( string $from_root, string $to_root ) : array {
        $from = $this->loadManifest( $from_root );
        $to = $this->loadManifest( $to_root );
        $from_files = (array) $from['inventory']['files'];
        $to_files = (array) $to['inventory']['files'];
        foreach ( array_keys( self::CRITICAL_PATHS ) as $path ) {
            if ( ! isset( $from_files[$path], $to_files[$path] ) ) {
                throw new \RuntimeException( 'The release does not contain the required update bootstrap files.' );
            }
        }
        $deletes = [];
        $directories = [];
        $replaces = [];

        foreach ( $from_files as $path => $metadata ) {
            if ( ! array_key_exists( $path, $to_files ) ) {
                $metadata = (array) $metadata;
                $deletes[] = [
                    'action' => 'delete',
                    'path' => $path,
                    'from_bytes' => (int) $metadata['bytes'],
                    'from_sha256' => (string) $metadata['sha256'],
                    'to_bytes' => 0,
                    'to_sha256' => '',
                ];
            }
        }
        foreach ( $to_files as $path => $metadata ) {
            $metadata = (array) $metadata;
            $old = is_array( $from_files[$path] ?? null ) ? $from_files[$path] : [];
            if ( $old !== []
                && (int) ( $old['bytes'] ?? -1 ) === (int) $metadata['bytes']
                && hash_equals( (string) ( $old['sha256'] ?? '' ), (string) $metadata['sha256'] ) ) {
                continue;
            }
            $replaces[] = [
                'action' => 'replace',
                'path' => $path,
                'from_bytes' => $old === [] ? 0 : (int) $old['bytes'],
                'from_sha256' => $old === [] ? '' : (string) $old['sha256'],
                'to_bytes' => (int) $metadata['bytes'],
                'to_sha256' => (string) $metadata['sha256'],
            ];
        }
        foreach ( (array) ( $to['includes']['empty_directories'] ?? [] ) as $path ) {
            $path = $this->normalizeRelativePath( (string) $path );
            $directories[$path] = [
                'action' => 'directory',
                'path' => $path,
                'from_bytes' => 0,
                'from_sha256' => '',
                'to_bytes' => 0,
                'to_sha256' => '',
            ];
        }
        $operations = array_merge( $deletes, array_values( $directories ), $replaces );
        usort( $operations, fn( array $left, array $right ) : int => $this->compareOperations( $left, $right ) );

        $from_manifest = $this->fileMetadata( $from_root . DIRECTORY_SEPARATOR . 'deploy-manifest.json' );
        $to_manifest = $this->fileMetadata( $to_root . DIRECTORY_SEPARATOR . 'deploy-manifest.json' );
        return( array_merge( $operations, [ [
            'action' => 'manifest',
            'path' => 'deploy-manifest.json',
            'from_bytes' => $from_manifest['bytes'],
            'from_sha256' => $from_manifest['sha256'],
            'to_bytes' => $to_manifest['bytes'],
            'to_sha256' => $to_manifest['sha256'],
        ] ] ) );
    }

    protected function compareOperations( array $left, array $right ) : int {
        $left_path = (string) $left['path'];
        $right_path = (string) $right['path'];
        $priority = $this->criticalPriority( $left_path ) <=> $this->criticalPriority( $right_path );
        if ( $priority !== 0 ) {
            return( $priority );
        }
        $ranks = [ 'delete' => 0, 'directory' => 1, 'replace' => 2 ];
        $left_rank = $ranks[(string) ( $left['action'] ?? '' )] ?? 3;
        $right_rank = $ranks[(string) ( $right['action'] ?? '' )] ?? 3;
        if ( $left_rank !== $right_rank ) {
            return( $left_rank <=> $right_rank );
        }
        if ( $left_rank === 0 ) {
            $depth = substr_count( $right_path, '/' ) <=> substr_count( $left_path, '/' );
            if ( $depth !== 0 ) {
                return( $depth );
            }
        }
        return( strcmp( $left_path, $right_path ) );
    }

    protected function criticalPriority( string $path ) : int {
        return( self::CRITICAL_PATHS[$path] ?? 0 );
    }

    protected function applyOperation( array $operation, string $source_root, string $transaction_id ) : void {
        $path = $this->normalizeRelativePath( (string) ( $operation['path'] ?? '' ) );
        $action = (string) ( $operation['action'] ?? '' );
        if ( $action === 'delete' ) {
            $this->deleteManagedFile( $path, $operation, $transaction_id );
            return;
        }
        if ( $action === 'directory' ) {
            $this->ensureManagedDirectory( $path );
            return;
        }
        if ( ! in_array( $action, [ 'replace', 'manifest' ], true ) ) {
            throw new \RuntimeException( 'The managed-file operation is invalid.' );
        }
        $source = $source_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path );
        $this->replaceManagedFile( $path, $source, $operation, $transaction_id );
    }

    protected function replaceManagedFile( string $path, string $source, array $operation, string $transaction_id ) : void {
        $to_bytes = $operation['to_bytes'] ?? null;
        $to_sha256 = (string) ( $operation['to_sha256'] ?? '' );
        $from_bytes = $operation['from_bytes'] ?? null;
        $from_sha256 = (string) ( $operation['from_sha256'] ?? '' );
        $this->assertMetadata( $to_bytes, $to_sha256, false );
        $this->assertMetadata( $from_bytes, $from_sha256, true );
        if ( is_link( $source ) || ! is_file( $source ) || ! is_readable( $source )
            || ! $this->fileMatches( $source, (int) $to_bytes, $to_sha256 ) ) {
            throw new \RuntimeException( 'A checked release file is unavailable.' );
        }

        $target = $this->targetPath( $path );
        $this->ensureTargetParent( dirname( $target ) );
        $temporary = $this->operationTemporaryPath( $target, $transaction_id, 'part' );
        $this->assertSafeOperationPath( $temporary );
        if ( is_dir( $target ) && ! is_link( $target ) && $from_sha256 === '' ) {
            $items = @ scandir( $target );
            if ( $items !== [ '.', '..' ] || ! @ rmdir( $target ) ) {
                throw new \RuntimeException( 'A managed application file path is occupied by a directory.' );
            }
        }
        if ( is_file( $target ) && ! is_link( $target ) && $this->fileMatches( $target, (int) $to_bytes, $to_sha256 ) ) {
            @ unlink( $temporary );
            return;
        }
        if ( file_exists( $target ) || is_link( $target ) ) {
            if ( is_link( $target ) || ! is_file( $target ) || $from_sha256 === ''
                || ! $this->fileMatches( $target, (int) $from_bytes, $from_sha256 ) ) {
                throw new \RuntimeException( 'A managed application file changed during the update.' );
            }
        } elseif ( $from_sha256 !== '' ) {
            throw new \RuntimeException( 'A managed application file disappeared during the update.' );
        }

        if ( file_exists( $temporary ) ) {
            if ( is_link( $temporary ) || ! is_file( $temporary ) ) {
                throw new \RuntimeException( 'A managed-file temporary path is unsafe.' );
            }
            if ( ! $this->fileMatches( $temporary, (int) $to_bytes, $to_sha256 ) && ! @ unlink( $temporary ) ) {
                throw new \RuntimeException( 'A stale managed-file temporary copy could not be removed.' );
            }
        }
        if ( ! file_exists( $temporary ) ) {
            $this->copyVerifiedFile( $source, $temporary, (int) $to_bytes, $to_sha256 );
        }
        if ( ! @ rename( $temporary, $target ) ) {
            throw new \RuntimeException( 'A managed application file could not be replaced.' );
        }
        @ chmod( $target, 0644 );
        if ( ! $this->fileMatches( $target, (int) $to_bytes, $to_sha256 ) ) {
            throw new \RuntimeException( 'A managed application file failed verification after replacement.' );
        }
    }

    protected function deleteManagedFile( string $path, array $operation, string $transaction_id ) : void {
        $from_bytes = $operation['from_bytes'] ?? null;
        $from_sha256 = (string) ( $operation['from_sha256'] ?? '' );
        $this->assertMetadata( $from_bytes, $from_sha256, false );
        $target = $this->targetPath( $path );
        $retired = $this->operationTemporaryPath( $target, $transaction_id, 'retired' );
        $this->assertSafeOperationPath( $retired );

        if ( ! file_exists( $target ) && ! is_link( $target ) ) {
            if ( file_exists( $retired ) ) {
                if ( is_link( $retired ) || ! is_file( $retired )
                    || ! $this->fileMatches( $retired, (int) $from_bytes, $from_sha256 )
                    || ! @ unlink( $retired ) ) {
                    throw new \RuntimeException( 'A retired managed-file copy is unsafe.' );
                }
            }
            return;
        }
        if ( is_link( $target ) || ! is_file( $target )
            || ! $this->fileMatches( $target, (int) $from_bytes, $from_sha256 ) ) {
            throw new \RuntimeException( 'A retired application file changed during the update.' );
        }
        if ( file_exists( $retired ) || is_link( $retired ) ) {
            throw new \RuntimeException( 'A retired managed-file path is already in use.' );
        }
        if ( ! @ rename( $target, $retired ) || ! @ unlink( $retired ) ) {
            throw new \RuntimeException( 'A retired application file could not be removed.' );
        }
    }

    protected function ensureManagedDirectory( string $path ) : void {
        $target = $this->targetPath( $path );
        $this->ensureTargetParent( dirname( $target ) );
        if ( is_link( $target ) || ( file_exists( $target ) && ! is_dir( $target ) ) ) {
            throw new \RuntimeException( 'A managed application directory path is unsafe.' );
        }
        if ( ! is_dir( $target ) && ! @ mkdir( $target, 0755 ) && ! is_dir( $target ) ) {
            throw new \RuntimeException( 'A managed application directory could not be created.' );
        }
    }

    protected function copyVerifiedFile( string $source, string $target, int $expected_bytes, string $expected_sha256 ) : void {
        $source_handle = @ fopen( $source, 'rb' );
        $target_handle = @ fopen( $target, 'xb' );
        if ( $source_handle === false || $target_handle === false ) {
            if ( is_resource( $source_handle ) ) {
                fclose( $source_handle );
            }
            if ( is_resource( $target_handle ) ) {
                fclose( $target_handle );
            }
            throw new \RuntimeException( 'A managed application file could not be staged.' );
        }
        @ chmod( $target, 0600 );
        $hash = hash_init( 'sha256' );
        $bytes = 0;
        try {
            while ( ! feof( $source_handle ) ) {
                $chunk = fread( $source_handle, 1048576 );
                if ( ! is_string( $chunk ) || ( $chunk === '' && ! feof( $source_handle ) ) ) {
                    throw new \RuntimeException( 'A checked release file could not be read.' );
                }
                if ( $chunk === '' ) {
                    break;
                }
                $offset = 0;
                while ( $offset < strlen( $chunk ) ) {
                    $written = fwrite( $target_handle, substr( $chunk, $offset ) );
                    if ( ! is_int( $written ) || $written < 1 ) {
                        throw new \RuntimeException( 'A managed application file could not be staged.' );
                    }
                    $offset += $written;
                }
                hash_update( $hash, $chunk );
                $bytes += strlen( $chunk );
            }
            $flushed = fflush( $target_handle );
            if ( $flushed && function_exists( 'fsync' ) ) {
                $flushed = fsync( $target_handle );
            }
        } finally {
            fclose( $source_handle );
            fclose( $target_handle );
        }
        if ( ! $flushed || $bytes !== $expected_bytes || ! hash_equals( $expected_sha256, hash_final( $hash ) ) ) {
            @ unlink( $target );
            throw new \RuntimeException( 'A managed application file changed while it was being staged.' );
        }
    }

    protected function ensureTargetParent( string $directory ) : void {
        $relative = substr( str_replace( '\\', '/', $directory ), strlen( str_replace( '\\', '/', $this->installation_root ) ) );
        $relative = trim( $relative, '/' );
        $current = $this->installation_root;
        foreach ( $relative === '' ? [] : explode( '/', $relative ) as $segment ) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if ( is_link( $current ) ) {
                throw new \RuntimeException( 'A managed application directory is unsafe.' );
            }
            if ( file_exists( $current ) && ! is_dir( $current ) ) {
                throw new \RuntimeException( 'A managed application directory is blocked by a file.' );
            }
            if ( ! is_dir( $current ) && ! @ mkdir( $current, 0755 ) && ! is_dir( $current ) ) {
                throw new \RuntimeException( 'A managed application directory could not be created.' );
            }
        }
    }

    protected function targetPath( string $path ) : string {
        return( $this->installation_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path ) );
    }

    protected function operationTemporaryPath( string $target, string $transaction_id, string $suffix ) : string {
        return( dirname( $target ) . DIRECTORY_SEPARATOR . '.tinymash-update-' . $transaction_id . '-' . substr( hash( 'sha256', basename( $target ) ), 0, 16 ) . '.' . $suffix );
    }

    protected function assertSafeOperationPath( string $path ) : void {
        if ( ! str_starts_with( $path, $this->installation_root . DIRECTORY_SEPARATOR ) || is_link( $path ) ) {
            throw new \RuntimeException( 'A managed-file temporary path is unsafe.' );
        }
        $parent = dirname( $path );
        while ( ! file_exists( $parent ) && ! is_link( $parent ) && $parent !== $this->installation_root ) {
            $parent = dirname( $parent );
        }
        $real_parent = @ realpath( $parent );
        if ( ! is_dir( $parent ) || is_link( $parent ) || ! is_string( $real_parent )
            || ! hash_equals( $parent, $real_parent ) ) {
            throw new \RuntimeException( 'A managed-file temporary directory is unsafe.' );
        }
    }

    protected function removeOperationArtifacts( array $plan, string $transaction_id ) : void {
        foreach ( $plan as $operation ) {
            if ( (string) $operation['action'] === 'directory' ) {
                continue;
            }
            $target = $this->targetPath( $this->normalizeRelativePath( (string) $operation['path'] ) );
            foreach ( [ 'part', 'retired' ] as $suffix ) {
                $temporary = $this->operationTemporaryPath( $target, $transaction_id, $suffix );
                $this->assertSafeOperationPath( $temporary );
                if ( ! file_exists( $temporary ) ) {
                    continue;
                }
                $stat = @ lstat( $temporary );
                if ( ! is_file( $temporary ) || ! is_array( $stat ) || (int) $stat['nlink'] !== 1
                    || ( function_exists( 'posix_geteuid' ) && (int) $stat['uid'] !== posix_geteuid() ) ) {
                    throw new \RuntimeException( 'A managed-file temporary copy is unsafe.' );
                }
                if ( $suffix === 'retired'
                    && ! $this->fileMatches( $temporary, (int) $operation['from_bytes'], (string) $operation['from_sha256'] )
                    && ! $this->fileMatches( $temporary, (int) $operation['to_bytes'], (string) $operation['to_sha256'] ) ) {
                    throw new \RuntimeException( 'A retired managed-file copy changed.' );
                }
                if ( ! @ unlink( $temporary ) ) {
                    throw new \RuntimeException( 'A managed-file temporary copy could not be removed.' );
                }
            }
        }
    }

    protected function fileMatches( string $filename, int $bytes, string $sha256 ) : bool {
        $actual_bytes = @ filesize( $filename );
        $actual_sha256 = @ hash_file( 'sha256', $filename );
        return( is_int( $actual_bytes )
            && $actual_bytes === $bytes
            && is_string( $actual_sha256 )
            && hash_equals( $sha256, $actual_sha256 ) );
    }

    protected function fileMetadata( string $filename ) : array {
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename ) ) {
            throw new \RuntimeException( 'A deploy manifest is unavailable.' );
        }
        $bytes = @ filesize( $filename );
        $sha256 = @ hash_file( 'sha256', $filename );
        $this->assertMetadata( $bytes, (string) $sha256, false );
        return( [ 'bytes' => $bytes, 'sha256' => $sha256 ] );
    }

    protected function assertMetadata( mixed $bytes, string $sha256, bool $allow_absent ) : void {
        if ( ! is_int( $bytes ) || $bytes < 0
            || ( $allow_absent && $bytes === 0 && $sha256 === '' ) ) {
            if ( $allow_absent && $bytes === 0 && $sha256 === '' ) {
                return;
            }
            throw new \RuntimeException( 'Managed-file metadata is invalid.' );
        }
        if ( preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) !== 1 ) {
            throw new \RuntimeException( 'Managed-file metadata is invalid.' );
        }
    }

    protected function loadManifest( string $root ) : array {
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
        $files = is_array( $manifest['inventory']['files'] ?? null ) ? $manifest['inventory']['files'] : [];
        if ( ! is_array( $manifest )
            || (string) ( $manifest['format'] ?? '' ) !== 'tinymash-deploy'
            || (int) ( $manifest['format_version'] ?? 0 ) !== 2
            || (string) ( $manifest['inventory']['algorithm'] ?? '' ) !== 'sha256'
            || count( $files ) > 50000 ) {
            throw new \RuntimeException( 'The deploy manifest is invalid.' );
        }
        $normalized = [];
        foreach ( $files as $path => $metadata ) {
            $path = $this->normalizeRelativePath( (string) $path );
            $metadata = is_array( $metadata ) ? $metadata : [];
            $bytes = $metadata['bytes'] ?? null;
            $sha256 = (string) ( $metadata['sha256'] ?? '' );
            $this->assertMetadata( $bytes, $sha256, false );
            if ( isset( $normalized[$path] ) ) {
                throw new \RuntimeException( 'The deploy manifest contains duplicate paths.' );
            }
            $normalized[$path] = [ 'bytes' => $bytes, 'sha256' => $sha256 ];
        }
        ksort( $normalized, SORT_STRING );
        $manifest['inventory']['files'] = $normalized;
        return( $manifest );
    }

    protected function normalizeRelativePath( string $path ) : string {
        $path = str_replace( '\\', '/', trim( $path ) );
        if ( $path === ''
            || strlen( $path ) > 4096
            || str_starts_with( $path, '/' )
            || preg_match( '/^[A-Za-z]:\//', $path ) === 1
            || preg_match( '/[\x00-\x1F\x7F]/', $path ) === 1 ) {
            throw new \RuntimeException( 'The deploy manifest contains an unsafe path.' );
        }
        foreach ( explode( '/', $path ) as $segment ) {
            if ( $segment === '' || $segment === '.' || $segment === '..' ) {
                throw new \RuntimeException( 'The deploy manifest contains an unsafe path.' );
            }
        }
        return( $path );
    }

    protected function assertAuditedTree( string $root, string $message ) : void {
        if ( empty( ( new TinyMashDeployAuditService( $root ) )->audit()['ok'] ) ) {
            throw new \RuntimeException( $message );
        }
    }

    protected function assertForwardState( array $journal ) : void {
        $managed = (array) ( $journal['steps']['managed_files'] ?? [] );
        $phase = (string) ( $journal['phase'] ?? '' );
        if ( $journal === []
            || ! in_array( $phase, [ 'prepared', 'installing', 'files_replaced', 'failed' ], true )
            || ( $phase === 'failed'
                && (string) ( $managed['status'] ?? '' ) !== 'waiting'
                && (string) ( $managed['direction'] ?? '' ) !== 'forward' )
            || (string) ( $managed['direction'] ?? 'forward' ) === 'rollback' ) {
            throw new \RuntimeException( 'The update is not ready for managed-file replacement.' );
        }
    }

    protected function assertPreparedStartState( array $journal ) : void {
        if ( $journal === []
            || (string) ( $journal['phase'] ?? '' ) !== 'prepared'
            || (string) ( $journal['steps']['site_backup']['status'] ?? '' ) !== 'complete'
            || (string) ( $journal['steps']['managed_files']['status'] ?? '' ) !== 'waiting' ) {
            throw new \RuntimeException( 'The update is not ready to start.' );
        }
    }

    protected function assertRollbackState( array $journal ) : void {
        $phase = (string) ( $journal['phase'] ?? '' );
        if ( $journal === [] || ! in_array( $phase, [ 'installing', 'files_replaced', 'rolling_back', 'rolled_back', 'failed' ], true )
            || (string) ( $journal['steps']['health_check']['status'] ?? '' ) === 'complete' ) {
            throw new \RuntimeException( 'The update is not ready for managed-file rollback.' );
        }
    }

    protected function assertTransaction( array $journal, string $transaction_id ) : void {
        if ( ! hash_equals( $transaction_id, (string) ( $journal['transaction_id'] ?? '' ) ) ) {
            throw new \RuntimeException( 'The update transaction changed.' );
        }
    }

    protected function assertPlanState( array $journal, array $plan, string $direction ) : void {
        $managed = (array) ( $journal['steps']['managed_files'] ?? [] );
        if ( ! hash_equals( $this->planSha256( $plan ), (string) ( $managed['plan_sha256'] ?? '' ) )
            || (int) ( $managed['total'] ?? -1 ) !== count( $plan )
            || (string) ( $managed['direction'] ?? '' ) !== $direction ) {
            throw new \RuntimeException( 'The managed-file update plan changed.' );
        }
    }

    protected function planSha256( array $plan ) : string {
        return( hash( 'sha256', json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ) );
    }

    protected function recordFailure( string $transaction_id, string $message ) : void {
        try {
            $this->journal->mutate(
                function( array $journal, callable $save ) use ( $transaction_id, $message ) : void {
                    $this->assertTransaction( $journal, $transaction_id );
                    if ( in_array( (string) ( $journal['phase'] ?? '' ), [ 'health_passed', 'complete', 'rolled_back' ], true ) ) {
                        return;
                    }
                    $journal['phase'] = 'failed';
                    $journal['error'] = $message;
                    $journal['updated_at_utc'] = $this->timestamp();
                    $save( $journal );
                }
            );
        } catch ( \Throwable ) {
        }
    }

    protected function recordPreparedStartFailure( string $transaction_id, string $message ) : void {
        $this->journal->mutate(
            function( array $journal, callable $save ) use ( $transaction_id, $message ) : void {
                $this->assertTransaction( $journal, $transaction_id );
                if ( (string) ( $journal['steps']['managed_files']['status'] ?? '' ) !== 'waiting' ) {
                    throw new \RuntimeException( 'Managed-file replacement already started.' );
                }
                $journal['phase'] = 'failed';
                $journal['steps']['backup_recheck'] = [ 'status' => 'waiting' ];
                $journal['error'] = $message;
                $journal['updated_at_utc'] = $this->timestamp();
                $save( $journal );
            }
        );
    }

    protected function recordOpcacheInvalidation( array $journal, string $manifest_root, string $release ) : array {
        $manifest = $this->loadManifest( $manifest_root );
        $enabled = filter_var(
            ini_get( PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable' ),
            FILTER_VALIDATE_BOOL
        );
        $attempted = 0;
        $invalidated = 0;
        if ( $enabled && function_exists( 'opcache_invalidate' ) ) {
            foreach ( array_keys( (array) $manifest['inventory']['files'] ) as $relative_path ) {
                if ( ! str_ends_with( strtolower( (string) $relative_path ), '.php' ) ) {
                    continue;
                }
                $attempted++;
                if ( @ opcache_invalidate( $this->targetPath( (string) $relative_path ), true ) ) {
                    $invalidated++;
                }
            }
        }
        $transaction_id = (string) $journal['transaction_id'];
        return( $this->journal->mutate(
            function( array $current, callable $save ) use ( $transaction_id, $release, $enabled, $attempted, $invalidated ) : array {
                $this->assertTransaction( $current, $transaction_id );
                $current['steps']['opcache'] = [
                    'status' => 'complete',
                    'release' => $release,
                    'enabled' => $enabled,
                    'attempted' => $attempted,
                    'invalidated' => $invalidated,
                ];
                $current['updated_at_utc'] = $this->timestamp();
                $save( $current );
                return( $current );
            }
        ) );
    }

    protected function checkpoint( ?callable $checkpoint, string $event, int $index, array $operation ) : void {
        if ( is_callable( $checkpoint ) ) {
            $checkpoint( $event, $index, $operation );
        }
    }

    protected function timestamp() : string {
        $now = is_callable( $this->clock ) ? (int) ( $this->clock )() : time();
        return( gmdate( 'Y-m-d\TH:i:s\Z', $now ) );
    }
}
