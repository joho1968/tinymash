<?php

use app\classes\TinyMashContentTargetPickerService;
use app\classes\TinyMashLockedJsonFile;

class TinyMashTinyLinksService {

    protected string $store_filename;
    protected TinyMashContentTargetPickerService $content_target_picker;

    public function __construct( string $store_filename, TinyMashContentTargetPickerService $content_target_picker ) {
        $this->store_filename = $store_filename;
        $this->content_target_picker = $content_target_picker;
    }

    public function listLinks() : array {
        $store = $this->readStore();
        $records = is_array( $store['links'] ?? null ) ? $store['links'] : [];
        $normalized_records = [];

        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }

            $normalized_record = $this->normalizeRecord( $record );
            if ( $normalized_record['id'] !== '' && $normalized_record['token'] !== '' ) {
                $normalized_records[] = $this->enrichRecord( $normalized_record );
            }
        }

        usort(
            $normalized_records,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['token'] ?? '' ), (string) ( $right['token'] ?? '' ) ) );
            }
        );

        return( $normalized_records );
    }

    public function saveLink( array $data ) : array {
        $submitted_id = trim( (string) ( $data['id'] ?? '' ) );
        $record = $this->normalizeSubmission( $data );

        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $submitted_id, $record ) : array {
                    $records = is_array( $store['links'] ?? null ) ? $store['links'] : [];
                    $updated_records = [];
                    $saved_record = $record;
                    $replaced = false;

                    if ( $saved_record['token'] === '' ) {
                        $saved_record['token'] = $this->generateUniqueToken( $records );
                    }

                    foreach ( $records as $existing_record ) {
                        if ( ! is_array( $existing_record ) ) {
                            continue;
                        }

                        $existing_record = $this->normalizeRecord( $existing_record );
                        if ( $existing_record['id'] === '' ) {
                            continue;
                        }

                        if ( $submitted_id !== '' && $existing_record['id'] === $submitted_id ) {
                            $saved_record['id'] = $existing_record['id'];
                            $saved_record['created_at_utc'] = $existing_record['created_at_utc'] !== '' ? $existing_record['created_at_utc'] : gmdate( 'Y-m-d\TH:i:s\Z' );
                            $saved_record['hit_count'] = max( 0, (int) ( $existing_record['hit_count'] ?? 0 ) );
                            $saved_record['last_hit_at_utc'] = (string) ( $existing_record['last_hit_at_utc'] ?? '' );
                            $updated_records[] = $saved_record;
                            $replaced = true;
                            continue;
                        }

                        if ( $existing_record['token'] === $saved_record['token'] ) {
                            throw new \InvalidArgumentException( 'token_conflict' );
                        }

                        $updated_records[] = $existing_record;
                    }

                    if ( ! $replaced ) {
                        $saved_record['id'] = $this->buildRecordId();
                        $saved_record['created_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                        $updated_records[] = $saved_record;
                    }

                    $store['version'] = 1;
                    $store['links'] = array_values( $updated_records );

                    return(
                        [
                            'data' => $store,
                            'result' => $this->enrichRecord( $saved_record ),
                        ]
                    );
                },
                [ 'version' => 1, 'links' => [] ]
            )
        );
    }

    public function deleteLink( string $id ) : bool {
        $id = trim( $id );
        if ( $id === '' ) {
            return( false );
        }

        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $id ) : array {
                    $records = is_array( $store['links'] ?? null ) ? $store['links'] : [];
                    $updated_records = [];
                    $deleted = false;

                    foreach ( $records as $record ) {
                        if ( ! is_array( $record ) ) {
                            continue;
                        }

                        $record = $this->normalizeRecord( $record );
                        if ( $record['id'] === $id ) {
                            $deleted = true;
                            continue;
                        }

                        if ( $record['id'] !== '' ) {
                            $updated_records[] = $record;
                        }
                    }

                    $store['version'] = 1;
                    $store['links'] = array_values( $updated_records );

                    return(
                        [
                            'data' => $store,
                            'result' => $deleted,
                        ]
                    );
                },
                [ 'version' => 1, 'links' => [] ]
            )
        );
    }

    public function resolveToken( string $token ) : array {
        $token = $this->normalizeToken( $token );
        if ( $token === '' ) {
            return( [] );
        }

        foreach ( $this->listLinks() as $record ) {
            if ( empty( $record['enabled'] ) || (string) ( $record['token'] ?? '' ) !== $token ) {
                continue;
            }
            if ( $this->isExpired( (string) ( $record['expires_at_utc'] ?? '' ) ) ) {
                continue;
            }

            $target_url = $this->resolveRecordTargetUrl( $record );
            if ( $target_url === '' ) {
                continue;
            }

            $this->recordHit( (string) ( $record['id'] ?? '' ) );
            $record['target_url'] = $target_url;
            return( $record );
        }

        return( [] );
    }

    public function getTargetOptions() : array {
        return(
            $this->content_target_picker->buildPickerOptions(
                null,
                null,
                true,
                [
                    'published_only' => true,
                    'types' => [ 'post', 'page' ],
                ]
            )
        );
    }

    public function normalizeToken( string $token ) : string {
        $token = strtolower( trim( $token ) );
        $token = preg_replace( '/[^a-z0-9_-]+/', '-', $token ) ?? '';
        return( trim( $token, '-_' ) );
    }

    public function normalizeInternalPathTarget( string $path ) : string {
        $path = trim( $path );
        if ( $path === '' ) {
            return( '' );
        }

        $path = (string) ( parse_url( $path, PHP_URL_PATH ) ?? $path );
        $path = '/' . ltrim( $path, '/' );
        $path = preg_replace( '#/+#', '/', $path ) ?? $path;
        $path = rtrim( $path, '/' );
        return( $path !== '' ? $path : '/' );
    }

    public function pathLooksReserved( string $path ) : bool {
        $path = $this->normalizeInternalPathTarget( $path );
        if ( $path === '' ) {
            return( true );
        }
        if ( $path === '/' ) {
            return( false );
        }

        $first_segment = strtolower( strtok( ltrim( $path, '/' ), '/' ) ?: '' );
        return( in_array( $first_segment, [ 'admin', 'media', 'assets', 'ext', 's', 't', 'search', 'tags', 'feed.rss', 'feed.atom', 'feed.json' ], true ) );
    }

    protected function normalizeSubmission( array $data ) : array {
        $token = $this->normalizeToken( (string) ( $data['token'] ?? '' ) );
        if ( $token !== '' && strlen( $token ) < 3 ) {
            throw new \InvalidArgumentException( 'token' );
        }

        $target_type = strtolower( trim( (string) ( $data['target_type'] ?? 'content' ) ) );
        if ( ! in_array( $target_type, [ 'content', 'path' ], true ) ) {
            throw new \InvalidArgumentException( 'target_type' );
        }

        $target_entry_id = '';
        $target_path = '';
        if ( $target_type === 'content' ) {
            $target_entry_id = trim( (string) ( $data['target_entry_id'] ?? '' ) );
            $target = $this->content_target_picker->findTargetById(
                $target_entry_id,
                null,
                null,
                true,
                [
                    'published_only' => true,
                    'types' => [ 'post', 'page' ],
                ]
            );
            if ( empty( $target ) || empty( $target['url'] ) ) {
                throw new \InvalidArgumentException( 'target_entry' );
            }
        } else {
            $target_path = $this->normalizeInternalPathTarget( (string) ( $data['target_path'] ?? '' ) );
            if ( $target_path === '' || preg_match( '#^https?://#i', $target_path ) === 1 || $this->pathLooksReserved( $target_path ) ) {
                throw new \InvalidArgumentException( 'target_path' );
            }
        }

        $expires_at_utc = $this->normalizeExpirationDate( (string) ( $data['expires_on'] ?? $data['expires_at_utc'] ?? '' ) );

        return(
            [
                'id' => trim( (string) ( $data['id'] ?? '' ) ),
                'token' => $token,
                'enabled' => ! empty( $data['enabled'] ),
                'target_type' => $target_type,
                'target_entry_id' => $target_entry_id,
                'target_path' => $target_path,
                'expires_at_utc' => $expires_at_utc,
                'note' => mb_substr( mb_trim( (string) ( $data['note'] ?? '' ) ), 0, 160 ),
                'created_at_utc' => '',
                'updated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'hit_count' => 0,
                'last_hit_at_utc' => '',
            ]
        );
    }

    protected function normalizeRecord( array $record ) : array {
        $target_type = strtolower( trim( (string) ( $record['target_type'] ?? 'content' ) ) );
        if ( ! in_array( $target_type, [ 'content', 'path' ], true ) ) {
            $target_type = 'content';
        }

        return(
            [
                'id' => trim( (string) ( $record['id'] ?? '' ) ),
                'token' => $this->normalizeToken( (string) ( $record['token'] ?? '' ) ),
                'enabled' => ! empty( $record['enabled'] ),
                'target_type' => $target_type,
                'target_entry_id' => trim( (string) ( $record['target_entry_id'] ?? '' ) ),
                'target_path' => $this->normalizeInternalPathTarget( (string) ( $record['target_path'] ?? '' ) ),
                'expires_at_utc' => trim( (string) ( $record['expires_at_utc'] ?? '' ) ),
                'note' => mb_substr( mb_trim( (string) ( $record['note'] ?? '' ) ), 0, 160 ),
                'created_at_utc' => trim( (string) ( $record['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $record['updated_at_utc'] ?? '' ) ),
                'hit_count' => max( 0, (int) ( $record['hit_count'] ?? 0 ) ),
                'last_hit_at_utc' => trim( (string) ( $record['last_hit_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function recordHit( string $id ) : void {
        $id = trim( $id );
        if ( $id === '' ) {
            return;
        }

        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $id ) : array {
                $records = is_array( $store['links'] ?? null ) ? $store['links'] : [];
                $updated_records = [];
                $hit_recorded = false;

                foreach ( $records as $record ) {
                    if ( ! is_array( $record ) ) {
                        continue;
                    }

                    $normalized_record = $this->normalizeRecord( $record );
                    if ( $normalized_record['id'] === $id ) {
                        $normalized_record['hit_count'] = max( 0, (int) ( $normalized_record['hit_count'] ?? 0 ) ) + 1;
                        $normalized_record['last_hit_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                        $hit_recorded = true;
                    }

                    if ( $normalized_record['id'] !== '' ) {
                        $updated_records[] = $normalized_record;
                    }
                }

                if ( $hit_recorded ) {
                    $store['version'] = 1;
                    $store['links'] = array_values( $updated_records );
                }

                return(
                    [
                        'data' => $store,
                        'result' => $hit_recorded,
                    ]
                );
            },
            [ 'version' => 1, 'links' => [] ]
        );
    }

    protected function enrichRecord( array $record ) : array {
        $record['target_url'] = $this->resolveRecordTargetUrl( $record );
        $record['target_label'] = $record['target_url'];
        if ( $record['target_type'] === 'content' && $record['target_entry_id'] !== '' ) {
            $target = $this->content_target_picker->findTargetById(
                $record['target_entry_id'],
                null,
                null,
                true,
                [
                    'published_only' => true,
                    'types' => [ 'post', 'page' ],
                ]
            );
            if ( ! empty( $target ) ) {
                $record['target_label'] = (string) ( $target['label'] ?? $record['target_url'] );
            }
        }

        $record['short_path'] = '/t/' . (string) ( $record['token'] ?? '' );
        $record['has_target'] = $record['target_url'] !== '';
        $record['expired'] = $this->isExpired( (string) ( $record['expires_at_utc'] ?? '' ) );
        $record['expires_on'] = $this->formatExpirationDate( (string) ( $record['expires_at_utc'] ?? '' ) );
        return( $record );
    }

    protected function resolveRecordTargetUrl( array $record ) : string {
        if ( (string) ( $record['target_type'] ?? 'content' ) === 'path' ) {
            return( $this->normalizeInternalPathTarget( (string) ( $record['target_path'] ?? '' ) ) );
        }

        $target = $this->content_target_picker->findTargetById(
            (string) ( $record['target_entry_id'] ?? '' ),
            null,
            null,
            true,
            [
                'published_only' => true,
                'types' => [ 'post', 'page' ],
            ]
        );

        return( (string) ( $target['url'] ?? '' ) );
    }

    protected function normalizeExpirationDate( string $date ) : string {
        $date = trim( $date );
        if ( $date === '' ) {
            return( '' );
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) !== 1 ) {
            throw new \InvalidArgumentException( 'expires_on' );
        }

        $timestamp = strtotime( $date . ' 23:59:59 UTC' );
        if ( $timestamp === false ) {
            throw new \InvalidArgumentException( 'expires_on' );
        }

        return( gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) );
    }

    protected function formatExpirationDate( string $expires_at_utc ) : string {
        $timestamp = strtotime( $expires_at_utc );
        return( $timestamp !== false ? gmdate( 'Y-m-d', $timestamp ) : '' );
    }

    protected function isExpired( string $expires_at_utc ) : bool {
        $timestamp = strtotime( $expires_at_utc );
        return( $timestamp !== false && $timestamp < time() );
    }

    protected function readStore() : array {
        $store = TinyMashLockedJsonFile::read( $this->store_filename, [ 'version' => 1, 'links' => [] ] );
        return( is_array( $store ) ? $store : [ 'version' => 1, 'links' => [] ] );
    }

    protected function generateUniqueToken( array $records ) : string {
        $existing_tokens = [];
        foreach ( $records as $record ) {
            if ( is_array( $record ) ) {
                $token = $this->normalizeToken( (string) ( $record['token'] ?? '' ) );
                if ( $token !== '' ) {
                    $existing_tokens[$token] = true;
                }
            }
        }

        for ( $attempt = 0; $attempt < 20; $attempt++ ) {
            $token = $this->generateToken();
            if ( ! isset( $existing_tokens[$token] ) ) {
                return( $token );
            }
        }

        throw new \RuntimeException( 'Unable to generate a unique tiny-link token.' );
    }

    protected function generateToken() : string {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $token = '';
        for ( $index = 0; $index < 6; $index++ ) {
            $token .= $alphabet[random_int( 0, strlen( $alphabet ) - 1 )];
        }

        return( $token );
    }

    protected function buildRecordId() : string {
        return( 'tiny_link_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) );
    }

}
