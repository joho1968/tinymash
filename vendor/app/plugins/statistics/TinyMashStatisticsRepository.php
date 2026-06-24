<?php

class TinyMashStatisticsRepository {

    protected string $storage_root;
    protected string $summary_filename;

    public function __construct( string $storage_root ) {
        $this->storage_root = rtrim( $storage_root, DIRECTORY_SEPARATOR );
        $this->summary_filename = $this->storage_root . DIRECTORY_SEPARATOR . 'summary.json';
    }

    public function recordView( array $view ) : array {
        $path = $this->normalizePath( (string) ( $view['path'] ?? '' ) );
        if ( $path === '' ) {
            throw new \InvalidArgumentException( 'statistics_path' );
        }

        $page_kind = $this->normalizePageKind( (string) ( $view['page_kind'] ?? '' ) );
        $author_slug = $this->normalizeAuthorSlug( (string) ( $view['author_slug'] ?? '' ) );
        $entry_type = $this->normalizeEntryType( (string) ( $view['entry_type'] ?? '' ) );
        $title = trim( (string) ( $view['title'] ?? '' ) );
        if ( $title === '' ) {
            $title = $path;
        }

        $date_key = gmdate( 'Y-m-d' );
        $timestamp_utc = gmdate( 'Y-m-d\TH:i:s\Z' );

        return( $this->withLockedData(
            static function( array $data ) use ( $path, $page_kind, $author_slug, $entry_type, $title, $date_key, $timestamp_utc ) : array {
                $data['updated_at_utc'] = $timestamp_utc;
                self::incrementBucket( $data['site'], $date_key, $timestamp_utc );

                if ( $author_slug === '' ) {
                    self::incrementBucket( $data['root'], $date_key, $timestamp_utc );
                    self::incrementPage(
                        $data['root']['pages'],
                        $path,
                        [
                            'path' => $path,
                            'title' => $title,
                            'page_kind' => $page_kind,
                            'entry_type' => $entry_type,
                            'author_slug' => '',
                        ],
                        $date_key,
                        $timestamp_utc
                    );
                } else {
                    if ( empty( $data['authors'][$author_slug] ) || ! is_array( $data['authors'][$author_slug] ) ) {
                        $data['authors'][$author_slug] = self::buildAuthorBucket( $author_slug );
                    }

                    self::incrementBucket( $data['authors'][$author_slug], $date_key, $timestamp_utc );
                    self::incrementPage(
                        $data['authors'][$author_slug]['pages'],
                        $path,
                        [
                            'path' => $path,
                            'title' => $title,
                            'page_kind' => $page_kind,
                            'entry_type' => $entry_type,
                            'author_slug' => $author_slug,
                        ],
                        $date_key,
                        $timestamp_utc
                    );
                }

                return(
                    [
                        'data' => $data,
                        'result' => [
                            'path' => $path,
                            'page_kind' => $page_kind,
                            'author_slug' => $author_slug,
                            'recorded_at_utc' => $timestamp_utc,
                        ],
                    ]
                );
            }
        ) );
    }

    public function getSiteReport( int $days = 14 ) : array {
        $data = $this->loadData();
        $pages = [];
        foreach ( (array) ( $data['root']['pages'] ?? [] ) as $page ) {
            if ( is_array( $page ) ) {
                $pages[] = $page;
            }
        }
        foreach ( (array) ( $data['authors'] ?? [] ) as $author_slug => $author_bucket ) {
            if ( ! is_array( $author_bucket ) ) {
                continue;
            }
            foreach ( (array) ( $author_bucket['pages'] ?? [] ) as $page ) {
                if ( ! is_array( $page ) ) {
                    continue;
                }
                $pages[] = $page;
            }
        }

        usort(
            $pages,
            static function( array $left, array $right ) : int {
                return( (int) ( $right['total_views'] ?? 0 ) <=> (int) ( $left['total_views'] ?? 0 ) );
            }
        );

        $authors = [];
        foreach ( (array) ( $data['authors'] ?? [] ) as $author_slug => $author_bucket ) {
            if ( ! is_array( $author_bucket ) ) {
                continue;
            }
            $authors[] = [
                'author_slug' => (string) $author_slug,
                'total_views' => (int) ( $author_bucket['total_views'] ?? 0 ),
                'last_7_days' => $this->sumDailyRange( (array) ( $author_bucket['daily'] ?? [] ), 7 ),
                'last_30_days' => $this->sumDailyRange( (array) ( $author_bucket['daily'] ?? [] ), 30 ),
            ];
        }

        usort(
            $authors,
            static function( array $left, array $right ) : int {
                return( (int) ( $right['total_views'] ?? 0 ) <=> (int) ( $left['total_views'] ?? 0 ) );
            }
        );

        return(
            [
                'total_views' => (int) ( $data['site']['total_views'] ?? 0 ),
                'last_7_days' => $this->sumDailyRange( (array) ( $data['site']['daily'] ?? [] ), 7 ),
                'last_30_days' => $this->sumDailyRange( (array) ( $data['site']['daily'] ?? [] ), 30 ),
                'tracked_authors' => count( $authors ),
                'tracked_pages' => count( $pages ),
                'root_total_views' => (int) ( $data['root']['total_views'] ?? 0 ),
                'daily_series' => $this->buildDailySeries( (array) ( $data['site']['daily'] ?? [] ), $days ),
                'top_pages' => array_slice( $pages, 0, 10 ),
                'top_authors' => array_slice( $authors, 0, 10 ),
                'last_viewed_at_utc' => trim( (string) ( $data['site']['last_viewed_at_utc'] ?? '' ) ),
            ]
        );
    }

    public function getAuthorReport( string $author_slug, int $days = 14 ) : array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        $data = $this->loadData();
        $author_bucket = is_array( $data['authors'][$author_slug] ?? null ) ? $data['authors'][$author_slug] : self::buildAuthorBucket( $author_slug );
        $pages = [];
        foreach ( (array) ( $author_bucket['pages'] ?? [] ) as $page ) {
            if ( is_array( $page ) ) {
                $pages[] = $page;
            }
        }

        usort(
            $pages,
            static function( array $left, array $right ) : int {
                return( (int) ( $right['total_views'] ?? 0 ) <=> (int) ( $left['total_views'] ?? 0 ) );
            }
        );

        return(
            [
                'author_slug' => $author_slug,
                'total_views' => (int) ( $author_bucket['total_views'] ?? 0 ),
                'last_7_days' => $this->sumDailyRange( (array) ( $author_bucket['daily'] ?? [] ), 7 ),
                'last_30_days' => $this->sumDailyRange( (array) ( $author_bucket['daily'] ?? [] ), 30 ),
                'tracked_pages' => count( $pages ),
                'daily_series' => $this->buildDailySeries( (array) ( $author_bucket['daily'] ?? [] ), $days ),
                'top_pages' => array_slice( $pages, 0, 10 ),
                'last_viewed_at_utc' => trim( (string) ( $author_bucket['last_viewed_at_utc'] ?? '' ) ),
            ]
        );
    }

    public function pruneDailyData( int $retention_days ) : array {
        if ( $retention_days <= 0 ) {
            return(
                [
                    'retention_days' => 0,
                    'removed' => 0,
                ]
            );
        }

        $cutoff_date = gmdate( 'Y-m-d', strtotime( '-' . max( 0, $retention_days - 1 ) . ' days' ) );
        return( $this->withLockedData(
            static function( array $data ) use ( $cutoff_date, $retention_days ) : array {
                $removed = 0;
                $removed += self::pruneDailyBucket( $data['site'], $cutoff_date );
                $removed += self::pruneDailyBucket( $data['root'], $cutoff_date );

                foreach ( (array) ( $data['root']['pages'] ?? [] ) as $page_key => $page ) {
                    if ( ! is_array( $page ) ) {
                        continue;
                    }
                    $removed += self::pruneDailyBucket( $data['root']['pages'][$page_key], $cutoff_date );
                }

                foreach ( (array) ( $data['authors'] ?? [] ) as $author_slug => $author_bucket ) {
                    if ( ! is_array( $author_bucket ) ) {
                        continue;
                    }
                    $removed += self::pruneDailyBucket( $data['authors'][$author_slug], $cutoff_date );
                    foreach ( (array) ( $author_bucket['pages'] ?? [] ) as $page_key => $page ) {
                        if ( ! is_array( $page ) ) {
                            continue;
                        }
                        $removed += self::pruneDailyBucket( $data['authors'][$author_slug]['pages'][$page_key], $cutoff_date );
                    }
                }

                $data['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                return(
                    [
                        'data' => $data,
                        'result' => [
                            'retention_days' => $retention_days,
                            'removed' => $removed,
                        ],
                    ]
                );
            }
        ) );
    }

    public function exportSiteData( string $target_directory ) : array {
        $data = $this->loadData();
        if ( ! $this->hasAnyData( $data ) ) {
            return(
                [
                    'exported' => false,
                ]
            );
        }

        $target_directory = rtrim( $target_directory, DIRECTORY_SEPARATOR );
        $this->ensureDirectory( $target_directory );
        file_put_contents(
            $target_directory . DIRECTORY_SEPARATOR . 'summary.json',
            json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL
        );

        return(
            [
                'exported' => true,
            ]
        );
    }

    public function exportAuthorData( string $author_slug, string $target_directory ) : array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            throw new \InvalidArgumentException( 'statistics_author_slug' );
        }

        $data = $this->loadData();
        $author_bucket = is_array( $data['authors'][$author_slug] ?? null ) ? $data['authors'][$author_slug] : null;
        if ( ! is_array( $author_bucket ) ) {
            return(
                [
                    'exported' => false,
                ]
            );
        }

        $target_directory = rtrim( $target_directory, DIRECTORY_SEPARATOR );
        $this->ensureDirectory( $target_directory . DIRECTORY_SEPARATOR . 'authors' );
        file_put_contents(
            $target_directory . DIRECTORY_SEPARATOR . 'authors' . DIRECTORY_SEPARATOR . $author_slug . '.json',
            json_encode(
                [
                    'format' => 'tinymash-statistics',
                    'format_version' => 1,
                    'scope' => 'author',
                    'author_slug' => $author_slug,
                    'author' => $author_bucket,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL
        );

        return(
            [
                'exported' => true,
            ]
        );
    }

    public function importSiteData( string $source_directory, bool $replace_existing = false ) : array {
        $source_filename = rtrim( $source_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'summary.json';
        if ( ! is_file( $source_filename ) || ! is_readable( $source_filename ) ) {
            return(
                [
                    'imported' => false,
                ]
            );
        }

        $source_data = $this->normalizeData( $this->readJsonFile( $source_filename ) );
        return( $this->withLockedData(
            static function( array $data ) use ( $source_data, $replace_existing ) : array {
                if ( ! $replace_existing && self::staticHasAnyData( $data ) ) {
                    throw new \RuntimeException( 'Statistics data already exists.' );
                }

                return(
                    [
                        'data' => $source_data,
                        'result' => [
                            'imported' => true,
                        ],
                    ]
                );
            }
        ) );
    }

    public function importAuthorData( string $author_slug, string $source_directory, bool $replace_existing = false ) : array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            throw new \InvalidArgumentException( 'statistics_author_slug' );
        }

        $source_filename = rtrim( $source_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'authors' . DIRECTORY_SEPARATOR . $author_slug . '.json';
        if ( ! is_file( $source_filename ) || ! is_readable( $source_filename ) ) {
            return(
                [
                    'imported' => false,
                ]
            );
        }

        $source_data = $this->readJsonFile( $source_filename );
        $author_bucket = is_array( $source_data['author'] ?? null ) ? $source_data['author'] : null;
        if ( ! is_array( $author_bucket ) ) {
            return(
                [
                    'imported' => false,
                ]
            );
        }

        return( $this->withLockedData(
            static function( array $data ) use ( $author_slug, $author_bucket, $replace_existing ) : array {
                if ( ! $replace_existing && ! empty( $data['authors'][$author_slug] ) ) {
                    throw new \RuntimeException( 'Statistics data already exists for this author.' );
                }

                $data['authors'][$author_slug] = self::normalizeAuthorBucket( $author_slug, $author_bucket );
                $data['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                return(
                    [
                        'data' => $data,
                        'result' => [
                            'imported' => true,
                        ],
                    ]
                );
            }
        ) );
    }

    public function hasData() : bool {
        return( $this->hasAnyData( $this->loadData() ) );
    }

    protected function loadData() : array {
        return( $this->normalizeData( $this->readJsonFile( $this->summary_filename ) ) );
    }

    protected function readJsonFile( string $filename ) : array {
        if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
            return( [] );
        }

        $json = file_get_contents( $filename );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( [] );
        }

        $decoded = json_decode( $json, true, 32 );
        return( is_array( $decoded ) ? $decoded : [] );
    }

    protected function withLockedData( callable $callback ) : mixed {
        $this->ensureDirectory( $this->storage_root );
        $handle = fopen( $this->summary_filename, 'c+' );
        if ( ! is_resource( $handle ) ) {
            throw new \RuntimeException( 'Statistics storage could not be opened.' );
        }

        if ( ! flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            throw new \RuntimeException( 'Statistics storage could not be locked.' );
        }

        try {
            rewind( $handle );
            $json = stream_get_contents( $handle );
            $data = $this->normalizeData( is_string( $json ) && trim( $json ) !== '' ? ( json_decode( $json, true, 32 ) ?: [] ) : [] );
            $response = $callback( $data );
            $updated_data = $data;
            $result = null;

            if ( is_array( $response ) && array_key_exists( 'data', $response ) ) {
                $updated_data = $this->normalizeData( is_array( $response['data'] ) ? $response['data'] : [] );
                $result = $response['result'] ?? null;
            } elseif ( is_array( $response ) ) {
                $updated_data = $this->normalizeData( $response );
                $result = $updated_data;
            } else {
                $result = $response;
            }

            rewind( $handle );
            ftruncate( $handle, 0 );
            fwrite( $handle, json_encode( $updated_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
            fflush( $handle );
        } finally {
            flock( $handle, LOCK_UN );
            fclose( $handle );
        }

        return( $result );
    }

    protected function normalizeData( array $data ) : array {
        $normalized = [
            'format' => 'tinymash-statistics',
            'format_version' => 1,
            'updated_at_utc' => trim( (string) ( $data['updated_at_utc'] ?? '' ) ),
            'site' => self::normalizeBucket( is_array( $data['site'] ?? null ) ? $data['site'] : [] ),
            'root' => self::normalizeBucket( is_array( $data['root'] ?? null ) ? $data['root'] : [] ),
            'authors' => [],
        ];
        $normalized['root']['pages'] = self::normalizePages( is_array( $data['root']['pages'] ?? null ) ? $data['root']['pages'] : [] );

        foreach ( (array) ( $data['authors'] ?? [] ) as $author_slug => $author_bucket ) {
            $normalized_author_slug = $this->normalizeAuthorSlug( (string) $author_slug );
            if ( $normalized_author_slug === '' || ! is_array( $author_bucket ) ) {
                continue;
            }
            $normalized['authors'][$normalized_author_slug] = self::normalizeAuthorBucket( $normalized_author_slug, $author_bucket );
        }

        return( $normalized );
    }

    protected function ensureDirectory( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0775, true );
        }
    }

    protected function normalizePath( string $path ) : string {
        $path = trim( $path );
        if ( $path === '' ) {
            return( '' );
        }
        $path = preg_replace( '/[#?].*$/', '', $path ) ?? $path;
        $path = '/' . ltrim( $path, '/' );
        $path = preg_replace( '#/{2,}#', '/', $path ) ?? $path;
        return( $path );
    }

    protected function normalizePageKind( string $page_kind ) : string {
        $page_kind = strtolower( trim( $page_kind ) );
        return( in_array( $page_kind, [ 'home', 'author_home', 'post', 'page' ], true ) ? $page_kind : 'page' );
    }

    protected function normalizeEntryType( string $entry_type ) : string {
        $entry_type = strtolower( trim( $entry_type ) );
        return( in_array( $entry_type, [ 'post', 'page' ], true ) ? $entry_type : '' );
    }

    protected function normalizeAuthorSlug( string $author_slug ) : string {
        $author_slug = strtolower( trim( $author_slug ) );
        return( preg_match( '/^[a-z0-9_]{1,64}$/', $author_slug ) === 1 ? $author_slug : '' );
    }

    protected function sumDailyRange( array $daily, int $days ) : int {
        $total = 0;
        foreach ( $this->buildDailySeries( $daily, $days ) as $series_item ) {
            $total += (int) ( $series_item['value'] ?? 0 );
        }

        return( $total );
    }

    protected function buildDailySeries( array $daily, int $days ) : array {
        $days = max( 1, min( 365, $days ) );
        $series = [];
        for ( $offset = $days - 1; $offset >= 0; $offset-- ) {
            $date_key = gmdate( 'Y-m-d', strtotime( '-' . $offset . ' days' ) );
            $series[] = [
                'date' => $date_key,
                'label' => gmdate( 'j M', strtotime( $date_key . ' 00:00:00 UTC' ) ),
                'value' => max( 0, (int) ( $daily[$date_key] ?? 0 ) ),
            ];
        }

        return( $series );
    }

    protected function hasAnyData( array $data ) : bool {
        return( self::staticHasAnyData( $data ) );
    }

    protected static function staticHasAnyData( array $data ) : bool {
        if ( (int) ( $data['site']['total_views'] ?? 0 ) > 0 ) {
            return( true );
        }
        if ( (int) ( $data['root']['total_views'] ?? 0 ) > 0 ) {
            return( true );
        }
        foreach ( (array) ( $data['authors'] ?? [] ) as $author_bucket ) {
            if ( is_array( $author_bucket ) && (int) ( $author_bucket['total_views'] ?? 0 ) > 0 ) {
                return( true );
            }
        }

        return( false );
    }

    protected static function buildAuthorBucket( string $author_slug ) : array {
        $bucket = self::normalizeBucket( [] );
        $bucket['author_slug'] = $author_slug;
        $bucket['pages'] = [];
        return( $bucket );
    }

    protected static function normalizeAuthorBucket( string $author_slug, array $bucket ) : array {
        $normalized = self::normalizeBucket( $bucket );
        $normalized['author_slug'] = $author_slug;
        $normalized['pages'] = self::normalizePages( is_array( $bucket['pages'] ?? null ) ? $bucket['pages'] : [] );
        return( $normalized );
    }

    protected static function normalizePages( array $pages ) : array {
        $normalized_pages = [];
        foreach ( $pages as $page_key => $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }
            $path = trim( (string) ( $page['path'] ?? ( is_string( $page_key ) ? $page_key : '' ) ) );
            if ( $path === '' ) {
                continue;
            }

            $normalized_pages[$path] = [
                'path' => $path,
                'title' => trim( (string) ( $page['title'] ?? $path ) ),
                'page_kind' => trim( (string) ( $page['page_kind'] ?? 'page' ) ),
                'entry_type' => trim( (string) ( $page['entry_type'] ?? '' ) ),
                'author_slug' => trim( (string) ( $page['author_slug'] ?? '' ) ),
                'total_views' => max( 0, (int) ( $page['total_views'] ?? 0 ) ),
                'last_viewed_at_utc' => trim( (string) ( $page['last_viewed_at_utc'] ?? '' ) ),
                'daily' => self::normalizeDaily( is_array( $page['daily'] ?? null ) ? $page['daily'] : [] ),
            ];
        }

        return( $normalized_pages );
    }

    protected static function normalizeBucket( array $bucket ) : array {
        return(
            [
                'total_views' => max( 0, (int) ( $bucket['total_views'] ?? 0 ) ),
                'last_viewed_at_utc' => trim( (string) ( $bucket['last_viewed_at_utc'] ?? '' ) ),
                'daily' => self::normalizeDaily( is_array( $bucket['daily'] ?? null ) ? $bucket['daily'] : [] ),
            ]
        );
    }

    protected static function normalizeDaily( array $daily ) : array {
        $normalized = [];
        foreach ( $daily as $date_key => $count ) {
            if ( ! is_string( $date_key ) || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_key ) !== 1 ) {
                continue;
            }
            $normalized[$date_key] = max( 0, (int) $count );
        }
        ksort( $normalized, SORT_STRING );
        return( $normalized );
    }

    protected static function incrementBucket( array &$bucket, string $date_key, string $timestamp_utc ) : void {
        $bucket['total_views'] = max( 0, (int) ( $bucket['total_views'] ?? 0 ) ) + 1;
        if ( empty( $bucket['daily'] ) || ! is_array( $bucket['daily'] ) ) {
            $bucket['daily'] = [];
        }
        $bucket['daily'][$date_key] = max( 0, (int) ( $bucket['daily'][$date_key] ?? 0 ) ) + 1;
        $bucket['last_viewed_at_utc'] = $timestamp_utc;
    }

    protected static function incrementPage( array &$pages, string $path, array $metadata, string $date_key, string $timestamp_utc ) : void {
        if ( empty( $pages[$path] ) || ! is_array( $pages[$path] ) ) {
            $pages[$path] = [
                'path' => $path,
                'title' => trim( (string) ( $metadata['title'] ?? $path ) ),
                'page_kind' => trim( (string) ( $metadata['page_kind'] ?? 'page' ) ),
                'entry_type' => trim( (string) ( $metadata['entry_type'] ?? '' ) ),
                'author_slug' => trim( (string) ( $metadata['author_slug'] ?? '' ) ),
                'total_views' => 0,
                'last_viewed_at_utc' => '',
                'daily' => [],
            ];
        } else {
            $pages[$path]['title'] = trim( (string) ( $metadata['title'] ?? $pages[$path]['title'] ?? $path ) );
            $pages[$path]['page_kind'] = trim( (string) ( $metadata['page_kind'] ?? $pages[$path]['page_kind'] ?? 'page' ) );
            $pages[$path]['entry_type'] = trim( (string) ( $metadata['entry_type'] ?? $pages[$path]['entry_type'] ?? '' ) );
            $pages[$path]['author_slug'] = trim( (string) ( $metadata['author_slug'] ?? $pages[$path]['author_slug'] ?? '' ) );
        }

        self::incrementBucket( $pages[$path], $date_key, $timestamp_utc );
    }

    protected static function pruneDailyBucket( array &$bucket, string $cutoff_date ) : int {
        $removed = 0;
        $daily = is_array( $bucket['daily'] ?? null ) ? $bucket['daily'] : [];
        foreach ( array_keys( $daily ) as $date_key ) {
            if ( strcmp( $date_key, $cutoff_date ) < 0 ) {
                unset( $bucket['daily'][$date_key] );
                $removed++;
            }
        }

        return( $removed );
    }
}
