<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashLockedJsonFile.php';

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
        return( TinyMashLockedJsonFile::read( $this->store_filename, [] ) );
    }

    protected function withLockedStore( callable $callback ) : void {
        try {
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                static function( array $store ) use ( $callback ) : array {
                    $updated_store = $callback( $store );
                    if ( ! is_array( $updated_store ) ) {
                        $updated_store = $store;
                    }
                    ksort( $updated_store );
                    return( $updated_store );
                }
            );
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( 'Unable to write media import mapping store.', 0, $e );
        }
    }

}
