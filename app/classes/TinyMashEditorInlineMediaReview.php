<?php
namespace app\classes;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;

class TinyMashEditorInlineMediaReview {

    public function __construct( protected mixed $media_service ) {
    }

    public function review( string $markdown, string $target_owner, bool $is_superadmin ) : array {
        if ( strlen( $markdown ) > 1048576 ) {
            throw new \InvalidArgumentException( 'Content is too large to scan.' );
        }
        if ( preg_match( '/^[a-z0-9_]+$/', $target_owner ) !== 1 ) {
            throw new \InvalidArgumentException( 'Choose a valid space.' );
        }

        $environment = new Environment();
        $environment->addExtension( new CommonMarkCoreExtension() );
        $document = ( new MarkdownParser( $environment ) )->parse( $markdown );
        $simple_urls = [];
        preg_match_all( '/!\[([^\]\r\n]*)\]\((\/media\/[^\s()?#]+)\)/', $markdown, $matches, PREG_SET_ORDER );
        foreach ( $matches as $match ) {
            $url = (string) $match[2];
            $simple_urls[$url] = (int) ( $simple_urls[$url] ?? 0 ) + 1;
        }

        $issues = [];
        $image_urls = [];
        $walker = $document->walker();
        while ( $event = $walker->next() ) {
            if ( ! $event->isEntering() ) {
                continue;
            }
            $node = $event->getNode();
            if ( $node instanceof Image ) {
                $url = (string) $node->getUrl();
                $owner = $this->ownerFromUrl( $url );
                if ( $owner === '' || $owner === $target_owner ) {
                    continue;
                }
                $image_urls[$url] = (int) ( $image_urls[$url] ?? 0 ) + 1;
                $line = $this->lineOfUrl( $markdown, $url );
                $first_child = $node->firstChild();
                $alt_text = $first_child instanceof Text && $first_child->next() === null ? trim( $first_child->getLiteral() ) : '';
                $label = $alt_text !== '' ? 'Image “' . mb_strimwidth( $alt_text, 0, 60, '…' ) . '” · line ' . $line : 'Image on line ' . $line;
                $issues[] = [
                    'url' => $url,
                    'owner' => $owner,
                    'line' => $line,
                    'label' => $label,
                    'kind' => 'markdown',
                    'linked' => $node->parent() instanceof Link,
                ];
            } elseif ( $node instanceof HtmlBlock || $node instanceof HtmlInline ) {
                preg_match_all( '/<img\b[^>]*\bsrc\s*=\s*["\'](\/media\/[^"\']+)["\']/i', $node->getLiteral(), $html_matches );
                foreach ( $html_matches[1] ?? [] as $url ) {
                    $owner = $this->ownerFromUrl( (string) $url );
                    if ( $owner === '' || $owner === $target_owner ) {
                        continue;
                    }
                    $line = $this->lineOfUrl( $markdown, (string) $url );
                    $issues[] = [
                        'url' => (string) $url,
                        'owner' => $owner,
                        'line' => $line,
                        'label' => 'HTML image on line ' . $line,
                        'kind' => 'html',
                    ];
                }
            }
            if ( count( $issues ) > 100 ) {
                throw new \InvalidArgumentException( 'Too many images to review at once.' );
            }
        }

        foreach ( $issues as &$issue ) {
            $url = $issue['url'];
            $simple = $issue['kind'] === 'markdown'
                && empty( $issue['linked'] )
                && ( $simple_urls[$url] ?? 0 ) === 1
                && ( $image_urls[$url] ?? 0 ) === 1
                && substr_count( $markdown, $url ) === 1;
            $issue['simple'] = $simple;
            unset( $issue['linked'] );
            $issue['media_id'] = '';
            if ( ! $simple || ! $is_superadmin || $target_owner === 'root' || $issue['owner'] !== 'root' ) {
                continue;
            }
            $metadata = is_object( $this->media_service ) && method_exists( $this->media_service, 'getAttachmentMetadataByUrl' )
                ? $this->media_service->getAttachmentMetadataByUrl( $url, [ 'root' ] )
                : null;
            $mime = (string) ( $metadata['mime'] ?? '' );
            if ( ! is_array( $metadata ) || ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ], true ) ) {
                continue;
            }
            $resolved = method_exists( $this->media_service, 'resolveMediaPath' )
                ? $this->media_service->resolveMediaPath( 'root', (string) ( $metadata['year'] ?? '' ), (string) ( $metadata['month'] ?? '' ), (string) ( $metadata['filename'] ?? '' ) )
                : null;
            if ( is_array( $resolved ) ) {
                $issue['media_id'] = (string) ( $metadata['media_id'] ?? '' );
            }
        }
        unset( $issue );

        return( $issues );
    }

    protected function ownerFromUrl( string $url ) : string {
        return( preg_match( '~^/media/([a-z0-9_]+)/\d{4}/\d{2}/[^/?#]+$~', $url, $match ) === 1 ? $match[1] : '' );
    }

    protected function lineOfUrl( string $markdown, string $url ) : int {
        $position = strpos( $markdown, $url );
        return( $position === false ? 1 : substr_count( substr( $markdown, 0, $position ), "\n" ) + 1 );
    }
}
