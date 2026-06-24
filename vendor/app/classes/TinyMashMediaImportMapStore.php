<?php
namespace app\classes;

class TinyMashMediaImportMapStore {

    protected string $store_filename;

    public function __construct( string $store_filename ) {
        $this->store_filename = $store_filename;
    }

    public function getMapping( string $importer_key, string $owner_username, string $source_key ) : ?array {
        $normalized_record = $this->normalizeRecord(
            [
                'importer_key' => $importer_key,
                'owner_username' => $owner_username,
                'source_key' => $source_key,
            ]
        );
        if ( $normalized_record['importer_key'] === '' || $normalized_record['owner_username'] === '' || $normalized_record['source_key'] === '' ) {
            return( null );
        }

        $store = $this->readStore();
        $record_key = $this->buildRecordKey( $normalized_record['importer_key'], $normalized_record['owner_username'], $normalized_record['source_key'] );
        $record = $store[$record_key] ?? null;
        if ( ! is_array( $record ) ) {
            return( null );
        }

        return( $this->normalizeRecord( $record ) );
    }

    public function storeMapping( array $record ) : array {
        $normalized_record = $this->normalizeRecord( $record );
        if ( $normalized_record['importer_key'] === '' || $normalized_record['owner_username'] === '' || $normalized_record['source_key'] === '' ) {
            throw new \InvalidArgumentException( 'Invalid media import mapping.' );
        }

        $this->withLockedStore(
            function( array $store ) use ( $normalized_record ) : array {
                $record_key = $this->buildRecordKey( $normalized_record['importer_key'], $normalized_record['owner_username'], $normalized_record['source_key'] );
                $store[$record_key] = $normalized_record;
                return( $store );
            }
        );
        return( $normalized_record );
    }

    protected function buildRecordKey( string $importer_key, string $owner_username, string $source_key ) : string {
        return( sha1( $importer_key . '|' . $owner_username . '|' . $source_key ) );
    }

    protected function normalizeRecord( array $record ) : array {
        $normalized_record = [
            'importer_key' => strtolower( trim( (string) ( $record['importer_key'] ?? '' ) ) ),
            'owner_username' => $this->normalizeOwnerUsername( (string) ( $record['owner_username'] ?? '' ) ),
            'source_key' => trim( (string) ( $record['source_key'] ?? '' ) ),
            'source_id' => trim( (string) ( $record['source_id'] ?? '' ) ),
            'source_url' => trim( (string) ( $record['source_url'] ?? '' ) ),
            'source_path' => trim( (string) ( $record['source_path'] ?? '' ) ),
            'original_filename' => trim( (string) ( $record['original_filename'] ?? '' ) ),
            'media_id' => trim( (string) ( $record['media_id'] ?? '' ) ),
            'media_url' => trim( (string) ( $record['media_url'] ?? '' ) ),
            'mime' => trim( (string) ( $record['mime'] ?? '' ) ),
            'imported_at_utc' => trim( (string) ( $record['imported_at_utc'] ?? '' ) ),
        ];

        if ( $normalized_record['imported_at_utc'] === '' ) {
            $normalized_record['imported_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
        }

        return( $normalized_record );
    }

    protected function normalizeOwnerUsername( string $owner_username ) : string {
        $owner_username = strtolower( trim( $owner_username ) );
        return( preg_replace( '/[^a-z0-9_]/', '', $owner_username ) ?? '' );
    }

    protected function readStore() : array {
        if ( ! is_file( $this->store_filename ) || ! is_readable( $this->store_filename ) ) {
            return( [] );
        }

        $handle = @ fopen( $this->store_filename, 'rb' );
        if ( $handle === false ) {
            return( [] );
        }

        try {
            if ( ! @ flock( $handle, LOCK_SH ) ) {
                return( [] );
            }
            $store_json = stream_get_contents( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        if ( ! is_string( $store_json ) || trim( $store_json ) === '' ) {
            return( [] );
        }

        return( $this->decodeStore( $store_json ) );
    }

    protected function writeStore( array $store ) : void {
        $directory = dirname( $this->store_filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create media import mapping directory.' );
        }

        ksort( $store );
        $encoded = json_encode( $store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
        if ( ! is_string( $encoded ) || $encoded === '' ) {
            throw new \RuntimeException( 'Unable to encode media import mapping store.' );
        }

        $handle = @ fopen( $this->store_filename, 'c+' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'Unable to open media import mapping store.' );
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                throw new \RuntimeException( 'Unable to lock media import mapping store.' );
            }
            ftruncate( $handle, 0 );
            rewind( $handle );
            if ( fwrite( $handle, $encoded . PHP_EOL ) === false ) {
                throw new \RuntimeException( 'Unable to write media import mapping store.' );
            }
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }
    }

    protected function withLockedStore( callable $callback ) : void {
        $directory = dirname( $this->store_filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create media import mapping directory.' );
        }

        $handle = @ fopen( $this->store_filename, 'c+' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'Unable to open media import mapping store.' );
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                throw new \RuntimeException( 'Unable to lock media import mapping store.' );
            }

            $store_json = stream_get_contents( $handle );
            $store = $this->decodeStore( is_string( $store_json ) ? $store_json : '' );
            $updated_store = $callback( $store );
            if ( ! is_array( $updated_store ) ) {
                $updated_store = $store;
            }

            ksort( $updated_store );
            $encoded_store = json_encode( $updated_store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
            if ( ! is_string( $encoded_store ) || $encoded_store === '' ) {
                throw new \RuntimeException( 'Unable to encode media import mapping store.' );
            }

            ftruncate( $handle, 0 );
            rewind( $handle );
            if ( fwrite( $handle, $encoded_store . PHP_EOL ) === false ) {
                throw new \RuntimeException( 'Unable to write media import mapping store.' );
            }
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }
    }

    protected function decodeStore( string $store_json ) : array {
        if ( trim( $store_json ) === '' ) {
            return( [] );
        }

        try {
            $store = json_decode( $store_json, true, 512, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            return( [] );
        }

        return( is_array( $store ) ? $store : [] );
    }

}
