<?php

use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashMarkdownRenderer;

class TinyMashCommentsPublicRenderer {

    protected TinyMashCommentsService $comments;
    protected TinyMashMarkdownRenderer $markdown_renderer;
    protected ?TinyMashDateFormatter $date_formatter;

    public function __construct(
        TinyMashCommentsService $comments,
        TinyMashMarkdownRenderer $markdown_renderer,
        ?TinyMashDateFormatter $date_formatter = null
    ) {
        $this->comments = $comments;
        $this->markdown_renderer = $markdown_renderer;
        $this->date_formatter = $date_formatter;
    }

    public function render( array $entry, string $form_html = '', string $reply_url = '', string $privacy_url = '' ) : string {
        $entry_id = (string) ( $entry['id'] ?? '' );
        try {
            $comments = $this->comments->getCommentsForEntry( $entry_id, true );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            error_log( 'tinymash comments: Comment records could not be read.' );
            return( '' );
        }
        if ( $comments === [] ) {
            return( $form_html !== '' ? $this->renderRegion( $form_html, $privacy_url, $this->formHasPrivacyLink( $form_html ) ) : '' );
        }

        $roots = [];
        $replies = [];
        foreach ( $comments as $comment ) {
            if ( (string) ( $comment['parent_id'] ?? '' ) === '' ) {
                $roots[(string) $comment['id']] = $comment;
                continue;
            }
            $root_id = (string) ( $comment['thread_root_id'] ?? '' );
            if ( $root_id !== '' ) {
                $replies[$root_id][] = $comment;
            }
        }
        if ( $roots === [] ) {
            return( $form_html !== '' ? $this->renderRegion( $form_html, $privacy_url, $this->formHasPrivacyLink( $form_html ) ) : '' );
        }

        $count = count( $roots );
        foreach ( array_keys( $roots ) as $root_id ) {
            $count += count( $replies[$root_id] ?? [] );
        }
        $html = '<section class="tm-comments tm-comments-thread" id="comments" data-tm-heading-scope="component">';
        $html .= '<h2 class="tm-comments-title">Comments <span class="tm-comments-count">' . $count . '</span></h2><ol class="tm-comments-list">';
        foreach ( $roots as $root_id => $comment ) {
            $html .= '<li>' . $this->renderComment( $comment, $reply_url );
            if ( ! empty( $replies[$root_id] ) ) {
                $html .= '<ol class="tm-comments-replies">';
                foreach ( $replies[$root_id] as $reply ) {
                    $html .= '<li>' . $this->renderComment( $reply, $reply_url ) . '</li>';
                }
                $html .= '</ol>';
            }
            $html .= '</li>';
        }
        $html .= '</ol></section>';
        return( $this->renderRegion( $html . $form_html, $privacy_url, $this->formHasPrivacyLink( $form_html ) ) );
    }

    protected function renderRegion( string $html, string $privacy_url, bool $form_has_privacy_link ) : string {
        $privacy_html = $form_has_privacy_link ? '' : $this->renderPrivacyLink( $privacy_url );
        return( '<div class="tm-comments-region">' . $html . $privacy_html . '</div>' );
    }

    protected function formHasPrivacyLink( string $form_html ) : bool {
        return( str_contains( $form_html, 'class="tm-comments-privacy"' ) );
    }

    protected function renderPrivacyLink( string $privacy_url ) : string {
        $privacy_url = trim( $privacy_url );
        return( $privacy_url !== '' ? '<p class="tm-comments-privacy"><a href="' . $this->escape( $privacy_url ) . '">Comment privacy</a></p>' : '' );
    }

    protected function renderComment( array $comment, string $reply_url ) : string {
        $comment_id = (string) ( $comment['id'] ?? '' );
        $anchor = 'comment-' . str_replace( '_', '-', $comment_id );
        $author_name = (string) ( $comment['author_name'] ?? '' );
        $created_at_utc = (string) ( $comment['created_at_utc'] ?? '' );
        $date = $this->date_formatter instanceof TinyMashDateFormatter
            ? $this->date_formatter->formatUtcDateTime( $created_at_utc )
            : $created_at_utc;
        $role = (string) ( $comment['moderator_role'] ?? '' );
        $html = '<article class="tm-comment" id="' . $this->escape( $anchor ) . '"><header class="tm-comment-header">';
        $html .= '<a class="tm-comment-date" href="#' . $this->escape( $anchor ) . '"><time datetime="' . $this->escape( $created_at_utc ) . '">' . $this->escape( $date ) . '</time></a>';
        $html .= '<span class="tm-comment-by">by</span><strong class="tm-comment-author">' . $this->escape( $author_name ) . '</strong>';
        if ( $role !== '' ) {
            $html .= '<span class="tm-comment-role">' . ( $role === 'admin' ? 'Administrator' : 'Author' ) . '</span>';
        }
        $html .= '</header>';
        $html .= '<div class="tm-comment-body">' . $this->renderBody( (string) ( $comment['body'] ?? '' ) ) . '</div>';
        if ( trim( $reply_url ) !== '' ) {
            $separator = str_contains( $reply_url, '?' ) ? '&' : '?';
            $url = $reply_url . $separator . 'reply=' . rawurlencode( $comment_id ) . '#comment-form';
            $html .= '<div class="tm-comment-actions"><a class="tm-comment-reply" href="' . $this->escape( $url ) . '">Reply</a></div>';
        }
        return( $html . '</article>' );
    }

    protected function renderBody( string $body ) : string {
        $body = str_replace( [ "\r\n", "\r" ], "\n", $body );
        $blocks = preg_split( '/\n{2,}/u', mb_trim( $body ) ) ?: [];
        $html = '';
        foreach ( $blocks as $block ) {
            $rendered = $this->markdown_renderer->render( $block, [ 'classic_smileys_enabled' => true ] );
            $inline = $this->limitInlineHtml( $rendered );
            if ( $inline !== '' ) {
                $html .= '<p>' . $inline . '</p>';
            }
        }
        return( $html );
    }

    protected function limitInlineHtml( string $html ) : string {
        $dom = new \DOMDocument( '1.0', 'UTF-8' );
        $previous_state = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="UTF-8"><div id="tm-comment-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_state );
        if ( ! $loaded ) {
            return( $this->escape( strip_tags( $html ) ) );
        }
        $root = $dom->getElementById( 'tm-comment-root' );
        if ( ! $root instanceof \DOMElement ) {
            return( '' );
        }
        $this->sanitizeChildren( $root );
        $result = '';
        foreach ( $root->childNodes as $child ) {
            $result .= $dom->saveHTML( $child );
        }
        return( mb_trim( $result ) );
    }

    protected function sanitizeChildren( \DOMElement $parent ) : void {
        foreach ( iterator_to_array( $parent->childNodes ) as $child ) {
            if ( ! $child instanceof \DOMElement ) {
                continue;
            }
            $this->sanitizeChildren( $child );
            $tag = strtolower( $child->tagName );
            if ( ! in_array( $tag, [ 'a', 'em', 'strong', 'code', 'br', 'span' ], true ) ) {
                while ( $child->firstChild !== null ) {
                    $parent->insertBefore( $child->firstChild, $child );
                }
                $parent->removeChild( $child );
                continue;
            }
            $href = $tag === 'a' ? trim( (string) $child->getAttribute( 'href' ) ) : '';
            $class = $tag === 'span' ? trim( (string) $child->getAttribute( 'class' ) ) : '';
            foreach ( iterator_to_array( $child->attributes ) as $attribute ) {
                $child->removeAttribute( $attribute->name );
            }
            if ( $tag === 'a' ) {
                if ( preg_match( '#^https?://#i', $href ) !== 1 ) {
                    while ( $child->firstChild !== null ) {
                        $parent->insertBefore( $child->firstChild, $child );
                    }
                    $parent->removeChild( $child );
                    continue;
                }
                $child->setAttribute( 'href', $href );
                $child->setAttribute( 'rel', 'nofollow ugc noopener' );
            } elseif ( $tag === 'span' ) {
                if ( $class !== 'tm-emoji' ) {
                    while ( $child->firstChild !== null ) {
                        $parent->insertBefore( $child->firstChild, $child );
                    }
                    $parent->removeChild( $child );
                    continue;
                }
                $child->setAttribute( 'class', 'tm-emoji' );
            }
        }
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
