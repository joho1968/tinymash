<?php
namespace app\classes;

class TinyMashMediaService {

    protected const DISPLAY_DERIVATIVE_MAX_WIDTH = 1600;
    protected const DISPLAY_DERIVATIVE_MAX_HEIGHT = 1600;

    protected string $media_root;
    protected TinyMashConfig $config;
    protected ?TinyMashMediaCapabilityRegistry $capability_registry;
    protected ?TinyMashMediaDerivativeRegistry $derivative_registry;
    protected ?TinyMashMediaAttachmentMetadataStore $metadata_store;

    public function __construct( string $media_root, TinyMashConfig $config, ?TinyMashMediaCapabilityRegistry $capability_registry = null, ?TinyMashMediaDerivativeRegistry $derivative_registry = null, ?TinyMashMediaAttachmentMetadataStore $metadata_store = null ) {
        $this->media_root = rtrim( $media_root, DIRECTORY_SEPARATOR );
        $this->config = $config;
        $this->capability_registry = $capability_registry;
        $this->derivative_registry = $derivative_registry;
        $this->metadata_store = $metadata_store;
    }

    public function getMediaRoot() : string {
        return( $this->media_root );
    }

    public function getRuntimeInfo() : array {
        $drivers = $this->getAvailableDrivers();
        $selected_driver = $this->resolveDriver();
        $image_uploads_mode = $this->config->getContentImagesMode();

        return(
            [
                'drivers' => $drivers,
                'selected_driver' => $selected_driver,
                'image_uploads_mode' => $image_uploads_mode,
                'image_uploads_mode_label' => $this->getImageUploadsModeLabel( $image_uploads_mode ),
                'uploads_enabled' => $image_uploads_mode !== 'disabled',
                'max_width' => $this->config->getContentImageMaxWidth(),
                'max_height' => $this->config->getContentImageMaxHeight(),
                'metadata_policy' => $this->config->getMediaMetadataPolicy(),
                'capability_details' => $this->getCapabilityDetails( $image_uploads_mode, $selected_driver ),
                'derivative_details' => $this->getDerivativeDetails( $selected_driver ),
                'format_details' => $this->getFormatDetails( $selected_driver ),
            ]
        );
    }

    public function getImageUploadsModeLabel( string $mode = '' ) : string {
        $mode = strtolower( trim( $mode !== '' ? $mode : $this->config->getContentImagesMode() ) );

        return(
            match ( $mode ) {
                'admins_only' => 'Admins only',
                'authenticated' => 'All logged-in users',
                default => 'Disabled',
            }
        );
    }

    public function getCapabilityDetails( string $image_uploads_mode = '', string $selected_driver = '' ) : array {
        $image_uploads_mode = strtolower( trim( $image_uploads_mode !== '' ? $image_uploads_mode : $this->config->getContentImagesMode() ) );
        $selected_driver = strtolower( trim( $selected_driver !== '' ? $selected_driver : $this->resolveDriver() ) );
        $uploads_enabled = $image_uploads_mode !== 'disabled';
        $capability_flags = [
            'editor.image_picker' => $uploads_enabled,
            'editor.paste_images' => $uploads_enabled,
            'editor.drag_drop_images' => $uploads_enabled,
            'storage.canonical_file' => true,
            'delivery.media_route' => true,
            'transform.resize_to_limits' => $selected_driver !== 'none',
            'transform.auto_orient' => $selected_driver === 'imagick',
        ];

        if ( $this->capability_registry instanceof TinyMashMediaCapabilityRegistry ) {
            return( $this->capability_registry->resolveCapabilities( $capability_flags ) );
        }

        $capability_details = [];
        foreach ( $capability_flags as $capability_key => $enabled ) {
            $capability_details[] = [
                'key' => $capability_key,
                'label' => ucwords( str_replace( [ '.', '-', '_' ], ' ', $capability_key ) ),
                'description' => '',
                'enabled' => $enabled,
            ];
        }

        return( $capability_details );
    }

    public function getDerivativeDetails( string $selected_driver = '' ) : array {
        $selected_driver = strtolower( trim( $selected_driver !== '' ? $selected_driver : $this->resolveDriver() ) );
        $stored_primary_definition = $this->derivative_registry instanceof TinyMashMediaDerivativeRegistry
            ? $this->derivative_registry->resolveDerivativeDefinition( 'stored_primary' )
            : [
                'key' => 'stored_primary',
                'label' => 'Stored primary image',
                'description' => '',
            ];

        if ( empty( $stored_primary_definition ) ) {
            return( [] );
        }

        $stored_primary_definition['enabled'] = true;
        $stored_primary_definition['max_width'] = $this->config->getContentImageMaxWidth();
        $stored_primary_definition['max_height'] = $this->config->getContentImageMaxHeight();
        $stored_primary_definition['selected_driver'] = $selected_driver;
        $stored_primary_definition['ingest_resize_enabled'] = $selected_driver !== 'none';
        $stored_primary_definition['summary'] = $selected_driver !== 'none'
            ? 'Large uploads are constrained to the configured limits before storage when needed.'
            : 'Uploads are stored as-is because no resize-capable image driver is active.';

        $details = [ $stored_primary_definition ];

        $thumbnail_definition = $this->derivative_registry instanceof TinyMashMediaDerivativeRegistry
            ? $this->derivative_registry->resolveDerivativeDefinition( 'thumbnail' )
            : [
                'key' => 'thumbnail',
                'label' => 'Generated thumbnail',
                'description' => '',
            ];
        if ( ! empty( $thumbnail_definition ) ) {
            $thumbnail_definition['enabled'] = $this->config->generatesThumbnails() && $selected_driver !== 'none';
            $thumbnail_definition['max_width'] = $this->config->getThumbnailMaxWidth();
            $thumbnail_definition['max_height'] = $this->config->getThumbnailMaxHeight();
            $thumbnail_definition['selected_driver'] = $selected_driver;
            $thumbnail_definition['ingest_resize_enabled'] = $thumbnail_definition['enabled'];
            $thumbnail_definition['summary'] = $thumbnail_definition['enabled']
                ? 'One smaller raster derivative is generated during upload or import for faster media browsing in admin pickers.'
                : 'Thumbnail generation is disabled, or no resize-capable image driver is active.';
            $details[] = $thumbnail_definition;
        }

        $display_definition = $this->derivative_registry instanceof TinyMashMediaDerivativeRegistry
            ? $this->derivative_registry->resolveDerivativeDefinition( 'display' )
            : [
                'key' => 'display',
                'label' => 'Display image',
                'description' => '',
            ];
        if ( ! empty( $display_definition ) ) {
            $display_definition['enabled'] = $selected_driver !== 'none';
            $display_definition['max_width'] = self::DISPLAY_DERIVATIVE_MAX_WIDTH;
            $display_definition['max_height'] = self::DISPLAY_DERIVATIVE_MAX_HEIGHT;
            $display_definition['selected_driver'] = $selected_driver;
            $display_definition['ingest_resize_enabled'] = $display_definition['enabled'];
            $display_definition['summary'] = $display_definition['enabled']
                ? 'One web-sized display derivative is generated during upload/import and can be backfilled from the CLI for older media.'
                : 'Display derivative generation requires a resize-capable image driver.';
            $details[] = $display_definition;
        }

        return( $details );
    }

    public function getFormatDetails( string $selected_driver = '' ) : array {
        $selected_driver = strtolower( trim( $selected_driver !== '' ? $selected_driver : $this->resolveDriver() ) );
        $format_definitions = [
            [
                'label' => 'ICO',
                'mime' => 'image/x-icon',
                'extension' => 'ico',
                'notes' => 'Accepted for favicon and site-identity use; stored as-is without ingest resize.',
            ],
            [
                'label' => 'JPEG',
                'mime' => 'image/jpeg',
                'extension' => 'jpg',
                'notes' => 'Well-supported for uploaded photos and flattened image output; transformed JPEGs are written progressively.',
            ],
            [
                'label' => 'PNG',
                'mime' => 'image/png',
                'extension' => 'png',
                'notes' => 'Preserves transparency and is safe for line art and UI-like graphics.',
            ],
            [
                'label' => 'GIF',
                'mime' => 'image/gif',
                'extension' => 'gif',
                'notes' => 'Accepted on upload, but ingest resize is intentionally skipped so stored files stay as-is.',
            ],
            [
                'label' => 'WebP',
                'mime' => 'image/webp',
                'extension' => 'webp',
                'notes' => 'Modern web format with driver-dependent resize support.',
            ],
            [
                'label' => 'AVIF',
                'mime' => 'image/avif',
                'extension' => 'avif',
                'notes' => 'Modern high-efficiency format with driver-dependent resize support.',
            ],
        ];
        $format_details = [];

        foreach ( $format_definitions as $format_definition ) {
            $mime = (string) ( $format_definition['mime'] ?? '' );
            $ingest_resize_supported = $this->supportsResizeForMime( $selected_driver, $mime );
            $format_details[] = [
                'label' => (string) ( $format_definition['label'] ?? strtoupper( $mime ) ),
                'mime' => $mime,
                'extension' => (string) ( $format_definition['extension'] ?? '' ),
                'upload_enabled' => $this->getExtensionForMime( $mime ) !== '',
                'ingest_resize_supported' => $ingest_resize_supported,
                'notes' => (string) ( $format_definition['notes'] ?? '' ),
                'driver_note' => $this->getDriverFormatNote( $selected_driver, $mime, $ingest_resize_supported ),
            ];
        }

        return( $format_details );
    }

    public function getAvailableDrivers() : array {
        return(
            [
                'imagick' => extension_loaded( 'imagick' ),
                'gd' => extension_loaded( 'gd' ),
            ]
        );
    }

    public function resolveDriver() : string {
        $preference = $this->config->getContentImageDriverPreference();
        $drivers = $this->getAvailableDrivers();

        if ( $preference === 'imagick' ) {
            return( ! empty( $drivers['imagick'] ) ? 'imagick' : 'none' );
        }
        if ( $preference === 'gd' ) {
            return( ! empty( $drivers['gd'] ) ? 'gd' : 'none' );
        }
        if ( $preference === 'none' ) {
            return( 'none' );
        }
        if ( ! empty( $drivers['imagick'] ) ) {
            return( 'imagick' );
        }
        if ( ! empty( $drivers['gd'] ) ) {
            return( 'gd' );
        }

        return( 'none' );
    }

    public function storeUploadedImage( array $file, string $owner_username, array $options = [] ) : array {
        if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_file( $file['tmp_name'] ) ) {
            throw new \InvalidArgumentException( 'content_image_file' );
        }
        if ( ! isset( $file['error'] ) || (int) $file['error'] !== UPLOAD_ERR_OK ) {
            throw new \InvalidArgumentException( 'content_image_upload' );
        }

        $owner_username = $this->normalizeOwnerUsername( $owner_username );
        if ( $owner_username === '' ) {
            throw new \InvalidArgumentException( 'content_image_owner' );
        }

        $source_bytes = is_file( $file['tmp_name'] ) ? (int) filesize( $file['tmp_name'] ) : 0;
        if ( $source_bytes > $this->config->getContentImageMaxUploadBytes() ) {
            throw new \InvalidArgumentException( 'content_image_too_large' );
        }

        $original_filename = trim( (string) ( $file['name'] ?? '' ) );
        $detected_info = $this->detectLocalImageInfo( $file['tmp_name'], $original_filename );
        $mime = (string) ( $detected_info['mime'] ?? '' );
        $width = (int) ( $detected_info['width'] ?? 0 );
        $height = (int) ( $detected_info['height'] ?? 0 );
        $extension = $this->getExtensionForMime( $mime );
        $allowed_mimes = $this->normalizeAllowedUploadMimes( $options['allowed_mimes'] ?? null );
        if ( $extension === '' || ! $this->isAllowedUploadMime( $mime, $allowed_mimes ) ) {
            throw new \InvalidArgumentException( 'content_image_type' );
        }
        $this->assertSupportedImageSignature( $file['tmp_name'], $mime );
        $image_metadata_payload = $this->buildImageMetadataPayload( $file['tmp_name'], $mime );

        $filename_base = $this->slugifyFilename( pathinfo( $original_filename !== '' ? $original_filename : 'image', PATHINFO_FILENAME ) );
        if ( $filename_base === '' ) {
            $filename_base = 'image';
        }

        [ 'year' => $year, 'month' => $month, 'target_directory' => $target_directory ] = $this->prepareTargetDirectory( $owner_username );
        if ( ! is_dir( $target_directory ) && ! @ mkdir( $target_directory, 0775, true ) && ! is_dir( $target_directory ) ) {
            throw new \RuntimeException( 'Unable to create target image directory.' );
        }

        $target_filename = $filename_base . '_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) . '.' . $extension;
        $target_path = $target_directory . DIRECTORY_SEPARATOR . $target_filename;

        $max_width = $this->config->getContentImageMaxWidth();
        $max_height = $this->config->getContentImageMaxHeight();
        $driver = $this->resolveDriver();
        $resized = false;

        if ( $width > 0 && $height > 0 && $this->shouldResizeImage( $mime, $width, $height, $max_width, $max_height, $driver ) ) {
            $resized = $this->resizeUploadedImage( $file['tmp_name'], $target_path, $mime, $width, $height, $max_width, $max_height, $driver );
        }

        if ( ! $resized ) {
            if ( ! empty( $options['sanitize_svg'] ) && $mime === 'image/svg+xml' ) {
                if ( ! $this->sanitizeSvgIntoPath( $file['tmp_name'], $target_path ) ) {
                    throw new \InvalidArgumentException( 'content_image_type' );
                }
            } elseif ( ! @ move_uploaded_file( $file['tmp_name'], $target_path ) ) {
                if ( ! @ copy( $file['tmp_name'], $target_path ) ) {
                    throw new \RuntimeException( 'Unable to store uploaded image.' );
                }
            }
        }

        $stored_info = $this->detectLocalImageInfo( $target_path, $target_filename );
        $stored_mime = trim( (string) ( $stored_info['mime'] ?? $mime ) );
        try {
            $this->assertStoredImageMatchesPolicy( $target_path, $stored_mime, $allowed_mimes, $extension );
        } catch ( \InvalidArgumentException $e ) {
            if ( is_file( $target_path ) ) {
                @ unlink( $target_path );
            }
            throw $e;
        }
        $embedded_metadata_strip_result = $this->stripEmbeddedImageMetadataIfNeeded( $target_path, $stored_mime, $driver, ! empty( $image_metadata_payload['strip_embedded_metadata'] ) );
        if ( ! empty( $embedded_metadata_strip_result['stripped'] ) ) {
            $stored_info = $this->detectLocalImageInfo( $target_path, $target_filename );
            $stored_mime = trim( (string) ( $stored_info['mime'] ?? $stored_mime ) );
            $this->assertStoredImageMatchesPolicy( $target_path, $stored_mime, $allowed_mimes, $extension );
        }
        $alt_text = $this->buildAltText( $original_filename, $filename_base );
        $url = '/media/' . rawurlencode( $owner_username ) . '/' . $year . '/' . $month . '/' . rawurlencode( $target_filename );
        $markdown = '![' . $alt_text . '](' . $url . ')';
        $stored_width = (int) ( $stored_info['width'] ?? $width );
        $stored_height = (int) ( $stored_info['height'] ?? $height );
        $stored_bytes = is_file( $target_path ) ? (int) filesize( $target_path ) : 0;
        $thumbnail = $this->buildThumbnailDerivative(
            $target_path,
            $owner_username,
            $year,
            $month,
            $target_filename,
            $stored_mime,
            $stored_width,
            $stored_height,
            $driver
        );
        $display = $this->buildDisplayDerivative(
            $target_path,
            $owner_username,
            $year,
            $month,
            $target_filename,
            $stored_mime,
            $stored_width,
            $stored_height,
            $driver
        );
        $metadata = $this->storeAttachmentMetadata(
            array_merge(
                [
                'owner_username' => $owner_username,
                'year' => $year,
                'month' => $month,
                'filename' => $target_filename,
                'path' => $target_path,
                'url' => $url,
                'original_filename' => $original_filename,
                'alt_text' => $alt_text,
                'markdown' => $markdown,
                'mime' => $stored_mime,
                'width' => $stored_width,
                'height' => $stored_height,
                'bytes' => $stored_bytes,
                'driver' => $driver,
                'derivative_key' => 'stored_primary',
                'resized_on_ingest' => $resized,
                'thumbnail' => $thumbnail,
                'display' => $display,
                'source' => 'upload',
                'created_at_utc' => gmdate( 'Y-m-d H:i:s' ),
                ],
                $image_metadata_payload['metadata'] ?? [],
                [
                    'embedded_metadata_stripped' => ! empty( $embedded_metadata_strip_result['stripped'] ),
                    'embedded_metadata_strip_note' => (string) ( $embedded_metadata_strip_result['note'] ?? '' ),
                ]
            )
        );

        return( $this->buildStoredMediaResult( $metadata, $original_filename, $markdown ) );
    }

    public function storeImportedFile( string $source_path, string $owner_username, array $options = [] ) : array {
        if ( ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
            throw new \InvalidArgumentException( 'content_image_file' );
        }

        $owner_username = $this->normalizeOwnerUsername( $owner_username );
        if ( $owner_username === '' ) {
            throw new \InvalidArgumentException( 'content_image_owner' );
        }

        $source_bytes = (int) filesize( $source_path );
        if ( $source_bytes > $this->config->getContentImageMaxUploadBytes() ) {
            throw new \InvalidArgumentException( 'content_image_too_large' );
        }

        $original_filename = trim( (string) ( $options['original_filename'] ?? basename( $source_path ) ) );
        $detected_info = $this->detectLocalImageInfo( $source_path, $original_filename );
        $mime = (string) ( $detected_info['mime'] ?? '' );
        $width = (int) ( $detected_info['width'] ?? 0 );
        $height = (int) ( $detected_info['height'] ?? 0 );
        $extension = $this->getExtensionForMime( $mime );
        $allowed_mimes = $this->normalizeAllowedUploadMimes( $options['allowed_mimes'] ?? null );
        if ( $extension === '' || ! $this->isAllowedUploadMime( $mime, $allowed_mimes ) ) {
            throw new \InvalidArgumentException( 'content_image_type' );
        }
        $this->assertSupportedImageSignature( $source_path, $mime );
        $image_metadata_payload = $this->buildImageMetadataPayload( $source_path, $mime );

        $filename_base = $this->slugifyFilename( pathinfo( $original_filename !== '' ? $original_filename : basename( $source_path ), PATHINFO_FILENAME ) );
        if ( $filename_base === '' ) {
            $filename_base = 'image';
        }

        [ 'year' => $year, 'month' => $month, 'target_directory' => $target_directory ] = $this->prepareTargetDirectory( $owner_username );
        if ( ! is_dir( $target_directory ) && ! @ mkdir( $target_directory, 0775, true ) && ! is_dir( $target_directory ) ) {
            throw new \RuntimeException( 'Unable to create target image directory.' );
        }

        $target_filename = $filename_base . '_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) . '.' . $extension;
        $target_path = $target_directory . DIRECTORY_SEPARATOR . $target_filename;
        $driver = $this->resolveDriver();
        $max_width = $this->config->getContentImageMaxWidth();
        $max_height = $this->config->getContentImageMaxHeight();
        $resized = false;

        if ( $width > 0 && $height > 0 && $this->shouldResizeImage( $mime, $width, $height, $max_width, $max_height, $driver ) ) {
            $resized = $this->resizeUploadedImage( $source_path, $target_path, $mime, $width, $height, $max_width, $max_height, $driver );
        }

        if ( ! $resized ) {
            if ( ! empty( $options['sanitize_svg'] ) && $mime === 'image/svg+xml' ) {
                if ( ! $this->sanitizeSvgIntoPath( $source_path, $target_path ) ) {
                    throw new \InvalidArgumentException( 'content_image_type' );
                }
            } elseif ( ! @ copy( $source_path, $target_path ) ) {
                throw new \RuntimeException( 'Unable to store imported image.' );
            }
        }

        $stored_info = $this->detectLocalImageInfo( $target_path, $target_filename );
        $stored_mime = trim( (string) ( $stored_info['mime'] ?? $mime ) );
        try {
            $this->assertStoredImageMatchesPolicy( $target_path, $stored_mime, $allowed_mimes, $extension );
        } catch ( \InvalidArgumentException $e ) {
            if ( is_file( $target_path ) ) {
                @ unlink( $target_path );
            }
            throw $e;
        }
        $embedded_metadata_strip_result = $this->stripEmbeddedImageMetadataIfNeeded( $target_path, $stored_mime, $driver, ! empty( $image_metadata_payload['strip_embedded_metadata'] ) );
        if ( ! empty( $embedded_metadata_strip_result['stripped'] ) ) {
            $stored_info = $this->detectLocalImageInfo( $target_path, $target_filename );
            $stored_mime = trim( (string) ( $stored_info['mime'] ?? $stored_mime ) );
            $this->assertStoredImageMatchesPolicy( $target_path, $stored_mime, $allowed_mimes, $extension );
        }
        $alt_text = function_exists( 'mb_trim' )
            ? mb_trim( (string) ( $options['alt_text'] ?? '' ) )
            : trim( (string) ( $options['alt_text'] ?? '' ) );
        if ( $alt_text === '' ) {
            $alt_text = $this->buildAltText( $original_filename, $filename_base );
        }

        $url = '/media/' . rawurlencode( $owner_username ) . '/' . $year . '/' . $month . '/' . rawurlencode( $target_filename );
        $markdown = '![' . $alt_text . '](' . $url . ')';
        $stored_width = (int) ( $stored_info['width'] ?? $width );
        $stored_height = (int) ( $stored_info['height'] ?? $height );
        $stored_bytes = is_file( $target_path ) ? (int) filesize( $target_path ) : 0;
        $thumbnail = $this->buildThumbnailDerivative(
            $target_path,
            $owner_username,
            $year,
            $month,
            $target_filename,
            $stored_mime,
            $stored_width,
            $stored_height,
            $driver
        );
        $display = $this->buildDisplayDerivative(
            $target_path,
            $owner_username,
            $year,
            $month,
            $target_filename,
            $stored_mime,
            $stored_width,
            $stored_height,
            $driver
        );
        $metadata = $this->storeAttachmentMetadata(
            array_merge(
                [
                'owner_username' => $owner_username,
                'year' => $year,
                'month' => $month,
                'filename' => $target_filename,
                'path' => $target_path,
                'url' => $url,
                'original_filename' => $original_filename,
                'alt_text' => $alt_text,
                'markdown' => $markdown,
                'mime' => $stored_mime,
                'width' => $stored_width,
                'height' => $stored_height,
                'bytes' => $stored_bytes,
                'driver' => $driver,
                'derivative_key' => 'stored_primary',
                'resized_on_ingest' => $resized,
                'thumbnail' => $thumbnail,
                'display' => $display,
                'source' => 'import',
                'importer_key' => trim( (string) ( $options['importer_key'] ?? '' ) ),
                'source_key' => trim( (string) ( $options['source_key'] ?? '' ) ),
                'source_id' => trim( (string) ( $options['source_id'] ?? '' ) ),
                'source_url' => trim( (string) ( $options['source_url'] ?? '' ) ),
                'created_at_utc' => gmdate( 'Y-m-d H:i:s' ),
                ],
                $image_metadata_payload['metadata'] ?? [],
                [
                    'embedded_metadata_stripped' => ! empty( $embedded_metadata_strip_result['stripped'] ),
                    'embedded_metadata_strip_note' => (string) ( $embedded_metadata_strip_result['note'] ?? '' ),
                ]
            )
        );

        return( $this->buildStoredMediaResult( $metadata, $original_filename, $markdown ) );
    }

    public function resolveMediaPath( string $owner_username, string $year, string $month, string $filename ) : ?array {
        $owner_username = $this->normalizeOwnerUsername( $owner_username );
        $year = preg_replace( '/[^0-9]/', '', $year ) ?? '';
        $month = preg_replace( '/[^0-9]/', '', $month ) ?? '';
        $filename = basename( (string) $filename );

        if ( $owner_username === '' || $year === '' || $month === '' || $filename === '' ) {
            return( null );
        }

        $path = $this->media_root . DIRECTORY_SEPARATOR . $owner_username . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . $filename;
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return( null );
        }

        $metadata = $this->getAttachmentMetadata( $owner_username, $year, $month, $filename );
        $mime = trim( (string) ( $metadata['mime'] ?? '' ) );
        if ( $mime === '' ) {
            $detected_info = $this->detectLocalImageInfo( $path, $filename );
            $mime = trim( (string) ( $detected_info['mime'] ?? '' ) );
        }
        if ( $mime === '' ) {
            $detected_mime = mime_content_type( $path );
            $mime = is_string( $detected_mime ) && $detected_mime !== '' ? $detected_mime : 'application/octet-stream';
        }

        return(
            [
                'path' => $path,
                'mime' => $mime,
                'owner_username' => $owner_username,
                'filename' => $filename,
                'metadata' => $metadata,
            ]
        );
    }

    public function getAttachmentMetadata( string $owner_username, string $year, string $month, string $filename ) : ?array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( null );
        }

        return( $this->metadata_store->getMetadata( $owner_username, $year, $month, $filename ) );
    }

    public function getAttachmentMetadataByMediaId( string $media_id, array $owner_usernames = [] ) : ?array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( null );
        }

        return( $this->metadata_store->getMetadataByMediaId( $media_id, $owner_usernames ) );
    }

    public function ensureAttachmentThumbnailByMediaId( string $media_id, array $owner_usernames = [] ) : ?array {
        $metadata = $this->getAttachmentMetadataByMediaId( $media_id, $owner_usernames );
        if ( ! is_array( $metadata ) ) {
            return( null );
        }

        return( $this->ensureAttachmentThumbnail( $metadata ) );
    }

    public function ensureAttachmentDisplayByMediaId( string $media_id, array $owner_usernames = [] ) : ?array {
        $metadata = $this->getAttachmentMetadataByMediaId( $media_id, $owner_usernames );
        if ( ! is_array( $metadata ) ) {
            return( null );
        }

        return( $this->ensureAttachmentDisplay( $metadata ) );
    }

    public function getAttachmentMetadataByUrl( string $url, array $owner_usernames = [] ) : ?array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( null );
        }

        return( $this->metadata_store->getMetadataByUrl( $url, $owner_usernames ) );
    }

    public function listRecentAttachments( array $owner_usernames = [], int $limit = 50 ) : array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( [] );
        }

        return( $this->metadata_store->listMetadata( $owner_usernames, $limit ) );
    }

    public function listAttachments( array $owner_usernames = [], int $limit = 500 ) : array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( [] );
        }

        return( $this->metadata_store->listMetadata( $owner_usernames, $limit ) );
    }

    public function listAttachmentsForContent( array $owner_usernames = [], string $entry_id = '', string $draft_id = '', string $attachment_session_id = '', int $limit = 50 ) : array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( [] );
        }

        return( $this->metadata_store->listMetadataForContent( $owner_usernames, $entry_id, $draft_id, $attachment_session_id, $limit ) );
    }

    public function pruneOrphanedThumbnails() : array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return(
                [
                    'removed_files' => 0,
                    'cleared_records' => 0,
                ]
            );
        }

        $removed_files = 0;
        $cleared_records = 0;
        foreach ( $this->metadata_store->listMetadata( [], PHP_INT_MAX ) as $metadata ) {
            if ( ! is_array( $metadata ) || (string) ( $metadata['derivative_key'] ?? 'stored_primary' ) !== 'stored_primary' ) {
                continue;
            }

            $thumbnail = is_array( $metadata['thumbnail'] ?? null ) ? $metadata['thumbnail'] : [];
            if ( empty( $thumbnail ) ) {
                continue;
            }

            $source_missing = ! is_file( (string) ( $metadata['path'] ?? '' ) );
            $thumbnail_path = trim( (string) ( $thumbnail['path'] ?? '' ) );
            $thumbnail_missing = $thumbnail_path === '' || ! is_file( $thumbnail_path );
            if ( ! $source_missing && ! $thumbnail_missing ) {
                continue;
            }

            if ( $thumbnail_path !== '' && is_file( $thumbnail_path ) && @ unlink( $thumbnail_path ) ) {
                $removed_files++;
            }

            $metadata['thumbnail'] = [];
            $this->storeAttachmentMetadata( $metadata );
            $cleared_records++;
        }

        return(
            [
                'removed_files' => $removed_files,
                'cleared_records' => $cleared_records,
            ]
        );
    }

    public function backfillMissingThumbnails( int $limit = PHP_INT_MAX ) : array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return(
                [
                    'generated' => 0,
                    'checked' => 0,
                ]
            );
        }

        $generated = 0;
        $checked = 0;
        foreach ( $this->metadata_store->listMetadata( [], PHP_INT_MAX ) as $metadata ) {
            if ( ! is_array( $metadata ) || (string) ( $metadata['derivative_key'] ?? 'stored_primary' ) !== 'stored_primary' ) {
                continue;
            }

            $thumbnail = is_array( $metadata['thumbnail'] ?? null ) ? $metadata['thumbnail'] : [];
            if ( ! empty( $thumbnail['url'] ) ) {
                continue;
            }

            $checked++;
            $updated_metadata = $this->ensureAttachmentThumbnail( $metadata );
            $updated_thumbnail = is_array( $updated_metadata['thumbnail'] ?? null ) ? $updated_metadata['thumbnail'] : [];
            if ( ! empty( $updated_thumbnail['url'] ) ) {
                $generated++;
            }

            if ( $checked >= max( 1, $limit ) ) {
                break;
            }
        }

        return(
            [
                'generated' => $generated,
                'checked' => $checked,
            ]
        );
    }

    public function backfillMissingDisplayDerivatives( int $limit = PHP_INT_MAX, array $owner_usernames = [], bool $dry_run = false ) : array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return(
                [
                    'generated' => 0,
                    'would_generate' => 0,
                    'checked' => 0,
                    'skipped' => 0,
                    'dry_run' => $dry_run,
                ]
            );
        }

        $generated = 0;
        $would_generate = 0;
        $checked = 0;
        $skipped = 0;
        foreach ( $this->metadata_store->listMetadata( $owner_usernames, PHP_INT_MAX ) as $metadata ) {
            if ( ! is_array( $metadata ) || (string) ( $metadata['derivative_key'] ?? 'stored_primary' ) !== 'stored_primary' ) {
                continue;
            }

            if ( ! $this->metadataNeedsDisplayDerivative( $metadata ) ) {
                $skipped++;
                continue;
            }

            $checked++;
            if ( $dry_run ) {
                $would_generate++;
            } else {
                $updated_metadata = $this->ensureAttachmentDisplay( $metadata );
                $updated_display = is_array( $updated_metadata['display'] ?? null ) ? $updated_metadata['display'] : [];
                if ( ! empty( $updated_display['url'] ) && is_file( (string) ( $updated_display['path'] ?? '' ) ) ) {
                    $generated++;
                }
            }

            if ( $checked >= max( 1, $limit ) ) {
                break;
            }
        }

        return(
            [
                'generated' => $generated,
                'would_generate' => $would_generate,
                'checked' => $checked,
                'skipped' => $skipped,
                'dry_run' => $dry_run,
            ]
        );
    }

    public function assignAttachmentToContent( string $media_id, array $owner_usernames = [], string $entry_id = '', string $draft_id = '', string $attachment_session_id = '' ) : ?array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( null );
        }

        return( $this->metadata_store->assignMetadataToContent( $media_id, $owner_usernames, $entry_id, $draft_id, $attachment_session_id ) );
    }

    public function deleteAttachmentByMediaId( string $media_id, array $owner_usernames = [] ) : array {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            throw new \RuntimeException( 'Media metadata storage is not available.' );
        }

        $metadata = $this->metadata_store->getMetadataByMediaId( trim( $media_id ), $owner_usernames );
        if ( ! is_array( $metadata ) ) {
            return( [ 'deleted' => false, 'reason' => 'not_found', 'removed_files' => 0 ] );
        }

        $asset_filenames = [ (string) ( $metadata['filename'] ?? '' ) ];
        foreach ( [ 'thumbnail', 'display' ] as $derivative_key ) {
            $derivative = is_array( $metadata[$derivative_key] ?? null ) ? $metadata[$derivative_key] : [];
            if ( ! empty( $derivative['filename'] ) ) {
                $asset_filenames[] = (string) $derivative['filename'];
            }
        }

        $asset_paths = [];
        foreach ( array_unique( $asset_filenames ) as $asset_filename ) {
            $asset_path = $this->buildStoredAssetPathFromMetadata( $metadata, $asset_filename );
            if ( $asset_path !== '' ) {
                $asset_paths[] = $asset_path;
            }
        }
        if ( empty( $asset_paths ) ) {
            throw new \RuntimeException( 'Media asset paths could not be resolved safely.' );
        }

        $removed_files = 0;
        foreach ( $asset_paths as $asset_path ) {
            if ( is_file( $asset_path ) ) {
                if ( ! @ unlink( $asset_path ) ) {
                    throw new \RuntimeException( 'Unable to delete stored media file.' );
                }
                $removed_files++;
            }
        }

        $this->metadata_store->deleteMetadata( $metadata );
        return(
            [
                'deleted' => true,
                'reason' => '',
                'removed_files' => $removed_files,
                'media_id' => (string) ( $metadata['media_id'] ?? '' ),
                'url' => (string) ( $metadata['url'] ?? '' ),
            ]
        );
    }

    public function adoptSessionAttachmentsToDraft( array $owner_usernames, string $attachment_session_id, string $draft_id, string $entry_id = '' ) : int {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( 0 );
        }

        return( $this->metadata_store->adoptSessionMetadataToDraft( $owner_usernames, $attachment_session_id, $draft_id, $entry_id ) );
    }

    public function adoptAttachmentsToEntry( array $owner_usernames, string $entry_id, string $source_entry_id = '', string $source_draft_id = '', string $attachment_session_id = '' ) : int {
        if ( ! $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( 0 );
        }

        return( $this->metadata_store->adoptMetadataToEntry( $owner_usernames, $entry_id, $source_entry_id, $source_draft_id, $attachment_session_id ) );
    }

    protected function storeAttachmentMetadata( array $metadata ) : array {
        if ( $this->metadata_store instanceof TinyMashMediaAttachmentMetadataStore ) {
            return( $this->metadata_store->storeMetadata( $metadata ) );
        }

        return( $metadata );
    }

    protected function buildStoredAssetPathFromMetadata( array $metadata, string $filename ) : string {
        $owner_username = $this->normalizeOwnerUsername( (string) ( $metadata['owner_username'] ?? '' ) );
        $year = trim( (string) ( $metadata['year'] ?? '' ) );
        $month = trim( (string) ( $metadata['month'] ?? '' ) );
        $filename = trim( $filename );
        if (
            $owner_username === ''
            || preg_match( '/^\d{4}$/', $year ) !== 1
            || preg_match( '/^\d{2}$/', $month ) !== 1
            || $filename === ''
            || basename( $filename ) !== $filename
            || str_contains( $filename, '\\' )
            || in_array( $filename, [ '.', '..' ], true )
        ) {
            return( '' );
        }

        return(
            $this->media_root
            . DIRECTORY_SEPARATOR . $owner_username
            . DIRECTORY_SEPARATOR . $year
            . DIRECTORY_SEPARATOR . $month
            . DIRECTORY_SEPARATOR . $filename
        );
    }

    protected function ensureAttachmentThumbnail( array $metadata ) : array {
        $thumbnail = is_array( $metadata['thumbnail'] ?? null ) ? $metadata['thumbnail'] : [];
        if ( ! empty( $thumbnail['url'] ) ) {
            return( $metadata );
        }
        if ( (string) ( $metadata['derivative_key'] ?? 'stored_primary' ) !== 'stored_primary' ) {
            return( $metadata );
        }

        $source_path = trim( (string) ( $metadata['path'] ?? '' ) );
        $owner_username = $this->normalizeOwnerUsername( (string) ( $metadata['owner_username'] ?? '' ) );
        $year = trim( (string) ( $metadata['year'] ?? '' ) );
        $month = trim( (string) ( $metadata['month'] ?? '' ) );
        $filename = trim( (string) ( $metadata['filename'] ?? '' ) );
        $mime = trim( (string) ( $metadata['mime'] ?? '' ) );
        $width = max( 0, (int) ( $metadata['width'] ?? 0 ) );
        $height = max( 0, (int) ( $metadata['height'] ?? 0 ) );
        $driver = strtolower( trim( (string) ( $metadata['driver'] ?? '' ) ) );

        if ( $source_path === '' || ! is_file( $source_path ) || $owner_username === '' || $year === '' || $month === '' || $filename === '' || $mime === '' ) {
            return( $metadata );
        }

        if ( $driver === '' || $driver === 'none' || ! in_array( $driver, [ 'imagick', 'gd' ], true ) ) {
            $driver = $this->resolveDriver();
        }

        $thumbnail = $this->buildThumbnailDerivative(
            $source_path,
            $owner_username,
            $year,
            $month,
            $filename,
            $mime,
            $width,
            $height,
            $driver
        );

        if ( empty( $thumbnail['url'] ) ) {
            return( $metadata );
        }

        $metadata['thumbnail'] = $thumbnail;
        return( $this->storeAttachmentMetadata( $metadata ) );
    }

    protected function ensureAttachmentDisplay( array $metadata ) : array {
        $display = is_array( $metadata['display'] ?? null ) ? $metadata['display'] : [];
        if ( ! empty( $display['url'] ) && is_file( (string) ( $display['path'] ?? '' ) ) ) {
            return( $metadata );
        }
        if ( (string) ( $metadata['derivative_key'] ?? 'stored_primary' ) !== 'stored_primary' ) {
            return( $metadata );
        }

        $source_path = (string) ( $metadata['path'] ?? '' );
        $owner_username = (string) ( $metadata['owner_username'] ?? '' );
        $year = trim( (string) ( $metadata['year'] ?? '' ) );
        $month = trim( (string) ( $metadata['month'] ?? '' ) );
        $filename = trim( (string) ( $metadata['filename'] ?? '' ) );
        $mime = trim( (string) ( $metadata['mime'] ?? '' ) );
        $width = max( 0, (int) ( $metadata['width'] ?? 0 ) );
        $height = max( 0, (int) ( $metadata['height'] ?? 0 ) );
        $driver = strtolower( trim( (string) ( $metadata['driver'] ?? '' ) ) );

        if ( $source_path === '' || ! is_file( $source_path ) || $owner_username === '' || $year === '' || $month === '' || $filename === '' || $mime === '' ) {
            return( $metadata );
        }

        if ( $driver === '' || $driver === 'none' || ! in_array( $driver, [ 'imagick', 'gd' ], true ) ) {
            $driver = $this->resolveDriver();
        }

        $display = $this->buildDisplayDerivative(
            $source_path,
            $owner_username,
            $year,
            $month,
            $filename,
            $mime,
            $width,
            $height,
            $driver
        );

        if ( empty( $display['url'] ) ) {
            return( $metadata );
        }

        $metadata['display'] = $display;
        return( $this->storeAttachmentMetadata( $metadata ) );
    }

    protected function metadataNeedsDisplayDerivative( array $metadata ) : bool {
        if ( (string) ( $metadata['derivative_key'] ?? 'stored_primary' ) !== 'stored_primary' ) {
            return( false );
        }
        $display = is_array( $metadata['display'] ?? null ) ? $metadata['display'] : [];
        if ( ! empty( $display['url'] ) && is_file( (string) ( $display['path'] ?? '' ) ) ) {
            return( false );
        }

        $mime = strtolower( trim( (string) ( $metadata['mime'] ?? '' ) ) );
        $source_path = trim( (string) ( $metadata['path'] ?? '' ) );
        if ( $source_path === '' || ! is_file( $source_path ) ) {
            return( false );
        }

        $driver = strtolower( trim( (string) ( $metadata['driver'] ?? '' ) ) );
        if ( $driver === '' || $driver === 'none' || ! in_array( $driver, [ 'imagick', 'gd' ], true ) ) {
            $driver = $this->resolveDriver();
        }
        if ( $driver === 'none' || ! $this->supportsResizeForMime( $driver, $mime ) ) {
            return( false );
        }

        $width = max( 0, (int) ( $metadata['width'] ?? 0 ) );
        $height = max( 0, (int) ( $metadata['height'] ?? 0 ) );
        return( $width > self::DISPLAY_DERIVATIVE_MAX_WIDTH || $height > self::DISPLAY_DERIVATIVE_MAX_HEIGHT );
    }

    protected function buildStoredMediaResult( array $metadata, string $original_filename, string $markdown ) : array {
        $thumbnail = is_array( $metadata['thumbnail'] ?? null ) ? $metadata['thumbnail'] : [];
        $display = is_array( $metadata['display'] ?? null ) ? $metadata['display'] : [];

        return(
            [
                'media_id' => (string) ( $metadata['media_id'] ?? '' ),
                'owner_username' => (string) ( $metadata['owner_username'] ?? '' ),
                'filename' => (string) ( $metadata['filename'] ?? '' ),
                'original_filename' => $original_filename,
                'alt_text' => (string) ( $metadata['alt_text'] ?? '' ),
                'mime' => (string) ( $metadata['mime'] ?? '' ),
                'width' => max( 0, (int) ( $metadata['width'] ?? 0 ) ),
                'height' => max( 0, (int) ( $metadata['height'] ?? 0 ) ),
                'bytes' => max( 0, (int) ( $metadata['bytes'] ?? 0 ) ),
                'path' => (string) ( $metadata['path'] ?? '' ),
                'url' => (string) ( $metadata['url'] ?? '' ),
                'markdown' => $markdown,
                'thumbnail' => $thumbnail,
                'thumbnail_url' => trim( (string) ( $thumbnail['url'] ?? '' ) ),
                'display' => $display,
                'display_url' => trim( (string) ( $display['url'] ?? '' ) ),
                'metadata' => $metadata,
            ]
        );
    }

    protected function buildThumbnailDerivative( string $source_path, string $owner_username, string $year, string $month, string $filename, string $mime, int $width, int $height, string $driver ) : array {
        if ( ! $this->config->generatesThumbnails() || $driver === 'none' ) {
            return( [] );
        }
        if ( $width < 1 || $height < 1 || ! $this->supportsResizeForMime( $driver, $mime ) ) {
            return( [] );
        }

        $max_width = $this->config->getThumbnailMaxWidth();
        $max_height = $this->config->getThumbnailMaxHeight();
        if ( $width <= $max_width && $height <= $max_height ) {
            return( [] );
        }

        $extension = pathinfo( $filename, PATHINFO_EXTENSION );
        $base_filename = pathinfo( $filename, PATHINFO_FILENAME );
        $thumbnail_filename = $base_filename . '__thumb.' . $extension;
        $thumbnail_path = dirname( $source_path ) . DIRECTORY_SEPARATOR . $thumbnail_filename;

        if ( ! $this->resizeUploadedImage( $source_path, $thumbnail_path, $mime, $width, $height, $max_width, $max_height, $driver ) ) {
            return( [] );
        }

        $thumbnail_info = $this->detectLocalImageInfo( $thumbnail_path, $thumbnail_filename );
        return(
            [
                'filename' => $thumbnail_filename,
                'path' => $thumbnail_path,
                'url' => '/media/' . rawurlencode( $owner_username ) . '/' . $year . '/' . $month . '/' . rawurlencode( $thumbnail_filename ),
                'mime' => $mime,
                'width' => (int) ( $thumbnail_info['width'] ?? 0 ),
                'height' => (int) ( $thumbnail_info['height'] ?? 0 ),
                'bytes' => is_file( $thumbnail_path ) ? (int) filesize( $thumbnail_path ) : 0,
                'derivative_key' => 'thumbnail',
            ]
        );
    }

    protected function buildDisplayDerivative( string $source_path, string $owner_username, string $year, string $month, string $filename, string $mime, int $width, int $height, string $driver ) : array {
        if ( $driver === 'none' ) {
            return( [] );
        }
        if ( $width < 1 || $height < 1 || ! $this->supportsResizeForMime( $driver, $mime ) ) {
            return( [] );
        }

        $max_width = self::DISPLAY_DERIVATIVE_MAX_WIDTH;
        $max_height = self::DISPLAY_DERIVATIVE_MAX_HEIGHT;
        if ( $width <= $max_width && $height <= $max_height ) {
            return( [] );
        }

        $extension = pathinfo( $filename, PATHINFO_EXTENSION );
        $base_filename = pathinfo( $filename, PATHINFO_FILENAME );
        $display_filename = $base_filename . '__display.' . $extension;
        $display_path = dirname( $source_path ) . DIRECTORY_SEPARATOR . $display_filename;

        if ( ! $this->resizeUploadedImage( $source_path, $display_path, $mime, $width, $height, $max_width, $max_height, $driver ) ) {
            return( [] );
        }

        $display_info = $this->detectLocalImageInfo( $display_path, $display_filename );
        return(
            [
                'filename' => $display_filename,
                'path' => $display_path,
                'url' => '/media/' . rawurlencode( $owner_username ) . '/' . $year . '/' . $month . '/' . rawurlencode( $display_filename ),
                'mime' => $mime,
                'width' => (int) ( $display_info['width'] ?? 0 ),
                'height' => (int) ( $display_info['height'] ?? 0 ),
                'bytes' => is_file( $display_path ) ? (int) filesize( $display_path ) : 0,
                'derivative_key' => 'display',
            ]
        );
    }

    protected function prepareTargetDirectory( string $owner_username ) : array {
        $year = gmdate( 'Y' );
        $month = gmdate( 'm' );

        return(
            [
                'year' => $year,
                'month' => $month,
                'target_directory' => $this->media_root . DIRECTORY_SEPARATOR . $owner_username . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month,
            ]
        );
    }

    protected function detectLocalImageInfo( string $source_path, string $original_filename = '' ) : array {
        $image_info = @ getimagesize( $source_path );
        $mime = '';
        $width = 0;
        $height = 0;

        if ( is_array( $image_info ) && ! empty( $image_info['mime'] ) ) {
            $mime = strtolower( (string) $image_info['mime'] );
            $width = (int) ( $image_info[0] ?? 0 );
            $height = (int) ( $image_info[1] ?? 0 );
        } else {
            $detected_mime = mime_content_type( $source_path );
            if ( is_string( $detected_mime ) ) {
                $mime = strtolower( trim( $detected_mime ) );
            }
        }

        $fallback_extension = strtolower( pathinfo( $original_filename !== '' ? $original_filename : $source_path, PATHINFO_EXTENSION ) );
        if ( $fallback_extension !== '' ) {
            $fallback_mime = match ( $fallback_extension ) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                'ico' => 'image/x-icon',
                'svg' => 'image/svg+xml',
                default => '',
            };

            if ( $fallback_mime !== '' && ( $mime === '' || $mime === 'text/plain' || $mime === 'application/xml' || $mime === 'text/xml' ) ) {
                $mime = $fallback_mime;
            }
        }

        return(
            [
                'mime' => $mime,
                'width' => $width,
                'height' => $height,
            ]
        );
    }

    protected function buildImageMetadataPayload( string $source_path, string $mime ) : array {
        $retention_groups = $this->config->getMediaMetadataRetentionPolicy();
        $public_groups = array_values( array_intersect( $this->config->getMediaMetadataPublicPolicy(), $retention_groups ) );
        $all_group_keys = $this->getImageMetadataGroupKeys();
        $strip_embedded_metadata = count( array_intersect( $all_group_keys, $retention_groups ) ) !== count( $all_group_keys );
        $rows = $this->extractImageMetadataRows( $source_path, $mime );
        $retained_lookup = array_fill_keys( $retention_groups, true );
        $public_lookup = array_fill_keys( $public_groups, true );
        $retained_rows = [];
        $public_rows = [];

        foreach ( $rows as $row ) {
            $group_key = (string) ( $row['group_key'] ?? '' );
            if ( $group_key === '' || ! isset( $retained_lookup[$group_key] ) ) {
                continue;
            }

            $retained_rows[] = $row;
            if ( isset( $public_lookup[$group_key] ) ) {
                $public_rows[] = [
                    'group' => (string) ( $row['group'] ?? 'Image' ),
                    'label' => (string) ( $row['label'] ?? '' ),
                    'value' => (string) ( $row['value'] ?? '' ),
                ];
            }
        }

        return(
            [
                'strip_embedded_metadata' => $strip_embedded_metadata,
                'metadata' => [
                    'metadata_rows' => $retained_rows,
                    'public_metadata_rows' => $public_rows,
                    'metadata_retention_groups' => $retention_groups,
                    'metadata_public_groups' => $public_groups,
                ],
            ]
        );
    }

    protected function extractImageMetadataRows( string $source_path, string $mime ) : array {
        $mime = strtolower( trim( $mime ) );
        if ( ! function_exists( 'exif_read_data' ) || $mime !== 'image/jpeg' || ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
            return( [] );
        }

        $exif = @ exif_read_data( $source_path, null, true, false );
        if ( ! is_array( $exif ) || empty( $exif ) ) {
            return( [] );
        }

        $rows = [];
        $this->addImageMetadataRow( $rows, 'camera_info', 'Camera', 'Make', $this->findExifText( $exif, [ 'Make' ] ) );
        $this->addImageMetadataRow( $rows, 'camera_info', 'Camera', 'Model', $this->findExifText( $exif, [ 'Model' ] ) );
        $this->addImageMetadataRow( $rows, 'camera_info', 'Camera', 'Lens', $this->findExifText( $exif, [ 'LensModel', 'Lens', 'UndefinedTag:0xA434' ] ) );

        $aperture = $this->formatExifAperture( $this->findExifValue( $exif, [ 'FNumber', 'ApertureFNumber' ] ) );
        $this->addImageMetadataRow( $rows, 'exposure_info', 'Exposure', 'Aperture', $aperture );
        $this->addImageMetadataRow( $rows, 'exposure_info', 'Exposure', 'Shutter speed', $this->formatExifExposureTime( $this->findExifValue( $exif, [ 'ExposureTime' ] ) ) );
        $this->addImageMetadataRow( $rows, 'exposure_info', 'Exposure', 'ISO', $this->formatExifInteger( $this->findExifValue( $exif, [ 'ISOSpeedRatings', 'PhotographicSensitivity' ] ) ) );
        $this->addImageMetadataRow( $rows, 'exposure_info', 'Exposure', 'Focal length', $this->formatExifMillimeters( $this->findExifValue( $exif, [ 'FocalLength' ] ) ) );

        $this->addImageMetadataRow( $rows, 'date_time_info', 'Date/time', 'Date taken', $this->formatExifDateTime( $this->findExifText( $exif, [ 'DateTimeOriginal', 'DateTimeDigitized', 'DateTime' ] ) ) );

        $coordinates = $this->formatExifGpsCoordinates( $this->findExifValue( $exif, [ 'GPSLatitude' ] ), $this->findExifText( $exif, [ 'GPSLatitudeRef' ] ), $this->findExifValue( $exif, [ 'GPSLongitude' ] ), $this->findExifText( $exif, [ 'GPSLongitudeRef' ] ) );
        $this->addImageMetadataRow( $rows, 'location_info', 'Location', 'Coordinates', $coordinates );
        $this->addImageMetadataRow( $rows, 'location_info', 'Location', 'Altitude', $this->formatExifMeters( $this->findExifValue( $exif, [ 'GPSAltitude' ] ) ) );

        $this->addImageMetadataRow( $rows, 'personal_rights_info', 'Personal/rights', 'Creator', $this->findExifText( $exif, [ 'Artist', 'Creator', 'Author', 'XPAuthor' ] ) );
        $this->addImageMetadataRow( $rows, 'personal_rights_info', 'Personal/rights', 'Copyright', $this->findExifText( $exif, [ 'Copyright' ] ) );
        $this->addImageMetadataRow( $rows, 'personal_rights_info', 'Personal/rights', 'Credit', $this->findExifText( $exif, [ 'Credit', 'By-line' ] ) );

        return( $this->dedupeImageMetadataRows( $rows ) );
    }

    protected function addImageMetadataRow( array &$rows, string $group_key, string $group, string $label, string $value ) : void {
        $value = $this->normalizeImageMetadataText( $value, 500 );
        if ( $value === '' ) {
            return;
        }

        $rows[] = [
            'group_key' => $group_key,
            'group' => $group,
            'label' => $label,
            'value' => $value,
        ];
    }

    protected function dedupeImageMetadataRows( array $rows ) : array {
        $seen = [];
        $deduped_rows = [];
        foreach ( $rows as $row ) {
            $key = strtolower( (string) ( $row['group_key'] ?? '' ) . '|' . (string) ( $row['label'] ?? '' ) . '|' . (string) ( $row['value'] ?? '' ) );
            if ( $key === '||' || isset( $seen[$key] ) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped_rows[] = $row;
            if ( count( $deduped_rows ) >= 24 ) {
                break;
            }
        }

        return( $deduped_rows );
    }

    protected function findExifText( array $exif, array $keys ) : string {
        return( $this->normalizeImageMetadataText( $this->findExifValue( $exif, $keys ), 500 ) );
    }

    protected function findExifValue( array $exif, array $keys ) : mixed {
        foreach ( $keys as $key ) {
            $requested_key = strtolower( (string) $key );
            foreach ( $exif as $section ) {
                if ( ! is_array( $section ) ) {
                    continue;
                }
                foreach ( $section as $section_key => $value ) {
                    if ( strtolower( (string) $section_key ) === $requested_key ) {
                        return( $value );
                    }
                }
            }
        }

        foreach ( $keys as $key ) {
            $requested_key = strtolower( (string) $key );
            foreach ( $exif as $section_key => $value ) {
                if ( strtolower( (string) $section_key ) === $requested_key ) {
                    return( $value );
                }
            }
        }

        return( null );
    }

    protected function normalizeImageMetadataText( mixed $value, int $max_length = 500 ) : string {
        if ( is_array( $value ) ) {
            if ( $this->looksLikeUtf16ByteArray( $value ) ) {
                $bytes = '';
                foreach ( $value as $byte ) {
                    $bytes .= chr( max( 0, min( 255, (int) $byte ) ) );
                }
                $decoded = @ mb_convert_encoding( $bytes, 'UTF-8', 'UTF-16LE' );
                $value = is_string( $decoded ) ? $decoded : '';
            } else {
                $parts = [];
                foreach ( $value as $part ) {
                    if ( is_scalar( $part ) ) {
                        $part_text = trim( (string) $part );
                        if ( $part_text !== '' ) {
                            $parts[] = $part_text;
                        }
                    }
                }
                $value = implode( ', ', $parts );
            }
        }
        if ( ! is_scalar( $value ) ) {
            return( '' );
        }

        $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', (string) $value ) ?? '';
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
        if ( $text === '' ) {
            return( '' );
        }

        return( mb_substr( $text, 0, max( 1, $max_length ) ) );
    }

    protected function looksLikeUtf16ByteArray( array $value ) : bool {
        if ( empty( $value ) || count( $value ) > 256 ) {
            return( false );
        }
        foreach ( $value as $byte ) {
            if ( ! is_int( $byte ) && ! ctype_digit( (string) $byte ) ) {
                return( false );
            }
            if ( (int) $byte < 0 || (int) $byte > 255 ) {
                return( false );
            }
        }

        return( true );
    }

    protected function formatExifAperture( mixed $value ) : string {
        $number = $this->parseExifNumber( $value );
        if ( $number === null || $number <= 0.0 ) {
            return( '' );
        }

        return( 'f/' . rtrim( rtrim( number_format( $number, 1, '.', '' ), '0' ), '.' ) );
    }

    protected function formatExifExposureTime( mixed $value ) : string {
        $text = $this->normalizeImageMetadataText( $value, 80 );
        if ( $text === '' ) {
            return( '' );
        }

        return( str_ends_with( $text, 's' ) ? $text : $text . ' s' );
    }

    protected function formatExifInteger( mixed $value ) : string {
        $number = $this->parseExifNumber( $value );
        return( $number !== null && $number > 0.0 ? (string) (int) round( $number ) : '' );
    }

    protected function formatExifMillimeters( mixed $value ) : string {
        $number = $this->parseExifNumber( $value );
        if ( $number === null || $number <= 0.0 ) {
            return( '' );
        }

        return( rtrim( rtrim( number_format( $number, 1, '.', '' ), '0' ), '.' ) . ' mm' );
    }

    protected function formatExifMeters( mixed $value ) : string {
        $number = $this->parseExifNumber( $value );
        if ( $number === null ) {
            return( '' );
        }

        return( rtrim( rtrim( number_format( $number, 1, '.', '' ), '0' ), '.' ) . ' m' );
    }

    protected function formatExifDateTime( string $value ) : string {
        $value = trim( $value );
        if ( preg_match( '/^(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/', $value, $matches ) === 1 ) {
            return( $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6] );
        }

        return( $value );
    }

    protected function formatExifGpsCoordinates( mixed $latitude, string $latitude_ref, mixed $longitude, string $longitude_ref ) : string {
        $lat = $this->parseExifGpsCoordinate( $latitude, $latitude_ref );
        $lon = $this->parseExifGpsCoordinate( $longitude, $longitude_ref );
        if ( $lat === null || $lon === null ) {
            return( '' );
        }

        return( number_format( $lat, 6, '.', '' ) . ', ' . number_format( $lon, 6, '.', '' ) );
    }

    protected function parseExifGpsCoordinate( mixed $coordinate, string $ref ) : ?float {
        if ( ! is_array( $coordinate ) || count( $coordinate ) < 3 ) {
            return( null );
        }

        $degrees = $this->parseExifNumber( $coordinate[0] ?? null );
        $minutes = $this->parseExifNumber( $coordinate[1] ?? null );
        $seconds = $this->parseExifNumber( $coordinate[2] ?? null );
        if ( $degrees === null || $minutes === null || $seconds === null ) {
            return( null );
        }

        $decimal = $degrees + ( $minutes / 60 ) + ( $seconds / 3600 );
        $ref = strtoupper( trim( $ref ) );
        if ( $ref === 'S' || $ref === 'W' ) {
            $decimal *= -1;
        }

        return( $decimal );
    }

    protected function parseExifNumber( mixed $value ) : ?float {
        if ( is_int( $value ) || is_float( $value ) ) {
            return( (float) $value );
        }
        if ( is_array( $value ) && count( $value ) === 1 ) {
            return( $this->parseExifNumber( reset( $value ) ) );
        }
        if ( ! is_scalar( $value ) ) {
            return( null );
        }

        $text = trim( (string) $value );
        if ( $text === '' ) {
            return( null );
        }
        if ( preg_match( '/^(-?\d+(?:\.\d+)?)\s*\/\s*(-?\d+(?:\.\d+)?)$/', $text, $matches ) === 1 ) {
            $denominator = (float) $matches[2];
            if ( abs( $denominator ) < 0.0000001 ) {
                return( null );
            }
            return( (float) $matches[1] / $denominator );
        }
        if ( is_numeric( $text ) ) {
            return( (float) $text );
        }

        return( null );
    }

    protected function stripEmbeddedImageMetadataIfNeeded( string $target_path, string $mime, string $driver, bool $strip_embedded_metadata ) : array {
        if ( ! $strip_embedded_metadata ) {
            return( [ 'stripped' => false, 'note' => 'policy_retains_all_groups' ] );
        }

        $mime = strtolower( trim( $mime ) );
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ], true ) ) {
            return( [ 'stripped' => false, 'note' => 'unsupported_format' ] );
        }
        if ( ! is_file( $target_path ) || ! is_writable( $target_path ) || ! class_exists( '\\Imagick' ) ) {
            return( [ 'stripped' => false, 'note' => 'strip_unavailable' ] );
        }

        $temporary_path = $target_path . '.strip-' . bin2hex( random_bytes( 4 ) );
        try {
            $image = new \Imagick( $target_path );
            if ( method_exists( $image, 'getNumberImages' ) && $image->getNumberImages() > 1 ) {
                $image->clear();
                $image->destroy();
                return( [ 'stripped' => false, 'note' => 'animated_image_skipped' ] );
            }
            if ( $mime === 'image/jpeg' && method_exists( $image, 'autoOrientImage' ) ) {
                $image->autoOrientImage();
                if ( defined( '\\Imagick::ORIENTATION_TOPLEFT' ) ) {
                    $image->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
                }
            }
            $image->stripImage();
            $this->applyImagickOutputPolicy( $image, $mime );
            $image->writeImage( $temporary_path );
            $image->clear();
            $image->destroy();

            $detected_info = $this->detectLocalImageInfo( $temporary_path, basename( $target_path ) );
            $detected_mime = trim( (string) ( $detected_info['mime'] ?? '' ) );
            $this->assertSupportedImageSignature( $temporary_path, $detected_mime !== '' ? $detected_mime : $mime );
            if ( ! @ rename( $temporary_path, $target_path ) ) {
                @ unlink( $temporary_path );
                return( [ 'stripped' => false, 'note' => 'strip_replace_failed' ] );
            }

            return( [ 'stripped' => true, 'note' => 'stripped_with_imagick' ] );
        } catch ( \Throwable $e ) {
            if ( is_file( $temporary_path ) ) {
                @ unlink( $temporary_path );
            }
            return( [ 'stripped' => false, 'note' => 'strip_failed' ] );
        }
    }

    protected function getImageMetadataGroupKeys() : array {
        return(
            [
                'camera_info',
                'exposure_info',
                'date_time_info',
                'location_info',
                'personal_rights_info',
            ]
        );
    }

    protected function shouldResizeImage( string $mime, int $width, int $height, int $max_width, int $max_height, string $driver ) : bool {
        if ( $driver === 'none' ) {
            return( false );
        }
        if ( $mime === 'image/gif' ) {
            return( false );
        }
        if ( ! $this->supportsResizeForMime( $driver, $mime ) ) {
            return( false );
        }

        return( $width > $max_width || $height > $max_height );
    }

    protected function resizeUploadedImage( string $source_path, string $target_path, string $mime, int $width, int $height, int $max_width, int $max_height, string $driver ) : bool {
        if ( $driver === 'imagick' ) {
            return( $this->resizeWithImagick( $source_path, $target_path, $mime, $max_width, $max_height ) );
        }
        if ( $driver === 'gd' ) {
            return( $this->resizeWithGd( $source_path, $target_path, $mime, $width, $height, $max_width, $max_height ) );
        }

        return( false );
    }

    protected function resizeWithImagick( string $source_path, string $target_path, string $mime, int $max_width, int $max_height ) : bool {
        if ( ! class_exists( '\\Imagick' ) ) {
            return( false );
        }

        try {
            $image = new \Imagick( $source_path );
            if ( method_exists( $image, 'autoOrientImage' ) ) {
                $image->autoOrientImage();
            }
            $image->thumbnailImage( $max_width, $max_height, true, false );
            $this->applyImagickOutputPolicy( $image, $mime );
            $image->writeImage( $target_path );
            $image->clear();
            $image->destroy();
            return( true );
        } catch ( \Throwable $e ) {
            return( false );
        }
    }

    protected function resizeWithGd( string $source_path, string $target_path, string $mime, int $width, int $height, int $max_width, int $max_height ) : bool {
        $source_image = match ( $mime ) {
            'image/jpeg' => function_exists( 'imagecreatefromjpeg' ) ? @ imagecreatefromjpeg( $source_path ) : false,
            'image/png' => function_exists( 'imagecreatefrompng' ) ? @ imagecreatefrompng( $source_path ) : false,
            'image/webp' => function_exists( 'imagecreatefromwebp' ) ? @ imagecreatefromwebp( $source_path ) : false,
            'image/avif' => function_exists( 'imagecreatefromavif' ) ? @ imagecreatefromavif( $source_path ) : false,
            default => false,
        };

        if ( ! is_object( $source_image ) && ! is_resource( $source_image ) ) {
            return( false );
        }

        $ratio = min( $max_width / max( 1, $width ), $max_height / max( 1, $height ) );
        $target_width = max( 1, (int) floor( $width * $ratio ) );
        $target_height = max( 1, (int) floor( $height * $ratio ) );
        $target_image = imagecreatetruecolor( $target_width, $target_height );
        if ( $mime === 'image/png' || $mime === 'image/webp' ) {
            imagealphablending( $target_image, false );
            imagesavealpha( $target_image, true );
        }
        imagecopyresampled( $target_image, $source_image, 0, 0, 0, 0, $target_width, $target_height, $width, $height );
        if ( $mime === 'image/jpeg' ) {
            imageinterlace( $target_image, true );
        }

        $saved = match ( $mime ) {
            'image/jpeg' => imagejpeg( $target_image, $target_path, 88 ),
            'image/png' => imagepng( $target_image, $target_path, 6 ),
            'image/webp' => function_exists( 'imagewebp' ) ? imagewebp( $target_image, $target_path, 88 ) : false,
            'image/avif' => function_exists( 'imageavif' ) ? imageavif( $target_image, $target_path, 52 ) : false,
            default => false,
        };

        imagedestroy( $source_image );
        imagedestroy( $target_image );
        return( $saved );
    }

    protected function applyImagickOutputPolicy( \Imagick $image, string $mime ) : void {
        $mime = strtolower( trim( $mime ) );
        if ( $mime !== 'image/jpeg' ) {
            return;
        }

        if ( defined( '\\Imagick::INTERLACE_PLANE' ) ) {
            if ( method_exists( $image, 'setImageInterlaceScheme' ) ) {
                $image->setImageInterlaceScheme( \Imagick::INTERLACE_PLANE );
            }
            if ( method_exists( $image, 'setInterlaceScheme' ) ) {
                $image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
            }
        }
        if ( method_exists( $image, 'setImageCompressionQuality' ) ) {
            $image->setImageCompressionQuality( 88 );
        }
    }

    protected function getExtensionForMime( string $mime ) : string {
        return(
            match ( $mime ) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
                'image/svg+xml' => 'svg',
                'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
                default => '',
            }
        );
    }

    protected function isAllowedContentImageMime( string $mime ) : bool {
        $mime = strtolower( trim( $mime ) );
        if ( $mime === '' ) {
            return( false );
        }

        return( in_array( $mime, $this->config->getAllowedContentImageMimes(), true ) );
    }

    protected function normalizeAllowedUploadMimes( mixed $allowed_mimes ) : array {
        if ( ! is_array( $allowed_mimes ) || empty( $allowed_mimes ) ) {
            return( [] );
        }

        $normalized_mimes = [];
        foreach ( $allowed_mimes as $mime ) {
            if ( ! is_string( $mime ) ) {
                continue;
            }

            $normalized_mime = strtolower( trim( $mime ) );
            if ( $normalized_mime === '' ) {
                continue;
            }

            $normalized_mimes[] = $normalized_mime;
        }

        return( array_values( array_unique( $normalized_mimes ) ) );
    }

    protected function isAllowedUploadMime( string $mime, array $allowed_mimes = [] ) : bool {
        $mime = strtolower( trim( $mime ) );
        if ( $mime === '' ) {
            return( false );
        }

        if ( ! empty( $allowed_mimes ) ) {
            return( in_array( $mime, $allowed_mimes, true ) );
        }

        return( $this->isAllowedContentImageMime( $mime ) );
    }

    protected function assertStoredImageMatchesPolicy( string $target_path, string $stored_mime, array $allowed_mimes, string $expected_extension ) : void {
        $stored_mime = strtolower( trim( $stored_mime ) );
        if ( $stored_mime === '' || ! $this->isAllowedUploadMime( $stored_mime, $allowed_mimes ) ) {
            throw new \InvalidArgumentException( 'content_image_type' );
        }

        $stored_extension = $this->getExtensionForMime( $stored_mime );
        if ( $stored_extension === '' || $stored_extension !== $expected_extension ) {
            throw new \InvalidArgumentException( 'content_image_type' );
        }

        $this->assertSupportedImageSignature( $target_path, $stored_mime );
    }

    protected function assertSupportedImageSignature( string $source_path, string $mime ) : void {
        $mime = strtolower( trim( $mime ) );
        if ( $mime === '' ) {
            throw new \InvalidArgumentException( 'content_image_type' );
        }

        $header = @ file_get_contents( $source_path, false, null, 0, 512 );
        if ( ! is_string( $header ) || $header === '' ) {
            throw new \InvalidArgumentException( 'content_image_type' );
        }

        $valid = match ( $mime ) {
            'image/jpeg' => str_starts_with( $header, "\xFF\xD8\xFF" ),
            'image/png' => str_starts_with( $header, "\x89PNG\r\n\x1A\n" ),
            'image/gif' => str_starts_with( $header, 'GIF87a' ) || str_starts_with( $header, 'GIF89a' ),
            'image/webp' => str_starts_with( $header, 'RIFF' ) && substr( $header, 8, 4 ) === 'WEBP',
            'image/avif' => substr( $header, 4, 4 ) === 'ftyp' && ( str_contains( substr( $header, 8, 48 ), 'avif' ) || str_contains( substr( $header, 8, 48 ), 'avis' ) ),
            'image/x-icon', 'image/vnd.microsoft.icon' => str_starts_with( $header, "\x00\x00\x01\x00" ),
            'image/svg+xml' => preg_match( '/^\s*(?:<\?xml\b[^>]*>\s*)?<svg\b/i', $header ) === 1,
            default => false,
        };

        if ( ! $valid ) {
            throw new \InvalidArgumentException( 'content_image_type' );
        }
    }

    protected function sanitizeSvgIntoPath( string $source_path, string $target_path ) : bool {
        $svg_markup = @ file_get_contents( $source_path );
        if ( ! is_string( $svg_markup ) || trim( $svg_markup ) === '' ) {
            return( false );
        }

        $sanitized_markup = $this->sanitizeSvgMarkup( $svg_markup );
        if ( $sanitized_markup === '' ) {
            return( false );
        }

        return( @ file_put_contents( $target_path, $sanitized_markup ) !== false );
    }

    protected function sanitizeSvgMarkup( string $svg_markup ) : string {
        if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $svg_markup ) ) {
            return( '' );
        }

        $previous_use_internal_errors = libxml_use_internal_errors( true );
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML( $svg_markup, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOCDATA );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_use_internal_errors );

        if ( ! $loaded || ! $dom->documentElement instanceof \DOMElement || strtolower( $dom->documentElement->localName ) !== 'svg' ) {
            return( '' );
        }

        $xpath = new \DOMXPath( $dom );
        foreach ( [ 'script', 'foreignObject', 'iframe', 'object', 'embed', 'audio', 'video', 'canvas', 'meta', 'link' ] as $tag_name ) {
            foreach ( iterator_to_array( $xpath->query( '//*[local-name()="' . $tag_name . '"]' ) ?: [] ) as $element ) {
                if ( $element instanceof \DOMNode && $element->parentNode instanceof \DOMNode ) {
                    $element->parentNode->removeChild( $element );
                }
            }
        }

        foreach ( iterator_to_array( $xpath->query( '//*' ) ?: [] ) as $element ) {
            if ( ! $element instanceof \DOMElement || ! $element->hasAttributes() ) {
                continue;
            }

            $attributes_to_remove = [];
            foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
                if ( ! $attribute instanceof \DOMAttr ) {
                    continue;
                }

                $attribute_name = strtolower( trim( $attribute->name ) );
                $attribute_value = trim( $attribute->value );
                if ( str_starts_with( $attribute_name, 'on' ) ) {
                    $attributes_to_remove[] = $attribute->name;
                    continue;
                }

                if ( in_array( $attribute_name, [ 'href', 'xlink:href', 'src' ], true ) ) {
                    if ( $attribute_value === '' || ! str_starts_with( $attribute_value, '#' ) ) {
                        $attributes_to_remove[] = $attribute->name;
                    }
                    continue;
                }

                if ( preg_match( '/(?:^|[^a-z0-9_-])javascript\s*:/i', $attribute_value ) ) {
                    $attributes_to_remove[] = $attribute->name;
                    continue;
                }

                if ( $attribute_name === 'style' && preg_match( '/url\s*\(|expression\s*\(|@import/i', $attribute_value ) ) {
                    $attributes_to_remove[] = $attribute->name;
                }
            }

            foreach ( $attributes_to_remove as $attribute_name ) {
                $element->removeAttribute( $attribute_name );
            }
        }

        $sanitized_markup = $dom->saveXML( $dom->documentElement );
        return( is_string( $sanitized_markup ) ? trim( $sanitized_markup ) : '' );
    }

    protected function buildAltText( string $original_filename, string $fallback_base ) : string {
        $alt = trim( pathinfo( $original_filename, PATHINFO_FILENAME ) );
        if ( $alt === '' ) {
            $alt = $fallback_base;
        }
        $alt = preg_replace( '/[_-]+/', ' ', $alt ) ?? $alt;
        return( trim( $alt ) );
    }

    protected function normalizeOwnerUsername( string $owner_username ) : string {
        $owner_username = strtolower( trim( $owner_username ) );
        return( preg_replace( '/[^a-z0-9_]/', '', $owner_username ) ?? '' );
    }

    protected function supportsResizeForMime( string $driver, string $mime ) : bool {
        $driver = strtolower( trim( $driver ) );
        $mime = strtolower( trim( $mime ) );

        if ( $driver === 'none' || $mime === 'image/gif' || $mime === 'image/svg+xml' || $mime === 'image/x-icon' || $mime === 'image/vnd.microsoft.icon' ) {
            return( false );
        }

        return(
            match ( $driver ) {
                'imagick' => $this->imagickSupportsMime( $mime ),
                'gd' => $this->gdSupportsMime( $mime ),
                default => false,
            }
        );
    }

    protected function gdSupportsMime( string $mime ) : bool {
        return(
            match ( strtolower( trim( $mime ) ) ) {
                'image/jpeg' => function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagejpeg' ),
                'image/png' => function_exists( 'imagecreatefrompng' ) && function_exists( 'imagepng' ),
                'image/webp' => function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagewebp' ),
                'image/avif' => function_exists( 'imagecreatefromavif' ) && function_exists( 'imageavif' ),
                default => false,
            }
        );
    }

    protected function imagickSupportsMime( string $mime ) : bool {
        if ( ! class_exists( '\\Imagick' ) ) {
            return( false );
        }

        $format = match ( strtolower( trim( $mime ) ) ) {
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/webp' => 'WEBP',
            'image/avif' => 'AVIF',
            default => '',
        };

        if ( $format === '' ) {
            return( false );
        }

        try {
            $formats = \Imagick::queryFormats( $format );
            return( is_array( $formats ) && ! empty( $formats ) );
        } catch ( \Throwable $e ) {
            return( false );
        }
    }

    protected function getDriverFormatNote( string $selected_driver, string $mime, bool $ingest_resize_supported ) : string {
        $driver_label = $this->getDriverDisplayLabel( $selected_driver );

        if ( $mime === 'image/gif' || $mime === 'image/svg+xml' || $mime === 'image/x-icon' || $mime === 'image/vnd.microsoft.icon' ) {
            return( 'Stored without ingest resizing.' );
        }
        if ( $selected_driver === 'none' ) {
            return( 'No active image driver; uploads are stored without resize.' );
        }
        if ( $ingest_resize_supported ) {
            return( $driver_label . ' can resize this format on ingest.' );
        }

        return( $driver_label . ' does not expose resize support for this format in the current PHP runtime.' );
    }

    protected function getDriverDisplayLabel( string $driver ) : string {
        return(
            match ( strtolower( trim( $driver ) ) ) {
                'gd' => 'GD',
                'imagick' => 'Imagick',
                'none' => 'No driver',
                default => ucfirst( trim( $driver ) ),
            }
        );
    }

    protected function slugifyFilename( string $filename ) : string {
        $filename = strtolower( trim( $filename ) );
        $filename = preg_replace( '/[^a-z0-9_-]+/', '-', $filename ) ?? '';
        return( trim( $filename, '-_' ) );
    }

}// TinyMashMediaService
