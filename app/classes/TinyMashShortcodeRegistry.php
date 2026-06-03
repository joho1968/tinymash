<?php
namespace app\classes;

class TinyMashShortcodeRegistry {

    protected array $shortcodes = [];
    protected bool $dynamic_rendered = false;
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
            'label' => trim( (string) ( $definition['label'] ?? $name ) ),
            'description' => trim( (string) ( $definition['description'] ?? '' ) ),
            'example' => trim( (string) ( $definition['example'] ?? '[' . $name . ']' ) ),
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
    }

    public function hasDynamicRenderForRequest() : bool {
        return( $this->dynamic_rendered );
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
                if ( $name === '' || ( ! isset( $this->shortcodes[$name] ) && trim( $raw_attributes ) === '' ) ) {
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
            return( (string) ( $rendered['markdown'] ?? $rendered['html'] ?? '' ) );
        }

        return( is_string( $rendered ) ? $rendered : '' );
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
