<?php
namespace app\classes;

class TinyMashImportLockService {

    protected string $lock_filename;
    protected int $stale_after_seconds;

    public function __construct( string $lock_filename, int $stale_after_seconds = 7200 ) {
        $this->lock_filename = $lock_filename;
        $this->stale_after_seconds = max( 300, $stale_after_seconds );
    }

    public function getAuthorLock( string $author_slug ) : ?array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            return( null );
        }

        $store = TinyMashLockedJsonFile::read( $this->lock_filename, [ 'authors' => [] ] );
        return( $this->normalizeRecord( $store['authors'][$author_slug] ?? null, $author_slug ) );
    }

    public function acquireAuthorLock( string $author_slug, string $importer_key, string $token, string $created_by_username = '', bool $force = false ) : array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        $importer_key = $this->normalizeKey( $importer_key );
        $token = $this->normalizeToken( $token );
        $created_by_username = $this->normalizeKey( $created_by_username );
        if ( $author_slug === '' || $importer_key === '' || $token === '' ) {
            throw new \InvalidArgumentException( 'author_slug' );
        }

        return( TinyMashLockedJsonFile::mutate(
            $this->lock_filename,
            function( array $store ) use ( $author_slug, $importer_key, $token, $created_by_username, $force ) : array {
                if ( ! isset( $store['authors'] ) || ! is_array( $store['authors'] ) ) {
                    $store['authors'] = [];
                }

                $existing = $this->normalizeRecord( $store['authors'][$author_slug] ?? null, $author_slug );
                if ( is_array( $existing ) ) {
                    if ( (string) ( $existing['token'] ?? '' ) === $token ) {
                        return( [ 'data' => $store, 'result' => $existing ] );
                    }

                    if ( ! $force || empty( $existing['is_stale'] ) ) {
                        return( [ 'data' => $store, 'result' => $existing ] );
                    }
                }

                $record = [
                    'author_slug' => $author_slug,
                    'importer_key' => $importer_key,
                    'token' => $token,
                    'created_by_username' => $created_by_username,
                    'created_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                ];
                $store['authors'][$author_slug] = $record;
                return( [ 'data' => $store, 'result' => $this->normalizeRecord( $record, $author_slug ) ] );
            },
            [ 'authors' => [] ]
        ) );
    }

    public function releaseAuthorLock( string $author_slug, string $token = '' ) : bool {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        $token = $this->normalizeToken( $token );
        if ( $author_slug === '' ) {
            return( false );
        }

        return( (bool) TinyMashLockedJsonFile::mutate(
            $this->lock_filename,
            function( array $store ) use ( $author_slug, $token ) : array {
                if ( empty( $store['authors'] ) || ! is_array( $store['authors'] ) || ! isset( $store['authors'][$author_slug] ) ) {
                    return( [ 'data' => $store, 'result' => false ] );
                }

                $existing = $this->normalizeRecord( $store['authors'][$author_slug], $author_slug );
                if ( ! is_array( $existing ) ) {
                    unset( $store['authors'][$author_slug] );
                    return( [ 'data' => $store, 'result' => true ] );
                }

                if ( $token !== '' && (string) ( $existing['token'] ?? '' ) !== $token ) {
                    return( [ 'data' => $store, 'result' => false ] );
                }

                unset( $store['authors'][$author_slug] );
                return( [ 'data' => $store, 'result' => true ] );
            },
            [ 'authors' => [] ]
        ) );
    }

    protected function normalizeRecord( mixed $record, string $fallback_author_slug = '' ) : ?array {
        if ( ! is_array( $record ) ) {
            return( null );
        }

        $author_slug = $this->normalizeAuthorSlug( (string) ( $record['author_slug'] ?? $fallback_author_slug ) );
        $importer_key = $this->normalizeKey( (string) ( $record['importer_key'] ?? '' ) );
        $token = $this->normalizeToken( (string) ( $record['token'] ?? '' ) );
        $created_by_username = $this->normalizeKey( (string) ( $record['created_by_username'] ?? '' ) );
        $created_at_utc = trim( (string) ( $record['created_at_utc'] ?? '' ) );
        if ( $author_slug === '' || $importer_key === '' || $token === '' || $created_at_utc === '' ) {
            return( null );
        }

        $created_timestamp = strtotime( $created_at_utc );
        $is_stale = $created_timestamp !== false && ( time() - $created_timestamp ) >= $this->stale_after_seconds;

        return(
            [
                'author_slug' => $author_slug,
                'importer_key' => $importer_key,
                'token' => $token,
                'created_by_username' => $created_by_username,
                'created_at_utc' => $created_at_utc,
                'is_stale' => $is_stale,
            ]
        );
    }

    protected function normalizeAuthorSlug( string $author_slug ) : string {
        return( $this->normalizeKey( $author_slug ) );
    }

    protected function normalizeKey( string $value ) : string {
        $value = strtolower( trim( $value ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $value ) ?? '' );
    }

    protected function normalizeToken( string $token ) : string {
        $token = strtolower( trim( $token ) );
        return( preg_replace( '/[^a-z0-9_]+/', '', $token ) ?? '' );
    }
}
