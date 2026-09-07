<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashUpdateInstallerService.php';
require_once __DIR__ . '/TinyMashUpdateJournal.php';
require_once __DIR__ . '/TinyMashUpdateRecoveryArtifactService.php';
require_once __DIR__ . '/TinyMashWriterGate.php';

final class TinyMashUpdateRecoveryWorker {

    protected TinyMashUpdateJournal $journal;
    protected TinyMashUpdateInstallerService $installer;
    protected TinyMashWriterGate $writer_gate;

    public function __construct(
        string $installation_root,
        string $recovery_root,
        string $journal_filename,
        string $runtime_directory,
        ?callable $clock = null
    ) {
        $this->journal = new TinyMashUpdateJournal( $journal_filename );
        $this->writer_gate = new TinyMashWriterGate( $runtime_directory );
        $artifacts = new TinyMashUpdateRecoveryArtifactService( $installation_root, $recovery_root );
        $this->installer = new TinyMashUpdateInstallerService(
            $this->journal,
            $this->writer_gate,
            $installation_root,
            static fn( array $journal ) : array => $artifacts->verify( $journal ),
            null,
            $clock
        );
    }

    public function getStatus() : array {
        return( $this->publicStatus( $this->journal->read() ) );
    }

    public function run( string $action, ?callable $checkpoint = null ) : array {
        $action = strtolower( trim( $action ) );
        $journal = match ( $action ) {
            'start' => $this->installer->startPreparedManagedFiles( $checkpoint ),
            'resume' => $this->installer->replaceManagedFiles( [], $checkpoint ),
            'rollback' => $this->installer->rollbackManagedFiles( $checkpoint ),
            default => throw new \InvalidArgumentException( 'The update recovery action is invalid.' ),
        };
        return( $this->publicStatus( $journal ) );
    }

    protected function publicStatus( array $journal ) : array {
        if ( $journal === [] ) {
            return( [] );
        }
        $steps = [];
        foreach ( (array) ( $journal['steps'] ?? [] ) as $name => $step ) {
            if ( ! is_string( $name ) || ! is_array( $step ) ) {
                continue;
            }
            $status = [ 'status' => (string) ( $step['status'] ?? 'waiting' ) ];
            if ( $name === 'managed_files' && (string) $status['status'] !== 'waiting' ) {
                $status['completed'] = max( 0, (int) ( $step['next_index'] ?? 0 ) );
                $status['total'] = max( 0, (int) ( $step['total'] ?? 0 ) );
            }
            $steps[$name] = $status;
        }
        return( [
            'installed_version' => (string) ( $journal['installed_version'] ?? '' ),
            'target_version' => (string) ( $journal['target_version'] ?? '' ),
            'phase' => (string) ( $journal['phase'] ?? '' ),
            'maintenance_active' => $this->writer_gate->getMaintenanceStatus() !== [],
            'steps' => $steps,
            'error' => (string) ( $journal['error'] ?? '' ),
        ] );
    }
}
