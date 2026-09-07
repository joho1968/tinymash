<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashDeployAuditService.php';
require_once __DIR__ . '/TinyMashUpdateJournal.php';
require_once __DIR__ . '/TinyMashWriterGate.php';

final class TinyMashUpdateHealthService {

    protected TinyMashUpdateJournal $journal;
    protected TinyMashWriterGate $writer_gate;
    protected string $installation_root;
    protected $clock;

    public function __construct(
        TinyMashUpdateJournal $journal,
        TinyMashWriterGate $writer_gate,
        string $installation_root,
        ?callable $clock = null
    ) {
        $installation_root = rtrim( trim( $installation_root ), DIRECTORY_SEPARATOR );
        $real_app = @ realpath( $installation_root . DIRECTORY_SEPARATOR . 'app' );
        if ( $installation_root === ''
            || ! is_string( $real_app )
            || ! hash_equals( rtrim( dirname( $real_app ), DIRECTORY_SEPARATOR ), $installation_root ) ) {
            throw new \InvalidArgumentException( 'The installation root is unavailable.' );
        }
        $this->journal = $journal;
        $this->writer_gate = $writer_gate;
        $this->installation_root = $installation_root;
        $this->clock = $clock;
    }

    public function complete( string $transaction_id, string $running_version ) : array {
        $transaction_id = strtolower( trim( $transaction_id ) );
        if ( preg_match( '/^[a-f0-9]{32}$/D', $transaction_id ) !== 1 ) {
            throw new \InvalidArgumentException( 'The update transaction ID is invalid.' );
        }
        $journal = $this->journal->read();
        try {
            $this->assertReady( $journal, $transaction_id, $running_version );
        } catch ( \Throwable $exception ) {
            $this->recordFailure( $transaction_id );
            throw $exception;
        }
        if ( (string) $journal['phase'] === 'complete' && $this->writer_gate->getMaintenanceStatus() === [] ) {
            return( $journal );
        }
        $lease = $this->writer_gate->acquireExclusive( $transaction_id );
        try {
            $journal = $this->journal->read();
            $this->assertReady( $journal, $transaction_id, $running_version );
            if ( empty( ( new TinyMashDeployAuditService( $this->installation_root ) )->audit()['ok'] ) ) {
                throw new \RuntimeException( 'The updated application failed its health audit.' );
            }
            if ( (string) $journal['phase'] === 'files_replaced' ) {
                $journal = $this->journal->mutate(
                    function( array $current, callable $save ) use ( $transaction_id, $running_version ) : array {
                        $this->assertReady( $current, $transaction_id, $running_version );
                        $current['phase'] = 'health_passed';
                        $current['steps']['health_check'] = [
                            'status' => 'complete',
                            'version' => $running_version,
                        ];
                        $current['error'] = '';
                        $current['updated_at_utc'] = $this->timestamp();
                        $save( $current );
                        return( $current );
                    }
                );
            }

            $journal = $this->journal->mutate(
                function( array $current, callable $save ) use ( $transaction_id ) : array {
                    if ( ! hash_equals( $transaction_id, (string) ( $current['transaction_id'] ?? '' ) )
                        || ! in_array( (string) ( $current['phase'] ?? '' ), [ 'health_passed', 'complete' ], true ) ) {
                        throw new \RuntimeException( 'The update health state changed.' );
                    }
                    $current['phase'] = 'complete';
                    $current['updated_at_utc'] = $this->timestamp();
                    $save( $current );
                    return( $current );
                }
            );
            $this->writer_gate->finishMaintenance( $lease, $transaction_id );
            return( $journal );
        } catch ( \Throwable $exception ) {
            $this->recordFailure( $transaction_id );
            throw $exception;
        } finally {
            $lease->release();
        }
    }

    protected function assertReady( array $journal, string $transaction_id, string $running_version ) : void {
        if ( ! hash_equals( $transaction_id, (string) ( $journal['transaction_id'] ?? '' ) )
            || ! in_array( (string) ( $journal['phase'] ?? '' ), [ 'files_replaced', 'health_passed', 'complete' ], true )
            || ! hash_equals( (string) ( $journal['target_version'] ?? '' ), $running_version )
            || (string) ( $journal['steps']['final_audit']['release'] ?? '' ) !== 'target'
            || (string) ( $journal['steps']['opcache']['release'] ?? '' ) !== 'target'
            || ( in_array( (string) $journal['phase'], [ 'health_passed', 'complete' ], true )
                && ( (string) ( $journal['steps']['health_check']['status'] ?? '' ) !== 'complete'
                    || ! hash_equals( $running_version, (string) ( $journal['steps']['health_check']['version'] ?? '' ) ) ) ) ) {
            throw new \RuntimeException( 'The updated application is not ready for its health check.' );
        }
    }

    protected function recordFailure( string $transaction_id ) : void {
        try {
            $this->journal->mutate(
                function( array $journal, callable $save ) use ( $transaction_id ) : void {
                    if ( ! hash_equals( $transaction_id, (string) ( $journal['transaction_id'] ?? '' ) )
                        || (string) ( $journal['phase'] ?? '' ) !== 'files_replaced' ) {
                        return;
                    }
                    $journal['phase'] = 'failed';
                    $journal['error'] = 'The updated application failed its health check.';
                    $journal['updated_at_utc'] = $this->timestamp();
                    $save( $journal );
                }
            );
        } catch ( \Throwable ) {
        }
    }

    protected function timestamp() : string {
        $now = is_callable( $this->clock ) ? (int) ( $this->clock )() : time();
        return( gmdate( 'Y-m-d\TH:i:s\Z', $now ) );
    }
}
