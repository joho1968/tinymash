<?php
namespace app\classes;

class TinyMashEmbedService {

    protected const REPORT_ITEM_LIMIT = 100;

    protected const YOUTUBE_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    ];

    public function registerCoreShortcode( TinyMashShortcodeRegistry $registry ) : void {
        $registry->registerShortcode(
            'core',
            'embed',
            fn( array $shortcode ) : array => $this->renderShortcode( $shortcode ),
            [
                'label' => 'External media',
                'description' => 'Loads recognized external media after visitor confirmation.',
                'example' => '[embed provider="youtube" id="iV8B8gT0VTY"]',
                'block' => true,
            ]
        );
    }

    public function createImportReport() : array {
        return(
            [
                'recognized' => 0,
                'converted' => 0,
                'unsupported' => 0,
                'items' => [],
                'items_truncated' => false,
            ]
        );
    }

    public function mergeImportReport( array $report, array $conversion, array $entry = [] ) : array {
        foreach ( [ 'recognized', 'converted', 'unsupported' ] as $count_key ) {
            $report[$count_key] = max( 0, (int) ( $report[$count_key] ?? 0 ) )
                + max( 0, (int) ( $conversion[$count_key] ?? 0 ) );
        }

        $items = is_array( $report['items'] ?? null ) ? $report['items'] : [];
        if ( ! empty( $conversion['items_truncated'] ) ) {
            $report['items_truncated'] = true;
        }
        foreach ( (array) ( $conversion['items'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            if ( count( $items ) >= self::REPORT_ITEM_LIMIT ) {
                $report['items_truncated'] = true;
                break;
            }

            $items[] = [
                'status' => (string) ( $item['status'] ?? '' ),
                'provider' => (string) ( $item['provider'] ?? '' ),
                'source_url' => (string) ( $item['source_url'] ?? '' ),
                'title' => (string) ( $entry['title'] ?? '' ),
                'slug' => (string) ( $entry['slug'] ?? '' ),
                'entry_type' => (string) ( $entry['entry_type'] ?? '' ),
            ];
        }
        $report['items'] = $items;

        return( $report );
    }

    public function convertImportedHtml( string $html ) : array {
        $report = $this->createImportReport();
        if ( $html === '' || stripos( $html, '<iframe' ) === false ) {
            return( [ 'content' => $html ] + $report );
        }

        $converted = preg_replace_callback(
            '#<iframe\b[^>]*>.*?</iframe\s*>|<iframe\b[^>]*/\s*>|<iframe\b[^>]*>#isu',
            function( array $matches ) use ( &$report ) : string {
                $source_url = $this->extractIframeSource( (string) ( $matches[0] ?? '' ) );
                $youtube_id = $this->extractYouTubeId( $source_url );
                if ( $youtube_id !== '' ) {
                    $shortcode = '[embed provider="youtube" id="' . $youtube_id . '"]';
                    $report['recognized']++;
                    $report['converted']++;
                    $this->appendReportItem( $report, [
                        'status' => 'converted',
                        'provider' => 'youtube',
                        'source_url' => $source_url,
                        'shortcode' => $shortcode,
                    ] );
                    return( "\n\n" . $shortcode . "\n\n" );
                }

                $safe_external_url = $this->isSafeExternalUrl( $source_url );
                $report['unsupported']++;
                $this->appendReportItem( $report, [
                    'status' => $safe_external_url ? 'linked' : 'removed',
                    'provider' => '',
                    'source_url' => mb_substr( $source_url, 0, 1000 ),
                    'shortcode' => '',
                ] );

                if ( ! $safe_external_url ) {
                    return( '' );
                }

                return(
                    "\n\n<a href=\""
                    . htmlspecialchars( $source_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
                    . "\">Open embedded content</a>\n\n"
                );
            },
            $html
        );

        return( [ 'content' => is_string( $converted ) ? $converted : $html ] + $report );
    }

    public function renderShortcode( array $shortcode ) : array {
        $attributes = is_array( $shortcode['attributes'] ?? null ) ? $shortcode['attributes'] : [];
        $context = is_array( $shortcode['context'] ?? null ) ? $shortcode['context'] : [];
        $provider = strtolower( trim( (string) ( $attributes['provider'] ?? '' ) ) );
        $video_id = trim( (string) ( $attributes['id'] ?? '' ) );
        if ( $provider !== 'youtube' || ! $this->isValidYouTubeId( $video_id ) ) {
            return( [ 'markdown' => '' ] );
        }

        $watch_url = 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id );
        $surface = strtolower( trim( (string) ( $context['surface'] ?? 'content' ) ) );
        if ( $surface === 'summary' ) {
            return( [ 'markdown' => '' ] );
        }
        if ( $surface === 'feed' ) {
            return( [ 'markdown' => '[Watch video on YouTube](' . $watch_url . ')' ] );
        }

        $escaped_id = htmlspecialchars( $video_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $escaped_watch_url = htmlspecialchars( $watch_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $html = '<figure class="tm-content-embed my-4">'
            . '<div class="ratio ratio-16x9 rounded-3 overflow-hidden bg-dark">'
            . '<a class="d-flex align-items-center justify-content-center p-3 text-center text-white text-decoration-none" href="' . $escaped_watch_url . '" data-tm-embed-provider="youtube" data-tm-embed-id="' . $escaped_id . '">'
            . '<span>Load video from YouTube</span>'
            . '</a></div>'
            . '<figcaption class="small mt-2"><a href="' . $escaped_watch_url . '" target="_blank" rel="noopener noreferrer">Open on YouTube</a></figcaption>'
            . '</figure>';

        return( [ 'html' => $html ] );
    }

    protected function extractIframeSource( string $iframe_html ) : string {
        if ( $iframe_html === '' || ! class_exists( '\DOMDocument' ) ) {
            return( '' );
        }

        $document = new \DOMDocument( '1.0', 'UTF-8' );
        $previous_errors = libxml_use_internal_errors( true );
        $loaded = $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $iframe_html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );
        if ( ! $loaded ) {
            return( '' );
        }

        $iframe = $document->getElementsByTagName( 'iframe' )->item( 0 );
        if ( ! $iframe instanceof \DOMElement ) {
            return( '' );
        }

        return( mb_trim( html_entity_decode( $iframe->getAttribute( 'src' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
    }

    protected function appendReportItem( array &$report, array $item ) : void {
        $items = is_array( $report['items'] ?? null ) ? $report['items'] : [];
        if ( count( $items ) >= self::REPORT_ITEM_LIMIT ) {
            $report['items_truncated'] = true;
            return;
        }

        $items[] = $item;
        $report['items'] = $items;
    }

    protected function extractYouTubeId( string $source_url ) : string {
        if ( ! $this->isSafeExternalUrl( $source_url ) ) {
            return( '' );
        }

        $scheme = strtolower( (string) parse_url( $source_url, PHP_URL_SCHEME ) );
        $host = strtolower( rtrim( (string) parse_url( $source_url, PHP_URL_HOST ), '.' ) );
        $path = (string) parse_url( $source_url, PHP_URL_PATH );
        $port = parse_url( $source_url, PHP_URL_PORT );
        if ( $scheme !== 'https' || ( is_int( $port ) && $port !== 443 ) || ! in_array( $host, self::YOUTUBE_HOSTS, true ) ) {
            return( '' );
        }
        if ( preg_match( '#^/embed/([A-Za-z0-9_-]{11})/?$#D', $path, $matches ) !== 1 ) {
            return( '' );
        }

        return( (string) ( $matches[1] ?? '' ) );
    }

    protected function isValidYouTubeId( string $video_id ) : bool {
        return( preg_match( '/^[A-Za-z0-9_-]{11}$/D', $video_id ) === 1 );
    }

    protected function isSafeExternalUrl( string $url ) : bool {
        $url = mb_trim( $url );
        if ( $url === '' || preg_match( '/[\x00-\x1F\x7F]/u', $url ) === 1 ) {
            return( false );
        }

        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return( false );
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
        return(
            in_array( $scheme, [ 'http', 'https' ], true )
            && $host !== ''
            && empty( $parts['user'] )
            && empty( $parts['pass'] )
        );
    }
}
