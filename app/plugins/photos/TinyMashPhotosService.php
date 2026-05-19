<?php

use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashMediaService;

class TinyMashPhotosService {

    protected mixed $media_service;
    protected ?TinyMashMarkdownRenderer $markdown_renderer;

    public function __construct( mixed $media_service = null, ?TinyMashMarkdownRenderer $markdown_renderer = null ) {
        $this->media_service = $media_service;
        $this->markdown_renderer = $markdown_renderer;
    }

    public function buildEntryEditorSettings( array $draft ) : array {
        $plugin_settings = is_array( $draft['plugin_settings']['photos'] ?? null ) ? $draft['plugin_settings']['photos'] : [];
        $media_ids = $this->normalizeMediaIds( $plugin_settings['gallery_media_ids'] ?? [] );
        $image_overrides = $this->normalizeGalleryImageOverrides( $plugin_settings['gallery_image_overrides'] ?? [], $media_ids );

        return(
            [
                'photo_post_enabled' => ! empty( $plugin_settings['photo_post_enabled'] ),
                'gallery_enabled' => ! empty( $plugin_settings['gallery_enabled'] ),
                'gallery_download_enabled' => ! empty( $plugin_settings['gallery_download_enabled'] ),
                'gallery_slideshow_enabled' => ! empty( $plugin_settings['gallery_slideshow_enabled'] ),
                'gallery_slideshow_delay_seconds' => $this->normalizeSlideshowDelaySeconds( $plugin_settings['gallery_slideshow_delay_seconds'] ?? 5 ),
                'gallery_media_ids' => $media_ids,
                'gallery_image_overrides' => $image_overrides,
                'gallery_position' => $this->normalizeGalleryPosition( (string) ( $plugin_settings['gallery_position'] ?? 'after_content' ) ),
            ]
        );
    }

    public function buildEditorTabHtml( array $context = [] ) : string {
        $draft = is_array( $context['draft'] ?? null ) ? $context['draft'] : [];
        $settings = $this->buildEntryEditorSettings( $draft );
        $attached_records = $this->listAttachedImageRecords( $context );
        $selected_ids = $settings['gallery_media_ids'];
        $selected_lookup = array_fill_keys( $selected_ids, true );
        $ordered_records = $this->sortRecordsBySelectedOrder( $attached_records, $selected_ids );
        $image_overrides = is_array( $settings['gallery_image_overrides'] ?? null ) ? $settings['gallery_image_overrides'] : [];
        $image_upload = is_array( $context['editor_image_upload'] ?? null ) ? $context['editor_image_upload'] : [];
        $image_upload_enabled = ! empty( $image_upload['enabled'] );

        $html = '<div class="row g-3">';
        $html .= '<div class="col-12">';
        $html .= '<div class="form-check">';
        $html .= '<input class="form-check-input" id="tm-editor-photos-photo-post-enabled" type="checkbox" data-editor-plugin-key="photos" data-editor-plugin-field="photo_post_enabled"' . ( ! empty( $settings['photo_post_enabled'] ) ? ' checked' : '' ) . '>';
        $html .= '<label class="form-check-label" for="tm-editor-photos-photo-post-enabled">Treat this as a photo post</label>';
        $html .= '</div>';
        $html .= '<div class="form-text">Photo posts are still normal posts. Themes may use this setting for image-focused layouts.</div>';
        $html .= '</div>';
        $html .= '<div class="col-12">';
        $html .= '<div class="form-check">';
        $html .= '<input class="form-check-input" id="tm-editor-photos-gallery-enabled" type="checkbox" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_enabled"' . ( ! empty( $settings['gallery_enabled'] ) ? ' checked' : '' ) . '>';
        $html .= '<label class="form-check-label" for="tm-editor-photos-gallery-enabled">Attach a gallery to this post</label>';
        $html .= '</div>';
        $html .= '<div class="form-text">The current featured image is the gallery cover. Selected gallery images render publicly only when this is enabled.</div>';
        $html .= '</div>';
        $html .= '<div class="col-12">';
        $html .= '<div class="form-check">';
        $html .= '<input class="form-check-input" id="tm-editor-photos-gallery-download-enabled" type="checkbox" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_download_enabled"' . ( ! empty( $settings['gallery_download_enabled'] ) ? ' checked' : '' ) . '>';
        $html .= '<label class="form-check-label" for="tm-editor-photos-gallery-download-enabled">Show download button in gallery lightbox</label>';
        $html .= '</div>';
        $html .= '<div class="form-text">Default off. When enabled, visitors get an explicit download action for each gallery image.</div>';
        $html .= '</div>';
        $html .= '<div class="col-12 col-lg-6">';
        $html .= '<div class="form-check">';
        $html .= '<input class="form-check-input" id="tm-editor-photos-gallery-slideshow-enabled" type="checkbox" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_slideshow_enabled"' . ( ! empty( $settings['gallery_slideshow_enabled'] ) ? ' checked' : '' ) . '>';
        $html .= '<label class="form-check-label" for="tm-editor-photos-gallery-slideshow-enabled">Enable slideshow in gallery lightbox</label>';
        $html .= '</div>';
        $html .= '<div class="form-text">Default off. Visitors can start or stop playback from the lightbox.</div>';
        $html .= '</div>';
        $html .= '<div class="col-12 col-lg-6">';
        $html .= '<label class="form-label small mb-1" for="tm-editor-photos-gallery-slideshow-delay">Slideshow delay (seconds)</label>';
        $html .= '<input class="form-control font-monospace" id="tm-editor-photos-gallery-slideshow-delay" type="number" min="2" max="60" step="1" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_slideshow_delay_seconds" value="' . (int) $settings['gallery_slideshow_delay_seconds'] . '">';
        $html .= '<div class="form-text">Allowed range is 2 to 60 seconds.</div>';
        $html .= '</div>';
        $html .= '<div class="col-12 col-lg-6">';
        $html .= '<label class="form-label small mb-1" for="tm-editor-photos-gallery-position">Gallery position</label>';
        $html .= '<select class="form-select" id="tm-editor-photos-gallery-position" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_position">';
        foreach ( $this->getGalleryPositionOptions() as $value => $label ) {
            $html .= '<option value="' . $this->escape( $value ) . '"' . ( $settings['gallery_position'] === $value ? ' selected' : '' ) . '>' . $this->escape( $label ) . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        $html .= '<div class="col-12">';
        $html .= '<input type="hidden" id="tm-editor-photos-gallery-media-ids" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_media_ids" value="' . $this->escape( implode( "\n", $selected_ids ) ) . '">';
        $html .= '<input type="hidden" id="tm-editor-photos-gallery-image-overrides" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_image_overrides" value="' . $this->escape( $this->encodeGalleryImageOverridesForField( $image_overrides ) ) . '">';
        $html .= '<div class="d-flex flex-column flex-lg-row justify-content-between gap-2 align-items-lg-end mb-2">';
        $html .= '<div>';
        $html .= '<div class="fw-semibold">Gallery images</div>';
        $html .= '<div class="small text-body-secondary">Select attached images, choose existing library images, or upload new gallery images. Removing an image here only detaches it from this gallery.</div>';
        $html .= '</div>';
        $html .= '<div class="d-flex flex-wrap gap-2 align-items-center">';
        $html .= '<button class="btn btn-outline-secondary btn-sm" id="tm-editor-photos-choose-existing" type="button"><span class="bi bi-images me-1" aria-hidden="true"></span>Choose existing images</button>';
        if ( $image_upload_enabled ) {
            $html .= '<button class="btn btn-outline-secondary btn-sm" id="tm-editor-photos-upload-gallery" type="button"><span class="bi bi-images me-1" aria-hidden="true"></span>Upload gallery images</button>';
        }
        $html .= '<span class="badge text-bg-secondary" id="tm-editor-photos-gallery-count">' . count( $selected_ids ) . ' selected</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="border rounded-3 bg-body-tertiary p-3 small text-body-secondary' . ( empty( $ordered_records ) ? '' : ' d-none' ) . '" id="tm-editor-photos-gallery-empty">No gallery images are selected yet. Choose existing images from the current content scope, upload gallery images here, or upload/insert images elsewhere in the editor and return to this tab. Photo-post mode alone does not render a gallery.</div>';
        $html .= '<div class="row g-2' . ( empty( $ordered_records ) ? ' d-none' : '' ) . '" id="tm-editor-photos-gallery-options">';
        foreach ( $ordered_records as $record ) {
            $media_id = (string) ( $record['media_id'] ?? '' );
            $html .= $this->buildEditorGalleryOptionHtml( $record, isset( $selected_lookup[$media_id] ), is_array( $image_overrides[$media_id] ?? null ) ? $image_overrides[$media_id] : [] );
        }
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= $this->buildEditorScript();

        return( $html );
    }

    public function normalizePersistedSettings( array $settings ) : array {
        return(
            [
                'photo_post_enabled' => ! empty( $settings['photo_post_enabled'] ) ? '1' : '0',
                'gallery_enabled' => ! empty( $settings['gallery_enabled'] ) ? '1' : '0',
                'gallery_download_enabled' => ! empty( $settings['gallery_download_enabled'] ) ? '1' : '0',
                'gallery_slideshow_enabled' => ! empty( $settings['gallery_slideshow_enabled'] ) ? '1' : '0',
                'gallery_slideshow_delay_seconds' => (string) $this->normalizeSlideshowDelaySeconds( $settings['gallery_slideshow_delay_seconds'] ?? 5 ),
                'gallery_media_ids' => implode( "\n", $this->normalizeMediaIds( $settings['gallery_media_ids'] ?? [] ) ),
                'gallery_image_overrides' => $this->encodeGalleryImageOverridesForField( $this->normalizeGalleryImageOverrides( $settings['gallery_image_overrides'] ?? [], $settings['gallery_media_ids'] ?? [] ) ),
                'gallery_position' => $this->normalizeGalleryPosition( (string) ( $settings['gallery_position'] ?? 'after_content' ) ),
            ]
        );
    }

    public function renderPublicEntryFragments( array $entry, array $context = [] ) : array {
        $settings = $this->buildEntryEditorSettings( $entry );
        if ( empty( $settings['gallery_enabled'] ) || empty( $settings['gallery_media_ids'] ) ) {
            return( [] );
        }

        $gallery_html = $this->buildPublicGalleryHtml(
            $entry,
            $settings['gallery_media_ids'],
            $settings['gallery_image_overrides'] ?? [],
            ! empty( $settings['gallery_download_enabled'] ),
            ! empty( $settings['gallery_slideshow_enabled'] ),
            (int) ( $settings['gallery_slideshow_delay_seconds'] ?? 5 )
        );
        if ( $gallery_html === '' ) {
            return( [] );
        }

        return(
            $settings['gallery_position'] === 'before_content'
                ? [ 'before_content_html' => $gallery_html ]
                : [ 'after_content_html' => $gallery_html ]
        );
    }

    protected function listAttachedImageRecords( array $context ) : array {
        if ( ! $this->media_service instanceof TinyMashMediaService ) {
            return( [] );
        }

        $draft = is_array( $context['draft'] ?? null ) ? $context['draft'] : [];
        $scope = strtolower( trim( (string) ( $draft['scope'] ?? 'root' ) ) ) === 'author' ? 'author' : 'root';
        $author_slug = strtolower( trim( (string) ( $draft['author_slug'] ?? $context['current_author_slug'] ?? '' ) ) );
        $owner_usernames = $this->buildOwnerUsernames( $scope, $author_slug, ! empty( $context['is_superadmin'] ), (string) ( $context['current_author_slug'] ?? '' ) );
        if ( empty( $owner_usernames ) ) {
            return( [] );
        }

        $records = [];
        foreach (
            $this->media_service->listAttachmentsForContent(
                $owner_usernames,
                (string) ( $context['loaded_entry_id'] ?? '' ),
                (string) ( $context['loaded_draft_id'] ?? '' ),
                '',
                80
            ) as $metadata
        ) {
            if ( ! is_array( $metadata ) || ! $this->metadataLooksLikeImage( $metadata ) ) {
                continue;
            }
            $record = $this->buildImageRecord( $metadata );
            if ( ! empty( $record['media_id'] ) ) {
                $records[$record['media_id']] = $record;
            }
        }

        return( array_values( $records ) );
    }

    protected function buildOwnerUsernames( string $scope, string $author_slug, bool $is_superadmin, string $current_author_slug ) : array {
        if ( ! $is_superadmin ) {
            $current_author_slug = strtolower( trim( $current_author_slug ) );
            return( $current_author_slug !== '' ? [ $current_author_slug ] : [] );
        }

        if ( $scope === 'author' && $author_slug !== '' ) {
            return( [ $author_slug ] );
        }

        return( [ 'root' ] );
    }

    protected function metadataLooksLikeImage( array $metadata ) : bool {
        $mime = strtolower( trim( (string) ( $metadata['mime'] ?? '' ) ) );
        return( str_starts_with( $mime, 'image/' ) && $mime !== 'image/svg+xml' && $mime !== 'image/svg' );
    }

    protected function buildImageRecord( array $metadata ) : array {
        $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
        if ( $media_id === '' ) {
            return( [] );
        }

        $filename = basename( trim( (string) ( $metadata['filename'] ?? ( $metadata['original_filename'] ?? '' ) ) ) );
        $alt_text = mb_trim( (string) ( $metadata['alt_text'] ?? '' ) );
        $label = $alt_text !== '' ? $alt_text : ( $filename !== '' ? $filename : $media_id );

        return(
            [
                'media_id' => $media_id,
                'filename' => $filename,
                'label' => $label,
                'alt_text' => $alt_text,
                'caption' => '',
                'url' => trim( (string) ( $metadata['url'] ?? '' ) ),
                'thumbnail_url' => trim( (string) ( $metadata['thumbnail']['url'] ?? '' ) ),
                'display_url' => trim( (string) ( $metadata['display']['url'] ?? '' ) ),
                'width' => max( 0, (int) ( $metadata['width'] ?? 0 ) ),
                'height' => max( 0, (int) ( $metadata['height'] ?? 0 ) ),
                'public_metadata_rows' => $this->normalizePublicMetadataRows( $metadata['public_metadata_rows'] ?? [] ),
            ]
        );
    }

    protected function buildEditorGalleryOptionHtml( array $record, bool $checked, array $overrides = [] ) : string {
        $media_id = (string) ( $record['media_id'] ?? '' );
        if ( $media_id === '' ) {
            return( '' );
        }

        $thumb_url = trim( (string) ( $record['thumbnail_url'] ?? '' ) );
        if ( $thumb_url === '' ) {
            $thumb_url = trim( (string) ( $record['url'] ?? '' ) );
        }
        $label = trim( (string) ( $record['label'] ?? $media_id ) );
        $filename = trim( (string) ( $record['filename'] ?? '' ) );
        $caption_override = mb_trim( (string) ( $overrides['caption'] ?? '' ) );
        $alt_override = mb_trim( (string) ( $overrides['alt'] ?? '' ) );

        $html = '<div class="col-12 col-md-6 col-xl-4 min-w-0" data-tm-editor-photos-gallery-option="' . $this->escape( $media_id ) . '">';
        $html .= '<div class="border rounded-3 p-2 d-flex flex-column gap-2 h-100 w-100 overflow-hidden tm-editor-photos-gallery-option" style="min-width: 0;">';
        $html .= '<label class="d-flex gap-2 w-100 overflow-hidden" style="min-width: 0;">';
        $html .= '<input class="form-check-input mt-1 tm-editor-photos-gallery-check" type="checkbox" value="' . $this->escape( $media_id ) . '"' . ( $checked ? ' checked' : '' ) . '>';
        if ( $thumb_url !== '' ) {
            $html .= '<img class="rounded-2 object-fit-cover flex-shrink-0" src="' . $this->escape( $thumb_url ) . '" alt="" style="width: 4.5rem; height: 4.5rem; min-width: 4.5rem;">';
        }
        $html .= '<span class="d-flex flex-column gap-1 min-w-0 flex-grow-1 overflow-hidden">';
        $html .= '<span class="fw-semibold text-truncate">' . $this->escape( $label !== '' ? $label : $media_id ) . '</span>';
        $html .= '<span class="small text-body-secondary text-truncate" title="' . $this->escape( $filename !== '' ? $filename : $media_id ) . '">' . $this->escape( $filename !== '' ? $filename : $media_id ) . '</span>';
        $html .= '<span class="small font-monospace text-body-secondary text-break">' . $this->escape( $media_id ) . '</span>';
        $html .= '</span>';
        $html .= '</label>';
        $html .= '<div class="d-flex flex-wrap gap-2 ps-4">';
        $html .= '<button class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-move" type="button" data-tm-editor-photos-gallery-move="up" aria-label="Move image earlier"><span class="bi bi-arrow-up" aria-hidden="true"></span></button>';
        $html .= '<button class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-move" type="button" data-tm-editor-photos-gallery-move="down" aria-label="Move image later"><span class="bi bi-arrow-down" aria-hidden="true"></span></button>';
        $html .= '<button class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-drag-handle" type="button" draggable="true" data-tm-editor-photos-gallery-drag-handle aria-label="Drag to reorder image" title="Drag to reorder"><span class="bi bi-grip-vertical" aria-hidden="true"></span></button>';
        $html .= '<button class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 tm-editor-photos-gallery-remove" type="button" data-tm-editor-photos-gallery-remove aria-label="Remove image from gallery"><span class="bi bi-x-lg" aria-hidden="true"></span><span>Remove</span></button>';
        $html .= '</div>';
        $html .= '<div class="row g-2 ps-4">';
        $html .= '<div class="col-12">';
        $html .= '<label class="form-label small mb-1" for="tm-editor-photos-caption-' . $this->escape( $media_id ) . '">Caption</label>';
        $html .= '<input class="form-control form-control-sm tm-editor-photos-gallery-caption" id="tm-editor-photos-caption-' . $this->escape( $media_id ) . '" type="text" maxlength="500" data-tm-editor-photos-media-id="' . $this->escape( $media_id ) . '" value="' . $this->escape( $caption_override ) . '">';
        $html .= '</div>';
        $html .= '<div class="col-12">';
        $html .= '<label class="form-label small mb-1" for="tm-editor-photos-alt-' . $this->escape( $media_id ) . '">Alt text</label>';
        $html .= '<input class="form-control form-control-sm tm-editor-photos-gallery-alt" id="tm-editor-photos-alt-' . $this->escape( $media_id ) . '" type="text" maxlength="300" data-tm-editor-photos-media-id="' . $this->escape( $media_id ) . '" value="' . $this->escape( $alt_override ) . '">';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return( $html );
    }

    protected function buildPublicGalleryHtml( array $entry, array $media_ids, array $image_overrides = [], bool $download_enabled = false, bool $slideshow_enabled = false, int $slideshow_delay_seconds = 5 ) : string {
        $records = $this->resolvePublicGalleryRecords( $entry, $media_ids, $image_overrides );
        if ( empty( $records ) ) {
            return( '' );
        }

        $slideshow_delay_seconds = $this->normalizeSlideshowDelaySeconds( $slideshow_delay_seconds );
        $slideshow_attributes = $slideshow_enabled && count( $records ) > 1
            ? ' data-tm-photos-slideshow-enabled="1" data-tm-photos-slideshow-delay-seconds="' . $slideshow_delay_seconds . '"'
            : '';
        $entry_title = mb_trim( (string) ( $entry['title'] ?? '' ) );
        $entry_title_attribute = $entry_title !== '' ? ' data-tm-photos-entry-title="' . $this->escape( $entry_title ) . '"' : '';
        $entry_title_html = $entry_title !== '' && $this->markdown_renderer instanceof TinyMashMarkdownRenderer
            ? $this->markdown_renderer->renderInlineCompact( $entry_title, [ 'classic_smileys_enabled' => true ] )
            : '';
        $entry_title_html_attribute = $entry_title_html !== '' ? ' data-tm-photos-entry-title-html="' . $this->escape( $entry_title_html ) . '"' : '';
        $html = '<section class="tm-photos-gallery my-4" data-tm-photos-gallery' . $slideshow_attributes . $entry_title_attribute . $entry_title_html_attribute . '>';
        $html .= '<div class="row g-2">';
        foreach ( $records as $record ) {
            $media_url = $this->normalizePublicMediaUrl( (string) ( $record['url'] ?? '' ) );
            if ( $media_url === '' ) {
                continue;
            }
            $display_url = $this->normalizePublicMediaUrl( (string) ( $record['display_url'] ?? '' ) );
            if ( $display_url === '' ) {
                $display_url = $media_url;
            }

            $thumbnail_url = $this->normalizePublicMediaUrl( (string) ( $record['thumbnail_url'] ?? '' ) );
            if ( $thumbnail_url === '' ) {
                $thumbnail_url = $media_url;
            }
            $label = mb_trim( (string) ( $record['label'] ?? '' ) );
            if ( $label === '' ) {
                $label = (string) ( $record['filename'] ?? '' );
            }
            if ( $label === '' ) {
                $label = (string) ( $record['media_id'] ?? 'Photo' );
            }
            $alt_text = mb_trim( (string) ( $record['alt_text'] ?? '' ) );
            if ( $alt_text === '' ) {
                $alt_text = $label;
            }

            $width = max( 0, (int) ( $record['width'] ?? 0 ) );
            $height = max( 0, (int) ( $record['height'] ?? 0 ) );
            $dimension_attributes = '';
            if ( $width > 0 && $height > 0 ) {
                $dimension_attributes = ' width="' . $width . '" height="' . $height . '"';
            }
            $metadata_attribute = $this->encodePublicMetadataRowsForAttribute( $this->buildPublicLightboxMetadataRows( $record ) );

            $html .= '<div class="col-6 col-md-4 col-xl-3">';
            $html .= '<figure class="tm-photos-gallery-item m-0">';
            $download_attribute = $download_enabled ? ' data-tm-photos-download-url="' . $this->escape( $media_url ) . '"' : '';
            $html .= '<a class="d-block" href="' . $this->escape( $display_url ) . '" aria-label="View ' . $this->escape( $label ) . '" data-tm-photos-lightbox-item data-tm-photos-src="' . $this->escape( $display_url ) . '" data-tm-photos-label="' . $this->escape( $label ) . '" data-tm-photos-metadata="' . $metadata_attribute . '"' . $download_attribute . '>';
            $html .= '<img class="img-fluid rounded object-fit-cover w-100" src="' . $this->escape( $thumbnail_url ) . '" alt="' . $this->escape( $alt_text ) . '" loading="lazy" decoding="async"' . $dimension_attributes . '>';
            $html .= '</a>';
            $html .= '</figure>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</section>';

        return( str_contains( $html, '<img ' ) ? $html : '' );
    }

    protected function resolvePublicGalleryRecords( array $entry, array $media_ids, array $image_overrides = [] ) : array {
        if ( ! is_object( $this->media_service ) || ! method_exists( $this->media_service, 'getAttachmentMetadataByMediaId' ) ) {
            return( [] );
        }

        $owner_usernames = $this->buildPublicOwnerUsernames( $entry );
        if ( empty( $owner_usernames ) ) {
            return( [] );
        }

        $records = [];
        foreach ( $media_ids as $media_id ) {
            $media_id = trim( (string) $media_id );
            if ( $media_id === '' ) {
                continue;
            }

            $metadata = $this->media_service->getAttachmentMetadataByMediaId( $media_id, $owner_usernames );
            if ( ! is_array( $metadata ) || ! $this->metadataLooksLikeImage( $metadata ) ) {
                continue;
            }

            $record = $this->buildImageRecord( $metadata );
            if ( empty( $record['media_id'] ) || $this->normalizePublicMediaUrl( (string) ( $record['url'] ?? '' ) ) === '' ) {
                continue;
            }

            $override = is_array( $image_overrides[$record['media_id']] ?? null ) ? $image_overrides[$record['media_id']] : [];
            $caption_override = mb_trim( (string) ( $override['caption'] ?? '' ) );
            $alt_override = mb_trim( (string) ( $override['alt'] ?? '' ) );
            if ( $caption_override !== '' ) {
                $record['caption'] = $caption_override;
                $record['label'] = $caption_override;
            }
            if ( $alt_override !== '' ) {
                $record['alt_text'] = $alt_override;
                if ( $caption_override === '' ) {
                    $record['label'] = $alt_override;
                }
            }

            $records[] = $record;
        }

        return( $records );
    }

    protected function buildPublicLightboxMetadataRows( array $record ) : array {
        $rows = [];
        $width = max( 0, (int) ( $record['width'] ?? 0 ) );
        $height = max( 0, (int) ( $record['height'] ?? 0 ) );
        if ( $width > 0 && $height > 0 ) {
            $rows[] = [
                'group' => 'Image',
                'label' => 'Dimensions',
                'value' => $width . ' x ' . $height . ' px',
            ];
        }

        foreach ( $this->normalizePublicMetadataRows( $record['public_metadata_rows'] ?? [] ) as $row ) {
            $rows[] = $row;
        }

        return( $rows );
    }

    protected function normalizePublicMetadataRows( mixed $rows ) : array {
        if ( ! is_array( $rows ) ) {
            return( [] );
        }

        $normalized_rows = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $group = mb_trim( (string) ( $row['group'] ?? '' ) );
            $label = mb_trim( (string) ( $row['label'] ?? '' ) );
            $value = mb_trim( (string) ( $row['value'] ?? '' ) );
            if ( $label === '' || $value === '' ) {
                continue;
            }

            $normalized_rows[] = [
                'group' => mb_substr( $group !== '' ? $group : 'Image', 0, 80 ),
                'label' => mb_substr( $label, 0, 120 ),
                'value' => mb_substr( $value, 0, 500 ),
            ];

            if ( count( $normalized_rows ) >= 24 ) {
                break;
            }
        }

        return( $normalized_rows );
    }

    protected function encodePublicMetadataRowsForAttribute( array $rows ) : string {
        $encoded = json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT );
        if ( ! is_string( $encoded ) ) {
            $encoded = '[]';
        }

        return( $this->escape( $encoded ) );
    }

    protected function buildPublicOwnerUsernames( array $entry ) : array {
        $scope = strtolower( trim( (string) ( $entry['scope'] ?? 'root' ) ) );
        if ( $scope === 'author' ) {
            $author_slug = strtolower( trim( (string) ( $entry['author_slug'] ?? '' ) ) );
            return( $author_slug !== '' ? [ $author_slug ] : [] );
        }

        return( [ 'root' ] );
    }

    protected function normalizePublicMediaUrl( string $url ) : string {
        $url = trim( $url );
        if ( $url === '' || ! str_starts_with( $url, '/' ) || str_starts_with( $url, '//' ) ) {
            return( '' );
        }

        return( $url );
    }

    protected function sortRecordsBySelectedOrder( array $records, array $selected_ids ) : array {
        if ( empty( $records ) || empty( $selected_ids ) ) {
            return( $records );
        }

        $order = array_flip( $selected_ids );
        usort(
            $records,
            static function( array $left, array $right ) use ( $order ) : int {
                $left_id = (string) ( $left['media_id'] ?? '' );
                $right_id = (string) ( $right['media_id'] ?? '' );
                $left_order = $order[$left_id] ?? PHP_INT_MAX;
                $right_order = $order[$right_id] ?? PHP_INT_MAX;
                if ( $left_order !== $right_order ) {
                    return( $left_order <=> $right_order );
                }
                return( strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) ) );
            }
        );

        return( $records );
    }

    protected function normalizeGalleryImageOverrides( mixed $value, mixed $allowed_media_ids = [] ) : array {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $value = is_array( $decoded ) ? $decoded : [];
        }
        if ( ! is_array( $value ) ) {
            return( [] );
        }

        $allowed_lookup = array_fill_keys( $this->normalizeMediaIds( $allowed_media_ids ), true );
        $overrides = [];
        foreach ( $value as $media_id => $override ) {
            $media_id = trim( (string) $media_id );
            if ( $media_id === '' || ! preg_match( '/^media_[A-Za-z0-9_-]+$/', $media_id ) ) {
                continue;
            }
            if ( ! empty( $allowed_lookup ) && ! isset( $allowed_lookup[$media_id] ) ) {
                continue;
            }
            if ( ! is_array( $override ) ) {
                continue;
            }

            $caption = mb_substr( mb_trim( (string) ( $override['caption'] ?? '' ) ), 0, 500 );
            $alt = mb_substr( mb_trim( (string) ( $override['alt'] ?? '' ) ), 0, 300 );
            if ( $caption === '' && $alt === '' ) {
                continue;
            }

            $overrides[$media_id] = [
                'caption' => $caption,
                'alt' => $alt,
            ];
        }

        return( $overrides );
    }

    protected function encodeGalleryImageOverridesForField( array $overrides ) : string {
        $encoded = json_encode( $overrides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT );
        return( is_string( $encoded ) ? $encoded : '{}' );
    }

    protected function normalizeMediaIds( mixed $value ) : array {
        if ( is_array( $value ) ) {
            $parts = $value;
        } else {
            $parts = preg_split( '/[\s,]+/u', (string) $value ) ?: [];
        }

        $media_ids = [];
        foreach ( $parts as $part ) {
            if ( ! is_string( $part ) && ! is_numeric( $part ) ) {
                continue;
            }
            $media_id = trim( (string) $part );
            if ( $media_id === '' || ! preg_match( '/^media_[A-Za-z0-9_-]+$/', $media_id ) ) {
                continue;
            }
            if ( ! in_array( $media_id, $media_ids, true ) ) {
                $media_ids[] = $media_id;
            }
        }

        return( $media_ids );
    }

    protected function normalizeGalleryPosition( string $value ) : string {
        $value = strtolower( trim( $value ) );
        return( array_key_exists( $value, $this->getGalleryPositionOptions() ) ? $value : 'after_content' );
    }

    protected function normalizeSlideshowDelaySeconds( mixed $value ) : int {
        $seconds = (int) $value;
        if ( $seconds < 2 ) {
            return( 2 );
        }
        if ( $seconds > 60 ) {
            return( 60 );
        }

        return( $seconds );
    }

    protected function getGalleryPositionOptions() : array {
        return(
            [
                'after_content' => 'After content',
                'before_content' => 'Before content',
            ]
        );
    }

    protected function buildEditorScript() : string {
        return(
            '<script>
(() => {
    const root = document.getElementById("tm-editor-photos-gallery-options");
    const hiddenField = document.getElementById("tm-editor-photos-gallery-media-ids");
    const overridesField = document.getElementById("tm-editor-photos-gallery-image-overrides");
    const countBadge = document.getElementById("tm-editor-photos-gallery-count");
    const emptyMessage = document.getElementById("tm-editor-photos-gallery-empty");
    const uploadButton = document.getElementById("tm-editor-photos-upload-gallery");
    const chooseExistingButton = document.getElementById("tm-editor-photos-choose-existing");
    if (!root || !hiddenField || !overridesField) {
        return;
    }
    let draggedOption = null;
    const normalizeRecord = (record) => {
        if (!record || typeof record !== "object") {
            return null;
        }
        const mediaId = String(record.media_id || "").trim();
        const url = String(record.url || "").trim();
        if (!mediaId || !url) {
            return null;
        }
        return {
            media_id: mediaId,
            url: url,
            thumbnail_url: String(record.thumbnail_url || "").trim(),
            label: String(record.label || record.alt_text || record.filename || mediaId).trim(),
            filename: String(record.filename || mediaId).trim(),
            alt_text: String(record.alt_text || "").trim(),
            mime: String(record.mime || "").trim(),
            width: Math.max(0, Number.parseInt(record.width || "0", 10) || 0),
            height: Math.max(0, Number.parseInt(record.height || "0", 10) || 0)
        };
    };
    const getSelectedMediaIds = () => Array.from(root.querySelectorAll(".tm-editor-photos-gallery-check:checked"))
        .map((field) => String(field.value || "").trim())
        .filter((value, index, values) => value !== "" && values.indexOf(value) === index);
    const collectOverrides = () => {
        const overrides = {};
        root.querySelectorAll("[data-tm-editor-photos-gallery-option]").forEach((option) => {
            const mediaId = String(option.getAttribute("data-tm-editor-photos-gallery-option") || "").trim();
            if (!mediaId) {
                return;
            }
            const captionField = option.querySelector(".tm-editor-photos-gallery-caption");
            const altField = option.querySelector(".tm-editor-photos-gallery-alt");
            const caption = captionField ? String(captionField.value || "").trim() : "";
            const alt = altField ? String(altField.value || "").trim() : "";
            if (caption || alt) {
                overrides[mediaId] = { caption: caption, alt: alt };
            }
        });
        return overrides;
    };
    const setEmptyState = () => {
        const hasOptions = root.querySelector(".tm-editor-photos-gallery-check") !== null;
        root.classList.toggle("d-none", !hasOptions);
        if (emptyMessage) {
            emptyMessage.classList.toggle("d-none", hasOptions);
        }
    };
    const getOptions = () => Array.from(root.querySelectorAll("[data-tm-editor-photos-gallery-option]"));
    const updateOrderControls = () => {
        const options = getOptions();
        options.forEach((option, index) => {
            option.querySelectorAll("[data-tm-editor-photos-gallery-move]").forEach((button) => {
                const direction = String(button.getAttribute("data-tm-editor-photos-gallery-move") || "");
                button.disabled = (direction === "up" && index === 0) || (direction === "down" && index === options.length - 1);
            });
        });
    };
    const update = () => {
        const selected = Array.from(root.querySelectorAll(".tm-editor-photos-gallery-check:checked"))
            .map((field) => String(field.value || "").trim())
            .filter((value, index, values) => value !== "" && values.indexOf(value) === index);
        hiddenField.value = selected.join("\n");
        if (countBadge) {
            countBadge.textContent = selected.length + " selected";
        }
        overridesField.value = JSON.stringify(collectOverrides());
        hiddenField.dispatchEvent(new Event("change", { bubbles: true }));
        overridesField.dispatchEvent(new Event("change", { bubbles: true }));
        setEmptyState();
        updateOrderControls();
    };
    const buildOption = (record) => {
        const normalized = normalizeRecord(record);
        if (!normalized) {
            return null;
        }
        const column = document.createElement("div");
        column.className = "col-12 col-md-6 col-xl-4 min-w-0";
        column.setAttribute("data-tm-editor-photos-gallery-option", normalized.media_id);

        const wrapper = document.createElement("div");
        wrapper.className = "border rounded-3 p-2 d-flex flex-column gap-2 h-100 w-100 overflow-hidden tm-editor-photos-gallery-option";
        wrapper.style.minWidth = "0";

        const label = document.createElement("label");
        label.className = "d-flex gap-2 w-100 overflow-hidden";
        label.style.minWidth = "0";

        const checkbox = document.createElement("input");
        checkbox.className = "form-check-input mt-1 tm-editor-photos-gallery-check";
        checkbox.type = "checkbox";
        checkbox.value = normalized.media_id;
        checkbox.checked = true;
        label.appendChild(checkbox);

        const thumbUrl = normalized.thumbnail_url || normalized.url;
        if (thumbUrl) {
            const image = document.createElement("img");
            image.className = "rounded-2 object-fit-cover flex-shrink-0";
            image.src = thumbUrl;
            image.alt = "";
            image.style.width = "4.5rem";
            image.style.height = "4.5rem";
            image.style.minWidth = "4.5rem";
            label.appendChild(image);
        }

        const textWrap = document.createElement("span");
        textWrap.className = "d-flex flex-column gap-1 min-w-0 flex-grow-1 overflow-hidden";

        const title = document.createElement("span");
        title.className = "fw-semibold text-truncate";
        title.textContent = normalized.label || normalized.media_id;
        textWrap.appendChild(title);

        const filename = document.createElement("span");
        filename.className = "small text-body-secondary text-truncate";
        filename.textContent = normalized.filename || normalized.media_id;
        filename.title = normalized.filename || normalized.media_id;
        textWrap.appendChild(filename);

        const mediaId = document.createElement("span");
        mediaId.className = "small font-monospace text-body-secondary text-break";
        mediaId.textContent = normalized.media_id;
        textWrap.appendChild(mediaId);

        label.appendChild(textWrap);
        wrapper.appendChild(label);

        const orderControls = document.createElement("div");
        orderControls.className = "d-flex flex-wrap gap-2 ps-4";

        const moveUp = document.createElement("button");
        moveUp.className = "btn btn-outline-secondary btn-sm d-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-move";
        moveUp.type = "button";
        moveUp.setAttribute("data-tm-editor-photos-gallery-move", "up");
        moveUp.setAttribute("aria-label", "Move image earlier");
        const moveUpIcon = document.createElement("span");
        moveUpIcon.className = "bi bi-arrow-up";
        moveUpIcon.setAttribute("aria-hidden", "true");
        moveUp.appendChild(moveUpIcon);
        orderControls.appendChild(moveUp);

        const moveDown = document.createElement("button");
        moveDown.className = "btn btn-outline-secondary btn-sm d-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-move";
        moveDown.type = "button";
        moveDown.setAttribute("data-tm-editor-photos-gallery-move", "down");
        moveDown.setAttribute("aria-label", "Move image later");
        const moveDownIcon = document.createElement("span");
        moveDownIcon.className = "bi bi-arrow-down";
        moveDownIcon.setAttribute("aria-hidden", "true");
        moveDown.appendChild(moveDownIcon);
        orderControls.appendChild(moveDown);

        const dragHandle = document.createElement("button");
        dragHandle.className = "btn btn-outline-secondary btn-sm d-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-drag-handle";
        dragHandle.type = "button";
        dragHandle.draggable = true;
        dragHandle.setAttribute("data-tm-editor-photos-gallery-drag-handle", "");
        dragHandle.setAttribute("aria-label", "Drag to reorder image");
        dragHandle.title = "Drag to reorder";
        const dragHandleIcon = document.createElement("span");
        dragHandleIcon.className = "bi bi-grip-vertical";
        dragHandleIcon.setAttribute("aria-hidden", "true");
        dragHandle.appendChild(dragHandleIcon);
        orderControls.appendChild(dragHandle);

        const removeButton = document.createElement("button");
        removeButton.className = "btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 tm-editor-photos-gallery-remove";
        removeButton.type = "button";
        removeButton.setAttribute("data-tm-editor-photos-gallery-remove", "");
        removeButton.setAttribute("aria-label", "Remove image from gallery");
        const removeIcon = document.createElement("span");
        removeIcon.className = "bi bi-x-lg";
        removeIcon.setAttribute("aria-hidden", "true");
        const removeText = document.createElement("span");
        removeText.textContent = "Remove";
        removeButton.appendChild(removeIcon);
        removeButton.appendChild(removeText);
        orderControls.appendChild(removeButton);

        wrapper.appendChild(orderControls);

        const fieldsRow = document.createElement("div");
        fieldsRow.className = "row g-2 ps-4";

        const captionColumn = document.createElement("div");
        captionColumn.className = "col-12";
        const captionLabel = document.createElement("label");
        captionLabel.className = "form-label small mb-1";
        captionLabel.setAttribute("for", "tm-editor-photos-caption-" + normalized.media_id);
        captionLabel.textContent = "Caption";
        const captionInput = document.createElement("input");
        captionInput.className = "form-control form-control-sm tm-editor-photos-gallery-caption";
        captionInput.id = "tm-editor-photos-caption-" + normalized.media_id;
        captionInput.type = "text";
        captionInput.maxLength = 500;
        captionInput.setAttribute("data-tm-editor-photos-media-id", normalized.media_id);
        captionColumn.appendChild(captionLabel);
        captionColumn.appendChild(captionInput);
        fieldsRow.appendChild(captionColumn);

        const altColumn = document.createElement("div");
        altColumn.className = "col-12";
        const altLabel = document.createElement("label");
        altLabel.className = "form-label small mb-1";
        altLabel.setAttribute("for", "tm-editor-photos-alt-" + normalized.media_id);
        altLabel.textContent = "Alt text";
        const altInput = document.createElement("input");
        altInput.className = "form-control form-control-sm tm-editor-photos-gallery-alt";
        altInput.id = "tm-editor-photos-alt-" + normalized.media_id;
        altInput.type = "text";
        altInput.maxLength = 300;
        altInput.setAttribute("data-tm-editor-photos-media-id", normalized.media_id);
        altColumn.appendChild(altLabel);
        altColumn.appendChild(altInput);
        fieldsRow.appendChild(altColumn);

        wrapper.appendChild(fieldsRow);
        column.appendChild(wrapper);
        return column;
    };
    const addRecords = (records) => {
        (Array.isArray(records) ? records : []).forEach((record) => {
            const normalized = normalizeRecord(record);
            if (!normalized) {
                return;
            }
            const existing = Array.from(root.querySelectorAll("[data-tm-editor-photos-gallery-option]"))
                .find((element) => element.getAttribute("data-tm-editor-photos-gallery-option") === normalized.media_id);
            if (existing) {
                const checkbox = existing.querySelector(".tm-editor-photos-gallery-check");
                if (checkbox) {
                    checkbox.checked = true;
                }
                return;
            }
            const option = buildOption(normalized);
            if (option) {
                root.appendChild(option);
            }
        });
        update();
    };
    root.addEventListener("click", (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const removeButton = target ? target.closest("[data-tm-editor-photos-gallery-remove]") : null;
        if (removeButton instanceof HTMLButtonElement) {
            const option = removeButton.closest("[data-tm-editor-photos-gallery-option]");
            const checkbox = option ? option.querySelector(".tm-editor-photos-gallery-check") : null;
            if (checkbox instanceof HTMLInputElement) {
                checkbox.checked = false;
                update();
                checkbox.focus({ preventScroll: true });
            }
            return;
        }
        const button = target ? target.closest("[data-tm-editor-photos-gallery-move]") : null;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const option = button.closest("[data-tm-editor-photos-gallery-option]");
        if (!(option instanceof HTMLElement)) {
            return;
        }
        const direction = String(button.getAttribute("data-tm-editor-photos-gallery-move") || "");
        if (direction === "up" && option.previousElementSibling) {
            root.insertBefore(option, option.previousElementSibling);
            update();
            const movedButton = option.querySelector("[data-tm-editor-photos-gallery-move=\"up\"]");
            if (movedButton instanceof HTMLButtonElement) {
                movedButton.focus({ preventScroll: true });
            }
        } else if (direction === "down" && option.nextElementSibling) {
            root.insertBefore(option.nextElementSibling, option);
            update();
            const movedButton = option.querySelector("[data-tm-editor-photos-gallery-move=\"down\"]");
            if (movedButton instanceof HTMLButtonElement) {
                movedButton.focus({ preventScroll: true });
            }
        }
    });
    root.addEventListener("dragstart", (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const handle = target ? target.closest("[data-tm-editor-photos-gallery-drag-handle]") : null;
        if (!handle) {
            return;
        }
        const option = handle.closest("[data-tm-editor-photos-gallery-option]");
        if (!(option instanceof HTMLElement)) {
            return;
        }
        draggedOption = option;
        option.classList.add("opacity-50");
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = "move";
            event.dataTransfer.setData("text/plain", option.getAttribute("data-tm-editor-photos-gallery-option") || "");
        }
    });
    root.addEventListener("dragover", (event) => {
        if (!(draggedOption instanceof HTMLElement)) {
            return;
        }
        const target = event.target instanceof Element ? event.target.closest("[data-tm-editor-photos-gallery-option]") : null;
        if (!(target instanceof HTMLElement) || target === draggedOption) {
            return;
        }
        event.preventDefault();
        const targetRect = target.getBoundingClientRect();
        const options = getOptions();
        const firstRowTop = options[0] ? options[0].getBoundingClientRect().top : targetRect.top;
        const columnsInFirstRow = options.filter((option) => Math.abs(option.getBoundingClientRect().top - firstRowTop) < 8).length;
        const placeBefore = columnsInFirstRow > 1
            ? event.clientX < targetRect.left + (targetRect.width / 2)
            : event.clientY < targetRect.top + (targetRect.height / 2);
        root.insertBefore(draggedOption, placeBefore ? target : target.nextElementSibling);
    });
    root.addEventListener("drop", (event) => {
        if (!(draggedOption instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        draggedOption.classList.remove("opacity-50");
        draggedOption = null;
        update();
    });
    root.addEventListener("dragend", () => {
        if (draggedOption instanceof HTMLElement) {
            draggedOption.classList.remove("opacity-50");
        }
        draggedOption = null;
        update();
    });
    root.addEventListener("change", update);
    root.addEventListener("input", (event) => {
        const target = event.target;
        if (target && (target.classList.contains("tm-editor-photos-gallery-caption") || target.classList.contains("tm-editor-photos-gallery-alt"))) {
            update();
        }
    });
    if (uploadButton) {
        uploadButton.addEventListener("click", () => {
            document.dispatchEvent(new CustomEvent("tinymash:editor-request-image-upload", {
                detail: { target: "gallery" }
            }));
        });
    }
    if (chooseExistingButton) {
        chooseExistingButton.addEventListener("click", () => {
            document.dispatchEvent(new CustomEvent("tinymash:editor-request-image-picker", {
                detail: { target: "gallery" }
            }));
        });
    }
    document.addEventListener("tinymash:editor-images-uploaded", (event) => {
        const detail = event && event.detail && typeof event.detail === "object" ? event.detail : {};
        if (String(detail.target || "") !== "gallery") {
            return;
        }
        addRecords(detail.records || []);
    });
    document.addEventListener("tinymash:editor-gallery-images-selected", (event) => {
        const detail = event && event.detail && typeof event.detail === "object" ? event.detail : {};
        addRecords(detail.records || []);
    });
    update();
})();
</script>'
        );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
