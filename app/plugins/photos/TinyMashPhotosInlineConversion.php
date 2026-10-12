<?php

use app\classes\TinyMashEditorMediaPickerService;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Parser\MarkdownParser;

class TinyMashPhotosInlineConversion {

    public function __construct( protected mixed $media_service ) {
    }

    public function preview( string $markdown, array $owner_usernames ) : array {
        if ( strlen( $markdown ) > 1048576 ) {
            throw new InvalidArgumentException( 'Content is too large to scan.' );
        }
        if ( empty( $owner_usernames ) ) {
            throw new InvalidArgumentException( 'A media owner is required.' );
        }

        $environment = new Environment();
        $environment->addExtension( new CommonMarkCoreExtension() );
        $document = ( new MarkdownParser( $environment ) )->parse( $markdown );
        $picker = new TinyMashEditorMediaPickerService( $this->media_service );
        $lines = preg_split( '/\r\n|\n|\r/', $markdown ) ?: [];
        $candidates = [];
        $skipped = [];
        $occurrences = [];
        $image_count = 0;
        $paragraph_lines = [];

        $walker = $document->walker();
        while ( $event = $walker->next() ) {
            if ( ! $event->isEntering() ) {
                continue;
            }

            $node = $event->getNode();
            if ( ( $node instanceof HtmlBlock || $node instanceof HtmlInline ) && preg_match( '/<img\b/i', $node->getLiteral() ) === 1 ) {
                $image_count++;
                if ( $image_count > 200 ) {
                    throw new InvalidArgumentException( 'Too many images to scan at once.' );
                }
                $skipped[] = [ 'url' => 'HTML image', 'reason' => 'HTML image needs manual review' ];
            }
            if ( ! $node instanceof Image ) {
                continue;
            }
            $image_count++;
            if ( $image_count > 200 ) {
                throw new InvalidArgumentException( 'Too many images to scan at once.' );
            }

            $image = $node;
            $url = $image->getUrl();
            $paragraph = $image->parent();
            $paragraph_id = $paragraph instanceof Paragraph ? spl_object_id( $paragraph ) : 0;
            if ( $paragraph_id !== 0 && ! array_key_exists( $paragraph_id, $paragraph_lines ) ) {
                $paragraph_lines[$paragraph_id] = $this->standaloneImageLines( $paragraph, $lines );
            }
            $source_image = $paragraph_lines[$paragraph_id][spl_object_id( $image )] ?? null;

            if ( ! str_starts_with( $url, '/media/' ) ) {
                $skipped[] = [ 'url' => $url, 'reason' => 'External or unmanaged image' ];
                continue;
            }
            if ( ! is_array( $source_image ) || $source_image['url'] !== $url ) {
                $skipped[] = [ 'url' => $url, 'reason' => 'Image is linked, captioned, or part of other content' ];
                continue;
            }

            $metadata = is_object( $this->media_service ) && method_exists( $this->media_service, 'getAttachmentMetadataByUrl' )
                ? $this->media_service->getAttachmentMetadataByUrl( $url, $owner_usernames )
                : null;
            $mime = strtolower( (string) ( $metadata['mime'] ?? '' ) );
            if ( ! is_array( $metadata ) || ! str_starts_with( $mime, 'image/' ) || in_array( $mime, [ 'image/svg+xml', 'image/svg' ], true ) ) {
                $skipped[] = [ 'url' => $url, 'reason' => 'Image is unavailable in this space' ];
                continue;
            }
            $record = $picker->buildRecord( $metadata );
            $media_id = (string) ( $record['media_id'] ?? '' );
            if ( preg_match( '/^media_[A-Za-z0-9_-]+$/', $media_id ) !== 1 || (string) ( $record['url'] ?? '' ) !== $url ) {
                $skipped[] = [ 'url' => $url, 'reason' => 'Image metadata is incomplete' ];
                continue;
            }
            $occurrences[$media_id] = (int) ( $occurrences[$media_id] ?? 0 ) + 1;
            $candidates[] = [ 'record' => $record, 'line' => $source_image['line'], 'alt_text' => $source_image['alt_text'], 'url' => $url ];
        }

        $records = [];
        $remove_lines = [];
        foreach ( $candidates as $candidate ) {
            $media_id = (string) $candidate['record']['media_id'];
            if ( $occurrences[$media_id] !== 1 ) {
                $skipped[] = [ 'url' => $candidate['url'], 'reason' => 'Image appears more than once' ];
                continue;
            }
            $record = $candidate['record'];
            $record['inline_alt_text'] = $candidate['alt_text'];
            $records[] = $record;
            $remove_lines[(int) $candidate['line']] = true;
        }

        $parts = preg_split( '/(\r\n|\n|\r)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: [];
        $converted = '';
        $line_number = 1;
        for ( $index = 0; $index < count( $parts ); $index += 2 ) {
            if ( ! isset( $remove_lines[$line_number] ) ) {
                $converted .= (string) $parts[$index] . (string) ( $parts[$index + 1] ?? '' );
            }
            $line_number++;
        }

        return( [
            'records' => $records,
            'skipped' => $skipped,
            'content' => $converted,
            'removed_count' => count( $remove_lines ),
        ] );
    }

    protected function standaloneImageLines( Paragraph $paragraph, array $lines ) : array {
        if ( ! $paragraph->parent() instanceof Document ) {
            return( [] );
        }
        $start = $paragraph->getStartLine();
        $end = $paragraph->getEndLine();
        if ( $start === null || $end === null || $end < $start ) {
            return( [] );
        }

        $source_images = [];
        for ( $line_number = $start; $line_number <= $end; $line_number++ ) {
            $line = (string) ( $lines[$line_number - 1] ?? '' );
            if ( preg_match( '/^ {0,3}!\[([^\]\r\n]*)\]\((\/media\/[^\s()?#]+)\) *$/', $line, $match ) !== 1 ) {
                return( [] );
            }
            $source_images[] = [ 'line' => $line_number, 'alt_text' => $match[1], 'url' => $match[2] ];
        }

        $result = [];
        $index = 0;
        $expect_image = true;
        for ( $child = $paragraph->firstChild(); $child !== null; $child = $child->next() ) {
            if ( $expect_image ) {
                if ( ! $child instanceof Image || ! isset( $source_images[$index] ) ) {
                    return( [] );
                }
                $result[spl_object_id( $child )] = $source_images[$index];
                $index++;
            } elseif ( ! $child instanceof Newline || $child->getType() !== Newline::SOFTBREAK ) {
                return( [] );
            }
            $expect_image = ! $expect_image;
        }

        return( $expect_image === false && $index === count( $source_images ) ? $result : [] );
    }
}
