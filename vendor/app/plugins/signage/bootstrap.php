<?php

require_once __DIR__ . '/TinyMashSignageService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRenderer;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashContentTargetPickerService;
use app\classes\TinyMashMediaService;
use app\classes\TinyMashMarkdownEditorComponent;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'signage' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $content_target_picker = $plugins->getService( 'content.target_picker' );
    $content_repository = $plugins->getService( 'content.repository' );
    $content_renderer = $plugins->getService( 'content.renderer' );
    $media_service = $plugins->getService( 'media.service' );
    $theme = $plugins->getService( 'theme' );
    $user_repository = $plugins->getService( 'user.repository' );

    if ( ! $config instanceof TinyMashConfig
        || ! $security instanceof TinyMashSecurity
        || ! $content_target_picker instanceof TinyMashContentTargetPickerService
        || ! $content_repository instanceof TinyMashContentRepository
        || ! $content_renderer instanceof TinyMashContentRenderer
        || ! $media_service instanceof TinyMashMediaService
        || ! $theme instanceof TinyMashTheme
        || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the Signage plugin are not available.' );
    }

    $service = new TinyMashSignageService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'signage' . DIRECTORY_SEPARATOR . 'signage.json',
        $content_target_picker,
        $content_repository,
        $media_service,
        $config,
        $user_repository
    );
    \Flight::set( 'plugin.signage.service', $service );
    $plugins->registerMediaUsageContributor(
        $plugin_key,
        static fn() : array => $service->getMediaUsageReferences()
    );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/signage';
    $settings_save_url = $plugin_url . '/settings/save';
    $loop_save_url = $plugin_url . '/loop/save';
    $loop_copy_url = $plugin_url . '/loop/copy';
    $loop_delete_url = $plugin_url . '/loop/delete';
    $loop_key_url = $plugin_url . '/loop/regenerate-key';
    $slide_save_url = $plugin_url . '/slide/save';
    $slide_preview_url = $plugin_url . '/slide/preview';
    $slide_delete_url = $plugin_url . '/slide/delete';
    $slide_copy_url = $plugin_url . '/slide/copy';
    $slide_move_url = $plugin_url . '/slide/move';
    $content_link_targets_url = $plugin_url . '/content-link-targets';
    $media_picker_url = $plugin_url . '/media/picker';
    $media_upload_url = $plugin_url . '/media/upload';
    $app_url = rtrim( (string) \Flight::get( 'app.url' ), '/' );

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'signage',
            'label' => 'Signage',
            'url' => $plugin_url,
            'icon' => 'bi-display',
            'order' => 79,
        ]
    );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );
    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-signage',
        [
            'title' => 'Signage',
            'summary' => 'Run key-protected looping screen presentations.',
            'group' => 'Plugins',
            'order' => 144,
            'roles' => [ 'admin' ],
            'sections' => [
                [
                    'title' => 'Loops and access',
                    'markdown' => 'Each enabled loop has a launch URL containing its access key. Opening that URL authorizes the display browser and redirects to the clean loop URL. Regenerate the access key when a launch URL should no longer work.',
                ],
                [
                    'title' => 'Slides',
                    'markdown' => 'A loop can contain public static pages, managed image slides, and composed Markdown slides with registered shortcodes. Referenced pages and images must already be public.',
                ],
                [
                    'title' => 'Display limits',
                    'markdown' => 'The first slice supports local image backgrounds with cover, contain, stretch, center, or tile fit; light/dark presentation; timing overrides; and fade or directional slide transitions. Documents, video, sound, and true dissolve transitions are not rendered yet.',
                ],
            ],
        ]
    );

    $escape = static fn( string $value ) : string => htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    $json = static fn( mixed $value ) : string => json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT ) ?: 'null';
    $emit_json = static function( array $payload, int $status = 200 ) use ( $plugins, $json ) : void {
        $plugins->setResponseStatus( $status );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: private, no-store' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        header( 'Referrer-Policy: no-referrer' );
        echo $json( $payload );
    };
    $protected_headers = static function() : void {
        header( 'Cache-Control: private, no-store' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Content-Type-Options: nosniff' );
    };
    $not_found_json = static function() use ( $emit_json ) : void {
        $emit_json( [ 'error' => 'Slide not available.' ], 404 );
    };
    $is_json_request = static function() : bool {
        return( str_contains( strtolower( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) ), 'application/json' ) );
    };
    $require_admin = static function() use ( $plugins, $security, $login_url, $admin_url, $escape ) : bool {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return( false );
        }
        if ( ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            $plugins->renderAdminPage(
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'current_section' => 'signage',
                    'current_location' => 'signage',
                    'plugin_page_kicker' => 'Signage',
                    'plugin_page_title' => 'Forbidden',
                    'plugin_page_summary' => 'Signage loop management is limited to admins.',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You do not have permission to manage signage loops.' ],
                    'plugin_page_body_html' => '<p><a class="btn btn-outline-secondary" href="' . $escape( $admin_url ) . '">Return to dashboard</a></p>',
                ]
            );
            return( false );
        }
        return( true );
    };
    $validate_post = static function( bool $json_response = false ) use ( $plugins, $security, $plugin_url, $emit_json ) : ?array {
        $data = $plugins->getRequestData();
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            if ( $json_response ) {
                $emit_json( [ 'error' => 'Your session token is invalid. Please reload the page and try again.' ], 403 );
                return( null );
            }
            $security->setFlash( 'signage.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            $plugins->redirect( $plugin_url );
            return( null );
        }
        return( $data );
    };
    $options_html = static function( array $options, string $current ) use ( $escape ) : string {
        $html = '';
        foreach ( $options as $value => $label ) {
            $html .= '<option value="' . $escape( (string) $value ) . '"' . ( (string) $value === $current ? ' selected' : '' ) . '>' . $escape( (string) $label ) . '</option>';
        }
        return( $html );
    };
    $fit_options = [ 'cover' => 'Cover', 'contain' => 'Contain', 'stretch' => 'Stretch', 'center' => 'Center', 'tile' => 'Tile' ];
    $scale_control_html = static function( string $id, string $name, int $value, bool $allow_inherit = false, int $inherited_value = 100 ) use ( $escape ) : string {
        $active_value = $allow_inherit && $value <= 0 ? $inherited_value : $value;
        $active_value = max( 60, min( 200, $active_value ) );
        $html = '<div data-tm-signage-scale-control><label class="form-label small mb-1" for="' . $escape( $id . '-number' ) . '">' . ( $allow_inherit ? 'Content scale' : 'Global content scale' ) . '</label>';
        if ( $allow_inherit ) {
            $html .= '<div class="form-check mb-2"><input class="form-check-input" id="' . $escape( $id . '-inherit' ) . '" type="checkbox" data-tm-signage-scale-inherit' . ( $value <= 0 ? ' checked' : '' ) . '><label class="form-check-label small" for="' . $escape( $id . '-inherit' ) . '">Use global default</label></div><input name="' . $escape( $name ) . '" type="hidden" value="' . ( $value <= 0 ? 0 : $active_value ) . '" data-tm-signage-scale-value>';
        }
        $html .= '<input class="form-range" id="' . $escape( $id . '-range' ) . '" aria-label="' . ( $allow_inherit ? 'Content scale slider' : 'Global content scale slider' ) . '" type="range" min="60" max="200" step="5" value="' . $active_value . '" data-tm-signage-scale-range' . ( $allow_inherit && $value <= 0 ? ' disabled' : '' ) . '><div class="input-group"><input class="form-control" id="' . $escape( $id . '-number' ) . '"' . ( $allow_inherit ? '' : ' name="' . $escape( $name ) . '"' ) . ' type="number" min="60" max="200" step="5" value="' . $active_value . '" data-tm-signage-scale-number' . ( $allow_inherit && $value <= 0 ? ' disabled' : '' ) . '><span class="input-group-text">%</span></div>';
        return( $html . ( $allow_inherit ? '<div class="form-text">Applied to ordinary text, headings, and plugin output. Global default: <span data-tm-signage-scale-global-label>' . $inherited_value . '%</span>.</div>' : '<div class="form-text">Applied unless a loop overrides it.</div>' ) . '</div>' );
    };
    $overlay_control_html = static function( array $source, string $field_key ) use ( $escape, $options_html ) : string {
        $legacy = strtolower( trim( (string) ( $source['background_overlay'] ?? 'dark' ) ) );
        $legacy = in_array( $legacy, [ 'none', 'dark', 'light' ], true ) ? $legacy : 'dark';
        $area = strtolower( trim( (string) ( $source['background_overlay_area'] ?? ( $legacy === 'none' ? 'none' : 'full' ) ) ) );
        $area = in_array( $area, [ 'none', 'full', 'content' ], true ) ? $area : 'full';
        $color = strtolower( trim( (string) ( $source['background_overlay_color'] ?? ( $legacy === 'light' ? 'light' : 'dark' ) ) ) );
        $color = in_array( $color, [ 'dark', 'light', 'custom' ], true ) ? $color : 'dark';
        $custom = trim( (string) ( $source['background_overlay_custom_color'] ?? '#000000' ) );
        $custom = preg_match( '/^#[0-9a-fA-F]{6}$/', $custom ) === 1 ? strtoupper( $custom ) : '#000000';
        $strength = max( 0, min( 90, (int) ( $source['background_overlay_strength'] ?? ( $legacy === 'light' ? 54 : 46 ) ) ) );
        $prefix = 'tm-signage-overlay-' . $field_key;
        $html = '<div class="col-12"><div class="row g-3 align-items-start" data-tm-signage-overlay-control>';
        $html .= '<div class="col-6 col-lg-3"><label class="form-label small mb-1" for="' . $escape( $prefix . '-area' ) . '">Overlay area</label><select class="form-select" id="' . $escape( $prefix . '-area' ) . '" name="background_overlay_area">' . $options_html( [ 'none' => 'None', 'full' => 'Full slide', 'content' => 'Content only' ], $area ) . '</select></div>';
        $html .= '<div class="col-6 col-lg-3"><label class="form-label small mb-1" for="' . $escape( $prefix . '-color' ) . '">Overlay color</label><select class="form-select" id="' . $escape( $prefix . '-color' ) . '" name="background_overlay_color">' . $options_html( [ 'dark' => 'Dark', 'light' => 'Light', 'custom' => 'Custom' ], $color ) . '</select></div>';
        $html .= '<div class="col-6 col-lg-3"><label class="form-label small mb-1" for="' . $escape( $prefix . '-custom' ) . '">Custom color</label><input class="form-control font-monospace" id="' . $escape( $prefix . '-custom' ) . '" name="background_overlay_custom_color" type="text" maxlength="7" pattern="#[0-9a-fA-F]{6}" value="' . $escape( $custom ) . '"><div class="form-text">Used when color is Custom.</div></div>';
        $html .= '<div class="col-6 col-lg-3"><label class="form-label small mb-1" for="' . $escape( $prefix . '-strength' ) . '">Overlay strength</label><div class="input-group"><input class="form-control" id="' . $escape( $prefix . '-strength' ) . '" name="background_overlay_strength" type="number" min="0" max="90" step="1" value="' . $strength . '"><span class="input-group-text">%</span></div></div>';
        return( $html . '</div></div>' );
    };
    $tile_style = static function( string $fit, string $url ) use ( $escape ) : string {
        return( $fit === 'tile' && $url !== '' ? ' style="' . $escape( 'background-image:url("' . $url . '")' ) . '"' : '' );
    };
    $overlay_style = static function( array $background ) use ( $escape ) : string {
        $rgb = (string) ( $background['overlay_rgb'] ?? '0 0 0' );
        $opacity = (string) ( $background['overlay_opacity'] ?? '.46' );
        return( ' style="' . $escape( '--tm-signage-overlay-rgb:' . $rgb . ';--tm-signage-overlay-opacity:' . $opacity ) . '"' );
    };
    $media_field_html = static function( string $name, string $field_id, string $selected = '', bool $required = false ) use ( $service, $escape ) : string {
        $selected = trim( $selected );
        $image = $selected !== '' ? $service->resolvePublicImage( $selected ) : null;
        $label = is_array( $image ) ? (string) ( $image['alt_text'] ?: $image['filename'] ?: $selected ) : '';
        $details = is_array( $image ) && (string) ( $image['owner_username'] ?? '' ) !== '' ? ' (' . (string) $image['owner_username'] . ')' : '';
        $status = $label !== '' ? 'Selected: ' . $label . $details : 'No image selected.';
        $html = '<div class="d-grid gap-2" data-tm-signage-media-field><input id="' . $escape( $field_id ) . '" name="' . $escape( $name ) . '" type="hidden" value="' . $escape( $selected ) . '" data-tm-signage-media-input>';
        $html .= '<div class="small text-body-secondary text-truncate" data-tm-signage-media-status title="' . $escape( $status ) . '">' . $escape( $status ) . '</div>';
        $html .= '<div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-secondary btn-sm" type="button" data-tm-signage-media-open><span class="bi bi-images me-1" aria-hidden="true"></span>Choose image</button>';
        $html .= '<button class="btn btn-outline-secondary btn-sm' . ( $selected === '' ? ' d-none' : '' ) . '" type="button" data-tm-signage-media-clear>Clear</button></div>';
        if ( $required ) {
            $html .= '<div class="form-text">An image is required for this slide type.</div>';
        }
        return( $html . '</div>' );
    };
    $page_options_html = static function( string $selected = '', bool $complete = false ) use ( $service, $escape ) : string {
        static $pages = null;
        if ( ! is_array( $pages ) ) {
            $pages = $service->listPublicPageOptions();
        }
        $html = '<option value="">Choose public page</option>';
        foreach ( $pages as $page ) {
            $id = (string) $page['id'];
            if ( ! $complete && $id !== $selected ) {
                continue;
            }
            $html .= '<option value="' . $escape( $id ) . '"' . ( $id === $selected ? ' selected' : '' ) . '>' . $escape( (string) $page['label'] ) . '</option>';
        }
        return( $html );
    };
    $slide_form = static function( array $loop, array $slide, bool $new = false ) use ( $plugins, $slide_save_url, $slide_preview_url, $content_link_targets_url, $security, $escape, $options_html, $fit_options, $media_field_html, $page_options_html, $overlay_control_html ) : string {
        $id = (string) ( $slide['id'] ?? '' );
        $field_key = $id !== '' ? $id : 'new-' . (string) $loop['id'];
        $type = (string) ( $slide['type'] ?? 'composed' );
        $enabled = ! array_key_exists( 'enabled', $slide ) || ! empty( $slide['enabled'] );
        $html = '<form method="post" action="' . $escape( $slide_save_url ) . '" class="d-grid gap-3" data-tm-signage-slide-form data-preview-url="' . $escape( $slide_preview_url ) . '" data-tm-signage-inline-save="' . ( $new ? 'slide-create' : 'slide' ) . '">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="loop_id" value="' . $escape( (string) $loop['id'] ) . '"><input type="hidden" name="slide_id" value="' . $escape( $id ) . '">';
        $html .= '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><div class="small text-body-secondary">Slide content and presentation</div><div class="form-check form-switch"><input class="form-check-input" id="tm-signage-enabled-' . $escape( $field_key ) . '" name="enabled" type="checkbox" value="1"' . ( $enabled ? ' checked' : '' ) . '><label class="form-check-label" for="tm-signage-enabled-' . $escape( $field_key ) . '">Enabled</label></div></div>';
        $html .= '<div class="row g-3">';
        $html .= '<div class="col-12 col-md-3"><label class="form-label small mb-1">Slide type</label><select class="form-select" name="type" data-tm-signage-type>' . $options_html( [ 'composed' => 'Composed', 'page' => 'Published page', 'image' => 'Image only' ], $type ) . '</select></div>';
        $html .= '<div class="col-12 col-md-5"><label class="form-label small mb-1">Internal label / composed title</label><input class="form-control" name="title" type="text" maxlength="160" value="' . $escape( (string) ( $slide['title'] ?? '' ) ) . '"></div>';
        $html .= '<div class="col-6 col-md-2"><label class="form-label small mb-1">Duration</label><div class="input-group"><input class="form-control" name="delay_seconds" type="number" min="0" max="3600" value="' . (int) ( $slide['delay_seconds'] ?? 0 ) . '"><span class="input-group-text">s</span></div></div>';
        $html .= '<div class="col-6 col-md-2"><label class="form-label small mb-1">Transition</label><select class="form-select" name="transition">' . $options_html( [ 'inherit' => 'Inherit', 'none' => 'None', 'fade' => 'Fade', 'slide-left' => 'Slide left', 'slide-right' => 'Slide right', 'slide-up' => 'Slide up', 'slide-down' => 'Slide down' ], (string) ( $slide['transition'] ?? 'inherit' ) ) . '</select></div>';
        $html .= '<div class="col-12 col-lg-6" data-tm-signage-type-panel="page"><label class="form-label small mb-1">Published page</label><select class="form-select" name="page_entry_id" data-tm-signage-options="pages">' . $page_options_html( (string) ( $slide['page_entry_id'] ?? '' ) ) . '</select><div class="form-check mt-2"><input class="form-check-input" name="show_page_title" id="tm-signage-title-' . $escape( $field_key ) . '" type="checkbox" value="1"' . ( ! empty( $slide['show_page_title'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-signage-title-' . $escape( $field_key ) . '">Show page title</label></div></div>';
        $html .= '<div class="col-12 col-lg-6" data-tm-signage-type-panel="image"><label class="form-label small mb-1">Image-only slide image</label>' . $media_field_html( 'image_media_id', 'tm-signage-image-' . $field_key, (string) ( $slide['image_media_id'] ?? '' ), true ) . '</div>';
        $html .= '<div class="col-12" data-tm-signage-type-panel="composed">' . TinyMashMarkdownEditorComponent::render( [ 'field_id' => 'tm-signage-content-' . $field_key, 'field_name' => 'content_markdown', 'label' => 'Composed Markdown and shortcodes', 'content' => (string) ( $slide['content_markdown'] ?? '' ), 'rows' => 8, 'shortcodes' => $plugins->getRegisteredShortcodes(), 'external_links' => true, 'internal_links' => true, 'internal_link_targets_url' => $content_link_targets_url, 'emoji_picker' => true, 'emoji_autocomplete' => true, 'field_attributes' => [ 'data-tm-signage-content' => '1' ] ] ) . '<div class="form-text">Used for Composed slides. Registered shortcode examples are available from the braces menu.</div><div class="mt-2"><button class="btn btn-outline-primary btn-sm" type="button" data-tm-signage-preview><span class="bi bi-eye me-1" aria-hidden="true"></span>Preview composed output</button></div><div class="border rounded bg-body mt-3 p-3 d-none" data-tm-signage-preview-output></div></div>';
        $html .= '<div class="col-12 col-lg-6"><label class="form-label small mb-1">Background override</label>' . $media_field_html( 'background_media_id', 'tm-signage-background-' . $field_key, (string) ( $slide['background_media_id'] ?? '' ) ) . '</div>';
        $html .= '<div class="col-6 col-lg-3"><label class="form-label small mb-1">Background fit</label><select class="form-select" name="background_fit">' . $options_html( $fit_options, (string) ( $slide['background_fit'] ?? 'cover' ) ) . '</select></div>';
        $html .= $overlay_control_html( $slide, 'slide-' . $field_key );
        $html .= '</div><div class="d-flex flex-wrap gap-2 align-items-center justify-content-end"><span class="small text-body-secondary me-auto" data-tm-signage-save-status aria-live="polite"></span><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">' . ( $new ? 'Add slide' : 'Save slide' ) . '</button></div></form>';
        return( $html );
    };
    $loop_form = static function( array $loop = [], bool $new = false, int $global_content_scale = 100 ) use ( $loop_save_url, $security, $escape, $options_html, $fit_options, $media_field_html, $scale_control_html, $overlay_control_html ) : string {
        $id = (string) ( $loop['id'] ?? '' );
        $field_key = $id !== '' ? $id : 'new';
        $html = '<form method="post" action="' . $escape( $loop_save_url ) . '" class="d-grid gap-3" data-tm-signage-inline-save="' . ( $new ? 'loop-create' : 'loop' ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="id" value="' . $escape( $id ) . '"><div class="row g-3">';
        $html .= '<div class="col-12 col-lg-5"><label class="form-label small mb-1">Name</label><input class="form-control" name="name" maxlength="120" value="' . $escape( (string) ( $loop['name'] ?? '' ) ) . '" placeholder="Reception screen" required></div><div class="col-12 col-lg-4"><label class="form-label small mb-1">Slug</label><input class="form-control font-monospace" name="slug" maxlength="120" value="' . $escape( (string) ( $loop['slug'] ?? '' ) ) . '" placeholder="generated-from-name"></div><div class="col-12 col-lg-3"><label class="form-label small mb-1">Mode</label><select class="form-select" name="color_mode">' . $options_html( [ 'dark' => 'Dark', 'light' => 'Light' ], (string) ( $loop['color_mode'] ?? 'dark' ) ) . '</select></div>';
        $html .= '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" id="tm-signage-loop-enabled-' . $escape( $field_key ) . '" name="enabled" type="checkbox" value="1"' . ( ! empty( $loop['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-signage-loop-enabled-' . $escape( $field_key ) . '">Loop enabled</label></div></div>';
        $html .= '<div class="col-6 col-lg-3"><label class="form-label small mb-1">Default duration</label><div class="input-group"><input class="form-control" name="default_delay_seconds" type="number" min="0" max="3600" value=' . (int) ( $loop['default_delay_seconds'] ?? 0 ) . '><span class="input-group-text">s</span></div><div class="form-text">0 uses global.</div></div><div class="col-6 col-lg-3"><label class="form-label small mb-1">Default transition</label><select class="form-select" name="default_transition">' . $options_html( [ 'none' => 'None', 'fade' => 'Fade', 'slide-left' => 'Slide left', 'slide-right' => 'Slide right', 'slide-up' => 'Slide up', 'slide-down' => 'Slide down' ], (string) ( $loop['default_transition'] ?? 'fade' ) ) . '</select></div><div class="col-12 col-lg-6">' . $scale_control_html( 'tm-signage-loop-scale-' . $field_key, 'content_scale_percent', (int) ( $loop['content_scale_percent'] ?? 0 ), true, $global_content_scale ) . '</div><div class="col-12 col-lg-6"><label class="form-label small mb-1">Loop background</label>' . $media_field_html( 'background_media_id', 'tm-signage-loop-background-' . $field_key, (string) ( $loop['background_media_id'] ?? '' ) ) . '</div><div class="col-6 col-lg-3"><label class="form-label small mb-1">Fit</label><select class="form-select" name="background_fit">' . $options_html( $fit_options, (string) ( $loop['background_fit'] ?? 'cover' ) ) . '</select></div>';
        $html .= $overlay_control_html( $loop, 'loop-' . $field_key );
        $html .= '<div class="col-12 col-xl-6"><div class="form-check form-switch"><input class="form-check-input" id="tm-signage-fullscreen-' . $escape( $field_key ) . '" name="prompt_fullscreen_start" type="checkbox" value="1"' . ( ! empty( $loop['prompt_fullscreen_start'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-signage-fullscreen-' . $escape( $field_key ) . '">Prompt to start full-screen</label></div><div class="form-text">Displays must click once to enter full-screen because browsers block automatic full-screen entry.</div></div><div class="col-12 col-xl-6"><div class="form-check form-switch"><input class="form-check-input" id="tm-signage-reduced-motion-' . $escape( $field_key ) . '" name="honor_reduced_motion" type="checkbox" value="1"' . ( $new || ! empty( $loop['honor_reduced_motion'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-signage-reduced-motion-' . $escape( $field_key ) . '">Honor reduced motion preferences</label></div><div class="form-text">When disabled, configured transitions animate even if the display browser requests reduced motion.</div></div></div>';
        $html .= '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-end"><span class="small text-body-secondary me-auto" data-tm-signage-save-status aria-live="polite"></span><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">' . ( $new ? 'Create loop' : 'Save loop' ) . '</button></div></form>';
        return( $html );
    };
    $confirmation_attributes = static function( string $title, string $message, string $submit_label, string $submit_class ) use ( $escape ) : string {
        return( ' data-tm-signage-confirm data-tm-signage-confirm-title="' . $escape( $title ) . '" data-tm-signage-confirm-message="' . $escape( $message ) . '" data-tm-signage-confirm-submit-label="' . $escape( $submit_label ) . '" data-tm-signage-confirm-submit-class="' . $escape( $submit_class ) . '"' );
    };
    $slide_actions_html = static function( string $loop_id, string $slide_id ) use ( $security, $escape, $plugin_url, $slide_move_url, $slide_copy_url, $slide_delete_url, $confirmation_attributes ) : string {
        $edit_url = $plugin_url . '?loop=' . rawurlencode( $loop_id ) . '&edit_slide=' . rawurlencode( $slide_id );
        $html = '<div class="d-flex flex-wrap gap-1 justify-content-end"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $edit_url ) . '" title="Edit slide" data-tm-signage-slide-edit-link><span class="bi bi-pencil" aria-hidden="true"></span></a>';
        foreach ( [ 'up' => 'arrow-up', 'down' => 'arrow-down' ] as $direction => $icon ) {
            $html .= '<form method="post" action="' . $escape( $slide_move_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="loop_id" value="' . $escape( $loop_id ) . '"><input type="hidden" name="slide_id" value="' . $escape( $slide_id ) . '"><input type="hidden" name="direction" value="' . $direction . '"><button class="btn btn-outline-secondary btn-sm" type="submit" title="Move ' . $direction . '"><span class="bi bi-' . $icon . '" aria-hidden="true"></span></button></form>';
        }
        return( $html . '<form method="post" action="' . $escape( $slide_copy_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="loop_id" value="' . $escape( $loop_id ) . '"><input type="hidden" name="slide_id" value="' . $escape( $slide_id ) . '"><button class="btn btn-outline-secondary btn-sm" type="submit" title="Copy slide"><span class="bi bi-copy" aria-hidden="true"></span></button></form><form method="post" action="' . $escape( $slide_delete_url ) . '"' . $confirmation_attributes( 'Delete slide?', 'Delete this slide permanently? Composed content will not be retained.', 'Delete slide', 'btn-danger' ) . '><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="loop_id" value="' . $escape( $loop_id ) . '"><input type="hidden" name="slide_id" value="' . $escape( $slide_id ) . '"><button class="btn btn-outline-danger btn-sm" type="submit" title="Delete slide"><span class="bi bi-trash" aria-hidden="true"></span></button></form></div>' );
    };
    $render_admin = static function( array $notice = [] ) use ( $plugins, $security, $service, $plugin_url, $settings_save_url, $loop_copy_url, $loop_delete_url, $loop_key_url, $media_picker_url, $media_upload_url, $app_url, $escape, $media_field_html, $page_options_html, $slide_form, $slide_actions_html, $loop_form, $scale_control_html, $confirmation_attributes, $config ) : void {
        if ( empty( $notice ) ) {
            $flash = $security->pullFlash( 'signage.notice', [] );
            $notice = is_array( $flash ) ? $flash : [];
        }
        $settings = $service->getSettings();
        $loops = $service->listLoops();
        $query = $plugins->getRequestQueryData();
        $selected_loop_id = trim( (string) ( $query['loop'] ?? '' ) );
        $auto_edit_slide = trim( (string) ( $query['edit_slide'] ?? '' ) );
        $selected_loop = $selected_loop_id !== '' ? $service->getLoop( $selected_loop_id ) : null;
        $auto_edit_slide_record = null;
        $auto_edit_slide_index = 0;
        if ( is_array( $selected_loop ) && $auto_edit_slide !== '' ) {
            foreach ( $selected_loop['slides'] as $index => $slide ) {
                if ( (string) ( $slide['id'] ?? '' ) === $auto_edit_slide ) {
                    $auto_edit_slide_record = $slide;
                    $auto_edit_slide_index = $index;
                    break;
                }
            }
        }
        $body = '<div class="d-grid gap-4">';
        $body .= '<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#tm-signage-loop-new-modal"><span class="bi bi-plus-lg me-1" aria-hidden="true"></span>New loop</button><button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#tm-signage-defaults-modal"><span class="bi bi-sliders me-1" aria-hidden="true"></span>Defaults</button><button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#tm-signage-images-modal"><span class="bi bi-images me-1" aria-hidden="true"></span>Signage images</button></div>';
        $body .= '<section><div class="small text-uppercase text-body-secondary mb-2">Loops</div>';
        if ( empty( $loops ) ) {
            $body .= '<p class="text-body-secondary mb-0">No signage loops have been created.</p>';
        } else {
            $body .= '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th scope="col">Loop</th><th scope="col">Status</th><th scope="col">Slides</th><th class="text-end" scope="col">Actions</th></tr></thead><tbody>';
            foreach ( $loops as $loop ) {
                $loop_id = (string) $loop['id'];
                $loop_launch_url = trim( (string) ( $loop['access_key'] ?? '' ) ) !== '' ? $service->buildLaunchUrl( $loop, $app_url ) : '';
                $loop_name_html = ! empty( $loop['enabled'] ) && $loop_launch_url !== '' ? '<a href="' . $escape( $loop_launch_url ) . '" target="_blank" rel="noreferrer" title="View loop" data-tm-signage-loop-launch>' . $escape( (string) $loop['name'] ) . '</a>' : $escape( (string) $loop['name'] );
                $body .= '<tr' . ( $selected_loop_id === $loop_id ? ' class="table-active"' : '' ) . ' data-tm-signage-loop-row="' . $escape( $loop_id ) . '"><td><div class="fw-semibold" data-tm-signage-loop-name>' . $loop_name_html . '</div><div class="small text-body-secondary font-monospace" data-tm-signage-loop-path>/signage/' . $escape( (string) $loop['slug'] ) . '</div></td><td><span class="badge text-bg-' . ( ! empty( $loop['enabled'] ) ? 'success' : 'secondary' ) . '" data-tm-signage-loop-state>' . ( ! empty( $loop['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</span></td><td>' . count( (array) $loop['slides'] ) . '</td><td><div class="d-flex flex-wrap gap-2 justify-content-end"><a class="btn btn-outline-primary btn-sm" href="' . $escape( $plugin_url . '?loop=' . rawurlencode( $loop_id ) ) . '">Slides</a><button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#tm-signage-loop-edit-' . $escape( $loop_id ) . '" title="Edit loop"><span class="bi bi-pencil" aria-hidden="true"></span></button><form method="post" action="' . $escape( $loop_copy_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="loop_id" value="' . $escape( $loop_id ) . '"><button class="btn btn-outline-secondary btn-sm" type="submit" title="Copy loop"><span class="bi bi-copy" aria-hidden="true"></span></button></form></div></td></tr>';
            }
            $body .= '</tbody></table></div>';
        }
        $body .= '</section>';
        if ( is_array( $selected_loop ) ) {
            $launch_url = $service->buildLaunchUrl( $selected_loop, $app_url );
            $loop_id = (string) $selected_loop['id'];
            $body .= '<section class="border-top pt-4 d-grid gap-3"><div class="d-flex flex-wrap align-items-start justify-content-between gap-3"><div><div class="small text-uppercase text-body-secondary mb-1">Slides</div><h2 class="h5 mb-1" data-tm-signage-selected-loop-name data-tm-signage-selected-loop-id="' . $escape( $loop_id ) . '">' . $escape( (string) $selected_loop['name'] ) . '</h2><div class="small text-body-secondary font-monospace" data-tm-signage-selected-loop-path data-tm-signage-selected-loop-id="' . $escape( $loop_id ) . '">/signage/' . $escape( (string) $selected_loop['slug'] ) . '</div></div><div class="d-flex flex-wrap gap-2"><button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#tm-signage-slide-new-modal"><span class="bi bi-plus-lg me-1" aria-hidden="true"></span>Add slide</button><button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#tm-signage-access-modal"><span class="bi bi-key me-1" aria-hidden="true"></span>Display access</button></div></div>';
            $body .= '<div class="alert alert-warning mb-0' . ( ! empty( $selected_loop['enabled'] ) ? ' d-none' : '' ) . '" role="status" data-tm-signage-disabled-loop-notice data-tm-signage-selected-loop-id="' . $escape( $loop_id ) . '">Loop is disabled and will return Not Found (404) until enabled.</div>';
            if ( empty( $selected_loop['slides'] ) ) {
                $body .= '<p class="text-body-secondary mb-0">This loop has no slides.</p>';
            } else {
                $body .= '<div class="table-responsive d-none d-lg-block"><table class="table table-hover align-middle mb-0"><thead><tr><th scope="col">Slide</th><th scope="col">Type</th><th scope="col">Duration</th><th scope="col">Transition</th><th scope="col">Status</th><th class="text-end" scope="col">Actions</th></tr></thead><tbody>';
                foreach ( $selected_loop['slides'] as $index => $slide ) {
                    $slide_id = (string) $slide['id'];
                    $title = (string) $slide['title'] !== '' ? (string) $slide['title'] : 'Untitled slide';
                    $body .= '<tr id="tm-signage-slide-row-' . $escape( $slide_id ) . '" data-tm-signage-slide-id="' . $escape( $slide_id ) . '"><td><span class="text-body-secondary me-2">' . ( $index + 1 ) . '.</span><span data-tm-signage-slide-row-title>' . $escape( $title ) . '</span></td><td>' . $escape( ucfirst( (string) $slide['type'] ) ) . '</td><td>' . ( (int) $slide['delay_seconds'] > 0 ? (int) $slide['delay_seconds'] . ' s' : 'Inherited' ) . '</td><td>' . $escape( ucfirst( str_replace( '-', ' ', (string) $slide['transition'] ) ) ) . '</td><td><span class="badge text-bg-' . ( ! empty( $slide['enabled'] ) ? 'success' : 'secondary' ) . '">' . ( ! empty( $slide['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</span></td><td>' . $slide_actions_html( $loop_id, $slide_id ) . '</td></tr>';
                }
                $body .= '</tbody></table></div><div class="list-group d-lg-none" data-tm-signage-slide-mobile-list>';
                foreach ( $selected_loop['slides'] as $index => $slide ) {
                    $slide_id = (string) $slide['id'];
                    $title = (string) $slide['title'] !== '' ? (string) $slide['title'] : 'Untitled slide';
                    $duration = (int) $slide['delay_seconds'] > 0 ? (int) $slide['delay_seconds'] . ' s' : 'Inherited duration';
                    $transition = ucfirst( str_replace( '-', ' ', (string) $slide['transition'] ) );
                    $body .= '<div class="list-group-item d-grid gap-2" data-tm-signage-slide-id="' . $escape( $slide_id ) . '"><div class="d-flex align-items-start justify-content-between gap-2"><div class="text-break"><span class="text-body-secondary me-2">' . ( $index + 1 ) . '.</span><span class="fw-semibold" data-tm-signage-slide-row-title>' . $escape( $title ) . '</span></div><span class="badge text-bg-' . ( ! empty( $slide['enabled'] ) ? 'success' : 'secondary' ) . '">' . ( ! empty( $slide['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</span></div><div class="small text-body-secondary">' . $escape( ucfirst( (string) $slide['type'] ) ) . ' <span aria-hidden="true">&middot;</span> ' . $escape( $duration ) . ' <span aria-hidden="true">&middot;</span> ' . $escape( $transition ) . '</div>' . $slide_actions_html( $loop_id, $slide_id ) . '</div>';
                }
                $body .= '</div>';            }
            $body .= '</section>';
            $open_action = '<a class="btn btn-outline-primary' . ( empty( $selected_loop['enabled'] ) ? ' disabled' : '' ) . '" data-tm-signage-loop-open data-tm-signage-selected-loop-id="' . $escape( $loop_id ) . '"' . ( ! empty( $selected_loop['enabled'] ) ? ' href="' . $escape( $launch_url ) . '" target="_blank" rel="noreferrer"' : ' aria-disabled="true" tabindex="-1"' ) . '>Open</a>';
            $disabled_access_notice = '<div class="alert alert-warning' . ( ! empty( $selected_loop['enabled'] ) ? ' d-none' : '' ) . '" data-tm-signage-disabled-loop-notice data-tm-signage-selected-loop-id="' . $escape( $loop_id ) . '">Loop is disabled and will return Not Found (404) until enabled.</div>';
            $body .= '<div class="modal fade" id="tm-signage-access-modal" tabindex="-1" aria-labelledby="tm-signage-access-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="tm-signage-access-modal-title">Display access</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">' . $disabled_access_notice . '<label class="form-label small mb-1">Launch URL</label><div class="input-group mb-3"><input class="form-control font-monospace" readonly value="' . $escape( $launch_url ) . '">' . $open_action . '</div><div class="small text-body-secondary mb-3">This URL contains a bearer access key and may appear in browser history or server logs. Displays retain authorization until the key is regenerated.</div><form method="post" action="' . $escape( $loop_key_url ) . '"' . $confirmation_attributes( 'Regenerate access key?', 'Existing authorized displays will stop working and must be opened with the new launch URL.', 'Regenerate key', 'btn-warning' ) . '><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="loop_id" value="' . $escape( $loop_id ) . '"><button class="btn btn-outline-warning" type="submit"><span class="bi bi-key me-1" aria-hidden="true"></span>Regenerate access key</button></form></div></div></div></div>';
            $body .= '<div class="modal fade" id="tm-signage-slide-new-modal" tabindex="-1" aria-labelledby="tm-signage-slide-new-modal-title" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable modal-fullscreen-lg-down modal-xl"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="tm-signage-slide-new-modal-title">Add slide</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">' . $slide_form( $selected_loop, [ 'enabled' => true, 'type' => 'composed', 'transition' => 'inherit', 'delay_seconds' => 0, 'background_fit' => 'cover', 'background_overlay' => 'dark' ], true ) . '</div></div></div></div>';
            if ( is_array( $auto_edit_slide_record ) ) {
                $slide_id = (string) $auto_edit_slide_record['id'];
                $body .= '<div class="modal fade" id="tm-signage-slide-edit-' . $escape( $slide_id ) . '" tabindex="-1" aria-labelledby="tm-signage-slide-edit-title-' . $escape( $slide_id ) . '" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable modal-fullscreen-lg-down modal-xl"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="tm-signage-slide-edit-title-' . $escape( $slide_id ) . '">Edit slide ' . ( $auto_edit_slide_index + 1 ) . '</h2><a class="btn-close" href="' . $escape( $plugin_url . '?loop=' . rawurlencode( $loop_id ) ) . '" aria-label="Close"></a></div><div class="modal-body">' . $slide_form( $selected_loop, $auto_edit_slide_record ) . '</div></div></div></div>';
            }
        }
        foreach ( $loops as $loop ) {
            $loop_id = (string) $loop['id'];
            $body .= '<div class="modal fade" id="tm-signage-loop-edit-' . $escape( $loop_id ) . '" tabindex="-1" aria-labelledby="tm-signage-loop-edit-title-' . $escape( $loop_id ) . '" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="tm-signage-loop-edit-title-' . $escape( $loop_id ) . '">Edit loop</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">' . $loop_form( $loop, false, (int) $settings['default_content_scale_percent'] ) . '<hr><form method="post" action="' . $escape( $loop_delete_url ) . '"' . $confirmation_attributes( 'Delete loop?', 'Delete this signage loop and all its slides permanently?', 'Delete loop', 'btn-danger' ) . '><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="loop_id" value="' . $escape( $loop_id ) . '"><button class="btn btn-outline-danger" type="submit"><span class="bi bi-trash me-1" aria-hidden="true"></span>Delete loop</button></form></div></div></div></div>';
        }
        $body .= '<div class="modal fade" id="tm-signage-loop-new-modal" tabindex="-1" aria-labelledby="tm-signage-loop-new-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h2 class="modal-title fs-5" id="tm-signage-loop-new-modal-title">New signage loop</h2><div class="form-text">New loops start disabled. Add slides and enable the loop when it is ready for display.</div></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">' . $loop_form( [], true, (int) $settings['default_content_scale_percent'] ) . '</div></div></div></div>';
        $body .= '<div class="modal fade" id="tm-signage-defaults-modal" tabindex="-1" aria-labelledby="tm-signage-defaults-modal-title" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="tm-signage-defaults-modal-title">Signage defaults</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><form method="post" action="' . $escape( $settings_save_url ) . '" class="d-grid gap-3" data-tm-signage-inline-save="settings"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><div><label class="form-label small mb-1">Global default slide duration</label><div class="input-group"><input class="form-control" name="default_delay_seconds" type="number" min="2" max="3600" value="' . (int) $settings['default_delay_seconds'] . '"><span class="input-group-text">seconds</span></div></div>' . $scale_control_html( 'tm-signage-default-scale', 'default_content_scale_percent', (int) $settings['default_content_scale_percent'] ) . '<div class="d-flex gap-2 align-items-center justify-content-end"><span class="small text-body-secondary me-auto" data-tm-signage-save-status aria-live="polite"></span><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save defaults</button></div></form></div></div></div></div>';
        $uploads_enabled = $config->getContentImagesMode() !== 'disabled';
        $body .= '<div class="modal fade" id="tm-signage-images-modal" tabindex="-1" aria-labelledby="tm-signage-images-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="tm-signage-images-modal-title">Signage images</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">';
        if ( $uploads_enabled ) {
            $body .= '<form method="post" enctype="multipart/form-data" action="' . $escape( $media_upload_url ) . '" class="row g-3 align-items-end"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><div class="col-12"><label class="form-label small mb-1">Upload new root image</label><input class="form-control" name="image" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" required></div><div class="col-12"><button class="btn btn-outline-primary" type="submit"><span class="bi bi-upload me-1" aria-hidden="true"></span>Upload image</button></div></form>';
        } else {
            $body .= '<p class="mb-0 text-body-secondary">Image uploads are disabled by the current media policy. Existing public images can still be selected while editing a loop or slide.</p>';
        }
        $body .= '</div></div></div></div>';
        $body .= '<div class="modal fade" id="tm-signage-confirm-modal" tabindex="-1" aria-labelledby="tm-signage-confirm-modal-title" aria-describedby="tm-signage-confirm-modal-message" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="tm-signage-confirm-modal-title">Confirm action</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="mb-0" id="tm-signage-confirm-modal-message">Confirm this action.</p></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="button" data-tm-signage-confirm-submit>Confirm</button></div></div></div></div>';
        $body .= '<template data-tm-signage-options-template="pages">' . $page_options_html( '', true ) . '</template>';
        $body .= '<div class="modal fade" id="tm-signage-media-picker-modal" tabindex="-1" aria-labelledby="tm-signage-media-picker-title" aria-hidden="true" data-picker-url="' . $escape( $media_picker_url ) . '" data-csrf-token="' . $escape( $security->getCsrfToken() ) . '"><div class="modal-dialog modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><div><div class="small text-uppercase text-body-secondary mb-1">Public media</div><h2 class="modal-title fs-5" id="tm-signage-media-picker-title">Choose display image</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body d-grid gap-3"><div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-secondary btn-sm active" type="button" data-tm-signage-media-source="recent">Recent</button><button class="btn btn-outline-secondary btn-sm" type="button" data-tm-signage-media-source="library">Library</button></div><input class="form-control" type="search" placeholder="Search filename or label in this view" data-tm-signage-media-filter><div class="row g-2" data-tm-signage-media-grid><div class="col-12 small text-body-secondary">Choose image to load public media.</div></div></div></div></div></div>';
        if ( is_array( $auto_edit_slide_record ) ) {
            $body .= '<script>document.addEventListener("DOMContentLoaded",function(){var modal=document.getElementById("tm-signage-slide-edit-' . $escape( $auto_edit_slide ) . '");if(modal&&window.bootstrap&&window.bootstrap.Modal){window.bootstrap.Modal.getOrCreateInstance(modal).show();}});</script>';
        }
        $body .= '</div>';
        $body .= <<<'HTML'
<script>
(function () {
    "use strict";
    var templates = {};
    document.querySelectorAll("[data-tm-signage-options-template]").forEach(function (template) {
        templates[template.getAttribute("data-tm-signage-options-template")] = template.innerHTML;
    });
    document.querySelectorAll("[data-tm-signage-options]").forEach(function (select) {
        var populate = function () {
            if (select.getAttribute("data-tm-signage-options-loaded") === "1") { return; }
            var currentValue = select.value;
            var templateHtml = templates[select.getAttribute("data-tm-signage-options")] || "";
            if (!templateHtml) { return; }
            select.innerHTML = templateHtml;
            var firstOption = select.options.length ? select.options[0] : null;
            if (firstOption && select.getAttribute("data-tm-signage-empty-label")) {
                firstOption.textContent = select.getAttribute("data-tm-signage-empty-label");
            }
            select.value = currentValue;
            select.setAttribute("data-tm-signage-options-loaded", "1");
        };
        select.addEventListener("pointerdown", populate, { once: true });
        select.addEventListener("focus", populate, { once: true });
        select.addEventListener("keydown", populate, { once: true });
    });
    var slideFormState = function (form) {
        var values = [];
        new FormData(form).forEach(function (value, key) {
            if (key === "tinymash_csrf") { return; }
            values.push([key, String(value)]);
        });
        values.sort(function (left, right) {
            return (left[0] + "\u0000" + left[1]).localeCompare(right[0] + "\u0000" + right[1]);
        });
        return JSON.stringify(values);
    };
    var markSlideFormClean = function (form) {
        form.setAttribute("data-tm-signage-clean-state", slideFormState(form));
    };
    var isSlideFormDirty = function (form) {
        return form.getAttribute("data-tm-signage-clean-state") !== slideFormState(form);
    };
    var suspendModalDirtyCheck = function (modalElement) {
        if (modalElement) { modalElement.setAttribute("data-tm-signage-dirty-suspend", "1"); }
    };
    var consumeModalDirtySuspension = function (modalElement) {
        if (!modalElement || modalElement.getAttribute("data-tm-signage-dirty-suspend") !== "1") { return false; }
        modalElement.removeAttribute("data-tm-signage-dirty-suspend");
        return true;
    };
    var hideModalElement = function (modalElement) {
        if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) { return; }
        var modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
        var hideStarted = false;
        var markHideStarted = function () { hideStarted = true; };
        modalElement.addEventListener("hide.bs.modal", markHideStarted, { once: true });
        suspendModalDirtyCheck(modalElement);
        modal.hide();
        modalElement.removeEventListener("hide.bs.modal", markHideStarted);
        if (!hideStarted && modalElement.classList.contains("show")) {
            modalElement.addEventListener("shown.bs.modal", function () {
                suspendModalDirtyCheck(modalElement);
                modal.hide();
            }, { once: true });
        }
    };
    window.tinymashSuspendModalDirtyCheck = suspendModalDirtyCheck;
    document.querySelectorAll("[data-tm-signage-scale-control]").forEach(function (control) {
        var range = control.querySelector("[data-tm-signage-scale-range]");
        var number = control.querySelector("[data-tm-signage-scale-number]");
        var storedValue = control.querySelector("[data-tm-signage-scale-value]");
        var inherit = control.querySelector("[data-tm-signage-scale-inherit]");
        var syncValue = function (value) {
            if (range) { range.value = value; }
            if (number) { number.value = value; }
            if (storedValue) { storedValue.value = inherit && inherit.checked ? "0" : value; }
        };
        if (range) {
            range.addEventListener("input", function () { syncValue(range.value); });
        }
        if (number) {
            number.addEventListener("input", function () {
                if (number.value !== "") { syncValue(number.value); }
            });
        }
        if (inherit) {
            inherit.addEventListener("change", function () {
                if (range) { range.disabled = inherit.checked; }
                if (number) { number.disabled = inherit.checked; }
                if (storedValue) { storedValue.value = inherit.checked ? "0" : (number ? number.value : "100"); }
            });
        }
    });
    document.querySelectorAll("[data-tm-signage-slide-form]").forEach(function (form) {
        var typeSelect = form.querySelector("[data-tm-signage-type]");
        var typePanels = form.querySelectorAll("[data-tm-signage-type-panel]");
        var updateTypePanels = function () {
            var type = typeSelect ? typeSelect.value : "composed";
            typePanels.forEach(function (panel) {
                panel.classList.toggle("d-none", panel.getAttribute("data-tm-signage-type-panel") !== type);
            });
        };
        if (typeSelect) {
            typeSelect.addEventListener("change", updateTypePanels);
            updateTypePanels();
        }
        markSlideFormClean(form);
        form.addEventListener("keydown", function (event) {
            if (!(event.ctrlKey || event.metaKey) || event.key !== "Enter") { return; }
            event.preventDefault();
            var submitter = form.querySelector('button[type="submit"]');
            if (form.requestSubmit) {
                form.requestSubmit(submitter || undefined);
            } else {
                form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
            }
        });

        var previewButton = form.querySelector("[data-tm-signage-preview]");
        var output = form.querySelector("[data-tm-signage-preview-output]");
        if (!previewButton || !output) { return; }
        previewButton.addEventListener("click", function () {
            var data = new FormData(form);
            data.set("type", "composed");
            fetch(form.getAttribute("data-preview-url"), {
                method: "POST",
                body: data,
                credentials: "same-origin",
                headers: { "Accept": "application/json" }
            }).then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok) { throw new Error(payload.error || "Preview failed."); }
                    output.innerHTML = payload.html || "";
                    output.classList.remove("d-none");
                });
            }).catch(function (error) {
                output.textContent = error.message || "Preview failed.";
                output.classList.remove("d-none");
            });
        });
    });
    var mediaModalElement = document.getElementById("tm-signage-media-picker-modal");
    var mediaGrid = mediaModalElement ? mediaModalElement.querySelector("[data-tm-signage-media-grid]") : null;
    var mediaFilter = mediaModalElement ? mediaModalElement.querySelector("[data-tm-signage-media-filter]") : null;
    var mediaRecords = {};
    var mediaSource = "recent";
    var activeMediaField = null;
    var returnMediaModalElement = null;
    var escapeHtml = function (value) {
        return String(value || "").replace(/[&<>"']/g, function (character) {
            return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[character];
        });
    };
    var getMediaModal = function () {
        if (!mediaModalElement || !window.bootstrap || !window.bootstrap.Modal) { return null; }
        return window.bootstrap.Modal.getOrCreateInstance(mediaModalElement);
    };
    var renderMediaGrid = function () {
        var records = mediaRecords[mediaSource];
        if (!mediaGrid || !Array.isArray(records)) { return; }
        var needle = mediaFilter ? String(mediaFilter.value || "").trim().toLowerCase() : "";
        var filtered = records.filter(function (record) {
            if (needle === "") { return true; }
            return [ record.alt_text, record.filename, record.media_id, record.owner_username ].join(" ").toLowerCase().includes(needle);
        });
        if (!filtered.length) {
            mediaGrid.innerHTML = '<div class="col-12 small text-body-secondary">No matching public images are available.</div>';
            return;
        }
        mediaGrid.innerHTML = filtered.map(function (record) {
            var title = record.alt_text || record.filename || record.media_id;
            var thumbnail = record.thumbnail_url || record.display_url || record.url;
            var dimensions = Number(record.width) > 0 && Number(record.height) > 0 ? String(record.width) + "x" + String(record.height) : "";
            var detail = [ record.owner_username, dimensions ].filter(Boolean).join(" | ");
            return '<div class="col-12 col-md-6 col-xl-4"><button class="btn text-start border rounded-3 bg-body h-100 w-100 p-2 d-flex gap-2 overflow-hidden" type="button" data-tm-signage-media-choice="' + escapeHtml(record.media_id) + '" data-tm-signage-media-label="' + escapeHtml(title + (record.owner_username ? " (" + record.owner_username + ")" : "")) + '"><img class="rounded-2 object-fit-cover flex-shrink-0" src="' + escapeHtml(thumbnail) + '" alt="" loading="lazy" decoding="async" style="width:4.5rem;height:4.5rem;min-width:4.5rem;"><span class="d-flex flex-column gap-1 flex-grow-1 overflow-hidden" style="min-width:0;"><span class="fw-semibold text-truncate" title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</span><span class="small text-body-secondary text-truncate" title="' + escapeHtml(record.filename || record.media_id) + '">' + escapeHtml(record.filename || record.media_id) + '</span><span class="small text-body-secondary">' + escapeHtml(detail) + '</span></span></button></div>';
        }).join("");
    };
    var loadMediaRecords = function (source) {
        if (!mediaModalElement || Array.isArray(mediaRecords[source])) { renderMediaGrid(); return; }
        mediaRecords[source] = [];
        if (mediaGrid) { mediaGrid.innerHTML = '<div class="col-12 small text-body-secondary">Loading public images...</div>'; }
        var data = new FormData();
        data.set("tinymash_csrf", mediaModalElement.getAttribute("data-csrf-token") || "");
        data.set("source", source);
        fetch(mediaModalElement.getAttribute("data-picker-url") || "", {
            method: "POST",
            body: data,
            credentials: "same-origin",
            headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) { throw new Error(payload.error || "Unable to load images."); }
                return payload;
            });
        }).then(function (payload) {
            mediaRecords[source] = Array.isArray(payload.records) ? payload.records : [];
            renderMediaGrid();
        }).catch(function (error) {
            mediaRecords[source] = [];
            if (mediaGrid) { mediaGrid.innerHTML = '<div class="col-12 text-danger small">' + escapeHtml(error.message || "Unable to load images.") + '</div>'; }
        });
    };
    document.querySelectorAll("[data-tm-signage-media-open]").forEach(function (button) {
        button.addEventListener("click", function () {
            activeMediaField = button.closest("[data-tm-signage-media-field]");
            if (mediaFilter) { mediaFilter.value = ""; }
            mediaSource = "recent";
            document.querySelectorAll("[data-tm-signage-media-source]").forEach(function (sourceButton) {
                sourceButton.classList.toggle("active", sourceButton.getAttribute("data-tm-signage-media-source") === mediaSource);
            });
            loadMediaRecords(mediaSource);
            var modal = getMediaModal();
            var editorModalElement = button.closest(".modal");
            if (!modal) { return; }
            if (editorModalElement && editorModalElement !== mediaModalElement && window.bootstrap && window.bootstrap.Modal) {
                returnMediaModalElement = editorModalElement;
                editorModalElement.addEventListener("hidden.bs.modal", function () { modal.show(); }, { once: true });
                hideModalElement(editorModalElement);
                return;
            }
            modal.show();
        });
    });
    document.querySelectorAll("[data-tm-signage-media-source]").forEach(function (button) {
        button.addEventListener("click", function () {
            mediaSource = button.getAttribute("data-tm-signage-media-source") === "library" ? "library" : "recent";
            document.querySelectorAll("[data-tm-signage-media-source]").forEach(function (sourceButton) {
                sourceButton.classList.toggle("active", sourceButton === button);
            });
            if (mediaFilter) { mediaFilter.value = ""; }
            loadMediaRecords(mediaSource);
        });
    });
    document.querySelectorAll("[data-tm-signage-media-clear]").forEach(function (button) {
        button.addEventListener("click", function () {
            var field = button.closest("[data-tm-signage-media-field]");
            var input = field ? field.querySelector("[data-tm-signage-media-input]") : null;
            var status = field ? field.querySelector("[data-tm-signage-media-status]") : null;
            if (input) { input.value = ""; }
            if (status) { status.textContent = "No image selected."; status.title = "No image selected."; }
            button.classList.add("d-none");
        });
    });
    if (mediaGrid) {
        mediaGrid.addEventListener("click", function (event) {
            var button = event.target instanceof Element ? event.target.closest("[data-tm-signage-media-choice]") : null;
            if (!button || !activeMediaField) { return; }
            var input = activeMediaField.querySelector("[data-tm-signage-media-input]");
            var status = activeMediaField.querySelector("[data-tm-signage-media-status]");
            var clearButton = activeMediaField.querySelector("[data-tm-signage-media-clear]");
            var label = "Selected: " + (button.getAttribute("data-tm-signage-media-label") || "");
            if (input) { input.value = button.getAttribute("data-tm-signage-media-choice") || ""; }
            if (status) { status.textContent = label; status.title = label; }
            if (clearButton) { clearButton.classList.remove("d-none"); }
            var modal = getMediaModal();
            if (modal) { modal.hide(); }
        });
    }
    if (mediaFilter) {
        mediaFilter.addEventListener("input", renderMediaGrid);
    }
    if (mediaModalElement) {
        mediaModalElement.addEventListener("hidden.bs.modal", function () {
            if (!returnMediaModalElement || !window.bootstrap || !window.bootstrap.Modal) { return; }
            var editorModalElement = returnMediaModalElement;
            returnMediaModalElement = null;
            window.bootstrap.Modal.getOrCreateInstance(editorModalElement).show();
        });
    }
    var confirmModalElement = document.getElementById("tm-signage-confirm-modal");
    var confirmTitle = confirmModalElement ? confirmModalElement.querySelector("#tm-signage-confirm-modal-title") : null;
    var confirmMessage = confirmModalElement ? confirmModalElement.querySelector("#tm-signage-confirm-modal-message") : null;
    var confirmSubmit = confirmModalElement ? confirmModalElement.querySelector("[data-tm-signage-confirm-submit]") : null;
    var pendingConfirmForm = null;
    var pendingDiscardModalElement = null;
    var returnConfirmModalElement = null;
    var confirmSubmitting = false;
    var showConfirmModal = function (form) {
        if (!confirmModalElement || !confirmSubmit || !window.bootstrap || !window.bootstrap.Modal) {
            form.submit();
            return;
        }
        pendingConfirmForm = form;
        confirmSubmitting = false;
        if (confirmTitle) { confirmTitle.textContent = form.getAttribute("data-tm-signage-confirm-title") || "Confirm action"; }
        if (confirmMessage) { confirmMessage.textContent = form.getAttribute("data-tm-signage-confirm-message") || "Confirm this action."; }
        confirmSubmit.textContent = form.getAttribute("data-tm-signage-confirm-submit-label") || "Confirm";
        confirmSubmit.className = "btn " + (form.getAttribute("data-tm-signage-confirm-submit-class") || "btn-danger");
        var originModalElement = form.closest(".modal");
        var modal = window.bootstrap.Modal.getOrCreateInstance(confirmModalElement);
        if (originModalElement && originModalElement !== confirmModalElement) {
            returnConfirmModalElement = originModalElement;
            originModalElement.addEventListener("hidden.bs.modal", function () { modal.show(); }, { once: true });
            hideModalElement(originModalElement);
            return;
        }
        returnConfirmModalElement = null;
        modal.show();
    };
    var showDiscardModal = function (originModalElement) {
        if (!confirmModalElement || !confirmSubmit || !window.bootstrap || !window.bootstrap.Modal) { return; }
        pendingConfirmForm = null;
        pendingDiscardModalElement = originModalElement;
        confirmSubmitting = false;
        if (confirmTitle) { confirmTitle.textContent = "Discard changes?"; }
        if (confirmMessage) { confirmMessage.textContent = "Discard unsaved slide changes?"; }
        confirmSubmit.textContent = "Discard changes";
        confirmSubmit.className = "btn btn-danger";
        var modal = window.bootstrap.Modal.getOrCreateInstance(confirmModalElement);
        returnConfirmModalElement = originModalElement;
        originModalElement.addEventListener("hidden.bs.modal", function () { modal.show(); }, { once: true });
        hideModalElement(originModalElement);
    };
    document.querySelectorAll("form[data-tm-signage-confirm]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            event.preventDefault();
            showConfirmModal(form);
        });
    });
    if (confirmSubmit) {
        confirmSubmit.addEventListener("click", function () {
            if (pendingDiscardModalElement) {
                var discardedForm = pendingDiscardModalElement.querySelector("[data-tm-signage-slide-form]");
                if (discardedForm) {
                    discardedForm.reset();
                    discardedForm.querySelectorAll("[data-tm-signage-type]").forEach(function (select) {
                        select.dispatchEvent(new Event("change", { bubbles: true }));
                    });
                    discardedForm.querySelectorAll("[data-tm-signage-preview-output]").forEach(function (output) {
                        output.innerHTML = "";
                        output.classList.add("d-none");
                    });
                    markSlideFormClean(discardedForm);
                }
                pendingDiscardModalElement = null;
                returnConfirmModalElement = null;
                confirmSubmitting = true;
                if (confirmModalElement && window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(confirmModalElement).hide();
                }
                return;
            }
            if (!pendingConfirmForm) { return; }
            var form = pendingConfirmForm;
            pendingConfirmForm = null;
            confirmSubmitting = true;
            form.submit();
        });
    }
    if (confirmModalElement) {
        confirmModalElement.addEventListener("hidden.bs.modal", function () {
            pendingConfirmForm = null;
            pendingDiscardModalElement = null;
            if (confirmSubmitting || !returnConfirmModalElement || !window.bootstrap || !window.bootstrap.Modal) {
                returnConfirmModalElement = null;
                confirmSubmitting = false;
                return;
            }
            var originModalElement = returnConfirmModalElement;
            returnConfirmModalElement = null;
            window.bootstrap.Modal.getOrCreateInstance(originModalElement).show();
        });
    }
    document.querySelectorAll(".modal").forEach(function (modalElement) {
        modalElement.addEventListener("hide.bs.modal", function (event) {
            var form = modalElement.querySelector("[data-tm-signage-slide-form]");
            if (!form || modalElement === confirmModalElement || modalElement === mediaModalElement) { return; }
            if (consumeModalDirtySuspension(modalElement)) { return; }
            if (!isSlideFormDirty(form)) { return; }
            event.preventDefault();
            showDiscardModal(modalElement);
        });
    });
    document.querySelectorAll("[data-tm-signage-inline-save]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            if (!window.fetch || !window.FormData) { return; }
            event.preventDefault();
            var submitter = event.submitter || form.querySelector('button[type="submit"]');
            var status = form.querySelector("[data-tm-signage-save-status]");
            var originalText = submitter ? submitter.textContent : "";
            if (status) {
                status.className = "small text-body-secondary";
                status.textContent = "Saving...";
            }
            if (submitter) {
                submitter.disabled = true;
                submitter.textContent = "Saving";
            }
            fetch(form.action, {
                method: "POST",
                body: new FormData(form),
                credentials: "same-origin",
                headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) {
                    if (!response.ok) { throw payload; }
                    return payload;
                });
            }).then(function (payload) {
                if (form.getAttribute("data-tm-signage-inline-save") === "slide-create" || form.getAttribute("data-tm-signage-inline-save") === "loop-create") {
                    window.location.reload();
                    return;
                }
                if (status) {
                    status.className = "small text-success";
                    status.textContent = payload.message || "Saved.";
                }
                if (form.getAttribute("data-tm-signage-inline-save") === "settings" && payload.settings) {
                    var globalScale = String(payload.settings.default_content_scale_percent || 100);
                    document.querySelectorAll("[data-tm-signage-scale-global-label]").forEach(function (label) {
                        label.textContent = globalScale + "%";
                    });
                    document.querySelectorAll("[data-tm-signage-scale-control]").forEach(function (control) {
                        var inherit = control.querySelector("[data-tm-signage-scale-inherit]");
                        if (!inherit || !inherit.checked) { return; }
                        var range = control.querySelector("[data-tm-signage-scale-range]");
                        var number = control.querySelector("[data-tm-signage-scale-number]");
                        if (range) { range.value = globalScale; }
                        if (number) { number.value = globalScale; }
                    });
                }
                if (form.getAttribute("data-tm-signage-inline-save") === "loop") {
                    var row = payload.loop ? document.querySelector('[data-tm-signage-loop-row="' + payload.loop.id + '"]') : null;
                    var state = row ? row.querySelector("[data-tm-signage-loop-state]") : null;
                    var name = row ? row.querySelector("[data-tm-signage-loop-name]") : null;
                    var launch = row ? row.querySelector("[data-tm-signage-loop-launch]") : null;
                    var path = row ? row.querySelector("[data-tm-signage-loop-path]") : null;
                    if (state && payload.loop) {
                        state.textContent = payload.loop.enabled ? "Enabled" : "Disabled";
                        state.className = "badge text-bg-" + (payload.loop.enabled ? "success" : "secondary");
                    }
                    if (name && payload.loop) {
                        name.textContent = "";
                        if (payload.loop.enabled && payload.loop.launch_url) {
                            launch = document.createElement("a");
                            launch.href = payload.loop.launch_url;
                            launch.target = "_blank";
                            launch.rel = "noreferrer";
                            launch.title = "View loop";
                            launch.setAttribute("data-tm-signage-loop-launch", "");
                            launch.textContent = payload.loop.name || "";
                            name.appendChild(launch);
                        } else {
                            name.textContent = payload.loop.name || "";
                        }
                    }
	                    if (path && payload.loop) { path.textContent = "/signage/" + (payload.loop.slug || ""); }
	                    if (payload.loop) {
	                        document.querySelectorAll('[data-tm-signage-selected-loop-name][data-tm-signage-selected-loop-id="' + payload.loop.id + '"]').forEach(function (heading) {
	                            heading.textContent = payload.loop.name || "";
	                        });
	                        document.querySelectorAll('[data-tm-signage-selected-loop-path][data-tm-signage-selected-loop-id="' + payload.loop.id + '"]').forEach(function (selectedPath) {
	                            selectedPath.textContent = "/signage/" + (payload.loop.slug || "");
	                        });
	                        document.querySelectorAll('[data-tm-signage-disabled-loop-notice][data-tm-signage-selected-loop-id="' + payload.loop.id + '"]').forEach(function (notice) {
	                            notice.classList.toggle("d-none", payload.loop.enabled);
	                        });
                        document.querySelectorAll('[data-tm-signage-loop-open][data-tm-signage-selected-loop-id="' + payload.loop.id + '"]').forEach(function (open) {
                            open.classList.toggle("disabled", !payload.loop.enabled);
                            if (payload.loop.enabled && payload.loop.launch_url) {
                                open.href = payload.loop.launch_url;
                                open.target = "_blank";
                                open.rel = "noreferrer";
                                open.removeAttribute("aria-disabled");
                                open.removeAttribute("tabindex");
                            } else {
                                open.removeAttribute("href");
                                open.removeAttribute("target");
                                open.removeAttribute("rel");
                                open.setAttribute("aria-disabled", "true");
                                open.setAttribute("tabindex", "-1");
	                            }
	                        });
	                    }
	                } else if (form.getAttribute("data-tm-signage-inline-save") === "slide" && payload.slide) {
                    document.querySelectorAll('[data-tm-signage-slide-id="' + payload.slide.id + '"] [data-tm-signage-slide-row-title]').forEach(function (title) {
                        title.textContent = payload.slide.title || "Untitled slide";
                    });
                    var modalElement = form.closest(".modal");
                    if (modalElement && window.bootstrap && window.bootstrap.Modal) {
	                        markSlideFormClean(form);
	                        hideModalElement(modalElement);
	                    }
	                }
	                if ((form.getAttribute("data-tm-signage-inline-save") === "settings" || form.getAttribute("data-tm-signage-inline-save") === "loop") && window.bootstrap && window.bootstrap.Modal) {
	                    var savedModalElement = form.closest(".modal");
	                    if (savedModalElement) {
	                        hideModalElement(savedModalElement);
	                    }
	                }
	            }).catch(function (payload) {
                if (status) {
                    status.className = "small text-danger";
                    status.textContent = payload && payload.error ? payload.error : "The settings could not be saved.";
                }
            }).finally(function () {
                if (submitter) {
                    submitter.disabled = false;
                    submitter.textContent = originalText;
                }
            });
        });
    });
}());
</script>
HTML;
        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Signage',
                'current_section' => 'signage',
                'current_location' => 'signage',
                'help_contexts' => [ 'plugin-signage' ],
                'plugin_page_kicker' => 'Signage',
                'plugin_page_title' => 'Display loops',
                'plugin_page_summary' => 'Compose full-screen looping presentations from public pages, images, and dynamic content.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body,
            ]
        );
    };

    $plugins->registerRoute( $plugin_key, 'get', $plugin_url, static function() use ( $require_admin, $render_admin ) : void {
        if ( $require_admin() ) {
            $render_admin();
        }
    } );
    $plugins->registerRoute( $plugin_key, 'post', $settings_save_url, static function() use ( $require_admin, $validate_post, $service, $security, $plugins, $plugin_url, $emit_json, $is_json_request ) : void {
        $json_response = $is_json_request();
        if ( ! $require_admin() || ( $data = $validate_post( $json_response ) ) === null ) {
            return;
        }
        $settings = $service->saveSettings( $data );
        if ( $json_response ) {
            $emit_json( [ 'ok' => true, 'message' => 'Defaults saved.', 'settings' => $settings ] );
            return;
        }
        $security->setFlash( 'signage.notice', [ 'type' => 'success', 'message' => 'Signage defaults saved.' ] );
        $plugins->redirect( $plugin_url );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $loop_save_url, static function() use ( $require_admin, $validate_post, $service, $security, $plugins, $plugin_url, $app_url, $emit_json, $is_json_request ) : void {
        $json_response = $is_json_request();
        if ( ! $require_admin() || ( $data = $validate_post( $json_response ) ) === null ) {
            return;
        }
        try {
            $loop = $service->saveLoop( $data );
            if ( $json_response ) {
                $emit_json(
                    [
                        'ok' => true,
                        'message' => 'Loop saved.',
                        'loop' => [
                            'id' => (string) $loop['id'],
                            'name' => (string) $loop['name'],
                            'slug' => (string) $loop['slug'],
                            'enabled' => ! empty( $loop['enabled'] ),
                            'launch_url' => $service->buildLaunchUrl( $loop, $app_url ),
                        ],
                    ]
                );
                return;
            }
            $security->setFlash( 'signage.notice', [ 'type' => 'success', 'message' => 'Signage loop saved.' ] );
        } catch ( \InvalidArgumentException $e ) {
            $messages = [ 'name' => 'Enter a loop name.', 'slug' => 'Enter a usable URL slug.', 'slug_conflict' => 'Another signage loop already uses that URL slug.', 'background_media_id' => 'Choose a public image for the loop background.' ];
            $message = $messages[$e->getMessage()] ?? 'The signage loop could not be saved.';
            if ( $json_response ) {
                $emit_json( [ 'error' => $message ], 422 );
                return;
            }
            $security->setFlash( 'signage.notice', [ 'type' => 'danger', 'message' => $message ] );
        }
        $plugins->redirect( isset( $loop['id'] ) ? $plugin_url . '?loop=' . rawurlencode( (string) $loop['id'] ) : $plugin_url );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $loop_key_url, static function() use ( $require_admin, $validate_post, $service, $security, $plugins, $plugin_url ) : void {
        if ( ! $require_admin() || ( $data = $validate_post() ) === null ) {
            return;
        }
        if ( $service->regenerateAccessKey( (string) ( $data['loop_id'] ?? '' ) ) === null ) {
            $security->setFlash( 'signage.notice', [ 'type' => 'danger', 'message' => 'That signage loop was not found.' ] );
        } else {
            $security->setFlash( 'signage.notice', [ 'type' => 'success', 'message' => 'The access key was regenerated. Existing displays must be opened with the new launch URL.' ] );
        }
        $loop_id = trim( (string) ( $data['loop_id'] ?? '' ) );
        $plugins->redirect( $loop_id !== '' ? $plugin_url . '?loop=' . rawurlencode( $loop_id ) : $plugin_url );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $loop_copy_url, static function() use ( $require_admin, $validate_post, $service, $security, $plugins, $plugin_url ) : void {
        if ( ! $require_admin() || ( $data = $validate_post() ) === null ) {
            return;
        }
        $copied = $service->copyLoop( (string) ( $data['loop_id'] ?? '' ) );
        if ( ! is_array( $copied ) ) {
            $security->setFlash( 'signage.notice', [ 'type' => 'danger', 'message' => 'That signage loop could not be copied.' ] );
            $plugins->redirect( $plugin_url );
            return;
        }
        $security->setFlash( 'signage.notice', [ 'type' => 'success', 'message' => 'Signage loop copied. Review its settings and enable it when ready.' ] );
        $plugins->redirect( $plugin_url . '?loop=' . rawurlencode( (string) $copied['id'] ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $loop_delete_url, static function() use ( $require_admin, $validate_post, $service, $security, $plugins, $plugin_url ) : void {
        if ( ! $require_admin() || ( $data = $validate_post() ) === null ) {
            return;
        }
        $service->deleteLoop( (string) ( $data['loop_id'] ?? '' ) );
        $security->setFlash( 'signage.notice', [ 'type' => 'success', 'message' => 'Signage loop deleted.' ] );
        $plugins->redirect( $plugin_url );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $slide_save_url, static function() use ( $require_admin, $validate_post, $service, $security, $plugins, $plugin_url, $emit_json, $is_json_request ) : void {
        $json_response = $is_json_request();
        if ( ! $require_admin() || ( $data = $validate_post( $json_response ) ) === null ) {
            return;
        }
        try {
            $slide = $service->saveSlide( (string) ( $data['loop_id'] ?? '' ), $data );
            if ( $json_response ) {
                $emit_json( [ 'ok' => true, 'message' => 'Slide saved.', 'slide' => [ 'id' => (string) $slide['id'], 'title' => (string) $slide['title'] ] ] );
                return;
            }
            $security->setFlash( 'signage.notice', [ 'type' => 'success', 'message' => 'Signage slide saved.' ] );
        } catch ( \InvalidArgumentException $e ) {
            $messages = [ 'loop' => 'That signage loop no longer exists.', 'page_entry_id' => 'Choose a published public page for this slide.', 'image_media_id' => 'Choose a public image for this slide.', 'background_media_id' => 'Choose a public image for the slide background.', 'composed_content' => 'A composed slide needs a title, Markdown content, or background image.' ];
            $message = $messages[$e->getMessage()] ?? 'The signage slide could not be saved.';
            if ( $json_response ) {
                $emit_json( [ 'error' => $message ], 422 );
                return;
            }
            $security->setFlash( 'signage.notice', [ 'type' => 'danger', 'message' => $message ] );
        }
        $plugins->redirect( $plugin_url . '?loop=' . rawurlencode( (string) ( $data['loop_id'] ?? '' ) ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $slide_preview_url, static function() use ( $require_admin, $plugins, $security, $content_renderer, $theme, $emit_json ) : void {
        if ( ! $require_admin() ) {
            return;
        }
        $data = $plugins->getRequestData();
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $emit_json( [ 'error' => 'Your session token is invalid. Please reload the page and try again.' ], 403 );
            return;
        }
        $rendered = $content_renderer->renderIsolatedMarkdown(
            mb_substr( mb_trim( (string) ( $data['title'] ?? '' ) ), 0, 160 ),
            mb_substr( (string) ( $data['content_markdown'] ?? '' ), 0, 100000 ),
            $theme,
            'signage-preview'
        );
        $html = '<article class="tm-signage-content tm-signage-composed">';
        if ( (string) $rendered['title_html'] !== '' ) {
            $html .= '<h2>' . (string) $rendered['title_html'] . '</h2>';
        }
        $html .= '<div>' . (string) $rendered['content_html'] . '</div></article>';
        $emit_json( [ 'html' => $html ] );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $slide_delete_url, static function() use ( $require_admin, $validate_post, $service, $security, $plugins, $plugin_url ) : void {
        if ( ! $require_admin() || ( $data = $validate_post() ) === null ) {
            return;
        }
        $service->deleteSlide( (string) ( $data['loop_id'] ?? '' ), (string) ( $data['slide_id'] ?? '' ) );
        $security->setFlash( 'signage.notice', [ 'type' => 'success', 'message' => 'Signage slide deleted.' ] );
        $plugins->redirect( $plugin_url . '?loop=' . rawurlencode( (string) ( $data['loop_id'] ?? '' ) ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $slide_copy_url, static function() use ( $require_admin, $validate_post, $service, $security, $plugins, $plugin_url ) : void {
        if ( ! $require_admin() || ( $data = $validate_post() ) === null ) {
            return;
        }
        $loop_id = (string) ( $data['loop_id'] ?? '' );
        $copied = $service->copySlide( $loop_id, (string) ( $data['slide_id'] ?? '' ) );
        if ( ! is_array( $copied ) ) {
            $security->setFlash( 'signage.notice', [ 'type' => 'danger', 'message' => 'That signage slide could not be copied.' ] );
            $plugins->redirect( $plugin_url . '?loop=' . rawurlencode( $loop_id ) );
            return;
        }
        $plugins->redirect( $plugin_url . '?loop=' . rawurlencode( $loop_id ) . '&edit_slide=' . rawurlencode( (string) $copied['id'] ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $slide_move_url, static function() use ( $require_admin, $validate_post, $service, $plugins, $plugin_url ) : void {
        if ( ! $require_admin() || ( $data = $validate_post() ) === null ) {
            return;
        }
        $service->moveSlide( (string) ( $data['loop_id'] ?? '' ), (string) ( $data['slide_id'] ?? '' ), (string) ( $data['direction'] ?? '' ) );
        $plugins->redirect( $plugin_url . '?loop=' . rawurlencode( (string) ( $data['loop_id'] ?? '' ) ) );
    } );
    $plugins->registerRoute( $plugin_key, 'get', $content_link_targets_url, static function() use ( $require_admin, $service, $emit_json ) : void {
        if ( ! $require_admin() ) {
            return;
        }
        $emit_json( [ 'targets' => $service->listPublicContentLinkOptions() ] );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $media_picker_url, static function() use ( $require_admin, $validate_post, $service, $emit_json ) : void {
        if ( ! $require_admin() || ( $data = $validate_post( true ) ) === null ) {
            return;
        }
        $source = strtolower( trim( (string) ( $data['source'] ?? 'recent' ) ) ) === 'library' ? 'library' : 'recent';
        $emit_json( [ 'source' => $source, 'records' => $service->listPublicImages( $source === 'library' ? 500 : 48 ) ] );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $media_upload_url, static function() use ( $require_admin, $validate_post, $service, $media_service, $config, $security, $plugins, $plugin_url ) : void {
        if ( ! $require_admin() || $validate_post() === null ) {
            return;
        }
        try {
            if ( $config->getContentImagesMode() === 'disabled' ) {
                throw new \InvalidArgumentException( 'disabled' );
            }
            if ( empty( $_FILES['image'] ) || ! is_array( $_FILES['image'] ) ) {
                throw new \InvalidArgumentException( 'file' );
            }
            $media_service->storeUploadedImage( $_FILES['image'], 'root', [ 'allowed_mimes' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ] ] );
            $security->setFlash( 'signage.notice', [ 'type' => 'success', 'message' => 'Image uploaded. It is now available in Signage image selectors.' ] );
        } catch ( \Throwable $e ) {
            $security->setFlash( 'signage.notice', [ 'type' => 'danger', 'message' => 'The signage image could not be uploaded. Use JPEG, PNG, GIF, WebP, or AVIF within the configured media limits.' ] );
        }
        $plugins->redirect( $plugin_url );
    } );

    $render_not_found = static function( ?array $loop = null, bool $show_background = false ) use ( $plugins, $service, $protected_headers, $escape, $tile_style, $overlay_style, $project_root ) : void {
        $protected_headers();
        $plugins->setResponseStatus( 404 );
        header( 'Content-Type: text/html; charset=UTF-8' );
        $mode = is_array( $loop ) && (string) ( $loop['color_mode'] ?? '' ) === 'light' ? 'light' : 'dark';
        $background = $show_background && is_array( $loop )
            ? $service->getEffectiveBackground( $loop, [] )
            : [ 'url' => '', 'fit' => 'cover', 'overlay' => 'dark' ];
        $stylesheet_url = '/plugins/signage/signage.css?v=' . (string) @ filemtime( $project_root . '/public/plugins/signage/signage.css' );
        echo '<!doctype html><html lang="en" data-bs-theme="' . $mode . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Not found | Signage</title><link rel="stylesheet" href="/ext/bs/css/bootstrap.min.css"><link rel="stylesheet" href="' . $escape( $stylesheet_url ) . '"></head><body class="tm-signage tm-signage-mode-' . $mode . '"><main class="tm-signage-player tm-signage-not-found" data-tm-signage-not-found><div class="tm-signage-stage">';
        if ( (string) ( $background['url'] ?? '' ) !== '' ) {
            echo '<div class="tm-signage-background tm-signage-fit-' . $escape( (string) $background['fit'] ) . '"' . $tile_style( (string) $background['fit'], (string) $background['url'] ) . '><img src="' . $escape( (string) $background['url'] ) . '" alt=""></div>';
            if ( (string) ( $background['overlay_area'] ?? 'full' ) === 'full' ) {
                echo '<div class="tm-signage-overlay"' . $overlay_style( $background ) . '></div>';
            }
        }
        $panel_class = 'container mt-5 p-5 position-relative' . ( (string) ( $background['overlay_area'] ?? 'none' ) === 'content' ? ' tm-signage-overlay-content-panel' : '' );
        echo '<div class="' . $escape( $panel_class ) . '"' . ( (string) ( $background['overlay_area'] ?? 'none' ) === 'content' ? $overlay_style( $background ) : '' ) . '><h1 class="display-6 fw-semibold mb-3">Not found</h1><p class="lead mb-0">This display is not available.</p></div></div></main></body></html>';
    };
    $render_player = static function( array $loop ) use ( $plugins, $service, $protected_headers, $escape, $json, $project_root ) : void {
        $protected_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );
        $css_urls = [ '/ext/bs/css/bootstrap.min.css', '/ext/bsi/bootstrap-icons.min.css', '/css/tinymash.css', '/plugins/signage/signage.css?v=' . (string) @ filemtime( $project_root . '/public/plugins/signage/signage.css' ) ];
        foreach ( $plugins->getPublicAssetUrls( 'css' ) as $asset_url ) {
            if ( ! str_contains( $asset_url, '/plugins/signage/' ) ) {
                $css_urls[] = $asset_url;
            }
        }
        $js_urls = [ '/ext/bs/js/bootstrap.bundle.min.js' ];
        foreach ( $plugins->getPublicAssetUrls( 'js' ) as $asset_url ) {
            if ( ! str_contains( $asset_url, '/plugins/signage/' ) ) {
                $js_urls[] = $asset_url;
            }
        }
        $js_urls[] = '/plugins/signage/signage.js?v=' . (string) @ filemtime( $project_root . '/public/plugins/signage/signage.js' );
        echo '<!doctype html><html lang="en" data-bs-theme="' . $escape( (string) $loop['color_mode'] ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $escape( (string) $loop['name'] ) . ' | Signage</title>';
        foreach ( array_unique( $css_urls ) as $url ) {
            echo '<link rel="stylesheet" href="' . $escape( $url ) . '">';
        }
        $manifest = $service->buildPlayerManifest( $loop );
        echo '</head><body class="tm-signage tm-signage-mode-' . $escape( (string) $loop['color_mode'] ) . '"><main id="tm-signage-player" class="tm-signage-player" style="--tm-signage-content-scale:' . $escape( (string) ( (int) $manifest['content_scale_percent'] / 100 ) ) . '" data-slide-endpoint="/signage/' . $escape( rawurlencode( (string) $loop['slug'] ) ) . '/slides/" data-manifest-endpoint="/signage/' . $escape( rawurlencode( (string) $loop['slug'] ) ) . '/manifest" data-manifest-hash="' . $escape( (string) $manifest['hash'] ) . '" data-content-scale-percent="' . (int) $manifest['content_scale_percent'] . '" data-slides="' . $escape( $json( $manifest['slides'] ) ) . '" data-fullscreen-start="' . ( ! empty( $loop['prompt_fullscreen_start'] ) ? '1' : '0' ) . '" data-honor-reduced-motion="' . ( ! empty( $loop['honor_reduced_motion'] ) ? '1' : '0' ) . '"><div class="tm-signage-stage" aria-live="polite">';
        if ( empty( $loop['prompt_fullscreen_start'] ) ) {
            echo '<div class="tm-signage-empty"><div class="tm-signage-empty-title">Waiting for slides</div><div class="tm-signage-empty-detail">No available slides in this loop.</div></div>';
        }
        echo '</div>';
        if ( ! empty( $loop['prompt_fullscreen_start'] ) ) {
            echo '<div class="tm-signage-start" data-signage-start-prompt><div class="container text-center"><h1 class="tm-signage-empty-title mb-4">Ready to present</h1><div class="d-flex flex-wrap gap-3 justify-content-center"><button class="btn btn-primary btn-lg" type="button" data-signage-start-fullscreen><span class="bi bi-fullscreen me-2" aria-hidden="true"></span>Start full-screen</button><button class="btn btn-outline-secondary btn-lg" type="button" data-signage-start-window>Continue in window</button></div></div></div>';
        }
        echo '<nav class="tm-signage-controls" aria-label="Playback controls"><button class="btn btn-dark" type="button" data-signage-command="previous" title="Previous slide"><span class="bi bi-chevron-left" aria-hidden="true"></span><span class="visually-hidden">Previous slide</span></button><button class="btn btn-dark" type="button" data-signage-command="toggle" title="Pause playback"><span class="bi bi-pause-fill" aria-hidden="true"></span><span class="visually-hidden">Pause playback</span></button><button class="btn btn-dark" type="button" data-signage-command="next" title="Next slide"><span class="bi bi-chevron-right" aria-hidden="true"></span><span class="visually-hidden">Next slide</span></button><button class="btn btn-dark" type="button" data-signage-command="fullscreen" title="Full screen"><span class="bi bi-fullscreen" aria-hidden="true"></span><span class="visually-hidden">Full screen</span></button></nav></main>';
        foreach ( array_unique( $js_urls ) as $url ) {
            echo '<script src="' . $escape( $url ) . '"></script>';
        }
        echo '</body></html>';
    };
    $render_slide = static function( array $loop, array $slide ) use ( $service, $content_renderer, $theme, $escape, $tile_style ) : ?array {
        $html = '';
        if ( $slide['type'] === 'page' ) {
            $entry = $service->resolvePublicPage( (string) $slide['page_entry_id'] );
            if ( ! is_array( $entry ) ) {
                return( null );
            }
            $rendered = $content_renderer->renderIsolatedEntryContent( $entry, $theme, 'signage' );
            $html = '<article class="tm-signage-content tm-signage-page">';
            if ( ! empty( $slide['show_page_title'] ) && (string) $rendered['title_html'] !== '' ) {
                $html .= '<h1 class="tm-signage-title">' . (string) $rendered['title_html'] . '</h1>';
            }
            $html .= '<div class="tm-signage-body">' . (string) $rendered['content_html'] . '</div></article>';
        } elseif ( $slide['type'] === 'image' ) {
            $image = $service->resolvePublicImage( (string) $slide['image_media_id'] );
            if ( ! is_array( $image ) ) {
                return( null );
            }
            $source = (string) ( $image['display_url'] ?: $image['url'] );
            $fit = (string) ( $slide['background_fit'] ?? 'cover' );
            $html = '<figure class="tm-signage-image-slide tm-signage-fit-' . $escape( $fit ) . '"' . $tile_style( $fit, $source ) . '><img src="' . $escape( $source ) . '" alt="' . $escape( (string) $image['alt_text'] ) . '" decoding="async"></figure>';
        } else {
            $rendered = $content_renderer->renderIsolatedMarkdown( (string) $slide['title'], (string) $slide['content_markdown'], $theme, 'signage' );
            $html = '<article class="tm-signage-content tm-signage-composed">';
            if ( (string) $rendered['title_html'] !== '' ) {
                $html .= '<h1 class="tm-signage-title">' . (string) $rendered['title_html'] . '</h1>';
            }
            $html .= '<div class="tm-signage-body">' . (string) $rendered['content_html'] . '</div></article>';
        }
        return(
            [
                'id' => (string) $slide['id'],
                'html' => $html,
                'delay_seconds' => $service->getEffectiveSlideDelay( $loop, $slide ),
                'transition' => $service->getEffectiveTransition( $loop, $slide ),
                'background' => $service->getEffectiveBackground( $loop, $slide ),
            ]
        );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/signage/@slug',
        static function( string $slug ) use ( $plugins, $service, $render_player, $render_not_found, $protected_headers ) : void {
            $loop = $service->getEnabledLoopBySlug( $slug );
            if ( ! is_array( $loop ) ) {
                $render_not_found();
                return;
            }
            $query = $plugins->getRequestQueryData();
            $key = trim( (string) ( $query['key'] ?? '' ) );
            if ( $key !== '' ) {
                if ( ! $service->grantAccess( $loop, $key ) ) {
                    $render_not_found();
                    return;
                }
                $protected_headers();
                \Flight::response()->status( 303 );
                header( 'Location: /signage/' . rawurlencode( (string) $loop['slug'] ), true, 303 );
                return;
            }
            if ( ! $service->currentVisitorCanAccess( $loop ) ) {
                $render_not_found();
                return;
            }
            if ( empty( $service->buildPlayerSlides( $loop ) ) ) {
                $render_not_found( $loop, true );
                return;
            }
            $render_player( $loop );
        }
    );
    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/signage/@slug/manifest',
        static function( string $slug ) use ( $plugins, $service, $emit_json, $protected_headers ) : void {
            $loop = $service->getEnabledLoopBySlug( $slug );
            if ( ! is_array( $loop ) || ! $service->currentVisitorCanAccess( $loop ) ) {
                $emit_json( [ 'error' => 'Display not available.' ], 404 );
                return;
            }
            $manifest = $service->buildPlayerManifest( $loop );
            $etag = '"' . (string) $manifest['hash'] . '"';
            header( 'ETag: ' . $etag );
            if ( trim( (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) ) === $etag ) {
                $protected_headers();
                $plugins->setResponseStatus( 304 );
                return;
            }
            $emit_json( $manifest );
        }
    );
    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/signage/@slug/slides/@slide_id',
        static function( string $slug, string $slide_id ) use ( $service, $render_slide, $emit_json, $not_found_json ) : void {
            $loop = $service->getEnabledLoopBySlug( $slug );
            if ( ! is_array( $loop ) || ! $service->currentVisitorCanAccess( $loop ) ) {
                $not_found_json();
                return;
            }
            $slide = $service->getSlide( $loop, $slide_id );
            $payload = is_array( $slide ) ? $render_slide( $loop, $slide ) : null;
            if ( ! is_array( $payload ) ) {
                $not_found_json();
                return;
            }
            $emit_json( $payload );
        }
    );
};
