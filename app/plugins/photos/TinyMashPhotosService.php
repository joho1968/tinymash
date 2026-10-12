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
        $linked_media = $this->normalizeGalleryLinkedMedia( $plugin_settings['gallery_linked_media'] ?? [], $media_ids );

        return(
            [
                'photo_post_enabled' => ! empty( $plugin_settings['photo_post_enabled'] ),
                'gallery_enabled' => ! empty( $plugin_settings['gallery_enabled'] ),
                'gallery_download_enabled' => ! empty( $plugin_settings['gallery_download_enabled'] ),
                'gallery_slideshow_enabled' => ! empty( $plugin_settings['gallery_slideshow_enabled'] ),
                'gallery_slideshow_delay_seconds' => $this->normalizeSlideshowDelaySeconds( $plugin_settings['gallery_slideshow_delay_seconds'] ?? 5 ),
                'gallery_media_ids' => $media_ids,
                'gallery_image_overrides' => $image_overrides,
                'gallery_linked_media' => $linked_media,
                'gallery_position' => $this->normalizeGalleryPosition( (string) ( $plugin_settings['gallery_position'] ?? 'after_content' ) ),
            ]
        );
    }

    public function buildEditorTabHtml( array $context = [] ) : string {
        $draft = is_array( $context['draft'] ?? null ) ? $context['draft'] : [];
        $settings = $this->buildEntryEditorSettings( $draft );
        $selected_ids = $settings['gallery_media_ids'];
        $linked_media = $settings['gallery_linked_media'];
        $attached_records = $this->listAttachedImageRecords( $context, $selected_ids, $linked_media );
        $selected_lookup = array_fill_keys( $selected_ids, true );
        $ordered_records = $this->sortRecordsBySelectedOrder( $attached_records, $selected_ids );
        $image_overrides = is_array( $settings['gallery_image_overrides'] ?? null ) ? $settings['gallery_image_overrides'] : [];
        $image_upload = is_array( $context['editor_image_upload'] ?? null ) ? $context['editor_image_upload'] : [];
        $image_upload_enabled = ! empty( $image_upload['enabled'] );
        $can_copy_linked = ! empty( $context['is_superadmin'] ) && $image_upload_enabled;
        $selected_count = count( $selected_ids );
        $unavailable_count = count( array_filter( $ordered_records, static fn( array $record ) : bool => trim( (string) ( $record['url'] ?? '' ) ) === '' ) );

        $html = '<div class="d-flex flex-column gap-4">';
        $html .= '<fieldset class="m-0">';
        $html .= '<legend class="small fw-semibold mb-2" id="tm-editor-photos-presentation-heading">Presentation</legend>';
        $html .= '<div class="form-check">';
        $html .= '<input class="form-check-input" id="tm-editor-photos-photo-post-enabled" type="checkbox" data-editor-plugin-key="photos" data-editor-plugin-field="photo_post_enabled"' . ( ! empty( $settings['photo_post_enabled'] ) ? ' checked' : '' ) . '>';
        $html .= '<label class="form-check-label" for="tm-editor-photos-photo-post-enabled">Treat this as a photo post</label>';
        $html .= '<div class="form-text">Themes can use this for image-led layouts. The entry remains a normal post or page.</div>';
        $html .= '</div>';
        $html .= '</fieldset>';
        $html .= '<fieldset class="m-0">';
        $html .= '<legend class="small fw-semibold mb-2" id="tm-editor-photos-gallery-heading">Gallery</legend>';
        $html .= '<div class="form-check">';
        $html .= '<input class="form-check-input" id="tm-editor-photos-gallery-enabled" type="checkbox" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_enabled"' . ( ! empty( $settings['gallery_enabled'] ) ? ' checked' : '' ) . '>';
        $html .= '<label class="form-check-label" for="tm-editor-photos-gallery-enabled">Show a gallery</label>';
        $html .= '<div class="form-text">Choose gallery images below. Images in the post are not added automatically. The featured image is the cover.</div>';
        $html .= '<div class="form-text' . ( empty( $settings['gallery_enabled'] ) && $selected_count > 0 ? '' : ' d-none' ) . '" id="tm-editor-photos-gallery-retained">' . $selected_count . ( $selected_count === 1 ? ' image remains selected' : ' images remain selected' ) . ' while the gallery is off.</div>';
        $html .= '</div>';
        $html .= '<div class="mt-2">';
        $html .= '<button class="btn btn-outline-secondary btn-sm" id="tm-editor-photos-convert-preview" type="button">Review post images for gallery</button>';
        $html .= '<div class="d-none mt-2" id="tm-editor-photos-convert-panel" aria-live="polite">';
        $html .= '<p class="small mb-1" id="tm-editor-photos-convert-summary"></p>';
        $html .= '<ol class="small mb-1 overflow-auto pe-2" id="tm-editor-photos-convert-images" style="max-height: min(50vh, 24rem);"></ol>';
        $html .= '<div class="small text-body-secondary mb-2 text-break overflow-auto" id="tm-editor-photos-convert-skipped" style="max-height: 16rem;"></div>';
        $html .= '<div class="d-flex flex-wrap gap-2">';
        $html .= '<button class="btn btn-primary btn-sm" id="tm-editor-photos-convert-apply" type="button">Convert to gallery</button>';
        $html .= '<button class="btn btn-outline-secondary btn-sm" id="tm-editor-photos-convert-cancel" type="button">Cancel</button>';
        $html .= '</div></div></div>';
        $html .= '<div class="mt-3' . ( empty( $settings['gallery_enabled'] ) ? ' d-none' : '' ) . '" id="tm-editor-photos-gallery-settings" aria-hidden="' . ( empty( $settings['gallery_enabled'] ) ? 'true' : 'false' ) . '">';
        $html .= '<input type="hidden" id="tm-editor-photos-gallery-media-ids" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_media_ids" value="' . $this->escape( implode( "\n", $selected_ids ) ) . '">';
        $html .= '<input type="hidden" id="tm-editor-photos-gallery-image-overrides" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_image_overrides" value="' . $this->escape( $this->encodeGalleryJsonForField( $image_overrides ) ) . '">';
        $html .= '<input type="hidden" id="tm-editor-photos-gallery-linked-media" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_linked_media" value="' . $this->escape( $this->encodeGalleryJsonForField( $linked_media ) ) . '">';
        $html .= '<div class="mb-2">';
        $html .= '<div class="fw-semibold">Gallery images</div>';
        $html .= '<div class="small text-body-secondary">Choose existing images or upload new ones. Removing an image only takes it out of this gallery.</div>';
        $html .= '<div class="d-flex flex-wrap gap-2 align-items-center mt-2">';
        $html .= '<button class="btn btn-outline-secondary btn-sm" id="tm-editor-photos-choose-existing" type="button"><span class="bi bi-images me-1" aria-hidden="true"></span>Choose existing images</button>';
        if ( $image_upload_enabled ) {
            $html .= '<button class="btn btn-outline-secondary btn-sm" id="tm-editor-photos-upload-gallery" type="button"><span class="bi bi-images me-1" aria-hidden="true"></span>Upload gallery images</button>';
        }
        $html .= '<span class="small text-body-secondary" id="tm-editor-photos-gallery-count" aria-live="polite">' . $selected_count . ( $selected_count === 1 ? ' image selected' : ' images selected' ) . '</span>';
        $html .= '</div>';
        $html .= '<div id="tm-editor-photos-gallery-sort" class="mt-2' . ( $selected_count < 2 ? ' d-none' : '' ) . '">';
        $html .= '<div class="d-flex flex-wrap align-items-end gap-2" id="tm-editor-photos-gallery-sort-controls">';
        $html .= '<div><label class="form-label small mb-1" for="tm-editor-photos-gallery-sort-key">Sort selected images by</label><select class="form-select form-select-sm" id="tm-editor-photos-gallery-sort-key"><option value="filename">Original filename</option><option value="upload">Upload time</option><option value="capture">Capture date (if retained)</option></select></div>';
        $html .= '<div><label class="form-label small mb-1" for="tm-editor-photos-gallery-sort-direction">Direction</label><select class="form-select form-select-sm" id="tm-editor-photos-gallery-sort-direction"><option value="asc">Ascending</option><option value="desc">Descending</option></select></div>';
        $html .= '<button class="btn btn-outline-secondary btn-sm" id="tm-editor-photos-gallery-sort-apply" type="button">Sort selected images</button>';
        $html .= '</div>';
        $html .= '<div class="small text-body-secondary mt-1" id="tm-editor-photos-gallery-sort-status" role="status" aria-live="polite">Manual order is kept until sorting is applied. Save to keep a new order.</div>';
        $html .= '</div>';
        $html .= '<div class="small text-body-secondary mt-1" id="tm-editor-photos-gallery-progress" aria-live="polite">Uploaded this session: 0 · Saved: ' . $selected_count . '</div>';
        if ( $unavailable_count > 0 ) {
            $html .= '<div class="small text-warning-emphasis mt-1" role="status">' . $unavailable_count . ( $unavailable_count === 1 ? ' image is' : ' images are' ) . ' unavailable here. Use Replace image to choose another, or remove it before publishing.</div>';
        }
        $html .= '<div class="small mt-1" id="tm-editor-photos-recovery-status" role="status" aria-live="polite"></div>';
        $html .= '</div>';
        $html .= '<p class="small text-body-secondary mb-0' . ( empty( $ordered_records ) ? '' : ' d-none' ) . '" id="tm-editor-photos-gallery-empty">No images are available for this entry yet.</p>';
        $html .= '<div class="overflow-auto' . ( empty( $ordered_records ) ? ' d-none' : '' ) . '" id="tm-editor-photos-gallery-options" role="list" aria-label="Gallery image order" tabindex="0" style="max-height: min(65vh, 38rem);" data-can-copy-linked="' . ( $can_copy_linked ? '1' : '0' ) . '">';
        foreach ( $ordered_records as $record ) {
            $media_id = (string) ( $record['media_id'] ?? '' );
            $html .= $this->buildEditorGalleryOptionHtml( $record, isset( $selected_lookup[$media_id] ), is_array( $image_overrides[$media_id] ?? null ) ? $image_overrides[$media_id] : [], isset( $linked_media[$media_id] ), $can_copy_linked );
        }
        $html .= '</div>';
        $html .= '<div class="small text-body-secondary mt-1' . ( count( $ordered_records ) > 10 ? '' : ' d-none' ) . '" id="tm-editor-photos-gallery-scroll-hint">Scroll the list to see all images.</div>';
        $html .= '<div class="visually-hidden" id="tm-editor-photos-gallery-order-status" role="status" aria-live="polite"></div>';
        $html .= '<div class="row g-3 mt-1">';
        $html .= '<div class="col-12 col-lg-4">';
        $html .= '<label class="form-label small mb-1" for="tm-editor-photos-gallery-position">Gallery position</label>';
        $html .= '<select class="form-select" id="tm-editor-photos-gallery-position" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_position">';
        foreach ( $this->getGalleryPositionOptions() as $value => $label ) {
            $html .= '<option value="' . $this->escape( $value ) . '"' . ( $settings['gallery_position'] === $value ? ' selected' : '' ) . '>' . $this->escape( $label ) . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        $html .= '<div class="col-12 col-lg-8">';
        $html .= '<div class="small fw-semibold mb-2" id="tm-editor-photos-lightbox-heading">Lightbox</div>';
        $html .= '<div class="row g-3" aria-labelledby="tm-editor-photos-lightbox-heading">';
        $html .= '<div class="col-12 col-md-6">';
        $html .= '<div class="form-check">';
        $html .= '<input class="form-check-input" id="tm-editor-photos-gallery-download-enabled" type="checkbox" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_download_enabled"' . ( ! empty( $settings['gallery_download_enabled'] ) ? ' checked' : '' ) . '>';
        $html .= '<label class="form-check-label" for="tm-editor-photos-gallery-download-enabled">Allow image downloads</label>';
        $html .= '<div class="form-text">Adds a download action for each gallery image.</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="col-12 col-md-6">';
        $html .= '<div class="form-check">';
        $html .= '<input class="form-check-input" id="tm-editor-photos-gallery-slideshow-enabled" type="checkbox" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_slideshow_enabled"' . ( ! empty( $settings['gallery_slideshow_enabled'] ) ? ' checked' : '' ) . '>';
        $html .= '<label class="form-check-label" for="tm-editor-photos-gallery-slideshow-enabled">Enable slideshow</label>';
        $html .= '<div class="form-text">Visitors can start and stop automatic playback.</div>';
        $html .= '<div class="mt-2' . ( empty( $settings['gallery_slideshow_enabled'] ) ? ' d-none' : '' ) . '" id="tm-editor-photos-gallery-slideshow-delay-wrap" aria-hidden="' . ( empty( $settings['gallery_slideshow_enabled'] ) ? 'true' : 'false' ) . '">';
        $html .= '<label class="form-label small mb-1" for="tm-editor-photos-gallery-slideshow-delay">Delay (seconds)</label>';
        $html .= '<input class="form-control font-monospace w-auto" id="tm-editor-photos-gallery-slideshow-delay" type="number" min="2" max="60" step="1" data-editor-plugin-key="photos" data-editor-plugin-field="gallery_slideshow_delay_seconds" value="' . (int) $settings['gallery_slideshow_delay_seconds'] . '">';
        $html .= '<div class="form-text">2 to 60 seconds.</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</fieldset>';
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
                'gallery_image_overrides' => $this->encodeGalleryJsonForField( $this->normalizeGalleryImageOverrides( $settings['gallery_image_overrides'] ?? [], $settings['gallery_media_ids'] ?? [] ) ),
                'gallery_linked_media' => $this->encodeGalleryJsonForField( $this->normalizeGalleryLinkedMedia( $settings['gallery_linked_media'] ?? [], $settings['gallery_media_ids'] ?? [] ) ),
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
            $settings['gallery_linked_media'] ?? [],
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

    protected function listAttachedImageRecords( array $context, array $selected_ids = [], array $linked_media = [] ) : array {
        $draft = is_array( $context['draft'] ?? null ) ? $context['draft'] : [];
        $scope = strtolower( trim( (string) ( $draft['scope'] ?? 'root' ) ) ) === 'author' ? 'author' : 'root';
        $author_slug = strtolower( trim( (string) ( $draft['author_slug'] ?? $context['current_author_slug'] ?? '' ) ) );
        $owner_usernames = $this->buildOwnerUsernames( $scope, $author_slug, ! empty( $context['is_superadmin'] ), (string) ( $context['current_author_slug'] ?? '' ) );
        $records = [];
        if ( $this->media_service instanceof TinyMashMediaService && ! empty( $owner_usernames ) ) {
            foreach ( $this->media_service->listAttachmentsForContent(
                $owner_usernames,
                (string) ( $context['loaded_entry_id'] ?? '' ),
                (string) ( $context['loaded_draft_id'] ?? '' ),
                '',
                80
            ) as $metadata ) {
                if ( ! is_array( $metadata ) || ! $this->metadataLooksLikeImage( $metadata ) ) {
                    continue;
                }
                $record = $this->buildImageRecord( $metadata );
                if ( ! empty( $record['media_id'] ) ) {
                    $records[$record['media_id']] = $record;
                }
            }
        }

        foreach ( $selected_ids as $media_id ) {
            if ( isset( $records[$media_id] ) && ! isset( $linked_media[$media_id] ) ) {
                continue;
            }
            $lookup_owners = isset( $linked_media[$media_id] ) && $scope === 'author' ? [ 'root' ] : $owner_usernames;
            $metadata = $this->media_service instanceof TinyMashMediaService && ! empty( $lookup_owners )
                ? $this->media_service->getAttachmentMetadataByMediaId( $media_id, $lookup_owners )
                : null;
            if ( is_array( $metadata ) && $this->metadataLooksLikeImage( $metadata ) ) {
                $records[$media_id] = $this->buildImageRecord( $metadata );
                continue;
            }
            $records[$media_id] = [
                'media_id' => $media_id,
                'label' => 'Unavailable image',
                'display_filename' => isset( $linked_media[$media_id] ) ? 'Linked Root image not found' : 'Not found in this space',
            ];
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
        $original_filename = basename( trim( (string) ( $metadata['original_filename'] ?? '' ) ) );
        $display_filename = $original_filename !== '' ? $original_filename : $filename;
        $alt_text = mb_trim( (string) ( $metadata['alt_text'] ?? '' ) );
        $label = $alt_text !== '' ? $alt_text : ( $display_filename !== '' ? $display_filename : $media_id );

        return(
            [
                'media_id' => $media_id,
                'owner_username' => strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) ),
                'filename' => $filename,
                'original_filename' => $original_filename,
                'display_filename' => $display_filename,
                'created_at_utc' => (string) ( $metadata['created_at_utc'] ?? '' ),
                'capture_date_local' => TinyMashMediaService::retainedCaptureDate( $metadata ),
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

    protected function buildEditorGalleryOptionHtml( array $record, bool $checked, array $overrides = [], bool $linked = false, bool $can_copy_linked = false ) : string {
        $media_id = (string) ( $record['media_id'] ?? '' );
        if ( $media_id === '' ) {
            return( '' );
        }

        $thumb_url = trim( (string) ( $record['thumbnail_url'] ?? '' ) );
        if ( $thumb_url === '' ) {
            $thumb_url = trim( (string) ( $record['url'] ?? '' ) );
        }
        $label = mb_trim( (string) ( $record['label'] ?? $media_id ) );
        $filename = trim( (string) ( $record['display_filename'] ?? $record['original_filename'] ?? $record['filename'] ?? '' ) );
        $caption_override = mb_trim( (string) ( $overrides['caption'] ?? '' ) );
        $alt_override = mb_trim( (string) ( $overrides['alt'] ?? '' ) );

        $html = '<div class="border-bottom py-2 min-w-0" role="listitem" data-tm-editor-photos-gallery-option="' . $this->escape( $media_id ) . '" data-sort-filename="' . $this->escape( (string) ( $record['display_filename'] ?? '' ) ) . '" data-sort-upload="' . $this->escape( (string) ( $record['created_at_utc'] ?? '' ) ) . '" data-sort-capture="' . $this->escape( (string) ( $record['capture_date_local'] ?? '' ) ) . '">';
        $html .= '<div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2 tm-editor-photos-gallery-option">';
        $html .= '<div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0">';
        $html .= '<span class="small text-body-secondary text-end flex-shrink-0" data-tm-editor-photos-gallery-position style="width: 2rem;" aria-hidden="true"></span>';
        $html .= '<label class="d-flex align-items-center gap-2 flex-grow-1 min-w-0 overflow-hidden">';
        $html .= '<input class="form-check-input mt-1 tm-editor-photos-gallery-check" type="checkbox" value="' . $this->escape( $media_id ) . '"' . ( $checked ? ' checked' : '' ) . '>';
        if ( $thumb_url !== '' ) {
            $html .= '<img class="rounded-2 object-fit-cover flex-shrink-0" src="' . $this->escape( $thumb_url ) . '" alt="" style="width: 3.5rem; height: 3.5rem; min-width: 3.5rem;">';
        }
        $html .= '<span class="d-flex flex-column gap-1 min-w-0 flex-grow-1 overflow-hidden">';
        $html .= '<span class="fw-semibold text-truncate" title="' . $this->escape( $label !== '' ? $label : $media_id ) . '">' . $this->escape( $label !== '' ? $label : $media_id ) . '</span>';
        if ( $filename !== '' && $filename !== $label ) {
            $html .= '<span class="small text-body-secondary text-truncate" title="' . $this->escape( $filename ) . '">' . $this->escape( $filename ) . '</span>';
        }
        if ( trim( (string) ( $record['url'] ?? '' ) ) === '' ) {
            $html .= '<span class="small font-monospace text-body-secondary text-break">' . $this->escape( $media_id ) . '</span>';
        }
        if ( $linked ) {
            $html .= '<span class="small text-body-secondary">Root media · linked</span>';
        }
        $html .= '</span>';
        $html .= '</label></div>';
        $html .= '<div class="d-flex flex-wrap gap-2 align-items-center ps-0 ps-lg-2">';
        $html .= '<button class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-move" type="button" data-tm-editor-photos-gallery-move="up" aria-label="Move image earlier"><span class="bi bi-arrow-up" aria-hidden="true"></span></button>';
        $html .= '<button class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-move" type="button" data-tm-editor-photos-gallery-move="down" aria-label="Move image later"><span class="bi bi-arrow-down" aria-hidden="true"></span></button>';
        $html .= '<button class="btn btn-outline-secondary btn-sm d-none d-sm-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-drag-handle" type="button" draggable="true" data-tm-editor-photos-gallery-drag-handle aria-label="Drag to reorder image" title="Drag to reorder"><span class="bi bi-grip-vertical" aria-hidden="true"></span></button>';
        $html .= '<button class="btn btn-outline-secondary btn-sm" type="button" data-tm-editor-photos-gallery-replace>Replace image</button>';
        if ( $linked && $can_copy_linked && trim( (string) ( $record['url'] ?? '' ) ) !== '' ) {
            $html .= '<button class="btn btn-outline-secondary btn-sm" type="button" data-tm-editor-photos-gallery-copy>Make a copy here</button>';
        }
        $html .= '<button class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 tm-editor-photos-gallery-remove" type="button" data-tm-editor-photos-gallery-remove aria-label="Remove image from gallery"><span class="bi bi-x-lg" aria-hidden="true"></span><span>Remove</span></button>';
        $html .= '</div></div>';
        $html .= '<details class="small mt-1 ps-0 ps-lg-4">';
        $html .= '<summary>Caption and alt text<span class="text-body-secondary" data-tm-editor-photos-gallery-edited>' . ( $caption_override !== '' || $alt_override !== '' ? ' · Edited' : '' ) . '</span></summary>';
        $html .= '<div class="row g-2 mt-1">';
        $html .= '<div class="col-12">';
        $html .= '<label class="form-label small mb-1" for="tm-editor-photos-caption-' . $this->escape( $media_id ) . '">Caption</label>';
        $html .= '<input class="form-control form-control-sm tm-editor-photos-gallery-caption" id="tm-editor-photos-caption-' . $this->escape( $media_id ) . '" type="text" maxlength="500" data-tm-editor-photos-media-id="' . $this->escape( $media_id ) . '" value="' . $this->escape( $caption_override ) . '">';
        $html .= '</div>';
        $html .= '<div class="col-12">';
        $html .= '<label class="form-label small mb-1" for="tm-editor-photos-alt-' . $this->escape( $media_id ) . '">Alt text</label>';
        $html .= '<input class="form-control form-control-sm tm-editor-photos-gallery-alt" id="tm-editor-photos-alt-' . $this->escape( $media_id ) . '" type="text" maxlength="300" data-tm-editor-photos-media-id="' . $this->escape( $media_id ) . '" value="' . $this->escape( $alt_override ) . '">';
        $html .= '</div></div></details>';
        $html .= '</div>';

        return( $html );
    }

    protected function buildPublicGalleryHtml( array $entry, array $media_ids, array $image_overrides = [], array $linked_media = [], bool $download_enabled = false, bool $slideshow_enabled = false, int $slideshow_delay_seconds = 5 ) : string {
        $records = $this->resolvePublicGalleryRecords( $entry, $media_ids, $image_overrides, $linked_media );
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

    protected function resolvePublicGalleryRecords( array $entry, array $media_ids, array $image_overrides = [], array $linked_media = [] ) : array {
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

            $lookup_owners = isset( $linked_media[$media_id] ) && strtolower( trim( (string) ( $entry['scope'] ?? '' ) ) ) === 'author' ? [ 'root' ] : $owner_usernames;
            $metadata = $this->media_service->getAttachmentMetadataByMediaId( $media_id, $lookup_owners );
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

    protected function normalizeGalleryLinkedMedia( mixed $value, mixed $allowed_media_ids = null ) : array {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $value = is_array( $decoded ) ? $decoded : [];
        }
        if ( ! is_array( $value ) ) {
            return( [] );
        }

        $allowed_lookup = $allowed_media_ids === null ? null : array_fill_keys( $this->normalizeMediaIds( $allowed_media_ids ), true );
        $linked = [];
        foreach ( $value as $media_id => $owner ) {
            if (
                ! is_string( $media_id )
                || ! preg_match( '/^media_[A-Za-z0-9_-]+$/', $media_id )
                || ( is_array( $allowed_lookup ) && ! isset( $allowed_lookup[$media_id] ) )
                || $owner !== 'root'
            ) {
                continue;
            }
            $linked[$media_id] = 'root';
        }

        return( $linked );
    }

    public function validateEditorGalleryLinks( array $payload, array $approved_source, bool $is_superadmin ) : array {
        $plugins = is_array( $payload['plugin_settings'] ?? null ) ? $payload['plugin_settings'] : [];
        if ( ! isset( $plugins['photos'] ) ) {
            return( $payload );
        }
        $settings = is_array( $plugins['photos'] ?? null ) ? $plugins['photos'] : [];
        $selected_ids = $this->normalizeMediaIds( $settings['gallery_media_ids'] ?? [] );
        $raw_links = $settings['gallery_linked_media'] ?? '{}';
        if ( is_string( $raw_links ) && strlen( $raw_links ) > 50000 ) {
            throw new \InvalidArgumentException( 'gallery_linked_media' );
        }
        $decoded = is_string( $raw_links ) ? json_decode( $raw_links, true ) : $raw_links;
        if ( ! is_array( $decoded ) || count( $decoded ) > 500 ) {
            throw new \InvalidArgumentException( 'gallery_linked_media' );
        }
        $linked = $this->normalizeGalleryLinkedMedia( $decoded, $selected_ids );
        if ( count( $linked ) !== count( $decoded ) ) {
            throw new \InvalidArgumentException( 'gallery_linked_media' );
        }

        $source_settings = is_array( $approved_source['plugin_settings']['photos'] ?? null ) ? $approved_source['plugin_settings']['photos'] : [];
        $source_links = $this->normalizeGalleryLinkedMedia( $source_settings['gallery_linked_media'] ?? [], $source_settings['gallery_media_ids'] ?? [] );
        $scope = (string) ( $payload['scope'] ?? '' );
        foreach ( $linked as $media_id => $owner ) {
            if ( $scope !== 'author' || ( ! $is_superadmin && ! isset( $source_links[$media_id] ) ) ) {
                throw new \InvalidArgumentException( 'gallery_linked_media' );
            }
            if ( isset( $source_links[$media_id] ) ) {
                continue;
            }
            $metadata = $this->media_service instanceof TinyMashMediaService
                ? $this->media_service->getAttachmentMetadataByMediaId( $media_id, [ 'root' ] )
                : null;
            if ( ! is_array( $metadata ) || ! $this->metadataLooksLikeImage( $metadata ) ) {
                throw new \InvalidArgumentException( 'gallery_linked_media' );
            }
        }

        $settings['gallery_linked_media'] = $this->encodeGalleryJsonForField( $linked );
        $plugins['photos'] = $settings;
        $payload['plugin_settings'] = $plugins;
        return( $payload );
    }

    protected function encodeGalleryJsonForField( array $value ) : string {
        $encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT );
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
    const linkedField = document.getElementById("tm-editor-photos-gallery-linked-media");
    const galleryEnabledField = document.getElementById("tm-editor-photos-gallery-enabled");
    const gallerySettings = document.getElementById("tm-editor-photos-gallery-settings");
    const galleryRetained = document.getElementById("tm-editor-photos-gallery-retained");
    const slideshowEnabledField = document.getElementById("tm-editor-photos-gallery-slideshow-enabled");
    const slideshowDelayWrap = document.getElementById("tm-editor-photos-gallery-slideshow-delay-wrap");
    const countText = document.getElementById("tm-editor-photos-gallery-count");
    const sortWrap = document.getElementById("tm-editor-photos-gallery-sort");
    const sortKeyField = document.getElementById("tm-editor-photos-gallery-sort-key");
    const sortDirectionField = document.getElementById("tm-editor-photos-gallery-sort-direction");
    const sortApplyButton = document.getElementById("tm-editor-photos-gallery-sort-apply");
    const sortStatus = document.getElementById("tm-editor-photos-gallery-sort-status");
    const progressText = document.getElementById("tm-editor-photos-gallery-progress");
    const emptyMessage = document.getElementById("tm-editor-photos-gallery-empty");
    const scrollHint = document.getElementById("tm-editor-photos-gallery-scroll-hint");
    const orderStatus = document.getElementById("tm-editor-photos-gallery-order-status");
    const recoveryStatus = document.getElementById("tm-editor-photos-recovery-status");
    const uploadButton = document.getElementById("tm-editor-photos-upload-gallery");
    const chooseExistingButton = document.getElementById("tm-editor-photos-choose-existing");
    const convertPreviewButton = document.getElementById("tm-editor-photos-convert-preview");
    const convertPanel = document.getElementById("tm-editor-photos-convert-panel");
    const convertSummary = document.getElementById("tm-editor-photos-convert-summary");
    const convertImages = document.getElementById("tm-editor-photos-convert-images");
    const convertSkipped = document.getElementById("tm-editor-photos-convert-skipped");
    const convertApplyButton = document.getElementById("tm-editor-photos-convert-apply");
    const convertCancelButton = document.getElementById("tm-editor-photos-convert-cancel");
    const editorRoot = document.getElementById("tm-editor-root");
    const markdownField = document.getElementById("tm-editor-markdown");
    const scopeField = document.getElementById("tm-editor-scope");
    const authorField = document.getElementById("tm-editor-author-slug");
    if (!root || !hiddenField || !overridesField || !linkedField) {
        return;
    }
    let linkedMedia = {};
    const canCopyLinked = root.dataset.canCopyLinked === "1";
    const showRecoveryStatus = (message, error = false, success = false) => {
        if (recoveryStatus) {
            recoveryStatus.textContent = message;
            recoveryStatus.classList.toggle("text-danger", error);
            recoveryStatus.classList.toggle("text-success", success);
        }
    };
    try {
        const parsed = JSON.parse(String(linkedField.value || "{}"));
        if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
            linkedMedia = parsed;
        }
    } catch (_) {}
    let draggedOption = null;
    let conversionPreview = null;
    let uploadedCount = 0;
    let savedCount = String(hiddenField.value || "").split(/\s+/).filter(Boolean).length;
    const updateProgress = () => {
        if (progressText) {
            progressText.textContent = "Uploaded this session: " + uploadedCount + " · Saved: " + savedCount;
        }
    };
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
            owner_username: String(record.owner_username || "").trim(),
            url: url,
            thumbnail_url: String(record.thumbnail_url || "").trim(),
            label: String(record.alt_text || record.display_filename || record.original_filename || record.filename || mediaId).trim(),
            filename: String(record.filename || mediaId).trim(),
            original_filename: String(record.original_filename || "").trim(),
            display_filename: String(record.display_filename || record.original_filename || record.filename || mediaId).trim(),
            created_at_utc: String(record.created_at_utc || "").trim(),
            capture_date_local: String(record.capture_date_local || "").trim(),
            alt_text: String(record.alt_text || "").trim(),
            mime: String(record.mime || "").trim(),
            width: Math.max(0, Number.parseInt(record.width || "0", 10) || 0),
            height: Math.max(0, Number.parseInt(record.height || "0", 10) || 0)
        };
    };
    const getSelectedMediaIds = () => Array.from(root.querySelectorAll(".tm-editor-photos-gallery-check:checked"))
        .map((field) => String(field.value || "").trim())
        .filter((value, index, values) => value !== "" && values.indexOf(value) === index);
    const syncConditionalVisibility = (selectedCount) => {
        const galleryEnabled = galleryEnabledField instanceof HTMLInputElement && galleryEnabledField.checked;
        if (gallerySettings) {
            gallerySettings.classList.toggle("d-none", !galleryEnabled);
            gallerySettings.setAttribute("aria-hidden", galleryEnabled ? "false" : "true");
        }
        if (galleryRetained) {
            const showRetained = !galleryEnabled && selectedCount > 0;
            galleryRetained.textContent = selectedCount + (selectedCount === 1 ? " image remains selected" : " images remain selected") + " while the gallery is off.";
            galleryRetained.classList.toggle("d-none", !showRetained);
        }
        if (slideshowDelayWrap) {
            const showDelay = galleryEnabled
                && slideshowEnabledField instanceof HTMLInputElement
                && slideshowEnabledField.checked;
            slideshowDelayWrap.classList.toggle("d-none", !showDelay);
            slideshowDelayWrap.setAttribute("aria-hidden", showDelay ? "false" : "true");
        }
    };
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
        let selectedPosition = 0;
        options.forEach((option, index) => {
            const selected = option.querySelector(".tm-editor-photos-gallery-check:checked") !== null;
            if (selected) {
                selectedPosition += 1;
            }
            const position = option.querySelector("[data-tm-editor-photos-gallery-position]");
            if (position) {
                position.textContent = selected ? selectedPosition + "." : "—";
            }
            option.querySelectorAll("[data-tm-editor-photos-gallery-move]").forEach((button) => {
                const direction = String(button.getAttribute("data-tm-editor-photos-gallery-move") || "");
                button.disabled = (direction === "up" && index === 0) || (direction === "down" && index === options.length - 1);
                button.setAttribute("aria-label", "Move image " + (selected ? selectedPosition : index + 1) + (direction === "up" ? " earlier" : " later"));
            });
        });
        if (scrollHint) {
            scrollHint.classList.toggle("d-none", options.length <= 10);
        }
    };
    const update = () => {
        const selected = getSelectedMediaIds();
        hiddenField.value = selected.join("\n");
        Object.keys(linkedMedia).forEach((mediaId) => {
            if (!selected.includes(mediaId)) {
                delete linkedMedia[mediaId];
            }
        });
        linkedField.value = JSON.stringify(linkedMedia);
        if (countText) {
            countText.textContent = selected.length + (selected.length === 1 ? " image selected" : " images selected");
        }
        if (sortApplyButton instanceof HTMLButtonElement) {
            sortApplyButton.disabled = selected.length < 2;
        }
        if (sortWrap) {
            sortWrap.classList.toggle("d-none", selected.length < 2);
        }
        updateProgress();
        overridesField.value = JSON.stringify(collectOverrides());
        getOptions().forEach((option) => {
            const edited = option.querySelector("[data-tm-editor-photos-gallery-edited]");
            if (edited) {
                const caption = option.querySelector(".tm-editor-photos-gallery-caption");
                const alt = option.querySelector(".tm-editor-photos-gallery-alt");
                edited.textContent = (String(caption?.value || "").trim() || String(alt?.value || "").trim()) ? " · Edited" : "";
            }
        });
        hiddenField.dispatchEvent(new Event("change", { bubbles: true }));
        overridesField.dispatchEvent(new Event("change", { bubbles: true }));
        linkedField.dispatchEvent(new Event("change", { bubbles: true }));
        setEmptyState();
        updateOrderControls();
        syncConditionalVisibility(selected.length);
    };
    const buildOption = (record) => {
        const normalized = normalizeRecord(record);
        if (!normalized) {
            return null;
        }
        const column = document.createElement("div");
        column.className = "border-bottom py-2 min-w-0";
        column.setAttribute("role", "listitem");
        column.setAttribute("data-tm-editor-photos-gallery-option", normalized.media_id);
        column.dataset.sortFilename = normalized.display_filename || normalized.filename;
        column.dataset.sortUpload = normalized.created_at_utc;
        column.dataset.sortCapture = normalized.capture_date_local;

        const wrapper = document.createElement("div");
        wrapper.className = "d-flex flex-column flex-lg-row align-items-lg-center gap-2 tm-editor-photos-gallery-option";

        const identity = document.createElement("div");
        identity.className = "d-flex align-items-center gap-2 flex-grow-1 min-w-0";
        const position = document.createElement("span");
        position.className = "small text-body-secondary text-end flex-shrink-0";
        position.style.width = "2rem";
        position.setAttribute("data-tm-editor-photos-gallery-position", "");
        position.setAttribute("aria-hidden", "true");
        identity.appendChild(position);

        const label = document.createElement("label");
        label.className = "d-flex align-items-center gap-2 flex-grow-1 min-w-0 overflow-hidden";

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
            image.style.width = "3.5rem";
            image.style.height = "3.5rem";
            image.style.minWidth = "3.5rem";
            label.appendChild(image);
        }

        const textWrap = document.createElement("span");
        textWrap.className = "d-flex flex-column gap-1 min-w-0 flex-grow-1 overflow-hidden";

        const title = document.createElement("span");
        title.className = "fw-semibold text-truncate";
        title.textContent = normalized.label || normalized.media_id;
        title.title = normalized.label || normalized.media_id;
        textWrap.appendChild(title);

        const displayFilename = normalized.display_filename || normalized.filename || normalized.media_id;
        if (displayFilename !== (normalized.label || normalized.media_id)) {
            const filename = document.createElement("span");
            filename.className = "small text-body-secondary text-truncate";
            filename.textContent = displayFilename;
            filename.title = displayFilename;
            textWrap.appendChild(filename);
        }

        if (linkedMedia[normalized.media_id] === "root") {
            const source = document.createElement("span");
            source.className = "small text-body-secondary tm-editor-photos-linked-source";
            source.textContent = "Root media · linked";
            textWrap.appendChild(source);
        }

        label.appendChild(textWrap);
        identity.appendChild(label);
        wrapper.appendChild(identity);

        const orderControls = document.createElement("div");
        orderControls.className = "d-flex flex-wrap gap-2 align-items-center ps-0 ps-lg-2";

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
        dragHandle.className = "btn btn-outline-secondary btn-sm d-none d-sm-inline-flex align-items-center justify-content-center tm-editor-photos-gallery-drag-handle";
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

        const replaceButton = document.createElement("button");
        replaceButton.className = "btn btn-outline-secondary btn-sm";
        replaceButton.type = "button";
        replaceButton.setAttribute("data-tm-editor-photos-gallery-replace", "");
        replaceButton.textContent = "Replace image";
        orderControls.appendChild(replaceButton);

        if (canCopyLinked && linkedMedia[normalized.media_id] === "root" && scopeField && scopeField.value === "author") {
            const copyButton = document.createElement("button");
            copyButton.className = "btn btn-outline-secondary btn-sm";
            copyButton.type = "button";
            copyButton.setAttribute("data-tm-editor-photos-gallery-copy", "");
            copyButton.textContent = "Make a copy here";
            orderControls.appendChild(copyButton);
        }

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
        column.appendChild(wrapper);

        const details = document.createElement("details");
        details.className = "small mt-1 ps-0 ps-lg-4";
        const summary = document.createElement("summary");
        summary.textContent = "Caption and alt text";
        const edited = document.createElement("span");
        edited.className = "text-body-secondary";
        edited.setAttribute("data-tm-editor-photos-gallery-edited", "");
        summary.appendChild(edited);
        details.appendChild(summary);

        const fieldsRow = document.createElement("div");
        fieldsRow.className = "row g-2 mt-1";

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

        details.appendChild(fieldsRow);
        column.appendChild(details);
        return column;
    };
    const addRecords = (records, keepLinked = false) => {
        (Array.isArray(records) ? records : []).forEach((record) => {
            const normalized = normalizeRecord(record);
            if (!normalized) {
                return;
            }
            if (keepLinked && normalized.owner_username === "root") {
                linkedMedia[normalized.media_id] = "root";
            }
            const existing = Array.from(root.querySelectorAll("[data-tm-editor-photos-gallery-option]"))
                .find((element) => element.getAttribute("data-tm-editor-photos-gallery-option") === normalized.media_id);
            if (existing) {
                const checkbox = existing.querySelector(".tm-editor-photos-gallery-check");
                if (checkbox) {
                    checkbox.checked = true;
                }
                if (linkedMedia[normalized.media_id] === "root" && !existing.querySelector(".tm-editor-photos-linked-source")) {
                    const source = document.createElement("span");
                    source.className = "small text-body-secondary tm-editor-photos-linked-source";
                    source.textContent = "Root media · linked";
                    existing.querySelector("label > span")?.appendChild(source);
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
    const replaceRecord = (oldMediaId, record, keepOverrides = false) => {
        const oldOption = getOptions().find((option) => option.getAttribute("data-tm-editor-photos-gallery-option") === oldMediaId);
        const normalized = normalizeRecord(record);
        if (!oldOption || !normalized) {
            showRecoveryStatus("This image could not be replaced. Reload the editor and try again.", true);
            return false;
        }
        if (normalized.media_id === oldMediaId) {
            showRecoveryStatus("Choose a different image to replace this one.", true);
            return false;
        }
        if (getOptions().some((option) => option !== oldOption && option.getAttribute("data-tm-editor-photos-gallery-option") === normalized.media_id)) {
            showRecoveryStatus("That image is already in this gallery. Choose another one.", true);
            return false;
        }
        const replacement = buildOption(normalized);
        if (!replacement) {
            showRecoveryStatus("This image could not be replaced. Try another one.", true);
            return false;
        }
        const oldCheckbox = oldOption.querySelector(".tm-editor-photos-gallery-check");
        const newCheckbox = replacement.querySelector(".tm-editor-photos-gallery-check");
        if (newCheckbox instanceof HTMLInputElement) {
            newCheckbox.checked = oldCheckbox instanceof HTMLInputElement && oldCheckbox.checked;
        }
        if (keepOverrides) {
            ["caption", "alt"].forEach((name) => {
                const oldField = oldOption.querySelector(".tm-editor-photos-gallery-" + name);
                const newField = replacement.querySelector(".tm-editor-photos-gallery-" + name);
                if (oldField instanceof HTMLInputElement && newField instanceof HTMLInputElement) {
                    newField.value = oldField.value;
                }
            });
            replacement.querySelector("details")?.setAttribute("open", "");
        }
        oldOption.replaceWith(replacement);
        delete linkedMedia[oldMediaId];
        update();
        replacement.querySelector("[data-tm-editor-photos-gallery-replace]")?.focus({ preventScroll: true });
        showRecoveryStatus(keepOverrides
            ? "A copy is ready in this space. Save this entry to keep the change."
            : "Image replaced. Check its caption and alt text, then save this entry.", false, true);
        return true;
    };
    if (sortApplyButton instanceof HTMLButtonElement && sortKeyField instanceof HTMLSelectElement && sortDirectionField instanceof HTMLSelectElement) {
        sortApplyButton.addEventListener("click", () => {
            const options = getOptions();
            const selected = options.filter((option) => option.querySelector(".tm-editor-photos-gallery-check:checked"));
            if (selected.length < 2) {
                return;
            }
            const key = ["filename", "upload", "capture"].includes(sortKeyField.value) ? sortKeyField.value : "filename";
            const direction = sortDirectionField.value === "desc" ? -1 : 1;
            const sorted = selected.map((option, index) => ({ option, index, value: String(option.dataset["sort" + key[0].toUpperCase() + key.slice(1)] || "").trim() }));
            const missing = sorted.filter((item) => item.value === "").length;
            if (missing === sorted.length) {
                if (sortStatus) {
                    sortStatus.textContent = "No selected images have " + (key === "capture" ? "retained capture dates" : key === "upload" ? "upload times" : "original filenames") + ".";
                }
                return;
            }
            sorted.sort((left, right) => {
                if (!left.value || !right.value) {
                    return left.value ? -1 : right.value ? 1 : left.index - right.index;
                }
                const comparison = key === "filename"
                    ? left.value.localeCompare(right.value, undefined, { numeric: true, sensitivity: "base" })
                    : left.value.localeCompare(right.value);
                return comparison === 0 ? left.index - right.index : comparison * direction;
            });
            sorted.forEach((item) => root.appendChild(item.option));
            options.filter((option) => !selected.includes(option)).forEach((option) => root.appendChild(option));
            update();
            if (sortStatus) {
                sortStatus.textContent = "Sorted " + selected.length + " selected images" + (missing > 0 ? "; " + missing + " without a value remain at the end" : "") + ". Save to keep this order.";
            }
        });
    }
    const conversionSource = () => ({
        content: markdownField instanceof HTMLTextAreaElement ? markdownField.value : "",
        scope: scopeField ? String(scopeField.value || "root") : "root",
        author_slug: authorField ? String(authorField.value || "") : "",
        loaded_entry_id: editorRoot ? String(editorRoot.dataset.loadedEntryId || "") : ""
    });
    const showConversionMessage = (message) => {
        if (convertSummary) {
            convertSummary.textContent = message;
        }
        if (convertPanel) {
            convertPanel.classList.remove("d-none");
        }
    };
    if (convertPreviewButton && convertPanel && convertApplyButton && editorRoot && markdownField) {
        convertPreviewButton.addEventListener("click", async () => {
            conversionPreview = null;
            convertApplyButton.disabled = true;
            convertImages.replaceChildren();
            convertSkipped.replaceChildren();
            const source = conversionSource();
            convertPreviewButton.disabled = true;
            showConversionMessage("Scanning inline images…");
            try {
                const response = await fetch(String(editorRoot.dataset.editorUrl || "") + "/photos/inline-preview", {
                    method: "POST",
                    credentials: "same-origin",
                    headers: { "Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json" },
                    body: new URLSearchParams({
                        ...source,
                        tinymash_csrf: String(editorRoot.dataset.csrfToken || "")
                    })
                });
                const result = await response.json();
                if (!response.ok) {
                    throw new Error(String(result.error || "Inline images could not be scanned."));
                }
                if (JSON.stringify(source) !== JSON.stringify(conversionSource())) {
                    showConversionMessage("Content changed. Preview the conversion again.");
                    return;
                }
                const records = Array.isArray(result.records) ? result.records : [];
                const skipped = Array.isArray(result.skipped) ? result.skipped : [];
                records.forEach((record) => {
                    const item = document.createElement("li");
                    item.className = "mb-1 text-break";
                    const thumbnailUrl = String(record.thumbnail_url || "");
                    if (thumbnailUrl.startsWith("/media/")) {
                        const thumbnail = document.createElement("img");
                        thumbnail.src = thumbnailUrl;
                        thumbnail.alt = "";
                        thumbnail.className = "rounded-2 object-fit-cover me-2 align-middle";
                        thumbnail.style.width = "2.5rem";
                        thumbnail.style.height = "2.5rem";
                        item.appendChild(thumbnail);
                    }
                    const label = document.createElement("span");
                    label.textContent = String(record.display_filename || record.filename || record.media_id || "Image");
                    item.appendChild(label);
                    convertImages.appendChild(item);
                });
                if (skipped.length > 0) {
                    const heading = document.createElement("div");
                    heading.textContent = skipped.length + (skipped.length === 1 ? " image will stay in the post:" : " images will stay in the post:");
                    convertSkipped.appendChild(heading);
                    const list = document.createElement("ul");
                    list.className = "mb-0";
                    skipped.forEach((item) => {
                        const row = document.createElement("li");
                        row.textContent = String(item.reason || "Not converted") + ": " + String(item.url || "Image");
                        list.appendChild(row);
                    });
                    convertSkipped.appendChild(list);
                }
                showConversionMessage(records.length === 0
                    ? "No images in this post can be moved to the gallery automatically."
                    : records.length + (records.length === 1 ? " image will move to the gallery. " : " images will move to the gallery in their current order. ")
                        + (result.removed_count === 1 ? "It will no longer appear" : "They will no longer appear")
                        + " in the post text. Save to keep the change."
                        + (convertImages.scrollHeight > convertImages.clientHeight + 1 ? " Scroll the list to review all images." : ""));
                if (records.length > 0) {
                    conversionPreview = { source: source, result: result };
                    convertApplyButton.disabled = false;
                }
            } catch (error) {
                showConversionMessage(error instanceof Error ? error.message : "Inline images could not be scanned.");
            } finally {
                convertPreviewButton.disabled = false;
            }
        });
        convertApplyButton.addEventListener("click", () => {
            if (!conversionPreview || JSON.stringify(conversionPreview.source) !== JSON.stringify(conversionSource())) {
                conversionPreview = null;
                convertApplyButton.disabled = true;
                convertImages.replaceChildren();
                convertSkipped.replaceChildren();
                showConversionMessage("Content changed. Preview the conversion again.");
                return;
            }
            const records = conversionPreview.result.records;
            const previouslySelected = new Set(getSelectedMediaIds());
            addRecords(records);
            records.forEach((record) => {
                if (previouslySelected.has(String(record.media_id || ""))) {
                    return;
                }
                const option = getOptions().find((element) => element.getAttribute("data-tm-editor-photos-gallery-option") === record.media_id);
                if (option) {
                    root.appendChild(option);
                }
                const altField = option ? option.querySelector(".tm-editor-photos-gallery-alt") : null;
                if (altField instanceof HTMLInputElement && !altField.value) {
                    altField.value = String(record.inline_alt_text || "");
                }
            });
            if (galleryEnabledField instanceof HTMLInputElement) {
                galleryEnabledField.checked = true;
                galleryEnabledField.dispatchEvent(new Event("change", { bubbles: true }));
            }
            markdownField.value = String(conversionPreview.result.content || "");
            markdownField.dispatchEvent(new Event("input", { bubbles: true }));
            update();
            conversionPreview = null;
            convertPanel.classList.add("d-none");
        });
        convertCancelButton.addEventListener("click", () => {
            conversionPreview = null;
            convertPanel.classList.add("d-none");
            convertPreviewButton.focus();
        });
    }
    root.addEventListener("click", (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const replaceButton = target ? target.closest("[data-tm-editor-photos-gallery-replace]") : null;
        if (replaceButton instanceof HTMLButtonElement) {
            const option = replaceButton.closest("[data-tm-editor-photos-gallery-option]");
            const mediaId = option ? String(option.getAttribute("data-tm-editor-photos-gallery-option") || "") : "";
            if (mediaId) {
                document.dispatchEvent(new CustomEvent("tinymash:editor-request-image-picker", {
                    detail: { target: "gallery", replaceMediaId: mediaId }
                }));
            }
            return;
        }
        const copyButton = target ? target.closest("[data-tm-editor-photos-gallery-copy]") : null;
        if (copyButton instanceof HTMLButtonElement) {
            const option = copyButton.closest("[data-tm-editor-photos-gallery-option]");
            const mediaId = option ? String(option.getAttribute("data-tm-editor-photos-gallery-option") || "") : "";
            if (!canCopyLinked || !mediaId || linkedMedia[mediaId] !== "root" || !editorRoot || !scopeField || scopeField.value !== "author") {
                showRecoveryStatus("Select an author space before copying this image.", true);
                return;
            }
            copyButton.disabled = true;
            showRecoveryStatus("Copying image…");
            const authorSlug = authorField ? String(authorField.value || "") : "";
            document.dispatchEvent(new CustomEvent("tinymash:editor-image-operation", { detail: { delta: 1 } }));
            fetch(String(editorRoot.dataset.editorUrl || "") + "/gallery-image-copy", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json" },
                body: new URLSearchParams({
                    tinymash_csrf: String(editorRoot.dataset.csrfToken || ""),
                    media_id: mediaId,
                    scope: "author",
                    author_slug: authorSlug,
                    attachment_session_id: String(editorRoot.dataset.attachmentSessionId || "")
                })
            }).then(async (response) => {
                const result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(String(result.error || "Image could not be copied."));
                }
                if (scopeField.value !== "author" || (authorField ? String(authorField.value || "") : "") !== authorSlug) {
                    showRecoveryStatus("The space changed while copying. Find the copy in Recent in the previous space.", true);
                    return;
                }
                replaceRecord(mediaId, result, true);
            }).catch((error) => {
                showRecoveryStatus(error instanceof Error ? error.message : "Image could not be copied.", true);
            }).finally(() => {
                copyButton.disabled = false;
                document.dispatchEvent(new CustomEvent("tinymash:editor-image-operation", { detail: { delta: -1 } }));
            });
            return;
        }
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
            if (orderStatus) {
                orderStatus.textContent = "Image moved earlier. Save to keep the new order.";
            }
            const movedButton = option.querySelector("[data-tm-editor-photos-gallery-move=\"up\"]");
            if (movedButton instanceof HTMLButtonElement) {
                movedButton.focus({ preventScroll: true });
            }
        } else if (direction === "down" && option.nextElementSibling) {
            root.insertBefore(option.nextElementSibling, option);
            update();
            if (orderStatus) {
                orderStatus.textContent = "Image moved later. Save to keep the new order.";
            }
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
        if (orderStatus) {
            orderStatus.textContent = "Image order changed. Save to keep the new order.";
        }
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
    if (galleryEnabledField) {
        galleryEnabledField.addEventListener("change", () => syncConditionalVisibility(getSelectedMediaIds().length));
    }
    if (slideshowEnabledField) {
        slideshowEnabledField.addEventListener("change", () => syncConditionalVisibility(getSelectedMediaIds().length));
    }
    document.addEventListener("tinymash:editor-images-uploaded", (event) => {
        const detail = event && event.detail && typeof event.detail === "object" ? event.detail : {};
        if (String(detail.target || "") !== "gallery") {
            return;
        }
        const records = Array.isArray(detail.records) ? detail.records : [];
        uploadedCount += records.length;
        addRecords(records);
    });
    document.addEventListener("tinymash:editor-gallery-saved", (event) => {
        const detail = event && event.detail && typeof event.detail === "object" ? event.detail : {};
        savedCount = Array.isArray(detail.mediaIds) ? detail.mediaIds.length : 0;
        updateProgress();
    });
    document.addEventListener("tinymash:editor-gallery-images-selected", (event) => {
        const detail = event && event.detail && typeof event.detail === "object" ? event.detail : {};
        const replaceMediaId = String(detail.replaceMediaId || "").trim();
        if (replaceMediaId) {
            const records = Array.isArray(detail.records) ? detail.records : [];
            if (records.length === 1) {
                replaceRecord(replaceMediaId, records[0]);
            }
            return;
        }
        addRecords(detail.records || [], detail.keepLinked === true);
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
