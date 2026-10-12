<?php
namespace app\classes;

final class TinyMashUpdateFailure extends \RuntimeException {

    protected string $reason_code;

    public function __construct( string $reason_code, ?\Throwable $previous = null ) {
        $reason_code = trim( $reason_code );
        if ( preg_match( '/^update\.[a-z0-9_]{1,80}$/D', $reason_code ) !== 1 ) {
            throw new \InvalidArgumentException( 'Invalid update failure code.' );
        }
        $this->reason_code = $reason_code;
        parent::__construct( $reason_code, 0, $previous );
    }

    public function getReasonCode() : string {
        return( $this->reason_code );
    }
}
