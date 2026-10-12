<?php

use app\classes\TinyMashLockedJsonFile;
use app\classes\TinyMashText;

require_once dirname( __DIR__, 2 ) . '/classes/TinyMashText.php';

class TinyMashRssReaderService {

    protected const MAX_RESPONSE_BYTES = 524288;
    protected const MAX_REDIRECTS = 5;
    protected const MAX_ITEMS_PER_SOURCE = 50;
    protected const USER_AGENT_PREFIX = 'tinymash-rss-reader/';

    protected string $data_directory;
    protected string $settings_filename;
    protected string $cache_filename;

    public function __construct( string $data_directory ) {
        $this->data_directory = rtrim( $data_directory, DIRECTORY_SEPARATOR );
        $this->settings_filename = $this->data_directory . DIRECTORY_SEPARATOR . 'settings.json';
        $this->cache_filename = $this->data_directory . DIRECTORY_SEPARATOR . 'cache.json';
    }

    public function getSettings() : array {
        return( $this->normalizeSettings( TinyMashLockedJsonFile::read( $this->settings_filename, $this->getDefaultSettings() ) ) );
    }

    public function getCache() : array {
        return( $this->normalizeCache( TinyMashLockedJsonFile::read( $this->cache_filename, $this->getDefaultCache() ) ) );
    }

    public function saveSettings( array $input ) : array {
        $settings = $this->normalizeSettings( $input );
        if ( ! TinyMashLockedJsonFile::write( $this->settings_filename, $settings ) ) {
            throw new \RuntimeException( 'settings_write' );
        }
        $this->trimCacheToSettings( $settings );
        return( $settings );
    }

    public function getDefaultSettings() : array {
        return(
            [
                'defaults' => [
                    'item_limit' => 5,
                    'layout' => 'compact',
                    'show_excerpt' => true,
                    'show_date' => true,
                    'stale_hours' => 24,
                ],
                'slots' => [
                    'sidebar' => [
                        'enabled' => false,
                        'source' => '',
                        'title' => '',
                        'item_limit' => 5,
                        'layout' => 'compact',
                        'show_excerpt' => false,
                        'show_date' => true,
                    ],
                ],
                'sources' => [],
            ]
        );
    }

    public function getDefaultCache() : array {
        return(
            [
                'refreshed_at_utc' => '',
                'sources' => [],
            ]
        );
    }

    public function normalizeSettings( array $input ) : array {
        $settings = $this->getDefaultSettings();
        $defaults = is_array( $input['defaults'] ?? null ) ? $input['defaults'] : [];
        $settings['defaults'] = [
            'item_limit' => $this->clampInt( $defaults['item_limit'] ?? $settings['defaults']['item_limit'], 1, self::MAX_ITEMS_PER_SOURCE ),
            'layout' => $this->normalizeLayout( (string) ( $defaults['layout'] ?? $settings['defaults']['layout'] ) ),
            'show_excerpt' => ! empty( $defaults['show_excerpt'] ),
            'show_date' => ! empty( $defaults['show_date'] ),
            'stale_hours' => $this->clampInt( $defaults['stale_hours'] ?? $settings['defaults']['stale_hours'], 1, 720 ),
        ];
        $settings['slots'] = $this->normalizeSlots( is_array( $input['slots'] ?? null ) ? $input['slots'] : [] );

        $sources = [];
        foreach ( is_array( $input['sources'] ?? null ) ? $input['sources'] : [] as $source ) {
            if ( ! is_array( $source ) ) {
                continue;
            }
            $normalized = $this->normalizeSource( $source, $settings['defaults'] );
            if ( ! empty( $normalized ) ) {
                $sources[$normalized['key']] = $normalized;
            }
        }
        uasort(
            $sources,
            static fn( array $left, array $right ) : int => TinyMashText::compareCaseInsensitive( (string) $left['label'], (string) $right['label'] )
        );
        $settings['sources'] = array_values( $sources );
        return( $settings );
    }

    public function normalizeCache( array $input ) : array {
        $sources = [];
        foreach ( is_array( $input['sources'] ?? null ) ? $input['sources'] : [] as $source ) {
            if ( ! is_array( $source ) ) {
                continue;
            }
            $key = $this->normalizeKey( (string) ( $source['key'] ?? '' ) );
            if ( $key === '' ) {
                continue;
            }
            $items = [];
            foreach ( is_array( $source['items'] ?? null ) ? $source['items'] : [] as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $url = $this->normalizePublicUrl( (string) ( $item['url'] ?? '' ) );
                $title = $this->cleanText( (string) ( $item['title'] ?? '' ), 180 );
                if ( $url === '' || $title === '' ) {
                    continue;
                }
                $items[] = [
                    'id' => $this->cleanText( (string) ( $item['id'] ?? $url ), 240 ),
                    'title' => $title,
                    'url' => $url,
                    'summary' => $this->cleanText( (string) ( $item['summary'] ?? '' ), 500 ),
                    'published_at_utc' => $this->normalizeDateTime( (string) ( $item['published_at_utc'] ?? '' ) ),
                    'updated_at_utc' => $this->normalizeDateTime( (string) ( $item['updated_at_utc'] ?? '' ) ),
                ];
                if ( count( $items ) >= self::MAX_ITEMS_PER_SOURCE ) {
                    break;
                }
            }
            $sources[$key] = [
                'key' => $key,
                'status' => in_array( (string) ( $source['status'] ?? '' ), [ 'ok', 'error', 'disabled' ], true ) ? (string) $source['status'] : 'error',
                'message' => $this->cleanText( (string) ( $source['message'] ?? '' ), 240 ),
                'checked_at_utc' => $this->normalizeDateTime( (string) ( $source['checked_at_utc'] ?? '' ) ),
                'last_success_at_utc' => $this->normalizeDateTime( (string) ( $source['last_success_at_utc'] ?? '' ) ),
                'feed_title' => $this->cleanText( (string) ( $source['feed_title'] ?? '' ), 160 ),
                'feed_url' => $this->normalizePublicUrl( (string) ( $source['feed_url'] ?? '' ) ),
                'items' => $items,
            ];
        }
        return(
            [
                'refreshed_at_utc' => $this->normalizeDateTime( (string) ( $input['refreshed_at_utc'] ?? '' ) ),
                'sources' => $sources,
            ]
        );
    }

    public function buildSettingsFromPost( array $data ) : array {
        $defaults = [
            'item_limit' => (int) ( $data['default_item_limit'] ?? 5 ),
            'layout' => (string) ( $data['default_layout'] ?? 'compact' ),
            'show_excerpt' => ! empty( $data['default_show_excerpt'] ),
            'show_date' => ! empty( $data['default_show_date'] ),
            'stale_hours' => (int) ( $data['stale_hours'] ?? 24 ),
        ];
        $sources = [];
        foreach ( is_array( $data['sources'] ?? null ) ? $data['sources'] : [] as $source ) {
            if ( ! is_array( $source ) ) {
                continue;
            }
            $sources[] = [
                'key' => (string) ( $source['key'] ?? '' ),
                'label' => (string) ( $source['label'] ?? '' ),
                'url' => (string) ( $source['url'] ?? '' ),
                'enabled' => ! empty( $source['enabled'] ),
                'authors_may_embed' => ! empty( $source['authors_may_embed'] ),
                'item_limit' => (int) ( $source['item_limit'] ?? 5 ),
            ];
        }

        return(
            [
                'defaults' => $defaults,
                'slots' => $this->buildSlotsFromPost( is_array( $data['slots'] ?? null ) ? $data['slots'] : [] ),
                'sources' => $sources,
            ]
        );
    }

    public function buildSettingsFromPostResult( array $data ) : array {
        $settings = $this->buildSettingsFromPost( $data );
        $errors = [];
        $seen = [];
        foreach ( is_array( $data['sources'] ?? null ) ? $data['sources'] : [] as $index => $source ) {
            if ( ! is_array( $source ) ) {
                continue;
            }
            $raw_label = mb_trim( (string) ( $source['label'] ?? '' ) );
            $raw_key = mb_trim( (string) ( $source['key'] ?? '' ) );
            $raw_url = mb_trim( (string) ( $source['url'] ?? '' ) );
            $has_data = $raw_label !== '' || $raw_key !== '' || $raw_url !== '';
            if ( ! $has_data ) {
                continue;
            }
            $row_label = $raw_label !== '' ? $raw_label : 'Source ' . ( (int) $index + 1 );
            $key = $this->normalizeKey( $raw_key !== '' ? $raw_key : $raw_label );
            if ( $raw_label === '' ) {
                $errors[] = $row_label . ' needs a label.';
            }
            if ( $key === '' ) {
                $errors[] = $row_label . ' needs a valid key or label.';
            } elseif ( isset( $seen[$key] ) ) {
                $errors[] = $row_label . ' uses a duplicate key.';
            }
            $seen[$key] = true;
            try {
                $this->validateSourceUrl( $raw_url );
            } catch ( \Throwable $e ) {
                $errors[] = $row_label . ' needs a valid public HTTP or HTTPS feed URL.';
            }
        }

        return( [ 'settings' => $settings, 'errors' => $errors ] );
    }

    public function buildScopedSettingsFromPostResult( array $data, ?array $current_settings = null ) : array {
        $section = strtolower( mb_trim( (string) ( $data['section'] ?? '' ) ) );
        if ( ! in_array( $section, [ 'defaults', 'sidebar', 'source' ], true ) ) {
            return( $this->buildSettingsFromPostResult( $data ) );
        }

        $current = $this->normalizeSettings( $current_settings ?? $this->getSettings() );
        $post = [
            'default_item_limit' => (int) $current['defaults']['item_limit'],
            'default_layout' => (string) $current['defaults']['layout'],
            'default_show_excerpt' => ! empty( $current['defaults']['show_excerpt'] ) ? '1' : '',
            'default_show_date' => ! empty( $current['defaults']['show_date'] ) ? '1' : '',
            'stale_hours' => (int) $current['defaults']['stale_hours'],
            'slots' => $current['slots'],
            'sources' => $current['sources'],
        ];
        $selection = [ 'view' => $section ];
        $errors = [];
        $source_validation_data = null;

        if ( $section === 'defaults' ) {
            foreach ( [ 'default_item_limit', 'default_layout', 'default_show_excerpt', 'default_show_date', 'stale_hours' ] as $field ) {
                $post[$field] = $data[$field] ?? '';
            }
        } elseif ( $section === 'sidebar' ) {
            $post['slots']['sidebar'] = is_array( $data['slots']['sidebar'] ?? null ) ? $data['slots']['sidebar'] : [];
        } else {
            $selection = [ 'source' => 'new' ];
            $original_key = $this->normalizeKey( (string) ( $data['original_source_key'] ?? '' ) );
            $action = strtolower( mb_trim( (string) ( $data['action'] ?? 'save' ) ) );
            $delete = $action === 'delete';
            $source = is_array( $data['source'] ?? null ) ? $data['source'] : [];
            $next_sources = [];
            $found = $original_key === '';

            foreach ( $current['sources'] as $current_source ) {
                if ( $original_key !== '' && (string) ( $current_source['key'] ?? '' ) === $original_key ) {
                    $found = true;
                    continue;
                }
                $next_sources[] = $current_source;
            }

            if ( ! $found ) {
                $errors[] = 'Choose a feed source that still exists.';
            } elseif ( $delete && ! hash_equals( $original_key, mb_trim( (string) ( $data['delete_confirmation'] ?? '' ) ) ) ) {
                $errors[] = 'Confirm the feed source before deleting it.';
            } elseif ( ! $delete && mb_trim( (string) ( $source['label'] ?? '' ) ) === '' && mb_trim( (string) ( $source['key'] ?? '' ) ) === '' && mb_trim( (string) ( $source['url'] ?? '' ) ) === '' ) {
                $errors[] = 'Feed source needs a label and URL.';
            } elseif ( ! $delete ) {
                $normalized_source = $this->normalizeSource( $source, $current['defaults'] );
                $normalized_key = (string) ( $normalized_source['key'] ?? '' );
                foreach ( $next_sources as $other_source ) {
                    if ( $normalized_key !== '' && (string) ( $other_source['key'] ?? '' ) === $normalized_key ) {
                        $errors[] = 'Another feed source already uses that key.';
                        break;
                    }
                }
                $next_sources[] = $source;
                $source_validation_data = $source;
                $selection = [ 'source' => (string) ( $normalized_source['key'] ?? 'new' ) ];
                if ( $original_key !== '' && (string) ( $post['slots']['sidebar']['source'] ?? '' ) === $original_key ) {
                    $post['slots']['sidebar']['source'] = (string) ( $normalized_source['key'] ?? '' );
                }
            } elseif ( (string) ( $post['slots']['sidebar']['source'] ?? '' ) === $original_key ) {
                $post['slots']['sidebar']['enabled'] = false;
                $post['slots']['sidebar']['source'] = '';
            }
            if ( ! empty( $errors ) ) {
                $post['sources'] = $current['sources'];
                $selection = [ 'source' => $original_key !== '' ? $original_key : 'new' ];
            } else {
                $post['sources'] = $next_sources;
                if ( $delete ) {
                    $selection = [ 'source' => ! empty( $next_sources ) ? (string) ( $next_sources[0]['key'] ?? 'new' ) : 'new' ];
                }
            }
        }

        $settings = $this->buildSettingsFromPost( $post );
        if ( is_array( $source_validation_data ) && empty( $errors ) ) {
            $source_post = $post;
            $source_post['sources'] = [ $source_validation_data ];
            $source_result = $this->buildSettingsFromPostResult( $source_post );
            $errors = array_merge( $errors, (array) ( $source_result['errors'] ?? [] ) );
        }
        return( [ 'settings' => $settings, 'errors' => array_values( $errors ), 'selection' => $selection ] );
    }

    public function getSourceMap( ?array $settings = null ) : array {
        $settings = $settings ?? $this->getSettings();
        $map = [];
        foreach ( $settings['sources'] as $source ) {
            $map[(string) $source['key']] = $source;
        }
        return( $map );
    }

    public function refreshSources( ?array $only_source_keys = null ) : array {
        $settings = $this->getSettings();
        $cache = $this->getCache();
        $limited_keys = [];
        foreach ( (array) $only_source_keys as $key ) {
            $key = $this->normalizeKey( (string) $key );
            if ( $key !== '' ) {
                $limited_keys[$key] = true;
            }
        }
        $limited = ! empty( $limited_keys );
        $next_sources = $cache['sources'];
        $refreshed = 0;
        $failed = 0;
        $disabled = 0;
        $failure_messages = [];

        foreach ( $settings['sources'] as $source ) {
            $key = (string) $source['key'];
            if ( $limited && ! isset( $limited_keys[$key] ) ) {
                continue;
            }
            if ( empty( $source['enabled'] ) ) {
                $disabled++;
                $next_sources[$key] = $this->buildSourceStatus( $source, 'disabled', 'Source is disabled.', (array) ( $next_sources[$key] ?? [] ) );
                continue;
            }
            try {
                $next_sources[$key] = $this->fetchSource( $source );
                $refreshed++;
            } catch ( \Throwable $e ) {
                $failed++;
                $message = $this->publicRefreshErrorMessage( $e->getMessage() );
                $next_sources[$key] = $this->buildSourceStatus( $source, 'error', $message, (array) ( $next_sources[$key] ?? [] ) );
                $failure_messages[] = (string) $source['label'] . ': ' . $message;
            }
        }

        $cache = [
            'refreshed_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'sources' => $next_sources,
        ];
        if ( ! TinyMashLockedJsonFile::write( $this->cache_filename, $this->normalizeCache( $cache ), true ) ) {
            throw new \RuntimeException( 'cache_write' );
        }

        return(
            [
                'refreshed' => $refreshed,
                'failed' => $failed,
                'disabled' => $disabled,
                'source_count' => count( $next_sources ),
                'failures' => $failure_messages,
                'message' => $this->formatRefreshSummary( $refreshed, $failed, $failure_messages ),
            ]
        );
    }

    public function renderShortcodeHtml( array $attributes = [], array $context = [] ) : string {
        $source_key = $this->normalizeKey( (string) ( $attributes['source'] ?? '' ) );
        if ( $source_key === '' ) {
            return( $this->renderUnavailableHtml( 'RSS source is not configured.' ) );
        }

        $settings = $this->getSettings();
        $sources = $this->getSourceMap( $settings );
        $source = is_array( $sources[$source_key] ?? null ) ? $sources[$source_key] : [];
        if ( empty( $source ) || empty( $source['enabled'] ) ) {
            return( $this->renderUnavailableHtml( 'RSS source is not available.' ) );
        }

        $entry = is_array( $context['entry'] ?? null ) ? $context['entry'] : [];
        if ( (string) ( $entry['scope'] ?? 'root' ) === 'author' && empty( $source['authors_may_embed'] ) ) {
            return( $this->renderUnavailableHtml( 'RSS source is not enabled for author embeds.' ) );
        }

        return( $this->renderSourceHtml( $source_key, $attributes, $settings, false ) );
    }

    public function renderSlotHtml( string $slot ) : string {
        $slot = $this->normalizeSlotName( $slot );
        if ( $slot === '' ) {
            return( '' );
        }
        $settings = $this->getSettings();
        $slot_settings = is_array( $settings['slots'][$slot] ?? null ) ? $settings['slots'][$slot] : [];
        if ( empty( $slot_settings['enabled'] ) ) {
            return( '' );
        }
        $source_key = $this->normalizeKey( (string) ( $slot_settings['source'] ?? '' ) );
        if ( $source_key === '' ) {
            return( '' );
        }

        return( $this->renderSourceHtml( $source_key, $slot_settings, $settings, true ) );
    }

    public function renderSourceHtml( string $source_key, array $attributes = [], ?array $settings = null, bool $slot = false ) : string {
        $source_key = $this->normalizeKey( $source_key );
        $settings = $settings ?? $this->getSettings();
        $sources = $this->getSourceMap( $settings );
        $source = is_array( $sources[$source_key] ?? null ) ? $sources[$source_key] : [];
        if ( empty( $source ) ) {
            return( $this->renderUnavailableHtml( 'RSS source is not configured.' ) );
        }
        $cache = $this->getCache();
        $feed = is_array( $cache['sources'][$source_key] ?? null ) ? $cache['sources'][$source_key] : [];
        if ( empty( $feed['items'] ) ) {
            return( $this->renderUnavailableHtml( 'RSS source has not been refreshed yet.' ) );
        }

        $defaults = is_array( $settings['defaults'] ?? null ) ? $settings['defaults'] : $this->getDefaultSettings()['defaults'];
        $limit = $this->clampInt( $attributes['limit'] ?? $attributes['item_limit'] ?? $source['item_limit'] ?? $defaults['item_limit'], 1, self::MAX_ITEMS_PER_SOURCE );
        $layout = $this->normalizeLayout( (string) ( $attributes['layout'] ?? $defaults['layout'] ?? 'compact' ) );
        $show_excerpt = $this->normalizeBoolean( $attributes['excerpt'] ?? $attributes['show_excerpt'] ?? $defaults['show_excerpt'] ?? true );
        $show_date = $this->normalizeBoolean( $attributes['date'] ?? $attributes['show_date'] ?? $defaults['show_date'] ?? true );
        $title = mb_trim( (string) ( $attributes['title'] ?? '' ) );
        if ( $title === '' ) {
            $title = (string) ( $source['label'] ?? $feed['feed_title'] ?? 'RSS' );
        }
        $items = array_slice( (array) $feed['items'], 0, $limit );
        $status = (string) ( $feed['status'] ?? '' );
        $stale = $this->isFeedStale( $feed, (int) ( $defaults['stale_hours'] ?? 24 ) );

        $classes = 'tm-rss-reader tm-rss-reader-' . $layout . ( $slot ? ' tm-rss-reader-slot' : '' ) . ( $stale ? ' tm-rss-reader-stale' : '' );
        $html = '<div class="' . $this->escape( $classes ) . '" data-rss-source="' . $this->escape( $source_key ) . '" data-tm-heading-scope="component">';
        $html .= '<div class="tm-rss-reader-header">';
        $html .= '<h2 class="tm-rss-reader-title">' . $this->escape( $title ) . '</h2>';
        if ( $stale || $status === 'error' ) {
            $html .= '<span class="tm-rss-reader-status">' . $this->escape( $stale ? 'Stale' : 'Cached' ) . '</span>';
        }
        $html .= '</div>';
        $list_tag = $layout === 'list' ? 'ul' : 'ol';
        $html .= '<' . $list_tag . ' class="tm-rss-reader-list">';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $date = (string) ( $item['published_at_utc'] ?: $item['updated_at_utc'] ?: '' );
            $item_url = (string) ( $item['url'] ?? '' );
            $summary_parts = $this->prepareSummaryForRender( (string) ( $item['summary'] ?? '' ) );
            $summary = (string) ( $summary_parts['summary'] ?? '' );
            $html .= '<li class="tm-rss-reader-item">';
            $html .= '<a class="tm-rss-reader-link" href="' . $this->escape( $item_url ) . '" rel="nofollow noopener" target="_blank">' . $this->escape( (string) $item['title'] ) . '</a>';
            if ( $show_date && $date !== '' ) {
                $html .= '<div class="tm-rss-reader-date"><time datetime="' . $this->escape( $date ) . '">' . $this->escape( $this->formatDateLabel( $date ) ) . '</time></div>';
            }
            if ( $show_excerpt && $summary !== '' ) {
                $html .= '<div class="tm-rss-reader-summary">' . $this->escape( $summary ) . '</div>';
            }
            $html .= '</li>';
        }
        $html .= '</' . $list_tag . '>';
        if ( ! empty( $feed['feed_url'] ) ) {
            $html .= '<div class="tm-rss-reader-source">Source: <a href="' . $this->escape( (string) $feed['feed_url'] ) . '" rel="nofollow noopener" target="_blank">' . $this->escape( (string) ( $feed['feed_title'] ?: $source['label'] ) ) . '</a></div>';
        }
        $html .= '</div>';
        return( $html );
    }

    public function getAdminRows() : array {
        $settings = $this->getSettings();
        $cache = $this->getCache();
        $rows = [];
        foreach ( $settings['sources'] as $source ) {
            $key = (string) $source['key'];
            $feed = is_array( $cache['sources'][$key] ?? null ) ? $cache['sources'][$key] : [];
            $rows[] = [
                'source' => $source,
                'cache' => $feed,
                'item_count' => count( is_array( $feed['items'] ?? null ) ? $feed['items'] : [] ),
                'last_success_at_utc' => (string) ( $feed['last_success_at_utc'] ?? '' ),
                'checked_at_utc' => (string) ( $feed['checked_at_utc'] ?? '' ),
                'status' => (string) ( $feed['status'] ?? ( ! empty( $source['enabled'] ) ? 'unrefreshed' : 'disabled' ) ),
                'message' => (string) ( $feed['message'] ?? '' ),
            ];
        }
        return( $rows );
    }

    protected function fetchSource( array $source ) : array {
        $url = $this->validateSourceUrl( (string) ( $source['url'] ?? '' ) );
        $body = $this->fetchRemoteFeedBody( $url );
        $parsed = $this->parseFeedXml( $body, (int) ( $source['item_limit'] ?? self::MAX_ITEMS_PER_SOURCE ) );
        return(
            [
                'key' => (string) $source['key'],
                'status' => 'ok',
                'message' => 'RSS source refreshed.',
                'checked_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'last_success_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'feed_title' => $this->cleanText( (string) ( $parsed['title'] ?? $source['label'] ?? '' ), 160 ),
                'feed_url' => $this->normalizePublicUrl( (string) ( $parsed['url'] ?? '' ) ) ?: $url,
                'items' => is_array( $parsed['items'] ?? null ) ? $parsed['items'] : [],
            ]
        );
    }

    protected function fetchRemoteFeedBody( string $url ) : string {
        if ( ! function_exists( 'curl_init' ) ) {
            throw new \RuntimeException( 'curl_missing' );
        }
        $current_url = $this->validateSourceUrl( $url );
        for ( $redirect_count = 0; $redirect_count <= self::MAX_REDIRECTS; $redirect_count++ ) {
            $response = $this->fetchRemoteFeedResponse( $current_url );
            $http_code = (int) ( $response['http_code'] ?? 0 );
            if ( in_array( $http_code, [ 301, 302, 303, 307, 308 ], true ) ) {
                if ( $redirect_count >= self::MAX_REDIRECTS ) {
                    throw new \RuntimeException( 'redirect_limit' );
                }
                $location = mb_trim( (string) ( $response['location'] ?? '' ) );
                if ( $location === '' ) {
                    throw new \RuntimeException( 'redirect_url' );
                }
                $current_url = $this->validateSourceUrl( $this->resolveRedirectUrl( $current_url, $location ) );
                continue;
            }
            if ( $http_code < 200 || $http_code >= 300 ) {
                throw new \RuntimeException( 'remote_fetch' );
            }

            $body = (string) ( $response['body'] ?? '' );
            if ( trim( $body ) === '' ) {
                throw new \RuntimeException( 'empty_response' );
            }
            return( $body );
        }

        throw new \RuntimeException( 'redirect_limit' );
    }

    protected function fetchRemoteFeedResponse( string $url ) : array {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            throw new \RuntimeException( 'remote_fetch' );
        }
        $host = trim( (string) $parts['host'], '[]' );
        $port = (int) ( $parts['port'] ?? ( strtolower( (string) ( $parts['scheme'] ?? '' ) ) === 'https' ? 443 : 80 ) );
        $addresses = $this->resolvePublicHostAddresses( $host );
        $resolve_addresses = array_map(
            static fn( string $address ) : string => str_contains( $address, ':' ) ? '[' . $address . ']' : $address,
            $addresses
        );
        $body = '';
        $location = '';
        $too_large = false;
        $curl = curl_init( $url );
        if ( $curl === false ) {
            throw new \RuntimeException( 'remote_fetch' );
        }
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => filter_var( $host, FILTER_VALIDATE_IP ) === false
                    ? [ $host . ':' . $port . ':' . implode( ',', $resolve_addresses ) ]
                    : [],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => self::USER_AGENT_PREFIX . ( defined( 'APP_VERSION' ) ? APP_VERSION : 'dev' ),
                CURLOPT_HTTPHEADER => [ 'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.5' ],
                CURLOPT_HEADERFUNCTION => static function( mixed $handle, string $header ) use ( &$location ) : int {
                    if ( stripos( $header, 'Location:' ) === 0 ) {
                        $location = trim( substr( $header, 9 ) );
                    }
                    return( strlen( $header ) );
                },
                CURLOPT_WRITEFUNCTION => static function( mixed $handle, string $chunk ) use ( &$body, &$too_large ) : int {
                    if ( strlen( $body ) + strlen( $chunk ) > self::MAX_RESPONSE_BYTES ) {
                        $too_large = true;
                        return( 0 );
                    }
                    $body .= $chunk;
                    return( strlen( $chunk ) );
                },
            ]
        );
        $ok = curl_exec( $curl );
        $http_code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        $effective_url = (string) curl_getinfo( $curl, CURLINFO_EFFECTIVE_URL );
        $primary_ip = (string) curl_getinfo( $curl, CURLINFO_PRIMARY_IP );
        curl_close( $curl );
        if ( $too_large ) {
            throw new \RuntimeException( 'response_size' );
        }
        if ( $ok === false ) {
            throw new \RuntimeException( 'remote_fetch' );
        }
        $this->validateSourceUrl( $effective_url );
        if ( $primary_ip === '' || ! $this->isPublicIpAddress( $primary_ip ) ) {
            throw new \RuntimeException( 'private_host' );
        }
        return(
            [
                'http_code' => $http_code,
                'effective_url' => $effective_url,
                'primary_ip' => $primary_ip,
                'location' => $location,
                'body' => $body,
            ]
        );
    }

    protected function parseFeedXml( string $xml, int $limit ) : array {
        $previous = libxml_use_internal_errors( true );
        $document = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $document instanceof \SimpleXMLElement ) {
            throw new \RuntimeException( 'parse' );
        }

        $root_name = strtolower( $document->getName() );
        if ( $root_name === 'rss' || $root_name === 'rdf' ) {
            return( $this->parseRssDocument( $document, $limit ) );
        }
        if ( $root_name === 'feed' ) {
            return( $this->parseAtomDocument( $document, $limit ) );
        }

        throw new \RuntimeException( 'unsupported_feed' );
    }

    protected function parseRssDocument( \SimpleXMLElement $document, int $limit ) : array {
        $channel = isset( $document->channel ) ? $document->channel : $document;
        $items = [];
        foreach ( $channel->item as $item ) {
            if ( count( $items ) >= $limit ) {
                break;
            }
            $title = $this->cleanText( (string) $item->title, 180 );
            $url = $this->normalizePublicUrl( (string) $item->link );
            if ( $title === '' || $url === '' ) {
                continue;
            }
            $date = $this->normalizeDateTime( (string) ( $item->pubDate ?? $item->date ?? '' ) );
            $items[] = [
                'id' => $this->cleanText( (string) ( $item->guid ?? $url ), 240 ),
                'title' => $title,
                'url' => $url,
                'summary' => $this->cleanText( (string) ( $item->description ?? '' ), 500 ),
                'published_at_utc' => $date,
                'updated_at_utc' => $date,
            ];
        }

        return(
            [
                'title' => $this->cleanText( (string) ( $channel->title ?? '' ), 160 ),
                'url' => $this->normalizePublicUrl( (string) ( $channel->link ?? '' ) ),
                'items' => $items,
            ]
        );
    }

    protected function parseAtomDocument( \SimpleXMLElement $document, int $limit ) : array {
        $namespaces = $document->getNamespaces( true );
        $atom = isset( $namespaces[''] ) ? $document->children( $namespaces[''] ) : $document;
        $items = [];
        foreach ( $atom->entry as $entry ) {
            if ( count( $items ) >= $limit ) {
                break;
            }
            $title = $this->cleanText( (string) $entry->title, 180 );
            $url = $this->normalizePublicUrl( $this->atomLinkUrl( $entry ) );
            if ( $title === '' || $url === '' ) {
                continue;
            }
            $published = $this->normalizeDateTime( (string) ( $entry->published ?? '' ) );
            $updated = $this->normalizeDateTime( (string) ( $entry->updated ?? '' ) );
            $items[] = [
                'id' => $this->cleanText( (string) ( $entry->id ?? $url ), 240 ),
                'title' => $title,
                'url' => $url,
                'summary' => $this->cleanText( (string) ( $entry->summary ?? $entry->content ?? '' ), 500 ),
                'published_at_utc' => $published !== '' ? $published : $updated,
                'updated_at_utc' => $updated !== '' ? $updated : $published,
            ];
        }

        return(
            [
                'title' => $this->cleanText( (string) ( $atom->title ?? '' ), 160 ),
                'url' => $this->normalizePublicUrl( $this->atomLinkUrl( $atom ) ),
                'items' => $items,
            ]
        );
    }

    protected function atomLinkUrl( \SimpleXMLElement $element ) : string {
        $fallback = '';
        foreach ( $element->link as $link ) {
            $attributes = $link->attributes();
            $href = isset( $attributes['href'] ) ? (string) $attributes['href'] : '';
            if ( $href === '' ) {
                continue;
            }
            $rel = strtolower( trim( isset( $attributes['rel'] ) ? (string) $attributes['rel'] : 'alternate' ) );
            if ( $rel === 'alternate' ) {
                return( $href );
            }
            if ( $fallback === '' ) {
                $fallback = $href;
            }
        }

        return( $fallback );
    }

    protected function buildSourceStatus( array $source, string $status, string $message, array $previous = [] ) : array {
        return(
            [
                'key' => (string) ( $source['key'] ?? '' ),
                'status' => $status,
                'message' => $message,
                'checked_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'last_success_at_utc' => (string) ( $previous['last_success_at_utc'] ?? '' ),
                'feed_title' => (string) ( $previous['feed_title'] ?? $source['label'] ?? '' ),
                'feed_url' => (string) ( $previous['feed_url'] ?? $source['url'] ?? '' ),
                'items' => is_array( $previous['items'] ?? null ) ? $previous['items'] : [],
            ]
        );
    }

    protected function normalizeSource( array $source, array $defaults ) : array {
        $label = $this->cleanText( (string) ( $source['label'] ?? '' ), 120 );
        $key = $this->normalizeKey( (string) ( $source['key'] ?? '' ) );
        if ( $key === '' ) {
            $key = $this->normalizeKey( $label );
        }
        $url = mb_trim( (string) ( $source['url'] ?? '' ) );
        if ( $label === '' && $key === '' && $url === '' ) {
            return( [] );
        }

        return(
            [
                'key' => $key,
                'label' => $label !== '' ? $label : $key,
                'url' => $url,
                'enabled' => ! empty( $source['enabled'] ),
                'authors_may_embed' => ! empty( $source['authors_may_embed'] ),
                'item_limit' => $this->clampInt( $source['item_limit'] ?? $defaults['item_limit'] ?? 5, 1, self::MAX_ITEMS_PER_SOURCE ),
            ]
        );
    }

    protected function normalizeSlots( array $input ) : array {
        $defaults = $this->getDefaultSettings()['slots'];
        $slots = [];
        foreach ( $defaults as $slot => $slot_defaults ) {
            $raw = is_array( $input[$slot] ?? null ) ? $input[$slot] : [];
            $slots[$slot] = [
                'enabled' => ! empty( $raw['enabled'] ),
                'source' => $this->normalizeKey( (string) ( $raw['source'] ?? $slot_defaults['source'] ) ),
                'title' => $this->cleanText( (string) ( $raw['title'] ?? '' ), 120 ),
                'item_limit' => $this->clampInt( $raw['item_limit'] ?? $slot_defaults['item_limit'], 1, self::MAX_ITEMS_PER_SOURCE ),
                'layout' => $this->normalizeLayout( (string) ( $raw['layout'] ?? $slot_defaults['layout'] ) ),
                'show_excerpt' => ! empty( $raw['show_excerpt'] ),
                'show_date' => ! empty( $raw['show_date'] ),
            ];
        }
        return( $slots );
    }

    protected function buildSlotsFromPost( array $slots ) : array {
        $built = [];
        foreach ( $this->getDefaultSettings()['slots'] as $slot => $defaults ) {
            $raw = is_array( $slots[$slot] ?? null ) ? $slots[$slot] : [];
            $built[$slot] = [
                'enabled' => ! empty( $raw['enabled'] ),
                'source' => (string) ( $raw['source'] ?? '' ),
                'title' => (string) ( $raw['title'] ?? '' ),
                'item_limit' => (int) ( $raw['item_limit'] ?? $defaults['item_limit'] ),
                'layout' => (string) ( $raw['layout'] ?? $defaults['layout'] ),
                'show_excerpt' => ! empty( $raw['show_excerpt'] ),
                'show_date' => ! empty( $raw['show_date'] ),
            ];
        }
        return( $built );
    }

    protected function validateSourceUrl( string $url ) : string {
        $url = mb_trim( $url );
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) {
            throw new \InvalidArgumentException( 'url' );
        }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host = strtolower( trim( (string) ( $parts['host'] ?? '' ) ) );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || $host === '' || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            throw new \InvalidArgumentException( 'url' );
        }
        if ( ! $this->hostResolvesToPublicAddress( $host ) ) {
            throw new \InvalidArgumentException( 'private_host' );
        }

        return( $url );
    }

    protected function resolveRedirectUrl( string $base_url, string $location ) : string {
        $location = mb_trim( html_entity_decode( $location, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $location === '' ) {
            throw new \InvalidArgumentException( 'redirect_url' );
        }
        if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $location ) === 1 ) {
            return( $location );
        }
        $base_parts = parse_url( $base_url );
        if ( ! is_array( $base_parts ) || empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
            throw new \InvalidArgumentException( 'redirect_url' );
        }
        $scheme = strtolower( (string) $base_parts['scheme'] );
        $host = (string) $base_parts['host'];
        $port = isset( $base_parts['port'] ) ? ':' . (int) $base_parts['port'] : '';
        if ( str_starts_with( $location, '//' ) ) {
            return( $scheme . ':' . $location );
        }
        if ( str_starts_with( $location, '?' ) ) {
            return( $scheme . '://' . $host . $port . ( (string) ( $base_parts['path'] ?? '/' ) ?: '/' ) . $location );
        }
        if ( str_starts_with( $location, '#' ) ) {
            $base_path = ( (string) ( $base_parts['path'] ?? '/' ) ?: '/' );
            $base_query = isset( $base_parts['query'] ) ? '?' . (string) $base_parts['query'] : '';
            return( $scheme . '://' . $host . $port . $base_path . $base_query . $location );
        }
        if ( str_starts_with( $location, '/' ) ) {
            return( $scheme . '://' . $host . $port . $location );
        }
        $path = (string) ( $base_parts['path'] ?? '/' );
        $directory = preg_replace( '~/[^/]*$~', '/', $path ) ?? '/';
        return( $scheme . '://' . $host . $port . $this->normalizeRedirectPath( $directory . $location ) );
    }

    protected function normalizeRedirectPath( string $path ) : string {
        $query = '';
        $fragment = '';
        $hash_position = strpos( $path, '#' );
        if ( $hash_position !== false ) {
            $fragment = substr( $path, $hash_position );
            $path = substr( $path, 0, $hash_position );
        }
        $query_position = strpos( $path, '?' );
        if ( $query_position !== false ) {
            $query = substr( $path, $query_position );
            $path = substr( $path, 0, $query_position );
        }
        $has_trailing_slash = $path !== '' && str_ends_with( $path, '/' );
        $segments = [];
        foreach ( explode( '/', $path ) as $segment ) {
            if ( $segment === '' || $segment === '.' ) {
                continue;
            }
            if ( $segment === '..' ) {
                array_pop( $segments );
                continue;
            }
            $segments[] = $segment;
        }
        $normalized = '/' . implode( '/', $segments );
        if ( $has_trailing_slash && $normalized !== '/' ) {
            $normalized .= '/';
        }
        return( $normalized . $query . $fragment );
    }

    protected function hostResolvesToPublicAddress( string $host ) : bool {
        try {
            $this->resolvePublicHostAddresses( $host );
            return( true );
        } catch ( \InvalidArgumentException ) {
            return( false );
        }
    }

    protected function resolvePublicHostAddresses( string $host ) : array {
        $host = trim( $host, '[]' );
        if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
            if ( ! $this->isPublicIpAddress( $host ) ) {
                throw new \InvalidArgumentException( 'private_host' );
            }
            return( [ $host ] );
        }
        if ( $host === 'localhost' || str_ends_with( $host, '.local') || str_ends_with( $host, '.localhost' ) ) {
            throw new \InvalidArgumentException( 'private_host' );
        }
        $records = dns_get_record( $host, DNS_A | DNS_AAAA );
        if ( ! is_array( $records ) ) {
            throw new \InvalidArgumentException( 'private_host' );
        }
        $addresses = [];
        foreach ( $records as $record ) {
            $address = (string) ( $record['ip'] ?? $record['ipv6'] ?? '' );
            if ( $address === '' || ! $this->isPublicIpAddress( $address ) ) {
                throw new \InvalidArgumentException( 'private_host' );
            }
            $addresses[] = $address;
        }
        if ( $addresses === [] ) {
            throw new \InvalidArgumentException( 'private_host' );
        }
        return( array_values( array_unique( $addresses ) ) );
    }

    protected function isPublicIpAddress( string $address ) : bool {
        $packed = inet_pton( $address );
        if ( $packed === false ) {
            return( false );
        }
        if ( strlen( $packed ) === 16 && (
            substr( $packed, 0, 2 ) === "\x20\x02" ||
            substr( $packed, 0, 4 ) === "\x20\x01\x00\x00" ||
            substr( $packed, 0, 12 ) === hex2bin( '0064ff9b0000000000000000' ) ||
            substr( $packed, 0, 6 ) === hex2bin( '0064ff9b0001' )
        ) ) {
            return( false );
        }
        return(
            filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false
        );
    }

    protected function normalizePublicUrl( string $url ) : string {
        $url = mb_trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return( '' );
        }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host = (string) ( $parts['host'] ?? '' );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || $host === '' ) {
            return( '' );
        }
        return( mb_substr( $url, 0, 500 ) );
    }

    protected function normalizeKey( string $value ) : string {
        $value = strtolower( mb_trim( $value ) );
        $value = preg_replace( '/[^a-z0-9_-]+/', '-', $value ) ?? '';
        $value = preg_replace( '/-+/', '-', $value ) ?? '';
        return( trim( $value, '-_' ) );
    }

    protected function normalizeLayout( string $layout ) : string {
        $layout = strtolower( trim( $layout ) );
        return( in_array( $layout, [ 'compact', 'list', 'headlines' ], true ) ? $layout : 'compact' );
    }

    protected function normalizeSlotName( string $slot ) : string {
        $slot = strtolower( trim( $slot ) );
        return( in_array( $slot, [ 'sidebar' ], true ) ? $slot : '' );
    }

    protected function normalizeBoolean( mixed $value ) : bool {
        if ( is_bool( $value ) ) {
            return( $value );
        }
        $value = strtolower( trim( (string) $value ) );
        return( in_array( $value, [ '1', 'true', 'yes', 'on' ], true ) );
    }

    protected function clampInt( mixed $value, int $min, int $max ) : int {
        $value = (int) $value;
        return( max( $min, min( $max, $value ) ) );
    }

    protected function normalizeDateTime( string $value ) : string {
        $value = mb_trim( $value );
        if ( $value === '' ) {
            return( '' );
        }
        $timestamp = strtotime( $value );
        if ( $timestamp === false ) {
            return( '' );
        }
        return( gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) );
    }

    protected function isFeedStale( array $feed, int $stale_hours ) : bool {
        $last_success = (string) ( $feed['last_success_at_utc'] ?? '' );
        $timestamp = $last_success !== '' ? strtotime( $last_success ) : false;
        if ( $timestamp === false ) {
            return( false );
        }
        return( ( time() - $timestamp ) > max( 1, $stale_hours ) * 3600 );
    }

    protected function formatDateLabel( string $utc_datetime ) : string {
        $timestamp = strtotime( $utc_datetime );
        if ( $timestamp === false ) {
            return( '' );
        }
        return( gmdate( 'Y-m-d', $timestamp ) );
    }

    protected function prepareSummaryForRender( string $summary ) : array {
        $summary = mb_trim( $summary );
        $has_read_more = false;
        if ( $summary !== '' ) {
            $trimmed_summary = preg_replace( '/(?:\s*(?:\.{3}|…))?\s+Read more\s*$/iu', '', $summary );
            if ( is_string( $trimmed_summary ) && $trimmed_summary !== $summary ) {
                $summary = mb_trim( $trimmed_summary );
                $has_read_more = true;
            }
        }

        return( [ 'summary' => $summary, 'read_more' => $has_read_more ] );
    }

    protected function cleanText( string $value, int $max_length ) : string {
        $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = strip_tags( $value );
        $value = preg_replace( '/\s+/u', ' ', $value ) ?? '';
        $value = mb_trim( $value );
        if ( mb_strlen( $value ) > $max_length ) {
            $value = mb_substr( $value, 0, max( 0, $max_length - 1 ) ) . '...';
        }
        return( $value );
    }

    protected function publicRefreshErrorMessage( string $message ) : string {
        return match ( $message ) {
            'curl_missing' => 'Remote feed fetching is unavailable on this server.',
            'response_size' => 'Feed response exceeded the size limit.',
            'empty_response' => 'Feed response was empty.',
            'remote_fetch' => 'Feed could not be fetched.',
            'redirect_limit' => 'Feed redirected too many times.',
            'redirect_url' => 'Feed redirected to an unsupported URL.',
            'parse' => 'Feed XML could not be parsed.',
            'unsupported_feed' => 'Feed format is not supported.',
            'private_host', 'url' => 'Feed URL is not an allowed public HTTP or HTTPS URL.',
            default => 'Feed refresh failed.',
        };
    }

    protected function formatRefreshSummary( int $refreshed, int $failed, array $failure_messages ) : string {
        $message = $refreshed . ' source' . ( $refreshed === 1 ? '' : 's' ) . ' refreshed';
        if ( $failed > 0 ) {
            $message .= ', ' . $failed . ' failed';
        }
        $message .= '.';
        if ( ! empty( $failure_messages ) ) {
            $message .= ' ' . implode( ' ', array_slice( $failure_messages, 0, 3 ) );
        }
        return( $message );
    }

    protected function trimCacheToSettings( array $settings ) : void {
        $source_keys = [];
        foreach ( $settings['sources'] as $source ) {
            $source_keys[(string) $source['key']] = true;
        }
        $cache = $this->getCache();
        $cache['sources'] = array_intersect_key( $cache['sources'], $source_keys );
        TinyMashLockedJsonFile::write( $this->cache_filename, $cache, true );
    }

    protected function renderUnavailableHtml( string $message ) : string {
        return( '<div class="tm-rss-reader tm-rss-reader-unavailable" data-tm-heading-scope="component"><span>' . $this->escape( $message ) . '</span></div>' );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
