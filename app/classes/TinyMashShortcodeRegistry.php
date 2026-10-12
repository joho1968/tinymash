<?php
namespace app\classes;

class TinyMashShortcodeRegistry {

    protected array $shortcodes = [];
    protected bool $dynamic_rendered = false;
    protected array $request_page_hints = [];
    protected array $trusted_html_fragments = [];
    protected string $unknown_shortcode_mode = 'code';

    public function __construct( string $unknown_shortcode_mode = 'code' ) {
        $this->unknown_shortcode_mode = $this->normalizeUnknownShortcodeMode( $unknown_shortcode_mode );
    }

    public function setUnknownShortcodeMode( string $mode ) : void {
        $this->unknown_shortcode_mode = $this->normalizeUnknownShortcodeMode( $mode );
    }

    public function getUnknownShortcodeMode() : string {
        return( $this->unknown_shortcode_mode );
    }

    public function registerShortcode( string $plugin_key, string $name, callable $handler, array $definition = [] ) : bool {
        $plugin_key = $this->normalizePluginKey( $plugin_key );
        $name = $this->normalizeShortcodeName( $name );
        if ( $plugin_key === '' || $name === '' ) {
            return( false );
        }

        $this->shortcodes[$name] = [
            'name' => $name,
            'plugin_key' => $plugin_key,
            'label' => mb_trim( (string) ( $definition['label'] ?? $name ) ),
            'description' => mb_trim( (string) ( $definition['description'] ?? '' ) ),
            'example' => mb_trim( (string) ( $definition['example'] ?? '[' . $name . ']' ) ),
            'block' => ! empty( $definition['block'] ),
            'dynamic' => ! empty( $definition['dynamic'] ),
            'handler' => $handler,
        ];

        return( true );
    }

    public function getRegisteredShortcodes() : array {
        $shortcodes = [];
        foreach ( $this->shortcodes as $shortcode ) {
            $shortcodes[] = [
                'name' => (string) $shortcode['name'],
                'plugin_key' => (string) $shortcode['plugin_key'],
                'label' => (string) $shortcode['label'],
                'description' => (string) $shortcode['description'],
                'example' => (string) $shortcode['example'],
                'block' => ! empty( $shortcode['block'] ),
                'dynamic' => ! empty( $shortcode['dynamic'] ),
            ];
        }

        usort(
            $shortcodes,
            static function( array $left, array $right ) : int {
                $plugin_compare = strcmp( (string) ( $left['plugin_key'] ?? '' ), (string) ( $right['plugin_key'] ?? '' ) );
                if ( $plugin_compare !== 0 ) {
                    return( $plugin_compare );
                }

                return( strcmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) ) );
            }
        );

        return( $shortcodes );
    }

    public function hasRegisteredShortcodes() : bool {
        return( ! empty( $this->shortcodes ) );
    }

    public function sourceMayContainShortcodes( string $source ) : bool {
        if ( $source === '' || ! str_contains( $source, '[' ) ) {
            return( false );
        }

        return(
            preg_match(
                '/(?<!!)\[(?:\/)?[a-z][a-z0-9_-]*(?:\s+[^\]\r\n]*)?(?:\/)?\](?![ \t]*(?:\(|\[|:))/iu',
                $source
            ) === 1
        );
    }

    public function scanShortcodeCandidates( string $source ) : array {
        if ( $source === '' || ! str_contains( $source, '[' ) ) {
            return( [] );
        }

        [ $protected_source ] = $this->protectCodeSegments( $source );
        $pattern = '/(?<![!\\\\])\[([a-z][a-z0-9_-]*)([^\]\r\n]*?)\](?:(?![ \t]*(?:\(|\[|:))|(?=[ \t]*\[\/\1\]))/iu';
        if ( preg_match_all( $pattern, $protected_source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) === false ) {
            return( [] );
        }

        $closing_names = [];
        if ( preg_match_all( '/\[\/([a-z][a-z0-9_-]*)\]/iu', $protected_source, $closing_matches ) !== false ) {
            foreach ( $closing_matches[1] ?? [] as $closing_name ) {
                $closing_names[strtolower( (string) $closing_name )] = true;
            }
        }

        $candidates = [];
        foreach ( $matches as $match ) {
            $name = strtolower( (string) ( $match[1][0] ?? '' ) );
            $raw = (string) ( $match[0][0] ?? '' );
            $offset = (int) ( $match[0][1] ?? 0 );
            $attributes = mb_trim( (string) ( $match[2][0] ?? '' ) );
            $registered = isset( $this->shortcodes[$name] );
            if ( ! $registered && $attributes !== '' && ! str_contains( $attributes, '=' ) && ! str_ends_with( $attributes, '/' ) && ! isset( $closing_names[$name] ) ) {
                continue;
            }
            if ( ! $registered
                && ! str_contains( $attributes, '=' )
                && ! str_ends_with( $attributes, '/' )
                && ! isset( $closing_names[$name] )
                && ! str_contains( $name, '_' )
                && ! $this->isStandaloneShortcodeCandidate( $protected_source, $offset, $raw ) ) {
                continue;
            }

            $candidates[] = [ 'name' => $name, 'supported' => $registered ];
        }

        return( $candidates );
    }

    public function renderShortcodes( string $source, array $context = [] ) : string {
        if ( $source === '' || ! $this->sourceMayContainShortcodes( $source ) ) {
            return( $source );
        }

        [ $protected_source, $protected_segments ] = $this->protectCodeSegments( $source );
        $rendered_source = $this->renderShortcodeFragment( $protected_source, $context, 0 );
        return( $this->restoreCodeSegments( $rendered_source, $protected_segments ) );
    }

    public function resetRequestState() : void {
        $this->dynamic_rendered = false;
        $this->request_page_hints = [];
        $this->trusted_html_fragments = [];
    }

    public function hasDynamicRenderForRequest() : bool {
        return( $this->dynamic_rendered );
    }

    public function getRequestPageHints() : array {
        return( $this->request_page_hints );
    }

    public function restoreTrustedHtmlFragments( string $html ) : string {
        if ( $html === '' || $this->trusted_html_fragments === [] || ! str_contains( $html, 'data-tm-trusted-shortcode' ) ) {
            return( $html );
        }

        return(
            preg_replace_callback(
                '/<div\b[^>]*\bdata-tm-trusted-shortcode="([a-f0-9]{32})"[^>]*>\s*<\/div>/iu',
                function( array $matches ) : string {
                    $token = strtolower( (string) ( $matches[1] ?? '' ) );
                    if ( $token === '' || ! array_key_exists( $token, $this->trusted_html_fragments ) ) {
                        return( (string) ( $matches[0] ?? '' ) );
                    }

                    $fragment = (string) $this->trusted_html_fragments[$token];
                    unset( $this->trusted_html_fragments[$token] );
                    return( $fragment );
                },
                $html
            ) ?? $html
        );
    }

    protected function renderShortcodeFragment( string $source, array $context, int $depth ) : string {
        if ( $source === '' || $depth > 20 ) {
            return( $source );
        }

        $paired_pattern = '/(?<!!)\[([a-z][a-z0-9_-]*)([^\]\r\n]*?)\](.*?)\[\/\1\](?![ \t]*(?:\(|\[|:))/isu';
        $source = preg_replace_callback(
            $paired_pattern,
            function( array $matches ) use ( $context, $depth ) : string {
                $name = $this->normalizeShortcodeName( (string) ( $matches[1] ?? '' ) );
                $raw_attributes = (string) ( $matches[2] ?? '' );
                $inner_source = (string) ( $matches[3] ?? '' );
                $inner_source = $this->renderShortcodeFragment( $inner_source, $context, $depth + 1 );
                return( $this->renderSingleShortcode( $name, $raw_attributes, $inner_source, (string) ( $matches[0] ?? '' ), $context, false ) );
            },
            $source
        ) ?? $source;

        $self_closing_pattern = '/(?<!!)\[([a-z][a-z0-9_-]*)([^\]\r\n]*?)\/\](?![ \t]*(?:\(|\[|:))/iu';
        $source = preg_replace_callback(
            $self_closing_pattern,
            function( array $matches ) use ( $context ) : string {
                $name = $this->normalizeShortcodeName( (string) ( $matches[1] ?? '' ) );
                return( $this->renderSingleShortcode( $name, (string) ( $matches[2] ?? '' ), '', (string) ( $matches[0] ?? '' ), $context, true ) );
            },
            $source
        ) ?? $source;

        $bare_pattern = '/(?<!!)\[([a-z][a-z0-9_-]*)([^\]\r\n]*?)\](?![ \t]*(?:\(|\[|:))/iu';
        $source = preg_replace_callback(
            $bare_pattern,
            function( array $matches ) use ( $context ) : string {
                $name = $this->normalizeShortcodeName( (string) ( $matches[1] ?? '' ) );
                $raw_attributes = (string) ( $matches[2] ?? '' );
                if ( $name === '' || ( ! isset( $this->shortcodes[$name] ) && ! str_contains( $raw_attributes, '=' ) ) ) {
                    return( (string) ( $matches[0] ?? '' ) );
                }

                return( $this->renderSingleShortcode( $name, $raw_attributes, '', (string) ( $matches[0] ?? '' ), $context, false ) );
            },
            $source
        ) ?? $source;

        return( $source );
    }

    protected function renderSingleShortcode( string $name, string $raw_attributes, string $inner_source, string $raw_shortcode, array $context, bool $self_closing ) : string {
        if ( $name === '' || ! isset( $this->shortcodes[$name] ) ) {
            return( $this->renderUnknownShortcode( $raw_shortcode ) );
        }

        $shortcode = $this->shortcodes[$name];
        if ( ! empty( $shortcode['dynamic'] ) ) {
            $this->dynamic_rendered = true;
        }

        try {
            $rendered = call_user_func(
                $shortcode['handler'],
                [
                    'name' => $name,
                    'attributes' => $this->parseAttributes( $raw_attributes ),
                    'content' => $inner_source,
                    'raw' => $raw_shortcode,
                    'self_closing' => $self_closing,
                    'context' => $context,
                ]
            );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Shortcode "' . $name . '" failed (' . $e->getMessage() . ')' );
            return( '' );
        }

        if ( is_array( $rendered ) ) {
            if ( ! empty( $rendered['dynamic'] ) ) {
                $this->dynamic_rendered = true;
            }
            if ( is_array( $rendered['page_hints'] ?? null ) ) {
                $this->mergePageHints( $rendered['page_hints'] );
            }
            if ( array_key_exists( 'markdown', $rendered ) ) {
                return( (string) $rendered['markdown'] );
            }

            $html = (string) ( $rendered['html'] ?? '' );
            if ( $html === '' || empty( $context['protect_html'] ) ) {
                return( $html );
            }

            $token = bin2hex( random_bytes( 16 ) );
            $this->trusted_html_fragments[$token] = $html;
            return( "\n\n<div data-tm-trusted-shortcode=\"{$token}\"></div>\n\n" );
        }

        return( is_string( $rendered ) ? $rendered : '' );
    }

    protected function mergePageHints( array $hints ) : void {
        if ( ! empty( $hints['wide_content'] ) ) {
            $this->request_page_hints['wide_content'] = true;
        }
    }

    protected function renderUnknownShortcode( string $raw_shortcode ) : string {
        if ( $this->unknown_shortcode_mode === 'hide' ) {
            return( '' );
        }

        return(
            "\n\n" . '<pre class="tm-shortcode-unknown"><code>'
            . str_replace(
                [ '[', ']' ],
                [ '&#91;', '&#93;' ],
                htmlspecialchars( $raw_shortcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
            )
            . '</code></pre>' . "\n\n"
        );
    }

    protected function parseAttributes( string $raw_attributes ) : array {
        $attributes = [];
        $raw_attributes = trim( $raw_attributes );
        if ( $raw_attributes === '' ) {
            return( [] );
        }

        preg_match_all(
            '/([a-z][a-z0-9_-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+)))?/iu',
            $raw_attributes,
            $matches,
            PREG_SET_ORDER
        );

        foreach ( $matches as $match ) {
            $key = $this->normalizeAttributeKey( (string) ( $match[1] ?? '' ) );
            if ( $key === '' ) {
                continue;
            }

            $value = true;
            if ( array_key_exists( 2, $match ) && (string) $match[2] !== '' ) {
                $value = (string) $match[2];
            } elseif ( array_key_exists( 3, $match ) && (string) $match[3] !== '' ) {
                $value = (string) $match[3];
            } elseif ( array_key_exists( 4, $match ) && (string) $match[4] !== '' ) {
                $value = (string) $match[4];
            }

            if ( is_string( $value ) ) {
                $value = mb_substr( trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value ) ?? $value ), 0, 500 );
            }

            $attributes[$key] = $value;
        }

        return( $attributes );
    }

    protected function protectCodeSegments( string $source ) : array {
        $segments = [];
        $protected_source = preg_replace_callback(
            '/(^|\n)(```|~~~).*?(?:\n\2[ \t]*(?=\n|$)|$)|`+[^`\n]*`+/su',
            static function( array $matches ) use ( &$segments ) : string {
                $token = "\x1A" . 'TM_SHORTCODE_CODE_' . count( $segments ) . "\x1A";
                $segments[$token] = (string) ( $matches[0] ?? '' );
                return( $token );
            },
            $source
        );

        return( [ is_string( $protected_source ) ? $protected_source : $source, $segments ] );
    }

    protected function isStandaloneShortcodeCandidate( string $source, int $offset, string $raw ) : bool {
        $line_start = strrpos( substr( $source, 0, $offset ), "\n" );
        $line_start = $line_start === false ? 0 : $line_start + 1;
        $line_end = strpos( $source, "\n", $offset + strlen( $raw ) );
        $line_end = $line_end === false ? strlen( $source ) : $line_end;
        return( trim( substr( $source, $line_start, $line_end - $line_start ) ) === $raw );
    }

    protected function restoreCodeSegments( string $source, array $segments ) : string {
        if ( empty( $segments ) ) {
            return( $source );
        }

        return( strtr( $source, $segments ) );
    }

    protected function normalizeShortcodeName( string $name ) : string {
        $name = strtolower( trim( $name ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $name ) ?? '' );
    }

    protected function normalizeAttributeKey( string $key ) : string {
        $key = strtolower( trim( $key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $key ) ?? '' );
    }

    protected function normalizePluginKey( string $plugin_key ) : string {
        $plugin_key = strtolower( trim( $plugin_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $plugin_key ) ?? '' );
    }

    protected function normalizeUnknownShortcodeMode( string $mode ) : string {
        $mode = strtolower( trim( $mode ) );
        return( $mode === 'hide' ? 'hide' : 'code' );
    }
}
