<?php
namespace app\classes;

use League\CommonMark\Delimiter\DelimiterInterface;
use League\CommonMark\Delimiter\Processor\CacheableDelimiterProcessorInterface;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\Autolink\UrlAutolinkParser;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\AbstractInline;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\DelimitedInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Xml\XmlNodeRendererInterface;

final class TinyMashUnderline extends AbstractInline implements DelimitedInterface {

    private string $delimiter;

    public function __construct( string $delimiter = '++' ) {
        parent::__construct();

        $this->delimiter = $delimiter;
    }

    public function getOpeningDelimiter() : string {
        return( $this->delimiter );
    }

    public function getClosingDelimiter() : string {
        return( $this->delimiter );
    }
}

final class TinyMashUnderlineRenderer implements NodeRendererInterface, XmlNodeRendererInterface {

    public function render( Node $node, ChildNodeRendererInterface $childRenderer ) : \Stringable {
        TinyMashUnderline::assertInstanceOf( $node );

        return( new HtmlElement( 'ins', $node->data->get( 'attributes' ), $childRenderer->renderNodes( $node->children() ) ) );
    }

    public function getXmlTagName( Node $node ) : string {
        return( 'ins' );
    }

    public function getXmlAttributes( Node $node ) : array {
        return( [] );
    }
}

final class TinyMashUnderlineDelimiterProcessor implements CacheableDelimiterProcessorInterface {

    public function getOpeningCharacter() : string {
        return( '+' );
    }

    public function getClosingCharacter() : string {
        return( '+' );
    }

    public function getMinLength() : int {
        return( 2 );
    }

    public function getDelimiterUse( DelimiterInterface $opener, DelimiterInterface $closer ) : int {
        if ( $opener->getLength() !== 2 || $closer->getLength() !== 2 ) {
            return( 0 );
        }

        return( 2 );
    }

    public function process( AbstractStringContainer $opener, AbstractStringContainer $closer, int $delimiterUse ) : void {
        $underline = new TinyMashUnderline( str_repeat( '+', $delimiterUse ) );

        $next = $opener->next();
        while ( $next !== null && $next !== $closer ) {
            $tmp = $next->next();
            $underline->appendChild( $next );
            $next = $tmp;
        }

        $opener->insertAfter( $underline );
    }

    public function getCacheKey( DelimiterInterface $closer ) : string {
        return( '+' . $closer->getLength() );
    }
}

final class TinyMashUnderlineExtension implements ExtensionInterface {

    public function register( EnvironmentBuilderInterface $environment ) : void {
        $environment->addDelimiterProcessor( new TinyMashUnderlineDelimiterProcessor() );
        $environment->addRenderer( TinyMashUnderline::class, new TinyMashUnderlineRenderer() );
    }
}

class TinyMashMarkdownRenderer {

    protected const ALLOWED_HTML_TAGS = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'code', 'dd', 'del', 'div', 'dl', 'dt', 'em',
        'figcaption', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'img', 'input', 'ins',
        'kbd', 'li', 'mark', 'ol', 'p', 'pre', 's', 'small', 'span', 'strong', 'sub', 'sup',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];
    protected const STRIP_WITH_CONTENT_TAGS = [ 'script', 'style', 'iframe', 'object', 'embed', 'meta', 'link', 'base', 'svg', 'math', 'form', 'textarea', 'select', 'option', 'button' ];
    protected const GLOBAL_HTML_ATTRIBUTES = [ 'class', 'dir', 'id', 'lang', 'name', 'role', 'title' ];
    protected const TAG_SPECIFIC_ATTRIBUTES = [
        'a' => [ 'href', 'target', 'rel' ],
        'img' => [ 'src', 'alt', 'width', 'height', 'loading', 'decoding', 'title' ],
        'ol' => [ 'start', 'reversed' ],
        'td' => [ 'colspan', 'rowspan', 'scope', 'align' ],
        'th' => [ 'colspan', 'rowspan', 'scope', 'align' ],
        'input' => [ 'type', 'checked', 'disabled' ],
    ];

    protected MarkdownConverter $converter;
    protected string $emoji_data_filename;
    protected array $emoji_shortcode_map = [];
    protected array $emoji_smiley_map = [];
    protected ?string $smiley_pattern = null;
    protected array $callout_variant_map = [
        'note' => [ 'variant' => 'info', 'label' => 'Note' ],
        'tip' => [ 'variant' => 'success', 'label' => 'Tip' ],
        'important' => [ 'variant' => 'primary', 'label' => 'Important' ],
        'warning' => [ 'variant' => 'warning', 'label' => 'Warning' ],
        'caution' => [ 'variant' => 'danger', 'label' => 'Caution' ],
        'primary' => [ 'variant' => 'primary', 'label' => 'Primary' ],
        'secondary' => [ 'variant' => 'secondary', 'label' => 'Secondary' ],
        'success' => [ 'variant' => 'success', 'label' => 'Success' ],
        'danger' => [ 'variant' => 'danger', 'label' => 'Danger' ],
        'warning_bootstrap' => [ 'variant' => 'warning', 'label' => 'Warning' ],
        'info' => [ 'variant' => 'info', 'label' => 'Info' ],
        'light' => [ 'variant' => 'light', 'label' => 'Light' ],
        'dark' => [ 'variant' => 'dark', 'label' => 'Dark' ],
    ];
    protected array $element_class_map = [
        'p' => [ 'mb-3' ],
        'ul' => [ 'mb-3', 'ps-4' ],
        'ol' => [ 'mb-3', 'ps-4' ],
        'blockquote' => [ 'tm-content-blockquote' ],
        'pre' => [ 'tm-content-pre', 'bg-body-tertiary', 'border', 'rounded-3', 'mb-3' ],
        'table' => [ 'table', 'table-striped', 'table-hover', 'align-middle', 'mb-0' ],
        'img' => [ 'img-fluid', 'rounded-3' ],
        'hr' => [ 'my-4' ],
        'h1' => [ 'display-6', 'mb-3' ],
        'h2' => [ 'h2', 'mt-4', 'mb-3' ],
        'h3' => [ 'h3', 'mt-4', 'mb-3' ],
        'h4' => [ 'h4', 'mt-4', 'mb-3' ],
        'h5' => [ 'h5', 'mt-4', 'mb-3' ],
        'h6' => [ 'h6', 'mt-4', 'mb-3' ],
    ];
    public function __construct( ?string $emoji_data_filename = null ) {
        $environment = new Environment(
            [
                'html_input' => 'allow',
                'allow_unsafe_links' => false,
                'renderer' => [
                    'soft_break' => "<br>\n",
                ],
            ]
        );
        $environment->addExtension( new CommonMarkCoreExtension() );
        $environment->addExtension( new DisallowedRawHtmlExtension() );
        $environment->addExtension( new StrikethroughExtension() );
        $environment->addExtension( new TableExtension() );
        $environment->addExtension( new TaskListExtension() );
        $environment->addInlineParser( new UrlAutolinkParser( [ 'http', 'https', 'ftp' ], 'http' ) );
        $environment->addExtension( new TinyMashUnderlineExtension() );
        $this->converter = new MarkdownConverter( $environment );
        $this->emoji_data_filename = is_string( $emoji_data_filename ) && $emoji_data_filename !== '' ? $emoji_data_filename : dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'emoji-picker-data' . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'github' . DIRECTORY_SEPARATOR . 'data.json';
        $this->loadEmojiMaps();
    }

    public function render( string $markdown, array $options = [] ) : string {
        $markdown = trim( $this->preserveIntentionalBlankLines( $this->normalizeNestedListShorthand( $markdown ) ) );
        if ( $markdown === '' ) {
            return( '' );
        }

        $html = (string) $this->converter->convert( $markdown );

        return( $this->decorateHtml( $html, $options ) );
    }

    public function renderInlineCompact( string $markdown, array $options = [] ) : string {
        $html = $this->render( $markdown, $options );
        if ( $html === '' || ! class_exists( '\DOMDocument' ) ) {
            return( $html );
        }

        $dom = new \DOMDocument( '1.0', 'UTF-8' );
        $wrapped_html = '<?xml encoding="UTF-8"><div id="tinymash-inline-root">' . $html . '</div>';

        $previous_state = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML(
            $wrapped_html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_state );

        if ( ! $loaded ) {
            return( $html );
        }

        $root = $dom->getElementById( 'tinymash-inline-root' );
        if ( ! $root instanceof \DOMElement ) {
            return( $html );
        }

        $meaningful_children = [];
        foreach ( iterator_to_array( $root->childNodes ) as $child_node ) {
            if ( $child_node instanceof \DOMText && trim( (string) ( $child_node->nodeValue ?? '' ) ) === '' ) {
                continue;
            }
            $meaningful_children[] = $child_node;
        }

        if ( count( $meaningful_children ) === 1 && $meaningful_children[0] instanceof \DOMElement && strtolower( $meaningful_children[0]->tagName ) === 'p' ) {
            return( $this->renderChildNodes( $meaningful_children[0] ) );
        }

        return( $html );
    }

    protected function preserveIntentionalBlankLines( string $markdown ) : string {
        if ( $markdown === '' ) {
            return( '' );
        }

        $lines = preg_split( "/\r\n|\n|\r/", $markdown );
        if ( ! is_array( $lines ) ) {
            return( $markdown );
        }

        $preserved_lines = [];
        $blank_count = 0;
        $inside_fence = false;

        foreach ( $lines as $line ) {
            $current_line = (string) $line;
            $is_fence = preg_match( '/^\s{0,3}(```|~~~)/', $current_line ) === 1;
            if ( $is_fence ) {
                $this->flushPreservedBlankLines( $preserved_lines, $blank_count, ! empty( $preserved_lines ) );
                $inside_fence = ! $inside_fence;
                $preserved_lines[] = $current_line;
                continue;
            }

            if ( ! $inside_fence && trim( $current_line ) === '' ) {
                $blank_count++;
                continue;
            }

            $this->flushPreservedBlankLines( $preserved_lines, $blank_count, ! $inside_fence );
            $preserved_lines[] = $current_line;
        }

        $blank_count = 0;

        return( implode( "\n", $preserved_lines ) );
    }

    protected function flushPreservedBlankLines( array &$lines, int &$blank_count, bool $preserve_extra = true ) : void {
        if ( $blank_count < 1 ) {
            return;
        }

        $has_previous_content = ! empty( $lines );
        if ( ! $has_previous_content ) {
            $blank_count = 0;
            return;
        }

        $lines[] = '';
        if ( $preserve_extra ) {
            for ( $index = 1; $index < $blank_count; $index++ ) {
                $lines[] = '<div class="tm-content-blank-line" aria-hidden="true"></div>';
                $lines[] = '';
            }
        } elseif ( $blank_count > 1 ) {
            for ( $index = 1; $index < $blank_count; $index++ ) {
                $lines[] = '';
            }
        }

        $blank_count = 0;
    }

    protected function normalizeNestedListShorthand( string $markdown ) : string {
        if ( $markdown === '' ) {
            return( '' );
        }

        $lines = preg_split( "/\r\n|\n|\r/", $markdown );
        if ( ! is_array( $lines ) ) {
            return( $markdown );
        }

        $normalized_lines = [];
        $inside_fence = false;
        foreach ( $lines as $line ) {
            $current_line = (string) $line;
            if ( preg_match( '/^\s{0,3}(```|~~~)/', $current_line ) === 1 ) {
                $inside_fence = ! $inside_fence;
                $normalized_lines[] = $current_line;
                continue;
            }

            if ( $inside_fence ) {
                $normalized_lines[] = $current_line;
                continue;
            }

            if ( preg_match( '/^(\s*)((?:-\s+){2,})(\S.*)$/', $current_line, $matches ) === 1 ) {
                $existing_indent = (string) ( $matches[1] ?? '' );
                $prefixes = (string) ( $matches[2] ?? '' );
                $content = (string) ( $matches[3] ?? '' );
                preg_match_all( '/-\s+/', $prefixes, $prefix_matches );
                $depth = max( 1, count( $prefix_matches[0] ?? [] ) - 1 );
                $normalized_lines[] = $existing_indent . str_repeat( '  ', $depth ) . '- ' . $content;
                continue;
            }

            $normalized_lines[] = $current_line;
        }

        return( implode( "\n", $normalized_lines ) );
    }

    protected function decorateHtml( string $html, array $options = [] ) : string {
        if ( $html === '' || ! class_exists( '\DOMDocument' ) ) {
            return( $html );
        }

        $dom = new \DOMDocument( '1.0', 'UTF-8' );
        $wrapped_html = '<?xml encoding="UTF-8"><div id="tinymash-markdown-root">' . $html . '</div>';

        $previous_state = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML(
            $wrapped_html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_state );

        if ( ! $loaded ) {
            return( $html );
        }

        $root = $dom->getElementById( 'tinymash-markdown-root' );
        if ( ! $root instanceof \DOMElement ) {
            return( $html );
        }

        $this->sanitizeHtmlTree( $root );

        foreach ( $this->element_class_map as $tag_name => $classes ) {
            foreach ( iterator_to_array( $root->getElementsByTagName( $tag_name ) ) as $element ) {
                if ( $element instanceof \DOMElement ) {
                    $this->addClasses( $element, $classes );
                }
            }
        }

        foreach ( [ 'ul', 'ol' ] as $list_tag_name ) {
            foreach ( iterator_to_array( $root->getElementsByTagName( $list_tag_name ) ) as $list_element ) {
                if ( ! $list_element instanceof \DOMElement ) {
                    continue;
                }

                if ( $list_element->parentNode instanceof \DOMElement && $list_element->parentNode->tagName === 'li' ) {
                    $this->removeClasses( $list_element, [ 'mb-3' ] );
                    $this->addClasses( $list_element, [ 'tm-nested-list', 'mb-1', 'mt-1' ] );
                }
            }
        }

        foreach ( iterator_to_array( $root->getElementsByTagName( 'code' ) ) as $code_element ) {
            if ( ! $code_element instanceof \DOMElement ) {
                continue;
            }

            if ( $code_element->parentNode instanceof \DOMElement && $code_element->parentNode->tagName === 'pre' ) {
                $this->addClasses( $code_element, [ 'font-monospace' ] );
                continue;
            }

            if ( str_contains( $code_element->textContent ?? '', "\n" ) ) {
                $this->addClasses( $code_element, [ 'font-monospace', 'bg-body-tertiary', 'rounded', 'd-block', 'p-2', 'overflow-auto', 'tm-inline-code-block' ] );
                continue;
            }

            $this->addClasses( $code_element, [ 'font-monospace', 'bg-body-tertiary', 'px-1', 'rounded' ] );
        }

        foreach ( iterator_to_array( $root->getElementsByTagName( 'table' ) ) as $table_element ) {
            if ( ! $table_element instanceof \DOMElement ) {
                continue;
            }
            if ( $table_element->parentNode instanceof \DOMElement && $table_element->parentNode->tagName === 'div' && str_contains( ' ' . $table_element->parentNode->getAttribute( 'class' ) . ' ', ' table-responsive ' ) ) {
                continue;
            }

            $wrapper = $dom->createElement( 'div' );
            $wrapper->setAttribute( 'class', 'table-responsive mb-3' );
            $table_element->parentNode?->replaceChild( $wrapper, $table_element );
            $wrapper->appendChild( $table_element );
        }

        foreach ( iterator_to_array( $root->getElementsByTagName( 'thead' ) ) as $table_head ) {
            if ( $table_head instanceof \DOMElement ) {
                $this->addClasses( $table_head, [ 'table-group-divider' ] );
            }
        }

        foreach ( iterator_to_array( $root->getElementsByTagName( 'input' ) ) as $input_element ) {
            if ( ! $input_element instanceof \DOMElement ) {
                continue;
            }

            if ( strtolower( $input_element->getAttribute( 'type' ) ) !== 'checkbox' ) {
                continue;
            }

            $this->addClasses( $input_element, [ 'form-check-input', 'me-2' ] );

            $list_item = $input_element->parentNode instanceof \DOMElement && $input_element->parentNode->tagName === 'li' ? $input_element->parentNode : null;
            if ( $list_item instanceof \DOMElement ) {
                $this->addClasses( $list_item, [ 'task-list-item', 'list-unstyled' ] );

                $list_container = $list_item->parentNode instanceof \DOMElement && in_array( $list_item->parentNode->tagName, [ 'ul', 'ol' ], true ) ? $list_item->parentNode : null;
                if ( $list_container instanceof \DOMElement ) {
                    $this->addClasses( $list_container, [ 'contains-task-list', 'list-unstyled', 'ps-0' ] );
                }
            }
        }

        foreach ( iterator_to_array( $root->getElementsByTagName( 'blockquote' ) ) as $blockquote ) {
            if ( ! $blockquote instanceof \DOMElement ) {
                continue;
            }

            $this->decorateCalloutBlockquote( $dom, $blockquote );
        }

        $this->decorateInlineTokens( $dom, $root, $this->resolveClassicSmileysEnabled( $options ) );

        return( $this->renderChildNodes( $root ) );
    }

    protected function decorateCalloutBlockquote( \DOMDocument $dom, \DOMElement $blockquote ) : void {
        $first_paragraph = null;
        foreach ( $blockquote->childNodes as $child_node ) {
            if ( $child_node instanceof \DOMElement && $child_node->tagName === 'p' ) {
                $first_paragraph = $child_node;
                break;
            }
        }

        if ( ! $first_paragraph instanceof \DOMElement ) {
            return;
        }

        $paragraph_text = trim( $first_paragraph->textContent );
        if ( ! preg_match( '/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION|PRIMARY|SECONDARY|SUCCESS|DANGER|INFO|LIGHT|DARK)(?:\|([^\]\r\n]*))?\](.*)$/s', $paragraph_text, $matches ) ) {
            return;
        }

        $callout_type = strtolower( trim( $matches[1] ) );
        $callout_key = $callout_type === 'warning' ? 'warning_bootstrap' : $callout_type;
        $callout_config = $this->callout_variant_map[$callout_key] ?? null;
        if ( ! is_array( $callout_config ) ) {
            return;
        }

        $custom_label = trim( (string) ( $matches[2] ?? '' ) );
        $visible_label = $custom_label !== '' ? $custom_label : (string) $callout_config['label'];
        $blockquote->setAttribute( 'class', 'tm-callout alert alert-' . $callout_config['variant'] . ' my-4 rounded-0' );
        $label = $dom->createElement( 'div', $visible_label );
        $label->setAttribute( 'class', 'tm-callout-label small fw-semibold mb-2' );
        $blockquote->insertBefore( $label, $first_paragraph );
        $this->stripCalloutMarkerFromParagraph( $first_paragraph );
    }

    protected function stripCalloutMarkerFromParagraph( \DOMElement $paragraph ) : void {
        foreach ( iterator_to_array( $paragraph->childNodes ) as $child_node ) {
            if ( ! $child_node instanceof \DOMText ) {
                continue;
            }

            $text = (string) ( $child_node->nodeValue ?? '' );
            if ( trim( $text ) === '' ) {
                if ( preg_match( '/^\s+$/', $text ) === 1 ) {
                    $paragraph->removeChild( $child_node );
                    continue;
                }
                continue;
            }

            $updated_text = preg_replace(
                '/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION|PRIMARY|SECONDARY|SUCCESS|DANGER|INFO|LIGHT|DARK)(?:\|([^\]\r\n]*))?\]\s*/',
                '',
                $text,
                1
            );

            if ( $updated_text === null || $updated_text === $text ) {
                return;
            }

            $updated_text = ltrim( $updated_text );

            if ( $updated_text === '' ) {
                $paragraph->removeChild( $child_node );
            } else {
                $child_node->nodeValue = $updated_text;
            }
            $this->trimLeadingCalloutBreaks( $paragraph );
            return;
        }

        $this->trimLeadingCalloutBreaks( $paragraph );
    }

    protected function trimLeadingCalloutBreaks( \DOMElement $paragraph ) : void {
        while ( $paragraph->firstChild instanceof \DOMText && trim( (string) $paragraph->firstChild->nodeValue ) === '' ) {
            $paragraph->removeChild( $paragraph->firstChild );
        }

        while ( $paragraph->firstChild instanceof \DOMElement && strtolower( $paragraph->firstChild->tagName ) === 'br' ) {
            $paragraph->removeChild( $paragraph->firstChild );
        }

        if ( $paragraph->firstChild instanceof \DOMText ) {
            $paragraph->firstChild->nodeValue = ltrim( (string) $paragraph->firstChild->nodeValue );
        }
    }

    protected function decorateInlineTokens( \DOMDocument $dom, \DOMNode $node, bool $classic_smileys_enabled ) : void {
        $child_nodes = iterator_to_array( $node->childNodes );
        foreach ( $child_nodes as $child_node ) {
            if ( $child_node instanceof \DOMElement && in_array( strtolower( $child_node->tagName ), [ 'code', 'pre', 'script', 'style' ], true ) ) {
                continue;
            }

            if ( $child_node instanceof \DOMText ) {
                $replacement_html = $this->replaceInlineTokensInText( $child_node->nodeValue ?? '', $classic_smileys_enabled );
                if ( $replacement_html === null ) {
                    continue;
                }

                $fragment = $dom->createDocumentFragment();
                $fragment->appendXML( $replacement_html );
                $child_node->parentNode?->replaceChild( $fragment, $child_node );
                continue;
            }

            if ( $child_node->hasChildNodes() ) {
                $this->decorateInlineTokens( $dom, $child_node, $classic_smileys_enabled );
            }
        }
    }

    protected function replaceInlineTokensInText( string $text, bool $classic_smileys_enabled ) : ?string {
        if ( $text === '' ) {
            return( null );
        }

        $pattern = '/(==([^=\r\n][^=\r\n]*?)==|(?<![A-Za-z0-9]):[a-z0-9_+-]+:(?![A-Za-z0-9])' . ( $classic_smileys_enabled ? '|' . $this->getSmileyPattern() : '' ) . '|\p{Extended_Pictographic}(?:\x{FE0F}|\x{200D}\p{Extended_Pictographic})*)/u';
        if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
            return( null );
        }

        $html = '';
        $offset = 0;

        foreach ( $matches[0] as $match ) {
            $token = (string) $match[0];
            $position = (int) $match[1];
            $length = strlen( $token );
            $html .= htmlspecialchars( substr( $text, $offset, $position - $offset ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
            $html .= $this->renderInlineToken( $token, $classic_smileys_enabled );
            $offset = $position + $length;
        }

        $html .= htmlspecialchars( substr( $text, $offset ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

        return( $html );
    }

    protected function sanitizeHtmlTree( \DOMElement $root ) : void {
        foreach ( self::STRIP_WITH_CONTENT_TAGS as $tag_name ) {
            foreach ( iterator_to_array( $root->getElementsByTagName( $tag_name ) ) as $element ) {
                if ( ! $element instanceof \DOMElement || ! $element->parentNode ) {
                    continue;
                }

                $element->parentNode->removeChild( $element );
            }
        }

        $all_elements = $root->getElementsByTagName( '*' );
        for ( $index = $all_elements->length - 1; $index >= 0; $index-- ) {
            $element = $all_elements->item( $index );
            if ( ! $element instanceof \DOMElement ) {
                continue;
            }

            $tag_name = strtolower( $element->tagName );
            if ( ! in_array( $tag_name, self::ALLOWED_HTML_TAGS, true ) ) {
                $this->unwrapElement( $element );
                continue;
            }

            if ( ! $element->hasAttributes() ) {
                continue;
            }

            $attributes_to_remove = [];
            foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
                if ( ! $attribute instanceof \DOMAttr ) {
                    continue;
                }

                $attribute_name = strtolower( $attribute->name );
                $attribute_value = trim( $attribute->value );
                if ( str_starts_with( $attribute_name, 'on' ) ) {
                    $attributes_to_remove[] = $attribute->name;
                    continue;
                }
                if ( $attribute_name === 'style' ) {
                    $attributes_to_remove[] = $attribute->name;
                    continue;
                }
                if ( ! $this->isAllowedHtmlAttribute( $tag_name, $attribute_name ) ) {
                    $attributes_to_remove[] = $attribute->name;
                    continue;
                }

                if ( in_array( $attribute_name, [ 'href', 'src' ], true ) && $this->isUnsafeUrl( $attribute_value ) ) {
                    $attributes_to_remove[] = $attribute->name;
                }
            }

            foreach ( $attributes_to_remove as $attribute_name ) {
                $element->removeAttribute( $attribute_name );
            }

            if ( $tag_name === 'input' ) {
                $input_type = strtolower( trim( $element->getAttribute( 'type' ) ) );
                if ( $input_type !== 'checkbox' ) {
                    $this->unwrapElement( $element );
                    continue;
                }

                $element->setAttribute( 'disabled', 'disabled' );
            }

            if ( $tag_name === 'a' && strtolower( trim( $element->getAttribute( 'target' ) ) ) === '_blank' && $this->isLocalUrl( $element->getAttribute( 'href' ) ) ) {
                $element->removeAttribute( 'target' );
                $element->removeAttribute( 'rel' );
            }

            if ( $tag_name === 'a' && strtolower( trim( $element->getAttribute( 'target' ) ) ) === '_blank' ) {
                $rel_tokens = preg_split( '/\s+/', strtolower( trim( $element->getAttribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );
                $rel_tokens = is_array( $rel_tokens ) ? $rel_tokens : [];
                foreach ( [ 'noopener', 'noreferrer' ] as $required_rel ) {
                    if ( ! in_array( $required_rel, $rel_tokens, true ) ) {
                        $rel_tokens[] = $required_rel;
                    }
                }
                $element->setAttribute( 'rel', implode( ' ', $rel_tokens ) );
            }
        }
    }

    protected function isLocalUrl( string $url ) : bool {
        $url = trim( $url );
        return( $url !== '' && str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) );
    }

    protected function isAllowedHtmlAttribute( string $tag_name, string $attribute_name ) : bool {
        if ( in_array( $attribute_name, self::GLOBAL_HTML_ATTRIBUTES, true ) ) {
            return( true );
        }
        if ( str_starts_with( $attribute_name, 'aria-' ) || str_starts_with( $attribute_name, 'data-' ) ) {
            return( true );
        }

        return( in_array( $attribute_name, self::TAG_SPECIFIC_ATTRIBUTES[$tag_name] ?? [], true ) );
    }

    protected function unwrapElement( \DOMElement $element ) : void {
        $parent = $element->parentNode;
        if ( ! $parent instanceof \DOMNode ) {
            return;
        }

        while ( $element->firstChild !== null ) {
            $parent->insertBefore( $element->firstChild, $element );
        }

        $parent->removeChild( $element );
    }

    protected function isUnsafeUrl( string $value ) : bool {
        $value = trim( $value );
        if ( $value === '' || str_starts_with( $value, '#' ) || str_starts_with( $value, '/' ) ) {
            return( false );
        }

        $normalized_value = strtolower( preg_replace( '/\s+/', '', $value ) ?? $value );
        if ( str_starts_with( $normalized_value, 'javascript:' ) || str_starts_with( $normalized_value, 'vbscript:' ) ) {
            return( true );
        }

        if ( str_starts_with( $normalized_value, 'file:' ) || str_starts_with( $normalized_value, 'blob:' ) ) {
            return( true );
        }

        if ( str_starts_with( $normalized_value, 'data:' ) ) {
            foreach ( [ 'data:image/png', 'data:image/jpeg', 'data:image/gif', 'data:image/webp', 'data:image/avif', 'data:image/x-icon', 'data:image/vnd.microsoft.icon' ] as $allowed_prefix ) {
                if ( str_starts_with( $normalized_value, $allowed_prefix ) ) {
                    return( false );
                }
            }

            return( true );
        }

        return( false );
    }

    protected function renderInlineToken( string $token, bool $classic_smileys_enabled ) : string {
        if ( str_starts_with( $token, '==' ) && str_ends_with( $token, '==' ) && strlen( $token ) > 4 ) {
            return( '<mark>' . htmlspecialchars( substr( $token, 2, -2 ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</mark>' );
        }

        $normalized_token = strtolower( $token );
        if ( isset( $this->emoji_shortcode_map[$normalized_token] ) ) {
            return( '<span class="tm-emoji">' . htmlspecialchars( $this->emoji_shortcode_map[$normalized_token], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</span>' );
        }

        if ( $classic_smileys_enabled && isset( $this->emoji_smiley_map[$token] ) ) {
            return( '<span class="tm-emoji">' . htmlspecialchars( $this->emoji_smiley_map[$token], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</span>' );
        }

        if ( preg_match( '/^\p{Extended_Pictographic}(?:\x{FE0F}|\x{200D}\p{Extended_Pictographic})*$/u', $token ) === 1 ) {
            return( '<span class="tm-emoji">' . htmlspecialchars( $token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</span>' );
        }

        return( htmlspecialchars( $token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }

    protected function resolveClassicSmileysEnabled( array $options ) : bool {
        if ( array_key_exists( 'classic_smileys_enabled', $options ) ) {
            return( ! empty( $options['classic_smileys_enabled'] ) );
        }

        return( true );
    }

    protected function loadEmojiMaps() : void {
        $this->emoji_shortcode_map = [];
        $this->emoji_smiley_map = [];

        if ( is_file( $this->emoji_data_filename ) && is_readable( $this->emoji_data_filename ) ) {
            $json = file_get_contents( $this->emoji_data_filename );
            $data = is_string( $json ) && $json !== '' ? json_decode( $json, true ) : null;
            if ( is_array( $data ) ) {
                foreach ( $data as $emoji_record ) {
                    if ( ! is_array( $emoji_record ) || empty( $emoji_record['emoji'] ) ) {
                        continue;
                    }

                    $emoji = (string) $emoji_record['emoji'];
                    foreach ( $emoji_record['shortcodes'] ?? [] as $shortcode ) {
                        $shortcode = strtolower( trim( (string) $shortcode ) );
                        if ( $shortcode === '' ) {
                            continue;
                        }
                        $this->emoji_shortcode_map[':' . $shortcode . ':'] = $emoji;
                    }

                    $emoticon = trim( (string) ( $emoji_record['emoticon'] ?? '' ) );
                    if ( $emoticon !== '' ) {
                        $this->emoji_smiley_map[$emoticon] = $emoji;
                    }
                }
            }
        }

        $this->addSmileyAliases( [ ':-)', ':)' ], '🙂' );
        $this->addSmileyAliases( [ ':-(', ':(' ], '☹️' );
        $this->addSmileyAliases( [ ';-)', ';)' ], '😉' );
        $this->addSmileyAliases( [ ':-D', ':D' ], '😄' );
        $this->addSmileyAliases( [ ':-P', ':P' ], '😛' );
        $this->addSmileyAliases( [ ':-/', ':/' ], '😕' );
        $this->addSmileyAliases( [ ':-|', ':|' ], '😐️' );
        $this->emoji_smiley_map['<3'] = '❤️';
        $this->addSmileyAliases( [ '8-)', '8)' ], '😎' );
        $this->addSmileyAliases( [ ':-\\', ':\\' ], '😕' );
        $this->smiley_pattern = null;
    }

    protected function addSmileyAliases( array $aliases, string $fallback_emoji ) : void {
        $emoji = $fallback_emoji;
        foreach ( $aliases as $alias ) {
            $alias = (string) $alias;
            if ( isset( $this->emoji_smiley_map[$alias] ) && $this->emoji_smiley_map[$alias] !== '' ) {
                $emoji = (string) $this->emoji_smiley_map[$alias];
                break;
            }
        }
        foreach ( $aliases as $alias ) {
            $alias = (string) $alias;
            if ( $alias !== '' ) {
                $this->emoji_smiley_map[$alias] = $emoji;
            }
        }
    }

    protected function getSmileyPattern() : string {
        if ( is_string( $this->smiley_pattern ) && $this->smiley_pattern !== '' ) {
            return( $this->smiley_pattern );
        }

        $smileys = array_keys( $this->emoji_smiley_map );
        usort(
            $smileys,
            function( string $left, string $right ) : int {
                return( strlen( $right ) <=> strlen( $left ) );
            }
        );

        $this->smiley_pattern = '(?<![A-Za-z0-9])(?:' . implode( '|', array_map( static fn( string $token ) : string => preg_quote( $token, '/' ), $smileys ) ) . ')(?![A-Za-z0-9])';

        return( $this->smiley_pattern );
    }

    protected function addClasses( \DOMElement $element, array $classes ) : void {
        $existing_classes = trim( $element->getAttribute( 'class' ) );
        $class_list = $existing_classes === '' ? [] : ( preg_split( '/\s+/', $existing_classes ) ?: [] );
        foreach ( $classes as $class_name ) {
            if ( ! in_array( $class_name, $class_list, true ) ) {
                $class_list[] = $class_name;
            }
        }
        $element->setAttribute( 'class', implode( ' ', $class_list ) );
    }

    protected function removeClasses( \DOMElement $element, array $classes ) : void {
        $existing_classes = trim( $element->getAttribute( 'class' ) );
        if ( $existing_classes === '' ) {
            return;
        }

        $class_list = preg_split( '/\s+/', $existing_classes ) ?: [];
        $class_list = array_values(
            array_filter(
                $class_list,
                static function( string $class_name ) use ( $classes ) : bool {
                    return( ! in_array( $class_name, $classes, true ) );
                }
            )
        );

        $element->setAttribute( 'class', implode( ' ', $class_list ) );
    }

    protected function renderChildNodes( \DOMElement $root ) : string {
        $html = '';
        foreach ( $root->childNodes as $child_node ) {
            $html .= $root->ownerDocument->saveHTML( $child_node );
        }

        return( $html );
    }

}// TinyMashMarkdownRenderer
