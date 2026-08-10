<?php
namespace app\classes;

trait TinyMashConfigFileReader {

    protected function readConfigJson() : string|bool {
        $lock_handle = @ fopen( $this->getConfigLockFilename(), 'c+' );
        if ( $lock_handle === false ) {
            return( false );
        }

        try {
            if ( ! @ flock( $lock_handle, LOCK_SH ) ) {
                return( false );
            }
            $config_json = @ file_get_contents( $this->getConfigFilename() );
            @ flock( $lock_handle, LOCK_UN );
        } finally {
            fclose( $lock_handle );
        }

        return( is_string( $config_json ) ? $config_json : false );
    }

    protected function getConfigLockFilename() : string {
        return( $this->getConfigFilename() . '.lock' );
    }
}
