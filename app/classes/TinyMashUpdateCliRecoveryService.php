<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashUpdateHealthService.php';
require_once __DIR__ . '/TinyMashUpdateJournal.php';
require_once __DIR__ . '/TinyMashUpdateRecoveryWorker.php';
require_once __DIR__ . '/TinyMashWriterGate.php';

final class TinyMashUpdateCliRecoveryService {

    protected TinyMashUpdateJournal $journal;
    protected TinyMashUpdateRecoveryWorker $worker;
    protected TinyMashUpdateHealthService $health;
    protected string $installation_root;

    public function __construct(
        string $installation_root,
        string $recovery_root,
        string $journal_filename,
        string $runtime_directory
    ) {
        $this->installation_root = rtrim( $installation_root, DIRECTORY_SEPARATOR );
        $this->journal = new TinyMashUpdateJournal( $journal_filename );
        $this->worker = new TinyMashUpdateRecoveryWorker(
            $installation_root,
            $recovery_root,
            $journal_filename,
            $runtime_directory
        );
        $this->health = new TinyMashUpdateHealthService(
            $this->journal,
            new TinyMashWriterGate( $runtime_directory ),
            $installation_root
        );
    }

    public function run( string $action ) : array {
        $action = strtolower( trim( $action ) );
        if ( $action === 'status' ) {
            return( $this->worker->getStatus() );
        }
        if ( $action === 'rollback' ) {
            $status = $this->worker->getStatus();
            if ( (string) ( $status['steps']['managed_files']['status'] ?? 'waiting' ) === 'waiting' ) {
                throw new \RuntimeException( 'No application files were replaced; rollback is not required.' );
            }
            return( $this->worker->run( 'rollback' ) );
        }
        if ( $action !== 'resume' ) {
            throw new \InvalidArgumentException( 'The update recovery action is invalid.' );
        }

        $status = $this->worker->getStatus();
        $phase = (string) ( $status['phase'] ?? '' );
        if ( $phase === 'failed'
            && (string) ( $status['steps']['managed_files']['status'] ?? 'waiting' ) === 'waiting' ) {
            throw new \RuntimeException( 'No application files were replaced. Prepare the update again from Admin.' );
        }
        if ( $phase === 'prepared' ) {
            $status = $this->worker->run( 'start' );
        } elseif ( in_array( $phase, [ 'installing', 'failed' ], true ) ) {
            $status = $this->worker->run( 'resume' );
        }
        if ( ! in_array( (string) ( $status['phase'] ?? '' ), [ 'files_replaced', 'health_passed', 'complete' ], true ) ) {
            throw new \RuntimeException( 'The update did not reach its health check.' );
        }

        $journal = $this->journal->read();
        $transaction_id = (string) ( $journal['transaction_id'] ?? '' );
        $target_version = (string) ( $journal['target_version'] ?? '' );
        $manifest_filename = $this->installation_root . DIRECTORY_SEPARATOR . 'deploy-manifest.json';
        $manifest = $this->readDeployManifest( $manifest_filename );
        $running_version = (string) ( $manifest['application']['version'] ?? '' );
        if ( ! hash_equals( $target_version, $running_version ) ) {
            throw new \RuntimeException( 'The installed version does not match the update target.' );
        }
        $this->health->complete( $transaction_id, $running_version );
        return( $this->worker->getStatus() );
    }

    protected function readDeployManifest( string $filename ) : array {
        $size = @ filesize( $filename );
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename )
            || ! is_int( $size ) || $size < 2 || $size > 8388608 ) {
            throw new \RuntimeException( 'The installed deploy manifest is unavailable.' );
        }
        try {
            $manifest = json_decode( (string) file_get_contents( $filename ), true, 32, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            throw new \RuntimeException( 'The installed deploy manifest is invalid.' );
        }
        if ( ! is_array( $manifest )
            || (string) ( $manifest['format'] ?? '' ) !== 'tinymash-deploy'
            || (int) ( $manifest['format_version'] ?? 0 ) !== 2 ) {
            throw new \RuntimeException( 'The installed deploy manifest is invalid.' );
        }
        return( $manifest );
    }
}
