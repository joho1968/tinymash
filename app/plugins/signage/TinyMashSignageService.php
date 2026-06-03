<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashContentTargetPickerService;
use app\classes\TinyMashLockedJsonFile;
use app\classes\TinyMashMediaService;
use app\classes\TinyMashUserRepository;

class TinyMashSignageService {

    protected const DEFAULT_DELAY_SECONDS = 10;
    protected const MAX_DELAY_SECONDS = 3600;
    protected const DEFAULT_CONTENT_SCALE_PERCENT = 100;
    protected const MIN_CONTENT_SCALE_PERCENT = 60;
    protected const MAX_CONTENT_SCALE_PERCENT = 200;
    protected const CONTENT_SCALE_STEP = 5;
    protected const COOKIE_PREFIX = 'tinymashSignage_';
    protected const TRANSITIONS = [ 'none', 'fade', 'slide-left', 'slide-right', 'slide-up', 'slide-down' ];
    protected const FIT_MODES = [ 'cover', 'contain', 'stretch', 'center', 'tile' ];
    protected const OVERLAYS = [ 'none', 'dark', 'light' ];
    protected const OVERLAY_AREAS = [ 'none', 'full', 'content' ];
    protected const OVERLAY_COLORS = [ 'dark', 'light', 'custom' ];
    protected const DEFAULT_DARK_OVERLAY_STRENGTH = 46;
    protected const DEFAULT_LIGHT_OVERLAY_STRENGTH = 54;

    public function __construct(
        protected string $store_filename,
        protected TinyMashContentTargetPickerService $content_target_picker,
        protected TinyMashContentRepository $content_repository,
        protected TinyMashMediaService $media_service,
        protected TinyMashConfig $config,
        protected TinyMashUserRepository $user_repository
    ) {}

    public function getSettings() : array {
        $store = $this->readStore();
        return( $this->normalizeSettings( is_array( $store['settings'] ?? null ) ? $store['settings'] : [] ) );
    }

    public function saveSettings( array $data ) : array {
        $settings = $this->normalizeSettings( $data );
        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                static function( array $store ) use ( $settings ) : array {
                    $store['version'] = 1;
                    $store['settings'] = $settings;
                    $store['loops'] = is_array( $store['loops'] ?? null ) ? array_values( $store['loops'] ) : [];
                    return( [ 'data' => $store, 'result' => $settings ] );
                },
                $this->buildEmptyStore()
            )
        );
    }

    public function listLoops() : array {
        $store = $this->readStore();
        $loops = [];
        foreach ( (array) ( $store['loops'] ?? [] ) as $loop ) {
            if ( ! is_array( $loop ) ) {
                continue;
            }
            $normalized = $this->normalizeLoopRecord( $loop );
            if ( $normalized['id'] !== '' && $normalized['slug'] !== '' && $normalized['access_key'] !== '' ) {
                $loops[] = $normalized;
            }
        }
        usort(
            $loops,
            static fn( array $left, array $right ) : int => strcmp( (string) $left['name'], (string) $right['name'] )
        );
        return( $loops );
    }

    public function getLoop( string $id ) : ?array {
        $id = trim( $id );
        foreach ( $this->listLoops() as $loop ) {
            if ( $loop['id'] === $id ) {
                return( $loop );
            }
        }
        return( null );
    }

    public function getEnabledLoopBySlug( string $slug ) : ?array {
        $slug = $this->normalizeSlug( $slug );
        foreach ( $this->listLoops() as $loop ) {
            if ( $loop['slug'] === $slug && ! empty( $loop['enabled'] ) ) {
                return( $loop );
            }
        }
        return( null );
    }

    public function saveLoop( array $data ) : array {
        $submitted_id = trim( (string) ( $data['id'] ?? '' ) );
        $submitted_name = mb_substr( mb_trim( (string) ( $data['name'] ?? '' ) ), 0, 120 );
        if ( $submitted_name === '' ) {
            throw new \InvalidArgumentException( 'name' );
        }
        $submitted_slug = $this->normalizeSlug( (string) ( $data['slug'] ?? '' ) );
        if ( $submitted_slug === '' ) {
            $submitted_slug = $this->normalizeSlug( $submitted_name );
        }
        if ( $submitted_slug === '' ) {
            throw new \InvalidArgumentException( 'slug' );
        }
        $background_media_id = trim( (string) ( $data['background_media_id'] ?? '' ) );
        if ( $background_media_id !== '' && $this->resolvePublicImage( $background_media_id ) === null ) {
            throw new \InvalidArgumentException( 'background_media_id' );
        }

        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $submitted_id, $submitted_name, $submitted_slug, $background_media_id, $data ) : array {
                    $records = is_array( $store['loops'] ?? null ) ? $store['loops'] : [];
                    $updated = [];
                    $saved = null;
                    $overlay = $this->normalizeOverlaySettings( $data );
                    foreach ( $records as $record ) {
                        if ( ! is_array( $record ) ) {
                            continue;
                        }
                        $record = $this->normalizeLoopRecord( $record );
                        if ( $record['id'] !== $submitted_id && $record['slug'] === $submitted_slug ) {
                            throw new \InvalidArgumentException( 'slug_conflict' );
                        }
                        if ( $submitted_id !== '' && $record['id'] === $submitted_id ) {
                            $record = array_merge(
                                $record,
                                [
                                    'name' => $submitted_name,
                                    'slug' => $submitted_slug,
                                    'enabled' => ! empty( $data['enabled'] ),
                                    'presentation' => 'neutral',
                                    'color_mode' => $this->normalizeColorMode( (string) ( $data['color_mode'] ?? 'dark' ) ),
                                    'prompt_fullscreen_start' => ! empty( $data['prompt_fullscreen_start'] ),
                                    'honor_reduced_motion' => ! empty( $data['honor_reduced_motion'] ),
                                    'default_delay_seconds' => $this->normalizeOverrideDelay( $data['default_delay_seconds'] ?? 0 ),
                                    'content_scale_percent' => $this->normalizeContentScale( $data['content_scale_percent'] ?? 0, true ),
                                    'default_transition' => $this->normalizeTransition( (string) ( $data['default_transition'] ?? 'fade' ), false ),
                                    'background_media_id' => $background_media_id,
                                    'background_fit' => $this->normalizeFit( (string) ( $data['background_fit'] ?? 'cover' ) ),
                                    ...$overlay,
                                    'updated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                                ]
                            );
                            $saved = $record;
                        }
                        $updated[] = $record;
                    }
                    if ( $saved === null ) {
                        $saved = $this->normalizeLoopRecord(
                            [
                                'id' => $this->buildId( 'signage_loop' ),
                                'name' => $submitted_name,
                                'slug' => $submitted_slug,
                                'enabled' => ! empty( $data['enabled'] ),
                                'access_key' => $this->generateAccessKey(),
                                'presentation' => 'neutral',
                                'color_mode' => $this->normalizeColorMode( (string) ( $data['color_mode'] ?? 'dark' ) ),
                                'prompt_fullscreen_start' => ! empty( $data['prompt_fullscreen_start'] ),
                                'honor_reduced_motion' => ! array_key_exists( 'honor_reduced_motion', $data ) || ! empty( $data['honor_reduced_motion'] ),
                                'default_delay_seconds' => $this->normalizeOverrideDelay( $data['default_delay_seconds'] ?? 0 ),
                                'content_scale_percent' => $this->normalizeContentScale( $data['content_scale_percent'] ?? 0, true ),
                                'default_transition' => $this->normalizeTransition( (string) ( $data['default_transition'] ?? 'fade' ), false ),
                                'background_media_id' => $background_media_id,
                                'background_fit' => $this->normalizeFit( (string) ( $data['background_fit'] ?? 'cover' ) ),
                                ...$overlay,
                                'slides' => [],
                                'created_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                                'updated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                            ]
                        );
                        $updated[] = $saved;
                    }
                    $store['version'] = 1;
                    $store['settings'] = $this->normalizeSettings( is_array( $store['settings'] ?? null ) ? $store['settings'] : [] );
                    $store['loops'] = array_values( $updated );
                    return( [ 'data' => $store, 'result' => $saved ] );
                },
                $this->buildEmptyStore()
            )
        );
    }

    public function deleteLoop( string $loop_id ) : bool {
        return( $this->removeRecord( $loop_id, '' ) );
    }

    public function copyLoop( string $loop_id ) : ?array {
        $loop_id = trim( $loop_id );
        if ( $loop_id === '' ) {
            return( null );
        }
        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $loop_id ) : array {
                    $loops = [];
                    $source = null;
                    foreach ( (array) ( $store['loops'] ?? [] ) as $record ) {
                        if ( ! is_array( $record ) ) {
                            continue;
                        }
                        $loop = $this->normalizeLoopRecord( $record );
                        if ( $loop['id'] === $loop_id ) {
                            $source = $loop;
                        }
                        $loops[] = $loop;
                    }
                    if ( ! is_array( $source ) ) {
                        return( [ 'data' => $store, 'result' => null ] );
                    }

                    $used_names = array_map( static fn( array $loop ) : string => mb_strtolower( (string) $loop['name'] ), $loops );
                    $used_slugs = array_map( static fn( array $loop ) : string => (string) $loop['slug'], $loops );
                    $copy_number = 1;
                    do {
                        $name_suffix = $copy_number === 1 ? ' copy' : ' copy ' . $copy_number;
                        $slug_suffix = $copy_number === 1 ? '-copy' : '-copy-' . $copy_number;
                        $name = mb_substr( (string) $source['name'], 0, max( 0, 120 - mb_strlen( $name_suffix ) ) ) . $name_suffix;
                        $slug_base = substr( (string) $source['slug'], 0, max( 1, 120 - strlen( $slug_suffix ) ) );
                        $slug = $this->normalizeSlug( rtrim( $slug_base, '-' ) . $slug_suffix );
                        ++$copy_number;
                    } while ( in_array( mb_strtolower( $name ), $used_names, true ) || in_array( $slug, $used_slugs, true ) );

                    $slides = [];
                    foreach ( $source['slides'] as $slide ) {
                        $slides[] = $this->normalizeSlideRecord( array_merge( $slide, [ 'id' => $this->buildId( 'signage_slide' ) ] ) );
                    }
                    $now = gmdate( 'Y-m-d\TH:i:s\Z' );
                    $copied = $this->normalizeLoopRecord(
                        array_merge(
                            $source,
                            [
                                'id' => $this->buildId( 'signage_loop' ),
                                'name' => $name,
                                'slug' => $slug,
                                'enabled' => false,
                                'access_key' => $this->generateAccessKey(),
                                'slides' => $slides,
                                'created_at_utc' => $now,
                                'updated_at_utc' => $now,
                            ]
                        )
                    );
                    $loops[] = $copied;
                    $store['version'] = 1;
                    $store['settings'] = $this->normalizeSettings( is_array( $store['settings'] ?? null ) ? $store['settings'] : [] );
                    $store['loops'] = array_values( $loops );
                    return( [ 'data' => $store, 'result' => $copied ] );
                },
                $this->buildEmptyStore()
            )
        );
    }

    public function regenerateAccessKey( string $loop_id ) : ?array {
        $loop_id = trim( $loop_id );
        if ( $loop_id === '' ) {
            return( null );
        }
        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $loop_id ) : array {
                    $saved = null;
                    $loops = [];
                    foreach ( (array) ( $store['loops'] ?? [] ) as $record ) {
                        if ( ! is_array( $record ) ) {
                            continue;
                        }
                        $loop = $this->normalizeLoopRecord( $record );
                        if ( $loop['id'] === $loop_id ) {
                            $loop['access_key'] = $this->generateAccessKey();
                            $loop['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                            $saved = $loop;
                        }
                        $loops[] = $loop;
                    }
                    $store['loops'] = $loops;
                    return( [ 'data' => $store, 'result' => $saved ] );
                },
                $this->buildEmptyStore()
            )
        );
    }

    public function saveSlide( string $loop_id, array $data ) : array {
        $loop_id = trim( $loop_id );
        $slide_id = trim( (string) ( $data['slide_id'] ?? '' ) );
        $type = strtolower( trim( (string) ( $data['type'] ?? 'composed' ) ) );
        if ( ! in_array( $type, [ 'page', 'image', 'composed' ], true ) ) {
            throw new \InvalidArgumentException( 'type' );
        }
        $page_entry_id = trim( (string) ( $data['page_entry_id'] ?? '' ) );
        $image_media_id = trim( (string) ( $data['image_media_id'] ?? '' ) );
        if ( $type === 'page' && $this->resolvePublicPage( $page_entry_id ) === null ) {
            throw new \InvalidArgumentException( 'page_entry_id' );
        }
        if ( $type === 'image' && $this->resolvePublicImage( $image_media_id ) === null ) {
            throw new \InvalidArgumentException( 'image_media_id' );
        }
        $background_media_id = trim( (string) ( $data['background_media_id'] ?? '' ) );
        if ( $background_media_id !== '' && $this->resolvePublicImage( $background_media_id ) === null ) {
            throw new \InvalidArgumentException( 'background_media_id' );
        }
        $title = mb_substr( mb_trim( (string) ( $data['title'] ?? '' ) ), 0, 160 );
        $markdown = mb_substr( (string) ( $data['content_markdown'] ?? '' ), 0, 100000 );
        if ( $type === 'composed' && $title === '' && mb_trim( $markdown ) === '' && $background_media_id === '' ) {
            throw new \InvalidArgumentException( 'composed_content' );
        }
        $slide_input = [
            'id' => $slide_id,
            'enabled' => ! empty( $data['enabled'] ),
            'type' => $type,
            'title' => $title,
            'content_markdown' => $markdown,
            'page_entry_id' => $type === 'page' ? $page_entry_id : '',
            'show_page_title' => $type === 'page' && ! empty( $data['show_page_title'] ),
            'image_media_id' => $type === 'image' ? $image_media_id : '',
            'delay_seconds' => $this->normalizeOverrideDelay( $data['delay_seconds'] ?? 0 ),
            'transition' => $this->normalizeTransition( (string) ( $data['transition'] ?? 'inherit' ), true ),
            'background_media_id' => $background_media_id,
            'background_fit' => $this->normalizeFit( (string) ( $data['background_fit'] ?? 'cover' ) ),
            ...$this->normalizeOverlaySettings( $data ),
        ];
        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $loop_id, $slide_id, $slide_input ) : array {
                    $saved = null;
                    $found_loop = false;
                    $loops = [];
                    foreach ( (array) ( $store['loops'] ?? [] ) as $record ) {
                        if ( ! is_array( $record ) ) {
                            continue;
                        }
                        $loop = $this->normalizeLoopRecord( $record );
                        if ( $loop['id'] !== $loop_id ) {
                            $loops[] = $loop;
                            continue;
                        }
                        $found_loop = true;
                        $slides = [];
                        foreach ( $loop['slides'] as $existing ) {
                            if ( $slide_id !== '' && $existing['id'] === $slide_id ) {
                                $saved = $this->normalizeSlideRecord( array_merge( $existing, $slide_input, [ 'id' => $existing['id'] ] ) );
                                $slides[] = $saved;
                            } else {
                                $slides[] = $existing;
                            }
                        }
                        if ( $saved === null ) {
                            $saved = $this->normalizeSlideRecord( array_merge( $slide_input, [ 'id' => $this->buildId( 'signage_slide' ) ] ) );
                            $slides[] = $saved;
                        }
                        $loop['slides'] = $slides;
                        $loop['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                        $loops[] = $loop;
                    }
                    if ( ! $found_loop ) {
                        throw new \InvalidArgumentException( 'loop' );
                    }
                    $store['loops'] = $loops;
                    return( [ 'data' => $store, 'result' => $saved ] );
                },
                $this->buildEmptyStore()
            )
        );
    }

    public function deleteSlide( string $loop_id, string $slide_id ) : bool {
        return( $this->removeRecord( $loop_id, $slide_id ) );
    }

    public function copySlide( string $loop_id, string $slide_id ) : ?array {
        $loop_id = trim( $loop_id );
        $slide_id = trim( $slide_id );
        if ( $loop_id === '' || $slide_id === '' ) {
            return( null );
        }
        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $loop_id, $slide_id ) : array {
                    $copied = null;
                    $loops = [];
                    foreach ( (array) ( $store['loops'] ?? [] ) as $record ) {
                        $loop = is_array( $record ) ? $this->normalizeLoopRecord( $record ) : null;
                        if ( ! is_array( $loop ) ) {
                            continue;
                        }
                        if ( $loop['id'] !== $loop_id ) {
                            $loops[] = $loop;
                            continue;
                        }
                        $slides = [];
                        foreach ( $loop['slides'] as $slide ) {
                            $slides[] = $slide;
                            if ( $slide['id'] !== $slide_id ) {
                                continue;
                            }
                            $copy_title = mb_trim( (string) $slide['title'] );
                            $copied = $this->normalizeSlideRecord(
                                array_merge(
                                    $slide,
                                    [
                                        'id' => $this->buildId( 'signage_slide' ),
                                        'title' => mb_substr( $copy_title !== '' ? $copy_title . ' copy' : 'Slide copy', 0, 160 ),
                                    ]
                                )
                            );
                            $slides[] = $copied;
                        }
                        if ( is_array( $copied ) ) {
                            $loop['updated_at_utc'] = gmdate( 'Y-m-d\\TH:i:s\\Z' );
                        }
                        $loop['slides'] = $slides;
                        $loops[] = $loop;
                    }
                    $store['loops'] = $loops;
                    return( [ 'data' => $store, 'result' => $copied ] );
                },
                $this->buildEmptyStore()
            )
        );
    }

    public function moveSlide( string $loop_id, string $slide_id, string $direction ) : bool {
        $loop_id = trim( $loop_id );
        $slide_id = trim( $slide_id );
        $direction = strtolower( trim( $direction ) );
        if ( $loop_id === '' || $slide_id === '' || ! in_array( $direction, [ 'up', 'down' ], true ) ) {
            return( false );
        }
        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $loop_id, $slide_id, $direction ) : array {
                    $moved = false;
                    $loops = [];
                    foreach ( (array) ( $store['loops'] ?? [] ) as $record ) {
                        $loop = is_array( $record ) ? $this->normalizeLoopRecord( $record ) : null;
                        if ( ! is_array( $loop ) || $loop['id'] !== $loop_id ) {
                            if ( is_array( $loop ) ) {
                                $loops[] = $loop;
                            }
                            continue;
                        }
                        foreach ( $loop['slides'] as $index => $slide ) {
                            if ( $slide['id'] !== $slide_id ) {
                                continue;
                            }
                            $target = $direction === 'up' ? $index - 1 : $index + 1;
                            if ( isset( $loop['slides'][$target] ) ) {
                                [ $loop['slides'][$index], $loop['slides'][$target] ] = [ $loop['slides'][$target], $loop['slides'][$index] ];
                                $loop['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                                $moved = true;
                            }
                            break;
                        }
                        $loops[] = $loop;
                    }
                    $store['loops'] = $loops;
                    return( [ 'data' => $store, 'result' => $moved ] );
                },
                $this->buildEmptyStore()
            )
        );
    }

    public function listPublicPageOptions() : array {
        $options = [];
        foreach ( $this->content_target_picker->buildPickerOptions( null, null, true, [ 'published_only' => true, 'types' => [ 'page' ] ] ) as $target ) {
            if ( is_array( $target ) && $this->targetIsPublic( $target ) ) {
                $options[] = $target;
            }
        }
        return( $options );
    }

    public function listPublicContentLinkOptions() : array {
        $options = [];
        foreach ( $this->content_target_picker->listTargets( null, null, true, [ 'published_only' => true, 'types' => [ 'post', 'page' ] ] ) as $target ) {
            if ( ! is_array( $target ) || ! $this->targetIsPublic( $target ) ) {
                continue;
            }
            $options[] = [
                'id' => (string) ( $target['id'] ?? '' ),
                'title' => (string) ( $target['title'] ?? '' ),
                'label' => (string) ( $target['label'] ?? '' ),
                'type' => (string) ( $target['type'] ?? '' ),
                'scope' => (string) ( $target['scope'] ?? '' ),
                'author_slug' => (string) ( $target['author_slug'] ?? '' ),
                'path' => (string) ( $target['path'] ?? '' ),
                'url' => (string) ( $target['url'] ?? '' ),
            ];
        }
        return( $options );
    }

    public function listPublicImages( int $limit = 500 ) : array {
        if ( ! $this->config->isSitePublic() ) {
            return( [] );
        }
        $images = [];
        foreach ( $this->media_service->listAttachments( [], max( 1, min( 1000, $limit ) ) ) as $metadata ) {
            $record = is_array( $metadata ) ? $this->normalizePublicImageRecord( $metadata ) : null;
            if ( is_array( $record ) ) {
                $images[] = $record;
            }
        }
        usort( $images, static fn( array $left, array $right ) : int => strcmp( (string) $right['created_at_utc'], (string) $left['created_at_utc'] ) );
        return( $images );
    }

    public function resolvePublicPage( string $entry_id ) : ?array {
        $entry = $this->content_repository->getPublishedEntryById( trim( $entry_id ), $this->config->getDefaultLanguage(), null, true );
        if ( ! is_array( $entry ) || (string) ( $entry['type'] ?? '' ) !== 'page' || ! $this->targetIsPublic( $entry ) ) {
            return( null );
        }
        return( $entry );
    }

    public function resolvePublicImage( string $media_id ) : ?array {
        $metadata = $this->media_service->getAttachmentMetadataByMediaId( trim( $media_id ), [] );
        return( is_array( $metadata ) ? $this->normalizePublicImageRecord( $metadata ) : null );
    }

    public function getEffectiveSlideDelay( array $loop, array $slide ) : int {
        $slide_delay = $this->normalizeOverrideDelay( $slide['delay_seconds'] ?? 0 );
        if ( $slide_delay > 0 ) {
            return( $slide_delay );
        }
        $loop_delay = $this->normalizeOverrideDelay( $loop['default_delay_seconds'] ?? 0 );
        return( $loop_delay > 0 ? $loop_delay : (int) $this->getSettings()['default_delay_seconds'] );
    }

    public function getEffectiveTransition( array $loop, array $slide ) : string {
        $transition = $this->normalizeTransition( (string) ( $slide['transition'] ?? 'inherit' ), true );
        return( $transition === 'inherit' ? $this->normalizeTransition( (string) ( $loop['default_transition'] ?? 'fade' ), false ) : $transition );
    }

    public function getEffectiveContentScalePercent( array $loop ) : int {
        $loop_scale = $this->normalizeContentScale( $loop['content_scale_percent'] ?? 0, true );
        return( $loop_scale > 0 ? $loop_scale : (int) $this->getSettings()['default_content_scale_percent'] );
    }

    public function getEffectiveBackground( array $loop, array $slide ) : array {
        $media_id = trim( (string) ( $slide['background_media_id'] ?? '' ) );
        $source = $slide;
        if ( $media_id === '' ) {
            $media_id = trim( (string) ( $loop['background_media_id'] ?? '' ) );
            $source = $loop;
        }
        $image = $media_id !== '' ? $this->resolvePublicImage( $media_id ) : null;
        $overlay = $this->normalizeOverlaySettings( $source );
        return(
            [
                'url' => is_array( $image ) ? (string) ( $image['display_url'] ?: $image['url'] ) : '',
                'alt_text' => is_array( $image ) ? (string) $image['alt_text'] : '',
                'fit' => $this->normalizeFit( (string) ( $source['background_fit'] ?? 'cover' ) ),
                'overlay' => $overlay['background_overlay'],
                'overlay_area' => $overlay['background_overlay_area'],
                'overlay_color' => $overlay['background_overlay_color'],
                'overlay_custom_color' => $overlay['background_overlay_custom_color'],
                'overlay_strength' => $overlay['background_overlay_strength'],
                'overlay_rgb' => $this->overlayRgb( $overlay['background_overlay_color'], $overlay['background_overlay_custom_color'] ),
                'overlay_opacity' => number_format( $overlay['background_overlay_strength'] / 100, 2, '.', '' ),
            ]
        );
    }

    public function buildPlayerSlides( array $loop ) : array {
        $items = [];
        foreach ( $loop['slides'] as $slide ) {
            if ( ! empty( $slide['enabled'] ) ) {
                $items[] = [ 'id' => (string) $slide['id'] ];
            }
        }
        return( $items );
    }

    public function buildPlayerManifest( array $loop ) : array {
        $manifest = [
            'slides' => $this->buildPlayerSlides( $loop ),
            'color_mode' => $this->normalizeColorMode( (string) ( $loop['color_mode'] ?? 'dark' ) ),
            'honor_reduced_motion' => ! empty( $loop['honor_reduced_motion'] ),
            'content_scale_percent' => $this->getEffectiveContentScalePercent( $loop ),
        ];
        $encoded = json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $manifest['hash'] = hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
        return( $manifest );
    }

    public function getSlide( array $loop, string $slide_id ) : ?array {
        foreach ( (array) ( $loop['slides'] ?? [] ) as $slide ) {
            if ( is_array( $slide ) && ! empty( $slide['enabled'] ) && (string) ( $slide['id'] ?? '' ) === trim( $slide_id ) ) {
                return( $this->normalizeSlideRecord( $slide ) );
            }
        }
        return( null );
    }

    public function grantAccess( array $loop, string $key ) : bool {
        $key = trim( $key );
        if ( empty( $loop['enabled'] ) || $key === '' || ! hash_equals( (string) $loop['access_key'], $key ) ) {
            return( false );
        }
        $this->persistAccessCookie( $loop, $key );
        return( true );
    }

    public function currentVisitorCanAccess( array $loop ) : bool {
        if ( empty( $loop['enabled'] ) ) {
            return( false );
        }
        $cookie_name = $this->buildCookieName( (string) $loop['id'] );
        $key = trim( (string) ( $_COOKIE[$cookie_name] ?? '' ) );
        return( $key !== '' && hash_equals( (string) $loop['access_key'], $key ) );
    }

    public function buildLaunchUrl( array $loop, string $app_url ) : string {
        return( rtrim( $app_url, '/' ) . '/signage/' . rawurlencode( (string) $loop['slug'] ) . '?key=' . rawurlencode( (string) $loop['access_key'] ) );
    }

    public function getMediaUsageReferences() : array {
        $references = [];
        foreach ( $this->listLoops() as $loop ) {
            $loop_label = (string) $loop['name'];
            if ( $loop['background_media_id'] !== '' ) {
                $references[] = [
                    'media_id' => $loop['background_media_id'],
                    'category' => 'system_plugin_settings',
                    'source' => 'plugin:signage:loop:' . $loop['id'],
                    'label' => 'Plugin: Signage - loop background: ' . $loop_label,
                ];
            }
            foreach ( $loop['slides'] as $slide ) {
                $slide_label = $slide['title'] !== '' ? $slide['title'] : ucfirst( $slide['type'] ) . ' slide';
                if ( $slide['image_media_id'] !== '' ) {
                    $references[] = [
                        'media_id' => $slide['image_media_id'],
                        'category' => 'system_plugin_settings',
                        'source' => 'plugin:signage:slide:' . $slide['id'],
                        'label' => 'Plugin: Signage - image slide: ' . $loop_label . ' / ' . $slide_label,
                    ];
                }
                if ( $slide['background_media_id'] !== '' ) {
                    $references[] = [
                        'media_id' => $slide['background_media_id'],
                        'category' => 'system_plugin_settings',
                        'source' => 'plugin:signage:slide-background:' . $slide['id'],
                        'label' => 'Plugin: Signage - slide background: ' . $loop_label . ' / ' . $slide_label,
                    ];
                }
            }
        }
        return( $references );
    }

    protected function removeRecord( string $loop_id, string $slide_id ) : bool {
        $loop_id = trim( $loop_id );
        $slide_id = trim( $slide_id );
        if ( $loop_id === '' ) {
            return( false );
        }
        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $loop_id, $slide_id ) : array {
                    $removed = false;
                    $loops = [];
                    foreach ( (array) ( $store['loops'] ?? [] ) as $record ) {
                        $loop = is_array( $record ) ? $this->normalizeLoopRecord( $record ) : null;
                        if ( ! is_array( $loop ) ) {
                            continue;
                        }
                        if ( $loop['id'] !== $loop_id ) {
                            $loops[] = $loop;
                            continue;
                        }
                        if ( $slide_id === '' ) {
                            $removed = true;
                            continue;
                        }
                        $next_slides = [];
                        foreach ( $loop['slides'] as $slide ) {
                            if ( $slide['id'] === $slide_id ) {
                                $removed = true;
                                continue;
                            }
                            $next_slides[] = $slide;
                        }
                        $loop['slides'] = $next_slides;
                        $loops[] = $loop;
                    }
                    $store['loops'] = $loops;
                    return( [ 'data' => $store, 'result' => $removed ] );
                },
                $this->buildEmptyStore()
            )
        );
    }

    protected function readStore() : array {
        $store = TinyMashLockedJsonFile::read( $this->store_filename, $this->buildEmptyStore() );
        return( is_array( $store ) ? $store : $this->buildEmptyStore() );
    }

    protected function buildEmptyStore() : array {
        return( [ 'version' => 1, 'settings' => [ 'default_delay_seconds' => self::DEFAULT_DELAY_SECONDS, 'default_content_scale_percent' => self::DEFAULT_CONTENT_SCALE_PERCENT ], 'loops' => [] ] );
    }

    protected function normalizeSettings( array $settings ) : array {
        $delay = (int) ( $settings['default_delay_seconds'] ?? self::DEFAULT_DELAY_SECONDS );
        return(
            [
                'default_delay_seconds' => max( 2, min( self::MAX_DELAY_SECONDS, $delay ) ),
                'default_content_scale_percent' => $this->normalizeContentScale( $settings['default_content_scale_percent'] ?? self::DEFAULT_CONTENT_SCALE_PERCENT ),
            ]
        );
    }

    protected function normalizeLoopRecord( array $loop ) : array {
        $slides = [];
        foreach ( (array) ( $loop['slides'] ?? [] ) as $slide ) {
            if ( is_array( $slide ) ) {
                $normalized = $this->normalizeSlideRecord( $slide );
                if ( $normalized['id'] !== '' ) {
                    $slides[] = $normalized;
                }
            }
        }
        return(
            [
                'id' => trim( (string) ( $loop['id'] ?? '' ) ),
                'name' => mb_substr( mb_trim( (string) ( $loop['name'] ?? '' ) ), 0, 120 ),
                'slug' => $this->normalizeSlug( (string) ( $loop['slug'] ?? '' ) ),
                'enabled' => ! empty( $loop['enabled'] ),
                'access_key' => trim( (string) ( $loop['access_key'] ?? '' ) ),
                'presentation' => 'neutral',
                'color_mode' => $this->normalizeColorMode( (string) ( $loop['color_mode'] ?? 'dark' ) ),
                'prompt_fullscreen_start' => ! empty( $loop['prompt_fullscreen_start'] ),
                'honor_reduced_motion' => ! array_key_exists( 'honor_reduced_motion', $loop ) || ! empty( $loop['honor_reduced_motion'] ),
                'default_delay_seconds' => $this->normalizeOverrideDelay( $loop['default_delay_seconds'] ?? 0 ),
                'content_scale_percent' => $this->normalizeContentScale( $loop['content_scale_percent'] ?? 0, true ),
                'default_transition' => $this->normalizeTransition( (string) ( $loop['default_transition'] ?? 'fade' ), false ),
                'background_media_id' => trim( (string) ( $loop['background_media_id'] ?? '' ) ),
                'background_fit' => $this->normalizeFit( (string) ( $loop['background_fit'] ?? 'cover' ) ),
                ...$this->normalizeOverlaySettings( $loop ),
                'slides' => $slides,
                'created_at_utc' => trim( (string) ( $loop['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $loop['updated_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function normalizeSlideRecord( array $slide ) : array {
        $type = strtolower( trim( (string) ( $slide['type'] ?? 'composed' ) ) );
        if ( ! in_array( $type, [ 'page', 'image', 'composed' ], true ) ) {
            $type = 'composed';
        }
        return(
            [
                'id' => trim( (string) ( $slide['id'] ?? '' ) ),
                'enabled' => ! array_key_exists( 'enabled', $slide ) || ! empty( $slide['enabled'] ),
                'type' => $type,
                'title' => mb_substr( mb_trim( (string) ( $slide['title'] ?? '' ) ), 0, 160 ),
                'content_markdown' => (string) ( $slide['content_markdown'] ?? '' ),
                'page_entry_id' => trim( (string) ( $slide['page_entry_id'] ?? '' ) ),
                'show_page_title' => ! empty( $slide['show_page_title'] ),
                'image_media_id' => trim( (string) ( $slide['image_media_id'] ?? '' ) ),
                'delay_seconds' => $this->normalizeOverrideDelay( $slide['delay_seconds'] ?? 0 ),
                'transition' => $this->normalizeTransition( (string) ( $slide['transition'] ?? 'inherit' ), true ),
                'background_media_id' => trim( (string) ( $slide['background_media_id'] ?? '' ) ),
                'background_fit' => $this->normalizeFit( (string) ( $slide['background_fit'] ?? 'cover' ) ),
                ...$this->normalizeOverlaySettings( $slide ),
            ]
        );
    }

    protected function normalizeOverrideDelay( mixed $delay ) : int {
        $delay = (int) $delay;
        return( $delay <= 0 ? 0 : max( 2, min( self::MAX_DELAY_SECONDS, $delay ) ) );
    }

    protected function normalizeContentScale( mixed $scale, bool $allow_inherit = false ) : int {
        $scale = (int) $scale;
        if ( $allow_inherit && $scale <= 0 ) {
            return( 0 );
        }
        if ( $scale <= 0 ) {
            $scale = self::DEFAULT_CONTENT_SCALE_PERCENT;
        }
        $scale = max( self::MIN_CONTENT_SCALE_PERCENT, min( self::MAX_CONTENT_SCALE_PERCENT, $scale ) );
        return( (int) ( round( $scale / self::CONTENT_SCALE_STEP ) * self::CONTENT_SCALE_STEP ) );
    }

    protected function normalizeTransition( string $transition, bool $allow_inherit ) : string {
        $transition = strtolower( trim( $transition ) );
        if ( $allow_inherit && $transition === 'inherit' ) {
            return( 'inherit' );
        }
        return( in_array( $transition, self::TRANSITIONS, true ) ? $transition : 'fade' );
    }

    protected function normalizeFit( string $fit ) : string {
        $fit = strtolower( trim( $fit ) );
        return( in_array( $fit, self::FIT_MODES, true ) ? $fit : 'cover' );
    }

    protected function normalizeOverlay( string $overlay ) : string {
        $overlay = strtolower( trim( $overlay ) );
        return( in_array( $overlay, self::OVERLAYS, true ) ? $overlay : 'dark' );
    }

    protected function normalizeOverlaySettings( array $source ) : array {
        $legacy = $this->normalizeOverlay( (string) ( $source['background_overlay'] ?? 'dark' ) );
        $legacy_area = $legacy === 'none' ? 'none' : 'full';
        $legacy_color = $legacy === 'light' ? 'light' : 'dark';
        $legacy_strength = $legacy === 'light' ? self::DEFAULT_LIGHT_OVERLAY_STRENGTH : self::DEFAULT_DARK_OVERLAY_STRENGTH;

        $area = $this->normalizeOverlayArea( (string) ( $source['background_overlay_area'] ?? $legacy_area ), $legacy_area );
        $color = $this->normalizeOverlayColor( (string) ( $source['background_overlay_color'] ?? $legacy_color ), $legacy_color );
        $custom_color = $this->normalizeOverlayCustomColor( (string) ( $source['background_overlay_custom_color'] ?? '#000000' ) );
        $strength = $this->normalizeOverlayStrength( $source['background_overlay_strength'] ?? $legacy_strength );

        return(
            [
                'background_overlay' => $area === 'none' ? 'none' : ( $color === 'light' ? 'light' : 'dark' ),
                'background_overlay_area' => $area,
                'background_overlay_color' => $color,
                'background_overlay_custom_color' => $custom_color,
                'background_overlay_strength' => $strength,
            ]
        );
    }

    protected function normalizeOverlayArea( string $area, string $fallback = 'full' ) : string {
        $area = strtolower( trim( $area ) );
        $fallback = in_array( $fallback, self::OVERLAY_AREAS, true ) ? $fallback : 'full';
        return( in_array( $area, self::OVERLAY_AREAS, true ) ? $area : $fallback );
    }

    protected function normalizeOverlayColor( string $color, string $fallback = 'dark' ) : string {
        $color = strtolower( trim( $color ) );
        $fallback = in_array( $fallback, self::OVERLAY_COLORS, true ) ? $fallback : 'dark';
        return( in_array( $color, self::OVERLAY_COLORS, true ) ? $color : $fallback );
    }

    protected function normalizeOverlayCustomColor( string $color ) : string {
        $color = trim( $color );
        return( preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) === 1 ? strtoupper( $color ) : '#000000' );
    }

    protected function normalizeOverlayStrength( mixed $strength ) : int {
        return( max( 0, min( 90, (int) $strength ) ) );
    }

    protected function overlayRgb( string $color, string $custom_color ) : string {
        if ( $color === 'light' ) {
            return( '255 255 255' );
        }
        if ( $color === 'custom' && preg_match( '/^#([0-9A-F]{2})([0-9A-F]{2})([0-9A-F]{2})$/', strtoupper( $custom_color ), $matches ) === 1 ) {
            return( (string) hexdec( $matches[1] ) . ' ' . (string) hexdec( $matches[2] ) . ' ' . (string) hexdec( $matches[3] ) );
        }
        return( '0 0 0' );
    }

    protected function normalizeColorMode( string $mode ) : string {
        return( strtolower( trim( $mode ) ) === 'light' ? 'light' : 'dark' );
    }

    protected function normalizeSlug( string $slug ) : string {
        $slug = str_replace( [ "'", "\xE2\x80\x99" ], '', mb_strtolower( mb_trim( $slug ) ) );
        $ascii = @ iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug );
        $slug = is_string( $ascii ) ? $ascii : $slug;
        $slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $slug ) ) ?? '';
        return( trim( $slug, '-' ) );
    }

    protected function targetIsPublic( array $record ) : bool {
        if ( ! $this->config->isSitePublic() ) {
            return( false );
        }
        if ( array_key_exists( 'owner_username', $record ) ) {
            $owner_username = strtolower( trim( (string) $record['owner_username'] ) );
            return( $owner_username === 'root' || ( $owner_username !== '' && $this->user_repository->isAuthorContentPublic( $owner_username ) ) );
        }
        if ( (string) ( $record['scope'] ?? 'root' ) !== 'author' ) {
            return( true );
        }
        $author_slug = strtolower( trim( (string) ( $record['author_slug'] ?? $record['owner_username'] ?? '' ) ) );
        return( $author_slug !== '' && $this->user_repository->isAuthorContentPublic( $author_slug ) );
    }

    protected function normalizePublicImageRecord( array $metadata ) : ?array {
        $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
        $mime = strtolower( trim( (string) ( $metadata['mime'] ?? '' ) ) );
        $url = trim( (string) ( $metadata['url'] ?? '' ) );
        if ( $media_id === '' || $url === '' || ! str_starts_with( $mime, 'image/' ) || ! $this->targetIsPublic( $metadata ) ) {
            return( null );
        }
        $display = is_array( $metadata['display'] ?? null ) ? $metadata['display'] : [];
        return(
            [
                'media_id' => $media_id,
                'owner_username' => strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) ),
                'url' => $url,
                'display_url' => trim( (string) ( $display['url'] ?? '' ) ),
                'thumbnail_url' => trim( (string) ( $metadata['thumbnail']['url'] ?? '' ) ),
                'alt_text' => trim( (string) ( $metadata['alt_text'] ?? '' ) ),
                'filename' => basename( (string) ( $metadata['filename'] ?? $metadata['original_filename'] ?? '' ) ),
                'mime' => $mime,
                'width' => max( 0, (int) ( $metadata['width'] ?? 0 ) ),
                'height' => max( 0, (int) ( $metadata['height'] ?? 0 ) ),
                'created_at_utc' => trim( (string) ( $metadata['created_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function buildId( string $prefix ) : string {
        return( $prefix . '_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 5 ) ) );
    }

    protected function generateAccessKey() : string {
        return( bin2hex( random_bytes( 24 ) ) );
    }

    protected function buildCookieName( string $loop_id ) : string {
        return( self::COOKIE_PREFIX . substr( sha1( trim( $loop_id ) ), 0, 16 ) );
    }

    protected function persistAccessCookie( array $loop, string $key ) : void {
        $cookie_name = $this->buildCookieName( (string) $loop['id'] );
        $secure_cookie = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on';
        setcookie(
            $cookie_name,
            $key,
            [
                'expires' => time() + ( 10 * 365 * 86400 ),
                'path' => '/signage',
                'secure' => $secure_cookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE[$cookie_name] = $key;
    }
}
