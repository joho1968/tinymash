<?php
declare(strict_types=1);
namespace app\classes;

class TinyMashUrlResolver {

    protected string $base_url;
    protected string $base_path;
    protected string $base_origin;

    public function __construct( string $base_url ) {
        $this->base_url = rtrim( trim( $base_url ), '/' );
        $parts = parse_url( $this->base_url );
        if ( ! is_array( $parts )
            || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
            || empty( $parts['host'] )
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
            || preg_match( '/[\x00-\x20\x7F<>"\'`\\\\]/', (string) ( $parts['path'] ?? '' ) ) === 1 ) {
            $this->base_url = '';
            $this->base_path = '';
            $this->base_origin = '';
            return;
        }

        $path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
        $this->base_path = $path === '/' ? '' : $path;
        $this->base_origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
        if ( isset( $parts['port'] ) ) {
            $this->base_origin .= ':' . (int) $parts['port'];
        }
    }

    public function getBasePath() : string {
        return( $this->base_path );
    }

    /** Resolve a route or stored root path for browser output without changing external URLs. */
    public function toBrowserUrl( string $url ) : string {
        if ( $url === '' || $this->base_path === '' || $url[0] === '#' || $url[0] === '?' ) {
            return( $url );
        }

        if ( preg_match( '~^https?://~i', $url ) === 1 ) {
            return( $this->resolveLocalAbsoluteUrl( $url ) );
        }
        if ( ! str_starts_with( $url, '/' ) || str_starts_with( $url, '//' ) ) {
            return( $url );
        }

        return( $this->prefixRootPath( $url ) );
    }

    public function toAbsoluteUrl( string $url ) : string {
        if ( $url === '' || str_starts_with( $url, '//' ) ) {
            return( $url );
        }
        if ( preg_match( '~^https?://~i', $url ) === 1 ) {
            return( $this->resolveLocalAbsoluteUrl( $url ) );
        }
        if ( preg_match( '~^[a-z][a-z0-9+.-]*:~i', $url ) === 1 ) {
            return( $url );
        }
        if ( $this->base_url === '' || ! str_starts_with( $url, '/' ) ) {
            return( $url );
        }

        $browser_url = $this->toBrowserUrl( $url );
        return( $this->base_origin . $browser_url );
    }

    /** Resolve URLs in a response copy without changing stored application records. */
    public function resolveResponseData( array $data ) : array {
        return( $this->resolveArrayValues( $data ) );
    }

    public function rewriteHtml( string $html ) : string {
        if ( $html === '' || $this->base_path === '' ) {
            return( $html );
        }

        $rewritten = preg_replace_callback(
            '~<[a-z][^<>]*>~isu',
            fn( array $matches ) : string => $this->rewriteHtmlTag( $matches[0] ),
            $html
        );
        $rewritten = is_string( $rewritten ) ? $rewritten : $html;

        $rewritten = preg_replace_callback(
            '~(<style\b[^>]*>)(.*?)(</style>)~isu',
            fn( array $matches ) : string => $matches[1] . $this->rewriteCssUrls( $matches[2] ) . $matches[3],
            $rewritten
        );
        return( is_string( $rewritten ) ? $rewritten : $html );
    }

    public function rewriteCssUrls( string $css ) : string {
        if ( $css === '' || $this->base_path === '' ) {
            return( $css );
        }

        $rewritten = preg_replace_callback(
            '~url\(\s*(["\']?)([^"\')]+)\1\s*\)~iu',
            function( array $matches ) : string {
                $quote = $matches[1];
                return( 'url(' . $quote . $this->toBrowserUrl( trim( $matches[2] ) ) . $quote . ')' );
            },
            $css
        );
        return( is_string( $rewritten ) ? $rewritten : $css );
    }

    protected function resolveArrayValues( array $data, string $parent_key = '' ) : array {
        foreach ( $data as $key => $value ) {
            $value_key = is_string( $key ) ? strtolower( $key ) : $parent_key;
            if ( is_array( $value ) ) {
                $data[$key] = $this->resolveArrayValues( $value, $value_key );
                continue;
            }
            if ( ! is_string( $value ) ) {
                continue;
            }

            if ( $this->keyContainsHtml( $value_key ) ) {
                $data[$key] = $this->rewriteHtml( $value );
            } elseif ( $this->keyContainsCss( $value_key ) ) {
                $data[$key] = $this->rewriteCssUrls( $value );
            } elseif ( $this->keyContainsUrl( $value_key ) || $this->keyContainsUrl( $parent_key ) ) {
                $data[$key] = $this->toBrowserUrl( $value );
            }
        }

        return( $data );
    }

    protected function keyContainsUrl( string $key ) : bool {
        if ( $key === 'return_url' ) {
            return( false );
        }

        return(
            in_array( $key, [ 'url', 'urls', 'href', 'src', 'action', 'poster' ], true )
            || str_ends_with( $key, '_url' )
            || str_ends_with( $key, '_urls' )
            || str_ends_with( $key, '_endpoint' )
        );
    }

    protected function keyContainsHtml( string $key ) : bool {
        return( $key === 'html' || str_ends_with( $key, '_html' ) );
    }

    protected function keyContainsCss( string $key ) : bool {
        return( $key === 'style' || str_ends_with( $key, '_style' ) || str_ends_with( $key, '_css' ) );
    }

    protected function prefixRootPath( string $url ) : string {
        $suffix_offset = strcspn( $url, '?#' );
        $path = substr( $url, 0, $suffix_offset );
        $suffix = substr( $url, $suffix_offset );
        if ( $path === $this->base_path || str_starts_with( $path, $this->base_path . '/' ) ) {
            return( $url );
        }
        if ( $path === '/' ) {
            return( $this->base_path . '/' . $suffix );
        }

        return( $this->base_path . $path . $suffix );
    }

    protected function resolveLocalAbsoluteUrl( string $url ) : string {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return( $url );
        }

        $origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
        if ( isset( $parts['port'] ) ) {
            $origin .= ':' . (int) $parts['port'];
        }
        if ( $origin !== $this->base_origin ) {
            return( $url );
        }

        $path = (string) ( $parts['path'] ?? '/' );
        $resolved = $this->prefixRootPath( $path );
        if ( isset( $parts['query'] ) ) {
            $resolved .= '?' . $parts['query'];
        }
        if ( isset( $parts['fragment'] ) ) {
            $resolved .= '#' . $parts['fragment'];
        }
        return( $this->base_origin . $resolved );
    }

    protected function rewriteHtmlTag( string $tag ) : string {
        $rewritten = preg_replace_callback(
            '~(\b(?:href|src|action|formaction|poster|xlink:href|data-source|data-[a-z0-9_-]*(?:url|endpoint))\s*=\s*)(["\'])(.*?)\2~isu',
            fn( array $matches ) : string => $matches[1] . $matches[2]
                . $this->escapeHtmlAttribute( $this->toBrowserUrl( $this->decodeHtmlAttribute( $matches[3] ) ), $matches[2] )
                . $matches[2],
            $tag
        );
        $rewritten = is_string( $rewritten ) ? $rewritten : $tag;

        $rewritten = preg_replace_callback(
            '~(\bsrcset\s*=\s*)(["\'])(.*?)\2~isu',
            fn( array $matches ) : string => $matches[1] . $matches[2]
                . $this->escapeHtmlAttribute( $this->rewriteSrcset( $this->decodeHtmlAttribute( $matches[3] ) ), $matches[2] )
                . $matches[2],
            $rewritten
        );
        $rewritten = is_string( $rewritten ) ? $rewritten : $tag;

        $rewritten = preg_replace_callback(
            '~(\bstyle\s*=\s*)(["\'])(.*?)\2~isu',
            fn( array $matches ) : string => $matches[1] . $matches[2]
                . $this->escapeHtmlAttribute( $this->rewriteCssUrls( $this->decodeHtmlAttribute( $matches[3] ) ), $matches[2] )
                . $matches[2],
            $rewritten
        );
        return( is_string( $rewritten ) ? $rewritten : $tag );
    }

    protected function rewriteSrcset( string $srcset ) : string {
        $candidates = explode( ',', $srcset );
        foreach ( $candidates as $index => $candidate ) {
            if ( preg_match( '/^(\s*)(\S+)(.*)$/s', $candidate, $matches ) !== 1 ) {
                continue;
            }
            $candidates[$index] = $matches[1] . $this->toBrowserUrl( $matches[2] ) . $matches[3];
        }
        return( implode( ',', $candidates ) );
    }

    protected function decodeHtmlAttribute( string $value ) : string {
        return( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }

    protected function escapeHtmlAttribute( string $value, string $quote ) : string {
        $flags = ENT_SUBSTITUTE | ENT_HTML5 | ( $quote === "'" ? ENT_QUOTES : ENT_COMPAT );
        return( htmlspecialchars( $value, $flags, 'UTF-8' ) );
    }
}
