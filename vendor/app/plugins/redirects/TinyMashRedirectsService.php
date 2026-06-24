<?php

use app\classes\TinyMashContentTargetPickerService;
use app\classes\TinyMashLockedJsonFile;

class TinyMashRedirectsService {

    protected string $store_filename;
    protected TinyMashContentTargetPickerService $content_target_picker;

    public function __construct( string $store_filename, TinyMashContentTargetPickerService $content_target_picker ) {
        $this->store_filename = $store_filename;
        $this->content_target_picker = $content_target_picker;
    }

    public function listRedirects() : array {
        $store = $this->readStore();
        $records = is_array( $store['redirects'] ?? null ) ? $store['redirects'] : [];
        $normalized_records = [];

        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }

            $normalized_record = $this->normalizeRecord( $record );
            if ( $normalized_record['id'] !== '' && $normalized_record['source_path'] !== '' ) {
                $normalized_records[] = $this->enrichRecord( $normalized_record );
            }
        }

        usort(
            $normalized_records,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['source_path'] ?? '' ), (string) ( $right['source_path'] ?? '' ) ) );
            }
        );

        return( $normalized_records );
    }

    public function saveRedirect( array $data ) : array {
        $submitted_id = trim( (string) ( $data['id'] ?? '' ) );
        $record = $this->normalizeSubmission( $data );

        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $submitted_id, $record ) : array {
                    $records = is_array( $store['redirects'] ?? null ) ? $store['redirects'] : [];
                    $updated_records = [];
                    $saved_record = $record;
                    $replaced = false;

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
                            $updated_records[] = $saved_record;
                            $replaced = true;
                            continue;
                        }

                        if ( $existing_record['source_path'] === $record['source_path'] ) {
                            throw new \InvalidArgumentException( 'source_conflict' );
                        }

                        $updated_records[] = $existing_record;
                    }

                    if ( ! $replaced ) {
                        $saved_record['id'] = $this->buildRecordId();
                        $saved_record['created_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                        $updated_records[] = $saved_record;
                    }

                    $store['version'] = 1;
                    $store['redirects'] = array_values( $updated_records );

                    return(
                        [
                            'data' => $store,
                            'result' => $this->enrichRecord( $saved_record ),
                        ]
                    );
                },
                [ 'version' => 1, 'redirects' => [] ]
            )
        );
    }

    public function deleteRedirect( string $id ) : bool {
        $id = trim( $id );
        if ( $id === '' ) {
            return( false );
        }

        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $id ) : array {
                    $records = is_array( $store['redirects'] ?? null ) ? $store['redirects'] : [];
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
                    $store['redirects'] = array_values( $updated_records );

                    return(
                        [
                            'data' => $store,
                            'result' => $deleted,
                        ]
                    );
                },
                [ 'version' => 1, 'redirects' => [] ]
            )
        );
    }

    public function resolveRequestPath( string $request_path ) : array {
        $source_path = $this->normalizeSourcePath( $request_path );
        if ( $source_path === '' ) {
            return( [] );
        }

        foreach ( $this->listRedirects() as $record ) {
            if ( empty( $record['enabled'] ) || (string) ( $record['source_path'] ?? '' ) !== $source_path ) {
                continue;
            }

            $target_url = $this->resolveRecordTargetUrl( $record );
            if ( $target_url === '' || $target_url === $source_path ) {
                continue;
            }

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

    public function normalizeSourcePath( string $path ) : string {
        $path = trim( $path );
        if ( $path === '' ) {
            return( '' );
        }

        $path = (string) ( parse_url( $path, PHP_URL_PATH ) ?? $path );
        $path = '/' . ltrim( $path, '/' );
        $path = preg_replace( '#/+#', '/', $path ) ?? $path;
        $path = rtrim( $path, '/' );
        if ( $path === '' ) {
            $path = '/';
        }

        return( $path );
    }

    public function normalizeInternalPathTarget( string $path ) : string {
        $path = $this->normalizeSourcePath( $path );
        if ( $path === '/' ) {
            return( '/' );
        }

        return( $path );
    }

    public function sourcePathLooksReserved( string $path ) : bool {
        $path = $this->normalizeSourcePath( $path );
        if ( $path === '' || $path === '/' ) {
            return( true );
        }

        $first_segment = strtolower( strtok( ltrim( $path, '/' ), '/' ) ?: '' );
        return( in_array( $first_segment, [ 'admin', 'media', 'assets', 'ext', 's', 'search', 'tags', 'feed.rss', 'feed.atom', 'feed.json' ], true ) );
    }

    protected function normalizeSubmission( array $data ) : array {
        $source_path = $this->normalizeSourcePath( (string) ( $data['source_path'] ?? '' ) );
        if ( $source_path === '' || $this->sourcePathLooksReserved( $source_path ) ) {
            throw new \InvalidArgumentException( 'source_path' );
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
            if ( $target_path === '' || $target_path === $source_path || preg_match( '#^https?://#i', $target_path ) === 1 || ( $target_path !== '/' && $this->sourcePathLooksReserved( $target_path ) ) ) {
                throw new \InvalidArgumentException( 'target_path' );
            }
        }

        $status_code = (int) ( $data['status_code'] ?? 302 );
        if ( ! in_array( $status_code, [ 301, 302, 307, 308 ], true ) ) {
            $status_code = 302;
        }

        return(
            [
                'id' => trim( (string) ( $data['id'] ?? '' ) ),
                'enabled' => ! empty( $data['enabled'] ),
                'source_path' => $source_path,
                'query_policy' => 'ignore',
                'target_type' => $target_type,
                'target_entry_id' => $target_entry_id,
                'target_path' => $target_path,
                'status_code' => $status_code,
                'created_at_utc' => '',
                'updated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            ]
        );
    }

    protected function normalizeRecord( array $record ) : array {
        $target_type = strtolower( trim( (string) ( $record['target_type'] ?? 'content' ) ) );
        if ( ! in_array( $target_type, [ 'content', 'path' ], true ) ) {
            $target_type = 'content';
        }

        $status_code = (int) ( $record['status_code'] ?? 302 );
        if ( ! in_array( $status_code, [ 301, 302, 307, 308 ], true ) ) {
            $status_code = 302;
        }

        return(
            [
                'id' => trim( (string) ( $record['id'] ?? '' ) ),
                'enabled' => ! empty( $record['enabled'] ),
                'source_path' => $this->normalizeSourcePath( (string) ( $record['source_path'] ?? '' ) ),
                'query_policy' => 'ignore',
                'target_type' => $target_type,
                'target_entry_id' => trim( (string) ( $record['target_entry_id'] ?? '' ) ),
                'target_path' => $this->normalizeInternalPathTarget( (string) ( $record['target_path'] ?? '' ) ),
                'status_code' => $status_code,
                'created_at_utc' => trim( (string) ( $record['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $record['updated_at_utc'] ?? '' ) ),
            ]
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

        $record['has_target'] = $record['target_url'] !== '';
        return( $record );
    }

    protected function resolveRecordTargetUrl( array $record ) : string {
        $target_type = (string) ( $record['target_type'] ?? 'content' );
        if ( $target_type === 'path' ) {
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

    protected function readStore() : array {
        $store = TinyMashLockedJsonFile::read( $this->store_filename, [ 'version' => 1, 'redirects' => [] ] );
        return( is_array( $store ) ? $store : [ 'version' => 1, 'redirects' => [] ] );
    }

    protected function buildRecordId() : string {
        return( 'redirect_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) );
    }

}
