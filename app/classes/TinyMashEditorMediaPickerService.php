<?php
namespace app\classes;

class TinyMashEditorMediaPickerService {

    protected mixed $media_service;
    protected ?TinyMashContentRepository $content_repository;
    protected bool $is_superadmin;
    protected string $current_author_slug;

    public function __construct( mixed $media_service, ?TinyMashContentRepository $content_repository = null, bool $is_superadmin = false, string $current_author_slug = '' ) {
        $this->media_service = $media_service;
        $this->content_repository = $content_repository;
        $this->is_superadmin = $is_superadmin;
        $this->current_author_slug = strtolower( trim( $current_author_slug ) );
    }

    public function getOwnerUsernamesForEditorScope( string $scope = 'root', string $author_slug = '' ) : array {
        if ( ! $this->is_superadmin ) {
            return( $this->current_author_slug !== '' ? [ $this->current_author_slug ] : [] );
        }

        $scope = strtolower( trim( $scope ) );
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $scope === 'author' && $author_slug !== '' ) {
            return( [ $author_slug ] );
        }

        return( [ 'root' ] );
    }

    public function buildRecord( array $metadata ) : array {
        $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
        $url = trim( (string) ( $metadata['url'] ?? '' ) );
        if ( $media_id === '' || $url === '' ) {
            return( [] );
        }

        $owner_username = strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) );
        $filename = basename( trim( (string) ( $metadata['filename'] ?? ( $metadata['original_filename'] ?? '' ) ) ) );
        $alt_text = mb_trim( (string) ( $metadata['alt_text'] ?? '' ) );
        $label = $alt_text !== '' ? $alt_text : ( $filename !== '' ? $filename : $media_id );
        $label_details = [];
        if ( $this->is_superadmin && $owner_username !== '' ) {
            $label_details[] = $owner_username;
        }
        $width = max( 0, (int) ( $metadata['width'] ?? 0 ) );
        $height = max( 0, (int) ( $metadata['height'] ?? 0 ) );
        if ( $width > 0 && $height > 0 ) {
            $label_details[] = $width . 'x' . $height;
        }
        if ( ! empty( $label_details ) ) {
            $label .= ' (' . implode( ', ', $label_details ) . ')';
        }

        return(
            [
                'media_id' => $media_id,
                'owner_username' => $owner_username,
                'filename' => $filename,
                'url' => $url,
                'thumbnail_url' => trim( (string) ( $metadata['thumbnail']['url'] ?? '' ) ),
                'alt_text' => $alt_text,
                'markdown' => trim( (string) ( $metadata['markdown'] ?? '' ) ),
                'mime' => trim( (string) ( $metadata['mime'] ?? '' ) ),
                'width' => $width,
                'height' => $height,
                'bytes' => max( 0, (int) ( $metadata['bytes'] ?? 0 ) ),
                'derivative_key' => trim( (string) ( $metadata['derivative_key'] ?? 'stored_primary' ) ),
                'label' => $label,
            ]
        );
    }

    public function listSourceRecords( string $source, array $context = [], array $current_records = [] ) : array {
        $source = strtolower( trim( $source ) );
        $context = $this->normalizeContext( $context );
        $owner_usernames = $this->getOwnerUsernamesForEditorScope(
            (string) ( $context['scope'] ?? 'root' ),
            (string) ( $context['author_slug'] ?? '' )
        );
        $records = [];

        if ( $source === 'attached' ) {
            foreach ( $this->listAttachedRecords( $owner_usernames, $context ) as $record ) {
                if ( $this->recordMatchesContext( $record, $context ) ) {
                    $records = $this->appendRecord( $records, $record );
                }
            }
        } elseif ( $source === 'recent' ) {
            foreach ( $this->listRecentRecords( $owner_usernames, 30 ) as $record ) {
                if ( $this->recordMatchesContext( $record, $context ) ) {
                    $records = $this->appendRecord( $records, $record );
                }
            }
        } elseif ( $source === 'library' ) {
            foreach ( $this->listLibraryRecords( $owner_usernames, 500 ) as $record ) {
                if ( $this->recordMatchesContext( $record, $context ) ) {
                    $records = $this->appendRecord( $records, $record );
                }
            }
        }

        foreach ( $current_records as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            $built_record = $this->buildRecord( $record );
            if ( $this->recordMatchesContext( $built_record, $context ) ) {
                $records = $this->appendRecord( $records, $built_record );
            }
        }

        return( $records );
    }

    public function resolveRecordByMediaId( string $media_id, string $scope = 'root', string $author_slug = '' ) : array {
        $media_id = trim( $media_id );
        if ( $media_id === '' || ! $this->canReadMediaMetadataById() ) {
            return( [] );
        }

        $owner_usernames = $this->getOwnerUsernamesForEditorScope( $scope, $author_slug );
        $metadata = $this->media_service->getAttachmentMetadataByMediaId( $media_id, $owner_usernames );
        if ( ! is_array( $metadata ) ) {
            return( [] );
        }

        return( $this->buildRecord( $metadata ) );
    }

    protected function normalizeContext( array $context ) : array {
        return(
            [
                'scope' => strtolower( trim( (string) ( $context['scope'] ?? 'root' ) ) ) === 'author' ? 'author' : 'root',
                'author_slug' => strtolower( trim( (string) ( $context['author_slug'] ?? '' ) ) ),
                'loaded_entry_id' => trim( (string) ( $context['loaded_entry_id'] ?? '' ) ),
                'loaded_draft_id' => trim( (string) ( $context['loaded_draft_id'] ?? '' ) ),
                'attachment_session_id' => trim( (string) ( $context['attachment_session_id'] ?? '' ) ),
                'media_type' => strtolower( trim( (string) ( $context['media_type'] ?? 'any' ) ) ) === 'image' ? 'image' : 'any',
            ]
        );
    }

    protected function recordMatchesContext( array $record, array $context ) : bool {
        if ( (string) ( $context['media_type'] ?? 'any' ) !== 'image' ) {
            return( true );
        }

        $mime = strtolower( trim( (string) ( $record['mime'] ?? '' ) ) );
        return( str_starts_with( $mime, 'image/' ) && $mime !== 'image/svg+xml' && $mime !== 'image/svg' );
    }

    protected function listAttachedRecords( array $owner_usernames, array $context ) : array {
        if ( ! $this->canListAttachmentsForContent() ) {
            return( [] );
        }

        $records = [];
        foreach (
            $this->media_service->listAttachmentsForContent(
                $owner_usernames,
                (string) ( $context['loaded_entry_id'] ?? '' ),
                (string) ( $context['loaded_draft_id'] ?? '' ),
                (string) ( $context['attachment_session_id'] ?? '' ),
                60
            ) as $attachment
        ) {
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $records = $this->appendRecord( $records, $this->buildRecord( $attachment ) );
        }

        if ( empty( $records ) && (string) ( $context['loaded_entry_id'] ?? '' ) !== '' ) {
            $this->backfillEntryAttachments( (string) ( $context['loaded_entry_id'] ?? '' ), $owner_usernames );
            foreach (
                $this->media_service->listAttachmentsForContent(
                    $owner_usernames,
                    (string) ( $context['loaded_entry_id'] ?? '' ),
                    (string) ( $context['loaded_draft_id'] ?? '' ),
                    (string) ( $context['attachment_session_id'] ?? '' ),
                    60
                ) as $attachment
            ) {
                if ( ! is_array( $attachment ) ) {
                    continue;
                }

                $records = $this->appendRecord( $records, $this->buildRecord( $attachment ) );
            }
        }

        return( $records );
    }

    protected function listRecentRecords( array $owner_usernames, int $limit ) : array {
        if ( ! is_object( $this->media_service ) || ! method_exists( $this->media_service, 'listRecentAttachments' ) ) {
            return( [] );
        }

        $records = [];
        foreach ( $this->media_service->listRecentAttachments( $owner_usernames, $limit ) as $attachment ) {
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $records = $this->appendRecord( $records, $this->buildRecord( $attachment ) );
        }

        return( $records );
    }

    protected function listLibraryRecords( array $owner_usernames, int $limit ) : array {
        if ( ! is_object( $this->media_service ) || ! method_exists( $this->media_service, 'listAttachments' ) ) {
            return( [] );
        }

        $records = [];
        foreach ( $this->media_service->listAttachments( $owner_usernames, $limit ) as $attachment ) {
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $records = $this->appendRecord( $records, $this->buildRecord( $attachment ) );
        }

        return( $records );
    }

    protected function appendRecord( array $records, array $record ) : array {
        $media_id = trim( (string) ( $record['media_id'] ?? '' ) );
        if ( $media_id === '' ) {
            return( $records );
        }

        foreach ( $records as $existing_record ) {
            if ( (string) ( $existing_record['media_id'] ?? '' ) === $media_id ) {
                return( $records );
            }
        }

        $records[] = $record;
        return( $records );
    }

    protected function backfillEntryAttachments( string $entry_id, array $owner_usernames ) : void {
        $entry_id = trim( $entry_id );
        if (
            $entry_id === ''
            || $this->content_repository === null
            || ! $this->canAssignAttachments()
            || ! $this->canReadMediaMetadataByUrl()
        ) {
            return;
        }

        $author_filter = $this->is_superadmin ? null : $this->current_author_slug;
        $allow_root = $this->is_superadmin;
        $entry = $this->content_repository->getAccessibleEntryById( $entry_id, null, $author_filter, $allow_root );
        if ( ! is_array( $entry ) ) {
            return;
        }

        $media_ids = [];
        $featured_media_id = trim( (string) ( $entry['featured_image_media_id'] ?? '' ) );
        if ( $featured_media_id !== '' && $this->canReadMediaMetadataById() ) {
            $metadata = $this->media_service->getAttachmentMetadataByMediaId( $featured_media_id, $owner_usernames );
            if ( is_array( $metadata ) ) {
                $media_ids[$featured_media_id] = $featured_media_id;
            }
        }

        foreach ( $this->extractLocalMediaUrlsFromContent( (string) ( $entry['content'] ?? '' ) ) as $media_url ) {
            $metadata = $this->media_service->getAttachmentMetadataByUrl( $media_url, $owner_usernames );
            if ( ! is_array( $metadata ) ) {
                continue;
            }

            $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
            if ( $media_id !== '' ) {
                $media_ids[$media_id] = $media_id;
            }
        }

        $featured_image_url = trim( (string) ( $entry['featured_image']['url'] ?? '' ) );
        if ( $featured_image_url !== '' ) {
            $metadata = $this->media_service->getAttachmentMetadataByUrl( $featured_image_url, $owner_usernames );
            if ( is_array( $metadata ) ) {
                $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
                if ( $media_id !== '' ) {
                    $media_ids[$media_id] = $media_id;
                }
            }
        }

        foreach ( $media_ids as $media_id ) {
            $this->media_service->assignAttachmentToContent( $media_id, $owner_usernames, $entry_id, '', '' );
        }
    }

    protected function extractLocalMediaUrlsFromContent( string $content ) : array {
        $content = (string) $content;
        if ( $content === '' || ! str_contains( $content, '/media/' ) ) {
            return( [] );
        }

        $urls = [];
        if (
            preg_match_all(
                '#(?:src|href)\s*=\s*["\'](?P<html>/media/[^"\']+)["\']|\((?P<markdown>/media/[^)\s]+)\)#i',
                $content,
                $matches,
                PREG_SET_ORDER
            ) !== false
        ) {
            foreach ( $matches as $match ) {
                $url = trim( (string) ( $match['html'] ?? $match['markdown'] ?? '' ) );
                if ( $url === '' ) {
                    continue;
                }

                $urls[$url] = $url;
            }
        }

        return( array_values( $urls ) );
    }

    protected function canListAttachmentsForContent() : bool {
        return( is_object( $this->media_service ) && method_exists( $this->media_service, 'listAttachmentsForContent' ) );
    }

    protected function canAssignAttachments() : bool {
        return( is_object( $this->media_service ) && method_exists( $this->media_service, 'assignAttachmentToContent' ) );
    }

    protected function canReadMediaMetadataById() : bool {
        return( is_object( $this->media_service ) && method_exists( $this->media_service, 'getAttachmentMetadataByMediaId' ) );
    }

    protected function canReadMediaMetadataByUrl() : bool {
        return( is_object( $this->media_service ) && method_exists( $this->media_service, 'getAttachmentMetadataByUrl' ) );
    }
}
