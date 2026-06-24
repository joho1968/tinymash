<?php
namespace app\classes;

class TinyMashMarkdownEditorComponent {

    public static function render( array $options = [] ) : string {
        $escape = static fn( string $value ) : string => htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $field_id = self::normalizeDomId( (string) ( $options['field_id'] ?? 'tm-markdown-editor-field' ) );
        $field_name = trim( (string) ( $options['field_name'] ?? '' ) );
        $label = trim( (string) ( $options['label'] ?? 'Markdown' ) );
        $content = (string) ( $options['content'] ?? '' );
        $rows = min( 40, max( 4, (int) ( $options['rows'] ?? 12 ) ) );
        $shortcodes = is_array( $options['shortcodes'] ?? null ) ? $options['shortcodes'] : [];
        $field_attributes = is_array( $options['field_attributes'] ?? null ) ? $options['field_attributes'] : [];
        $external_links = ! empty( $options['external_links'] );
        $internal_links = ! empty( $options['internal_links'] );
        $internal_link_targets = is_array( $options['internal_link_targets'] ?? null ) ? $options['internal_link_targets'] : [];
        $internal_link_targets_url = trim( (string) ( $options['internal_link_targets_url'] ?? '' ) );
        $images = ! empty( $options['images'] );
        $emoji = ! empty( $options['emoji'] );
        $emoji_picker = ! empty( $options['emoji_picker'] );
        $emoji_autocomplete = ! empty( $options['emoji_autocomplete'] );
        $main_editor = $field_id === 'tm-editor-markdown';

        $html = '<div data-tm-markdown-editor';
        if ( $internal_links && ! $main_editor && ! empty( $internal_link_targets ) ) {
            $html .= ' data-tm-markdown-internal-link-targets="' . $escape( json_encode( array_values( $internal_link_targets ), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '[]' ) . '"';
        }
        if ( $internal_links && ! $main_editor && $internal_link_targets_url !== '' ) {
            $html .= ' data-tm-markdown-internal-link-targets-url="' . $escape( $internal_link_targets_url ) . '"';
        }
        $html .= '><label class="form-label small mb-1" for="' . $escape( $field_id ) . '">' . $escape( $label ) . '</label>';
        $html .= '<div class="btn-toolbar gap-2 mb-3" role="toolbar" aria-label="Markdown formatting tools">';
        $html .= '<div class="btn-group btn-group-sm" role="group" aria-label="Text styles">';
        $html .= self::toolButton( 'wrap', 'bi-type-bold', 'Bold', [ 'prefix' => '**', 'suffix' => '**', 'placeholder' => 'bold text' ] );
        $html .= self::toolButton( 'wrap', 'bi-type-italic', 'Italic', [ 'prefix' => '*', 'suffix' => '*', 'placeholder' => 'italic text' ] );
        $html .= self::toolButton( 'wrap', 'bi-type-strikethrough', 'Strikethrough', [ 'prefix' => '~~', 'suffix' => '~~', 'placeholder' => 'struck text' ] );
        $html .= self::toolButton( 'wrap', 'bi-code-slash', 'Inline code', [ 'prefix' => '`', 'suffix' => '`', 'placeholder' => 'code' ] );
        $html .= self::toolButton( 'wrap', 'bi-marker-tip', 'Mark / highlight', [ 'prefix' => '==', 'suffix' => '==', 'placeholder' => 'highlighted text' ] );
        $html .= '</div><div class="btn-group btn-group-sm" role="group" aria-label="Blocks">';
        $html .= '<div class="btn-group btn-group-sm" role="group" aria-label="Heading levels"><button class="btn btn-outline-secondary" type="button" data-tm-markdown-action="heading-apply" title="Apply heading level" aria-label="Apply heading level"><span class="bi bi-type-h1 me-1" aria-hidden="true"></span><span data-tm-markdown-heading-label>H1</span></button><button class="btn btn-outline-secondary px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Choose heading level" aria-label="Choose heading level"><span class="bi bi-three-dots-vertical" aria-hidden="true"></span></button><ul class="dropdown-menu">';
        foreach ( range( 1, 6 ) as $level ) {
            $html .= '<li><button class="dropdown-item font-monospace" type="button" data-tm-markdown-action="heading-level" data-level="' . $level . '" title="Heading ' . $level . '">H' . $level . '</button></li>';
        }
        $html .= '</ul></div>';
        $html .= self::toolButton( 'prefix-lines', 'bi-blockquote-left', 'Blockquote', [ 'prefix' => '> ', 'placeholder' => 'Blockquote' ] );
        $html .= '<div class="btn-group btn-group-sm" role="group" aria-label="Alerts"><button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Insert alert" aria-label="Insert alert"><span class="bi bi-exclamation-square" aria-hidden="true"></span></button><ul class="dropdown-menu">';
        foreach ( [ 'PRIMARY' => 'text-primary', 'SECONDARY' => 'text-body-secondary', 'SUCCESS' => 'text-success', 'DANGER' => 'text-danger', 'WARNING' => 'text-warning', 'INFO' => 'text-info', 'LIGHT' => 'text-body-tertiary', 'DARK' => 'text-body-emphasis' ] as $type => $class ) {
            $html .= '<li><button class="dropdown-item" type="button" data-tm-markdown-action="callout" data-callout="' . $type . '" title="Insert ' . strtolower( $type ) . ' alert"><span class="bi bi-circle-fill ' . $class . ' me-2" aria-hidden="true"></span>' . ucfirst( strtolower( $type ) ) . '</button></li>';
        }
        $html .= '</ul></div>';
        $html .= self::toolButton( 'prefix-lines', 'bi-list-ul', 'Bullet list', [ 'prefix' => '- ', 'placeholder' => 'List item' ] );
        $html .= self::toolButton( 'prefix-lines', 'bi-list-check', 'Task list', [ 'prefix' => '- [ ] ', 'placeholder' => 'Task item' ] );
        $html .= self::toolButton( 'table', 'bi-table', 'Insert or convert table' );
        $html .= self::toolButton( 'wrap-block', 'bi-braces-asterisk', 'Code block', [ 'prefix' => "```text\n", 'suffix' => "\n```", 'placeholder' => 'code' ], '<span class="d-none d-lg-inline ms-1">Block</span>' );
        $html .= '</div>';
        if ( $external_links || $internal_links || $images ) {
            $html .= '<div class="btn-group btn-group-sm" role="group" aria-label="Links">';
            if ( $external_links ) {
                $html .= '<button class="btn btn-outline-secondary" ' . ( $main_editor ? 'id="tm-editor-open-external-link"' : 'data-tm-markdown-action="external-link"' ) . ' type="button" title="Insert external link" aria-label="Insert external link"><span class="bi bi-link-45deg" aria-hidden="true"></span></button>';
            }
            if ( $internal_links ) {
                $html .= '<button class="btn btn-outline-secondary" ' . ( $main_editor ? 'id="tm-editor-open-internal-link"' : 'data-tm-markdown-action="internal-link"' ) . ' type="button" title="Insert internal link" aria-label="Insert internal link"><span class="bi bi-signpost-2" aria-hidden="true"></span></button>';
            }
            if ( $images ) {
                $html .= '<button class="btn btn-outline-secondary" id="tm-editor-open-image-upload" type="button" title="Insert image" aria-label="Insert image"><span class="bi bi-card-image" aria-hidden="true"></span></button>';
            }
            $html .= '</div>';
        }
        if ( $emoji_picker ) {
            $html .= '<div class="btn-group btn-group-sm" role="group" aria-label="Emoji"><button class="btn btn-outline-secondary" type="button" data-tm-markdown-action="emoji-picker" title="Open emoji picker" aria-label="Open emoji picker"><span class="bi bi-emoji-smile" aria-hidden="true"></span></button></div>';
        } elseif ( $emoji ) {
            $html .= '<div class="btn-group btn-group-sm" role="group" aria-label="Emoji"><button class="btn btn-outline-secondary" id="tm-editor-open-emoji-picker" type="button" title="Open emoji picker" aria-label="Open emoji picker"><span class="bi bi-emoji-smile" aria-hidden="true"></span></button></div>';
        }
        if ( ! empty( $shortcodes ) ) {
            $html .= '<div class="btn-group btn-group-sm" role="group" aria-label="Shortcodes"><button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Insert shortcode" aria-label="Insert shortcode"><span class="bi bi-braces" aria-hidden="true"></span></button><ul class="dropdown-menu dropdown-menu-end">';
            foreach ( $shortcodes as $shortcode ) {
                if ( ! is_array( $shortcode ) ) {
                    continue;
                }
                $name = trim( (string) ( $shortcode['name'] ?? '' ) );
                if ( preg_match( '/^[a-z][a-z0-9_-]*$/', $name ) !== 1 ) {
                    continue;
                }
                $label_text = trim( (string) ( $shortcode['label'] ?? '' ) );
                $example = trim( (string) ( $shortcode['example'] ?? '[' . $name . ']' ) );
                $html .= '<li><button class="dropdown-item" type="button" data-tm-markdown-action="shortcode" data-shortcode-name="' . $escape( $name ) . '" data-shortcode-example="' . $escape( $example ) . '" data-shortcode-block="' . ( ! empty( $shortcode['block'] ) ? '1' : '0' ) . '"><span class="font-monospace">[' . $escape( $name ) . ']</span>';
                if ( $label_text !== '' ) {
                    $html .= '<span class="small text-body-secondary ms-2">' . $escape( $label_text ) . '</span>';
                }
                $html .= '</button></li>';
            }
            $html .= '</ul></div>';
        }
        $html .= '</div><div class="position-relative"><textarea class="form-control font-monospace" id="' . $escape( $field_id ) . '" rows="' . $rows . '" spellcheck="true" data-tm-markdown-field';
        if ( $field_name !== '' ) {
            $html .= ' name="' . $escape( $field_name ) . '"';
        }
        foreach ( $field_attributes as $name => $value ) {
            $name = strtolower( trim( (string) $name ) );
            if ( preg_match( '/^data-[a-z0-9_-]+$/', $name ) !== 1 ) {
                continue;
            }
            $html .= ' ' . $name . '="' . $escape( (string) $value ) . '"';
        }
        $html .= '>' . $escape( $content ) . '</textarea>';
        if ( $emoji_autocomplete ) {
            $autocomplete_id = $field_id === 'tm-editor-markdown' ? ' id="tm-editor-emoji-autocomplete"' : '';
            $html .= '<div class="list-group shadow-sm d-none tm-editor-emoji-autocomplete"' . $autocomplete_id . ' data-tm-markdown-emoji-autocomplete role="listbox" aria-label="Emoji shortcode suggestions"></div>';
        }
        if ( $images ) {
            $html .= '<input class="d-none" id="tm-editor-image-input" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" aria-label="Upload image">';
        }
        return( $html . '</div></div>' );
    }

    protected static function toolButton( string $action, string $icon, string $label, array $data = [], string $suffix_html = '' ) : string {
        $escape = static fn( string $value ) : string => htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $html = '<button class="btn btn-outline-secondary d-inline-flex align-items-center" type="button" data-tm-markdown-action="' . $escape( $action ) . '" title="' . $escape( $label ) . '" aria-label="' . $escape( $label ) . '"';
        foreach ( $data as $name => $value ) {
            $html .= ' data-' . $escape( $name ) . '="' . $escape( (string) $value ) . '"';
        }
        return( $html . '><span class="bi ' . $escape( $icon ) . '" aria-hidden="true"></span>' . $suffix_html . '</button>' );
    }

    protected static function normalizeDomId( string $id ) : string {
        $id = preg_replace( '/[^A-Za-z0-9_-]+/', '-', trim( $id ) ) ?: '';
        return( $id !== '' ? $id : 'tm-markdown-editor-field' );
    }
}
