<?php
namespace app\controllers;

if ( empty( TINYMASH_RUNNING ) ) {
    die( 'We do not seem to have all the bits configured' );
}

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashConfigIO;
use app\classes\TinyMashEditorMediaPickerService;
use app\classes\TinyMashMenuService;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;
use app\classes\TinyMashDraftRepository;
use app\classes\TinyMashNotificationService;
use flight\Engine;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashSecurity;
use app\controllers\BaseController;

require_once dirname(__FILE__, 2 ) . '/include/defines.inc.php';
require_once dirname(__FILE__ ) . '/BaseController.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashMarkdownRenderer.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashSecurity.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashConfig.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashContentRepository.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashDraftRepository.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashEditorMediaPickerService.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashMenuService.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashTheme.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashConfigIO.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashUserRepository.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashNotificationService.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashPasswordResetService.php';
require_once dirname(__FILE__, 2 ) . '/classes/TinyMashMailer.php';
require_once dirname(__FILE__ ) . '/AdminAuthConcern.php';
require_once dirname(__FILE__ ) . '/AdminContentConcern.php';
require_once dirname(__FILE__ ) . '/AdminEditorActionConcern.php';
require_once dirname(__FILE__ ) . '/AdminEditorStateConcern.php';
require_once dirname(__FILE__ ) . '/AdminProfileConcern.php';
require_once dirname(__FILE__ ) . '/AdminProfilePublishingConcern.php';
require_once dirname(__FILE__ ) . '/AdminProfileSettingsConcern.php';
require_once dirname(__FILE__ ) . '/AdminSystemConcern.php';
require_once dirname(__FILE__ ) . '/AdminThemeConcern.php';

class AdminController extends BaseController {

    use AdminAuthConcern;
    use AdminContentConcern;
    use AdminEditorActionConcern;
    use AdminEditorStateConcern;
    use AdminProfileConcern;
    use AdminProfilePublishingConcern;
    use AdminProfileSettingsConcern;
    use AdminSystemConcern;
    use AdminThemeConcern;

    protected TinyMashSecurity $security;
    protected TinyMashConfig $config;
    protected ?TinyMashContentRepository $content_repository;
    protected ?TinyMashTheme $theme;
    protected ?TinyMashDraftRepository $draft_repository;
    protected ?TinyMashMarkdownRenderer $markdown_renderer;
    protected ?TinyMashEditorMediaPickerService $editor_media_picker_service = null;
    protected $app;

    public function __construct( $app, $router, $security, $config, ?TinyMashMarkdownRenderer $markdown_renderer = null, ?TinyMashDraftRepository $draft_repository = null, ?TinyMashContentRepository $content_repository = null, ?TinyMashTheme $theme = null ) {
        parent::__construct( $app, $router );
        $this->security = $security;
        $this->config = $config;
        $this->content_repository = $content_repository;
        $this->theme = $theme;
        $this->markdown_renderer = $markdown_renderer;
        $this->draft_repository = $draft_repository;
        $this->app = $app;
    }

    protected function emitServerTimingHeader( array $timings ) : void {
        if ( headers_sent() ) {
            return;
        }

        $parts = [];
        foreach ( $timings as $metric_name => $duration_ms ) {
            $metric_name = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', trim( (string) $metric_name ) ) ?? '' );
            if ( $metric_name === '' || ! is_numeric( $duration_ms ) ) {
                continue;
            }

            $parts[] = $metric_name . ';dur=' . number_format( max( 0, (float) $duration_ms ), 2, '.', '' );
        }

        if ( ! empty( $parts ) ) {
            header( 'Server-Timing: ' . implode( ', ', $parts ) );
        }
    }

    protected function getRequestDataArray() : array {
        $content_type = strtolower( trim( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) ) );
        if ( str_starts_with( $content_type, 'application/json' ) ) {
            $raw_body = file_get_contents( 'php://input' );
            if ( ! is_string( $raw_body ) || trim( $raw_body ) === '' ) {
                return( [] );
            }

            $decoded = json_decode( $raw_body, true );
            return( is_array( $decoded ) ? $decoded : [] );
        }

        $data = $this->app->request()->data->getData();
        return( is_array( $data ) ? $data : [] );
    }

    public function index() : void {
        if ( ! $this->security->isSuperAdmin() ) {
            $this->authorHome();
            return;
        }
        $content_stats = $this->content_repository !== null ? $this->content_repository->getContentStats() : [];
        $system_users = $this->getSystemUsers();
        $this->renderAdmin( 'home',
                       [
                           'title' => APP_NAME . APP_TITLE_SEP . 'Admin',
                           'app_url' => $this->app->get( 'app.url' ),
                           'admin_url' => $this->app->get( 'admin.url' ),
                           'current_role' => $this->security->getCurrentRole(),
                           'current_username' => $this->security->getCurrentUsername(),
                           'is_superadmin' => $this->security->isSuperAdmin(),
                           'content_moderation_required' => $this->config->isContentModerationRequired(),
                           'content_stats' => $content_stats,
                           'system_user_count' => count( $system_users ),
                           'system_settings' => $this->config->getSystemSettings(),
                           'login_security_summary' => $this->getLoginSecuritySummaryViewData(),
                           'current_section' => 'overview',
                           'current_location' => 'dashboard',
                           'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                           'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                           'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
                           'entries_url' => $this->app->get( 'admin.url' ) . '/content',
                           'editor_url' => $this->app->get( 'admin.url' ) . '/editor',
                           'help_contexts' => [ 'admin-overview' ],
                       ]
        );
    }

    public function authorHome() : void {
        $this->app->redirect( $this->app->get( 'admin.url' ) . '/content' );
    }

    public function about() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        $admin_theme_info = $this->getSystemAdminThemeInfo();
        $this->renderAdmin(
            'about',
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'About',
                'app_url' => $this->app->get( 'app.url' ),
                'admin_url' => $this->app->get( 'admin.url' ),
                'current_role' => $this->security->getCurrentRole(),
                'current_username' => $this->security->getCurrentUsername(),
                'is_superadmin' => $this->security->isSuperAdmin(),
                'current_section' => 'about',
                'current_location' => 'about',
                'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
                'entries_url' => $this->app->get( 'admin.url' ) . '/content',
                'help_contexts' => [ 'admin-global' ],
                'about_info' => [
                    'app_name' => APP_NAME,
                    'app_version' => APP_VERSION,
                    'license' => 'AGPL-3.0-or-later',
                    'site_name' => $this->config->getSiteName(),
                    'base_url' => (string) $this->config->configGetBaseURL(),
                    'public_theme' => $this->theme !== null ? $this->theme->getThemeName() : 'Unknown',
                    'public_theme_key' => $this->config->getPublicThemeKey(),
                    'admin_theme' => (string) ( $admin_theme_info['name'] ?? 'Baseline Admin' ),
                    'admin_theme_key' => (string) ( $admin_theme_info['key'] ?? 'baseline' ),
                    'php_version' => PHP_VERSION,
                    'extension_roots' => [
                        [
                            'label' => 'First-party plugins',
                            'path' => 'app/plugins',
                        ],
                        [
                            'label' => 'First-party themes',
                            'path' => 'app/themes',
                        ],
                        [
                            'label' => 'Third-party plugins',
                            'path' => 'plugins',
                        ],
                        [
                            'label' => 'Third-party themes',
                            'path' => 'themes',
                        ],
                    ],
                    'core_packages' => [
                        'flightphp/core',
                        'latte/latte',
                        'league/commonmark',
                        'symfony/mailer',
                    ],
                ],
            ]
        );
    }

    public function help() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->json( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }

        if ( ! $this->app->has( 'help.catalog' ) ) {
            $this->json( [ 'error' => 'Help catalog is not available.' ], 500 );
            return;
        }

        $data = $this->app->request()->query->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        $contexts = $this->filterHelpContextsByRole( $this->normalizeHelpContexts( (string) ( $data['contexts'] ?? 'admin-global' ) ) );
        $locale = (string) ( $data['locale'] ?? 'en' );
        $catalog = $this->app->get( 'help.catalog' );
        if ( ! is_object( $catalog ) || ! method_exists( $catalog, 'getContexts' ) || ! method_exists( $catalog, 'getIndex' ) ) {
            $this->json( [ 'error' => 'Help catalog is not available.' ], 500 );
            return;
        }

        $documents = $catalog->getContexts( $contexts, $locale );
        $index = $this->filterHelpIndexByRole( $catalog->getIndex( $locale ) );
        $this->json(
            [
                'contexts' => $contexts,
                'documents' => $documents,
                'index' => $index,
                'html' => $this->renderHelpHtml( $documents, $index, $contexts ),
            ]
        );
    }

    protected function formatUtcDateTime( string $utc_datetime ) : string {
        $utc_datetime = trim( $utc_datetime );
        if ( $utc_datetime === '' ) {
            return( '' );
        }

        try {
            if ( $this->app->has( 'date.formatter' ) ) {
                $formatter = $this->app->get( 'date.formatter' );
                if ( is_object( $formatter ) && method_exists( $formatter, 'formatUtcDateTime' ) ) {
                    return( (string) $formatter->formatUtcDateTime( $utc_datetime ) );
                }
            }
        } catch ( \Throwable $e ) {
            return( $utc_datetime );
        }

        return( $utc_datetime );
    }

    protected function resolveSystemSiteImageUpload( array $current_image, string $field_key, array $allowed_mimes ) : array {
        $current_image = $this->normalizeSystemSiteImageValue( $current_image );
        if ( ! empty( $_POST[$field_key . '_clear'] ) ) {
            $current_image = [];
        }

        $selected_media_id = trim( (string) ( $_POST[$field_key . '_media_id'] ?? '' ) );
        if ( $selected_media_id !== '' && empty( $_POST[$field_key . '_clear'] ) ) {
            $selected_image = $this->resolveSystemSiteImageSelection( $selected_media_id, $allowed_mimes );
            if ( ! empty( $selected_image ) ) {
                $current_image = $selected_image;
            }
        }

        $file_key = $field_key . '_file';
        if ( empty( $_FILES[$file_key] ) || ! is_array( $_FILES[$file_key] ) ) {
            return( $current_image );
        }

        $file = $_FILES[$file_key];
        $upload_error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
        if ( $upload_error === UPLOAD_ERR_NO_FILE ) {
            return( $current_image );
        }
        if ( $upload_error !== UPLOAD_ERR_OK ) {
            throw new \InvalidArgumentException( 'site_identity_image_upload' );
        }

        if ( ! $this->app->has( 'media.service' ) ) {
            throw new \InvalidArgumentException( 'site_identity_image_upload' );
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'storeUploadedImage' ) ) {
            throw new \InvalidArgumentException( 'site_identity_image_upload' );
        }

        try {
            $uploaded_image = $media_service->storeUploadedImage(
                $file,
                'root',
                [
                    'allowed_mimes' => $allowed_mimes,
                    'sanitize_svg' => in_array( 'image/svg+xml', $allowed_mimes, true ),
                ]
            );
        } catch ( \InvalidArgumentException $e ) {
            $message = (string) $e->getMessage();
            if ( in_array( $message, [ 'content_image_type', 'content_image_upload', 'content_image_file' ], true ) ) {
                throw new \InvalidArgumentException( $message === 'content_image_type' ? 'site_identity_image_type' : 'site_identity_image_upload' );
            }
            throw $e;
        }

        $mime = strtolower( trim( (string) ( $uploaded_image['mime'] ?? '' ) ) );
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            throw new \InvalidArgumentException( 'site_identity_image_type' );
        }

        return( $this->normalizeSystemSiteImageValue( $uploaded_image ) );
    }

    protected function resolveSystemSiteImageSelection( string $media_id, array $allowed_mimes ) : array {
        $media_id = trim( $media_id );
        if ( $media_id === '' || ! $this->app->has( 'media.service' ) ) {
            return( [] );
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'getAttachmentMetadataByMediaId' ) ) {
            throw new \InvalidArgumentException( 'site_identity_image_upload' );
        }

        $metadata = $media_service->getAttachmentMetadataByMediaId( $media_id, [ 'root' ] );
        if ( ! is_array( $metadata ) ) {
            throw new \InvalidArgumentException( 'site_identity_image_upload' );
        }

        $mime = strtolower( trim( (string) ( $metadata['mime'] ?? '' ) ) );
        if ( ! str_starts_with( $mime, 'image/' ) || ! in_array( $mime, $allowed_mimes, true ) ) {
            throw new \InvalidArgumentException( 'site_identity_image_type' );
        }

        return( $this->normalizeSystemSiteImageValue( $metadata ) );
    }

    protected function normalizeSystemSiteImageValue( array $image ) : array {
        $media_id = trim( (string) ( $image['media_id'] ?? '' ) );
        $url = trim( (string) ( $image['url'] ?? '' ) );
        if ( $media_id === '' || $url === '' ) {
            return( [] );
        }

        return(
            [
                'media_id' => $media_id,
                'owner_username' => strtolower( trim( (string) ( $image['owner_username'] ?? '' ) ) ),
                'filename' => basename( trim( (string) ( $image['filename'] ?? '' ) ) ),
                'url' => $url,
                'alt_text' => trim( (string) ( $image['alt_text'] ?? '' ) ),
                'mime' => trim( (string) ( $image['mime'] ?? '' ) ),
                'width' => max( 0, (int) ( $image['width'] ?? 0 ) ),
                'height' => max( 0, (int) ( $image['height'] ?? 0 ) ),
                'bytes' => max( 0, (int) ( $image['bytes'] ?? 0 ) ),
                'derivative_key' => trim( (string) ( $image['derivative_key'] ?? 'stored_primary' ) ),
            ]
        );
    }

    protected function normalizeHelpContexts( string $contexts ) : array {
        $items = [];
        foreach ( explode( ',', $contexts ) as $context ) {
            $context = strtolower( trim( $context ) );
            $context = preg_replace( '/[^a-z0-9-]/', '', $context ) ?? '';
            if ( $context === '' || in_array( $context, $items, true ) ) {
                continue;
            }
            $items[] = $context;
        }

        if ( empty( $items ) ) {
            $items[] = 'admin-global';
        }

        return( $items );
    }

    protected function renderHelpHtml( array $documents, array $index = [], array $contexts = [] ) : string {
        if ( empty( $documents ) ) {
            return( '<div class="alert alert-secondary mb-0" role="alert">No help is available for this section yet.</div>' );
        }

        $current_contexts = array_values(
            array_filter(
                array_map( static fn( mixed $context ) : string => strtolower( trim( (string) $context ) ), $contexts ),
                static fn( string $context ) : bool => $context !== ''
            )
        );
        $current_contexts = array_values( array_unique( $current_contexts ) );

        $html = '<div class="row g-4 tm-help-modal-grid">';
        $html .= '<div class="col-12 col-xl-4 d-flex">';
        $html .= '<section class="border rounded-3 p-3 bg-body position-xl-sticky tm-help-index-panel w-100" style="top: 1rem;">';
        $html .= '<div class="d-flex align-items-center justify-content-between gap-2 mb-2">';
        $html .= '<div class="small text-uppercase text-body-secondary fw-semibold mb-0">Table of contents</div>';
        $html .= '<button class="btn btn-outline-secondary btn-sm d-xl-none" type="button" data-bs-toggle="collapse" data-bs-target="#tm-help-index-collapse" aria-expanded="false" aria-controls="tm-help-index-collapse" title="Toggle table of contents" aria-label="Toggle table of contents">';
        $html .= '<i class="bi bi-list" aria-hidden="true"></i>';
        $html .= '</button>';
        $html .= '</div>';
        $html .= '<nav id="tm-help-index-collapse" class="collapse d-xl-block tm-help-index" aria-label="Table of contents">';
        $html .= '<div class="d-grid gap-2 tm-help-index-scroll">';
        $current_group = '';
        foreach ( $index as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $context = strtolower( trim( (string) ( $item['context'] ?? '' ) ) );
            if ( $context === '' ) {
                continue;
            }

             $group = trim( (string) ( $item['group'] ?? 'Core' ) );
            if ( $group === '' ) {
                $group = 'Core';
            }
            if ( $group !== $current_group ) {
                if ( $current_group !== '' ) {
                    $html .= '<div class="border-top my-2"></div>';
                }
                if ( strtolower( $group ) !== 'core' ) {
                    $html .= '<div class="small text-uppercase text-body-secondary fw-semibold mt-1">' . htmlspecialchars( $group, ENT_QUOTES, 'UTF-8' ) . '</div>';
                }
                $current_group = $group;
            }

            $topic_contexts = $this->buildHelpTopicContexts( $context );
            $is_active = count( array_diff( $topic_contexts, $current_contexts ) ) === 0 && count( array_diff( $current_contexts, $topic_contexts ) ) === 0;
            $html .= '<a href="#" class="text-start text-decoration-none tm-help-index-link' . ( $is_active ? ' active' : '' ) . '" data-help-contexts="' . htmlspecialchars( implode( ',', $topic_contexts ), ENT_QUOTES, 'UTF-8' ) . '">';
            $html .= htmlspecialchars( (string) ( $item['title'] ?? $context ), ENT_QUOTES, 'UTF-8' );
            $html .= '</a>';
        }
        $html .= '</div>';
        $html .= '</nav>';
        $html .= '</section>';
        $html .= '</div>';
        $html .= '<div class="col-12 col-xl-8 d-flex">';
        $html .= '<div class="d-grid gap-4 tm-help-documents-scroll w-100">';
        foreach ( $documents as $document ) {
            if ( ! is_array( $document ) ) {
                continue;
            }

            $title = htmlspecialchars( (string) ( $document['title'] ?? 'Help' ), ENT_QUOTES, 'UTF-8' );
            $summary = '';
            if ( ! empty( $document['summary'] ) && $this->markdown_renderer !== null ) {
                $summary = $this->markdown_renderer->render( (string) $document['summary'] );
            }

            $html .= '<section class="border rounded-3 p-3 bg-body">';
            $html .= '<h3 class="h5 mb-3">' . $title . '</h3>';
            if ( $summary !== '' ) {
                $html .= '<div class="tm-help-content mb-3">' . $summary . '</div>';
            }
            if ( strtolower( trim( (string) ( $document['context'] ?? '' ) ) ) === 'admin-editor' ) {
                $html .= '<div class="mb-3">';
                $html .= '<a href="#" class="btn btn-outline-secondary btn-sm" data-help-contexts="markdown-reference">Open Markdown reference</a>';
                $html .= '</div>';
            }

            foreach ( $document['sections'] ?? [] as $section ) {
                if ( ! is_array( $section ) ) {
                    continue;
                }

                $section_title = trim( (string) ( $section['title'] ?? '' ) );
                $section_markdown = trim( (string) ( $section['markdown'] ?? '' ) );
                if ( $section_title === '' && $section_markdown === '' ) {
                    continue;
                }

                $html .= '<div class="tm-help-section border-top pt-3 mt-3">';
                if ( $section_title !== '' ) {
                    $html .= '<h4 class="h6 mb-2">' . htmlspecialchars( $section_title, ENT_QUOTES, 'UTF-8' ) . '</h4>';
                }
                if ( $section_markdown !== '' && $this->markdown_renderer !== null ) {
                    $html .= '<div class="tm-help-content">' . $this->markdown_renderer->render( $section_markdown ) . '</div>';
                }
                $html .= '</div>';
            }

            $html .= '</section>';
        }
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return( $html );
    }

    protected function buildHelpTopicContexts( string $context ) : array {
        $context = strtolower( trim( $context ) );
        if ( $context === '' || $context === 'admin-global' ) {
            return( [ 'admin-global' ] );
        }

        return( [ $context ] );
    }

    protected function filterHelpContextsByRole( array $contexts ) : array {
        if ( $this->security->isSuperAdmin() ) {
            return( $contexts );
        }

        $help_catalog = $this->app->has( 'help.catalog' ) ? $this->app->get( 'help.catalog' ) : null;

        $filtered_contexts = array_values(
            array_filter(
                array_map( static fn( mixed $context ) : string => strtolower( trim( (string) $context ) ), $contexts ),
                static function( string $context ) use ( $help_catalog ) : bool {
                    return( $help_catalog instanceof \app\classes\TinyMashHelpCatalog
                        && $help_catalog->isContextVisibleToRole( $context, 'author' ) );
                }
            )
        );

        if ( empty( $filtered_contexts ) ) {
            return( [ 'admin-content' ] );
        }

        return( array_values( array_unique( $filtered_contexts ) ) );
    }

    protected function filterHelpIndexByRole( array $index ) : array {
        if ( $this->security->isSuperAdmin() ) {
            return( $index );
        }

        $help_catalog = $this->app->has( 'help.catalog' ) ? $this->app->get( 'help.catalog' ) : null;

        return(
            array_values(
                array_filter(
                    $index,
                    static function( mixed $item ) use ( $help_catalog ) : bool {
                        if ( ! is_array( $item ) ) {
                            return( false );
                        }

                        $context = strtolower( trim( (string) ( $item['context'] ?? '' ) ) );
                        return( $help_catalog instanceof \app\classes\TinyMashHelpCatalog
                            && $help_catalog->isContextVisibleToRole( $context, 'author' ) );
                    }
                )
            )
        );
    }

    protected function isValidCsrfSubmission( array $data ) : bool {
        return( $this->security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) );
    }

    protected function getSystemRegisteredPlugins() : array {
        $plugins = $this->getPluginsService();
        if ( $plugins === null || ! method_exists( $plugins, 'getRegisteredPlugins' ) ) {
            return( [] );
        }

        $registered_plugins = $plugins->getRegisteredPlugins();
        return( is_array( $registered_plugins ) ? $registered_plugins : [] );
    }

    protected function getSystemRegisteredPluginByKey( string $plugin_key ) : array {
        $plugin_key = $this->normalizePluginKey( $plugin_key );
        if ( $plugin_key === '' ) {
            return( [] );
        }

        foreach ( $this->getSystemRegisteredPlugins() as $plugin ) {
            if ( ! is_array( $plugin ) ) {
                continue;
            }
            if ( (string) ( $plugin['key'] ?? '' ) === $plugin_key ) {
                return( $plugin );
            }
        }

        return( [] );
    }

    protected function getSystemRegisteredPluginCapabilities() : array {
        $plugins = $this->getPluginsService();
        if ( $plugins === null || ! method_exists( $plugins, 'getRegisteredPluginCapabilities' ) ) {
            return( [] );
        }

        $registered_capabilities = $plugins->getRegisteredPluginCapabilities();
        return( is_array( $registered_capabilities ) ? $registered_capabilities : [] );
    }

    protected function normalizeSystemSettingsGroup( string $group ) : string {
        $group = strtolower( trim( $group ) );
        return( in_array( $group, [ 'site', 'security', 'content_media', 'media', 'locale', 'menus', 'themes', 'plugins', 'moderation', 'smtp', 'notifications' ], true ) ? $group : 'site' );
    }

    protected function validateSystemSettings( array $data, string $settings_group = 'site' ) : array {
        $settings_group = $this->normalizeSystemSettingsGroup( $settings_group );
        if ( $settings_group === 'themes' ) {
            return( $this->validateThemeSettings( $data ) );
        }
        if ( $settings_group === 'menus' ) {
            return( $this->validateMenusSettings( $data ) );
        }
        if ( $settings_group === 'plugins' ) {
            return( $this->validateSystemPluginSettings( $data ) );
        }
        if ( $settings_group === 'moderation' ) {
            return(
                [
                    'require_moderation' => ! empty( $data['require_moderation'] ),
                ]
            );
        }

        if ( $settings_group === 'locale' ) {
            $default_language = TinyMashConfig::normalizeHtmlLanguageTag( (string) ( $data['default_language'] ?? 'en' ) );

            $time_format = trim( (string) ( $data['time_format'] ?? '' ) );
            if ( $time_format === '' ) {
                throw new \InvalidArgumentException( 'time_format' );
            }

            $timezone = trim( (string) ( $data['timezone'] ?? '' ) );
            if ( $timezone === '' ) {
                throw new \InvalidArgumentException( 'timezone' );
            }

            try {
                new \DateTimeZone( $timezone );
            } catch ( \Throwable $e ) {
                throw new \InvalidArgumentException( 'timezone' );
            }

            $week_starts_on = strtolower( trim( (string) ( $data['week_starts_on'] ?? 'monday' ) ) );
            if ( ! in_array( $week_starts_on, [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ], true ) ) {
                throw new \InvalidArgumentException( 'week_starts_on' );
            }

            return(
                [
                    'default_language' => $default_language,
                    'time_format' => mb_substr( $time_format, 0, 80 ),
                    'timezone' => $timezone,
                    'week_starts_on' => $week_starts_on,
                ]
            );
        }

        if ( $settings_group === 'smtp' ) {
            $smtp_enabled = ! empty( $data['smtp_enabled'] );
            $host = trim( (string) ( $data['smtp_host'] ?? '' ) );
            $port = (int) ( $data['smtp_port'] ?? 587 );
            $username = trim( (string) ( $data['smtp_username'] ?? '' ) );
            $password = (string) ( $data['smtp_password'] ?? '' );
            $encryption = strtolower( trim( (string) ( $data['smtp_encryption'] ?? 'tls' ) ) );
            $from_email = trim( (string) ( $data['smtp_from_email'] ?? '' ) );
            $from_name = trim( (string) ( $data['smtp_from_name'] ?? '' ) );
            $reply_to_email = trim( (string) ( $data['smtp_reply_to_email'] ?? '' ) );

            if ( $host !== '' ) {
                $host = preg_replace( '/[^a-z0-9._-]/i', '', $host ) ?? '';
            }
            if ( $smtp_enabled && $host === '' ) {
                throw new \InvalidArgumentException( 'smtp_host' );
            }
            if ( $port < 1 || $port > 65535 ) {
                throw new \InvalidArgumentException( 'smtp_port' );
            }
            if ( ! in_array( $encryption, [ 'none', 'ssl', 'tls' ], true ) ) {
                throw new \InvalidArgumentException( 'smtp_encryption' );
            }
            if ( $from_email !== '' && filter_var( $from_email, FILTER_VALIDATE_EMAIL ) === false ) {
                throw new \InvalidArgumentException( 'smtp_from_email' );
            }
            if ( $reply_to_email !== '' && filter_var( $reply_to_email, FILTER_VALIDATE_EMAIL ) === false ) {
                throw new \InvalidArgumentException( 'smtp_reply_to_email' );
            }

            return(
                [
                    'smtp_enabled' => $smtp_enabled,
                    'smtp_host' => mb_substr( $host, 0, 200 ),
                    'smtp_port' => $port,
                    'smtp_username' => mb_substr( $username, 0, 200 ),
                    'smtp_password' => $password,
                    'smtp_encryption' => $encryption,
                    'smtp_from_email' => mb_substr( $from_email, 0, 200 ),
                    'smtp_from_name' => mb_substr( $from_name, 0, 200 ),
                    'smtp_reply_to_email' => mb_substr( $reply_to_email, 0, 200 ),
                ]
            );
        }

        if ( $settings_group === 'notifications' ) {
            $notification_retention_days = (int) ( $data['notification_retention_days'] ?? 30 );
            if ( $notification_retention_days < 7 || $notification_retention_days > 60 ) {
                throw new \InvalidArgumentException( 'notification_retention_days' );
            }

            return(
                [
                    'notification_retention_days' => $notification_retention_days,
                    'notification_email_events' => [
                        'moderation_required' => ! empty( $data['notification_email_events']['moderation_required'] ),
                        'profile_email_changed' => ! empty( $data['notification_email_events']['profile_email_changed'] ),
                        'user_lockout' => ! empty( $data['notification_email_events']['user_lockout'] ),
                        'component_updates' => ! empty( $data['notification_email_events']['component_updates'] ),
                    ],
                ]
            );
        }

        if ( $settings_group === 'security' ) {
            $secret_link_default_expiry_days = (int) ( $data['secret_link_default_expiry_days'] ?? 60 );
            if ( $secret_link_default_expiry_days < 1 || $secret_link_default_expiry_days > 365 ) {
                throw new \InvalidArgumentException( 'secret_link_default_expiry_days' );
            }
            $password_reset_throttle_window_minutes = (int) ( $data['password_reset_throttle_window_minutes'] ?? 60 );
            if ( $password_reset_throttle_window_minutes < 1 || $password_reset_throttle_window_minutes > 1440 ) {
                throw new \InvalidArgumentException( 'password_reset_throttle_window_minutes' );
            }
            $password_reset_max_ip_requests = (int) ( $data['password_reset_max_ip_requests'] ?? 5 );
            if ( $password_reset_max_ip_requests < 1 || $password_reset_max_ip_requests > 100 ) {
                throw new \InvalidArgumentException( 'password_reset_max_ip_requests' );
            }
            $password_reset_max_identifier_requests = (int) ( $data['password_reset_max_identifier_requests'] ?? 3 );
            if ( $password_reset_max_identifier_requests < 1 || $password_reset_max_identifier_requests > 100 ) {
                throw new \InvalidArgumentException( 'password_reset_max_identifier_requests' );
            }
            $filesystem_umask = tinymash_normalize_umask( (string) ( $data['filesystem_umask'] ?? '0007' ), '' );
            if ( $filesystem_umask === '' ) {
                throw new \InvalidArgumentException( 'filesystem_umask' );
            }
            $housekeeping_web_fallback_mode = strtolower( trim( (string) ( $data['housekeeping_web_fallback_mode'] ?? 'auto' ) ) );
            if ( ! in_array( $housekeeping_web_fallback_mode, [ 'auto', 'off' ], true ) ) {
                throw new \InvalidArgumentException( 'housekeeping_web_fallback_mode' );
            }
            $forwarded_ip_mode = strtolower( trim( (string) ( $data['forwarded_ip_mode'] ?? 'off' ) ) );
            if ( ! in_array( $forwarded_ip_mode, [ 'off', 'cf-connecting-ip', 'x-forwarded-for-first' ], true ) ) {
                throw new \InvalidArgumentException( 'forwarded_ip_mode' );
            }
            $public_cache_warm_basic_auth_username = trim( (string) ( $data['public_cache_warm_basic_auth_username'] ?? '' ) );
            if ( mb_strlen( $public_cache_warm_basic_auth_username ) > 190 || str_contains( $public_cache_warm_basic_auth_username, ':' ) ) {
                throw new \InvalidArgumentException( 'public_cache_warm_basic_auth_username' );
            }
            $public_cache_warm_basic_auth_password = (string) ( $data['public_cache_warm_basic_auth_password'] ?? '' );

            return(
                [
                    'site_public' => ! empty( $data['site_public'] ),
                    'allow_registrations' => ! empty( $data['allow_registrations'] ),
                    'maintenance' => ! empty( $data['maintenance'] ),
                    'allow_admin_password_resets' => ! empty( $data['allow_admin_password_resets'] ),
                    'allow_author_password_resets' => ! empty( $data['allow_author_password_resets'] ),
                    'allow_username_change' => ! empty( $data['allow_username_change'] ),
                    'allow_email_change' => ! empty( $data['allow_email_change'] ),
                    'allow_secret_links' => ! empty( $data['allow_secret_links'] ),
                    'discourage_search_indexing' => ! empty( $data['discourage_search_indexing'] ),
                    'secret_link_default_expiry_days' => $secret_link_default_expiry_days,
                    'password_reset_throttle_window_minutes' => $password_reset_throttle_window_minutes,
                    'password_reset_max_ip_requests' => $password_reset_max_ip_requests,
                    'password_reset_max_identifier_requests' => $password_reset_max_identifier_requests,
                    'filesystem_umask' => $filesystem_umask,
                    'forwarded_ip_mode' => $forwarded_ip_mode,
                    'public_cache_warm_basic_auth_username' => $public_cache_warm_basic_auth_username,
                    'public_cache_warm_basic_auth_password' => $public_cache_warm_basic_auth_password,
                    'public_cache_warm_insecure_tls' => ! empty( $data['public_cache_warm_insecure_tls'] ),
                    'housekeeping_web_fallback_mode' => $housekeeping_web_fallback_mode,
                ]
            );
        }

        if ( $settings_group === 'content_media' ) {
            $content_revision_retention_limit = (int) ( $data['content_revision_retention_limit'] ?? 20 );
            if ( $content_revision_retention_limit < 0 || $content_revision_retention_limit > 100 ) {
                throw new \InvalidArgumentException( 'content_revision_retention_limit' );
            }
            $content_trash_retention_days = (int) ( $data['content_trash_retention_days'] ?? 30 );
            if ( $content_trash_retention_days < 0 || $content_trash_retention_days > 90 ) {
                throw new \InvalidArgumentException( 'content_trash_retention_days' );
            }
            $housekeeping_stale_draft_retention_days = (int) ( $data['housekeeping_stale_draft_retention_days'] ?? 0 );
            if ( $housekeeping_stale_draft_retention_days < 0 || $housekeeping_stale_draft_retention_days > 365 ) {
                throw new \InvalidArgumentException( 'housekeeping_stale_draft_retention_days' );
            }
            $editor_autosave_interval_seconds = (int) ( $data['editor_autosave_interval_seconds'] ?? 120 );
            if ( $editor_autosave_interval_seconds < 30 || $editor_autosave_interval_seconds > 180 ) {
                throw new \InvalidArgumentException( 'editor_autosave_interval_seconds' );
            }

            $editor_classic_smileys_enabled = ! empty( $data['editor_classic_smileys_enabled'] );
            $unknown_shortcode_mode = strtolower( trim( (string) ( $data['unknown_shortcode_mode'] ?? 'code' ) ) );
            if ( ! in_array( $unknown_shortcode_mode, [ 'code', 'hide' ], true ) ) {
                throw new \InvalidArgumentException( 'unknown_shortcode_mode' );
            }

            return(
                [
                    'content_tags_enabled' => ! empty( $data['content_tags_enabled'] ),
                    'content_show_page_timestamps' => ! empty( $data['content_show_page_timestamps'] ),
                    'content_revision_retention_limit' => $content_revision_retention_limit,
                    'content_trash_retention_days' => $content_trash_retention_days,
                    'housekeeping_stale_draft_retention_days' => $housekeeping_stale_draft_retention_days,
                    'rendered_content_cache_enabled' => ! empty( $data['rendered_content_cache_enabled'] ),
                    'unknown_shortcode_mode' => $unknown_shortcode_mode,
                    'editor_autosave_enabled' => ! empty( $data['editor_autosave_enabled'] ),
                    'editor_autosave_interval_seconds' => $editor_autosave_interval_seconds,
                    'editor_classic_smileys_enabled' => $editor_classic_smileys_enabled,
                ]
            );
        }

        if ( $settings_group === 'media' ) {
            $content_images_mode = strtolower( trim( (string) ( $data['content_images_mode'] ?? 'authenticated' ) ) );
            if ( ! in_array( $content_images_mode, [ 'disabled', 'admins_only', 'authenticated' ], true ) ) {
                throw new \InvalidArgumentException( 'content_images_mode' );
            }
            $content_image_driver = strtolower( trim( (string) ( $data['content_image_driver'] ?? 'auto' ) ) );
            if ( ! in_array( $content_image_driver, [ 'auto', 'imagick', 'gd', 'none' ], true ) ) {
                throw new \InvalidArgumentException( 'content_image_driver' );
            }
            $content_image_max_width = (int) ( $data['content_image_max_width'] ?? 2560 );
            if ( $content_image_max_width < 256 || $content_image_max_width > 8192 ) {
                throw new \InvalidArgumentException( 'content_image_max_width' );
            }
            $content_image_max_height = (int) ( $data['content_image_max_height'] ?? 2560 );
            if ( $content_image_max_height < 256 || $content_image_max_height > 8192 ) {
                throw new \InvalidArgumentException( 'content_image_max_height' );
            }
            $content_image_max_upload_mb = (int) ( $data['content_image_max_upload_mb'] ?? 10 );
            if ( $content_image_max_upload_mb < 1 || $content_image_max_upload_mb > 200 ) {
                throw new \InvalidArgumentException( 'content_image_max_upload_mb' );
            }
            $content_image_allowed_mimes = $this->normalizeSelectedContentImageMimes( $data['content_image_allowed_mimes'] ?? [] );
            if ( empty( $content_image_allowed_mimes ) ) {
                throw new \InvalidArgumentException( 'content_image_allowed_mimes' );
            }
            $content_media_metadata_retention_groups = $this->normalizeSelectedMediaMetadataGroups( $data['content_media_metadata_retention_groups'] ?? [] );
            $content_media_metadata_public_groups = $this->normalizeSelectedMediaMetadataGroups( $data['content_media_metadata_public_groups'] ?? [] );
            if ( ! empty( $content_media_metadata_public_groups ) ) {
                $content_media_metadata_public_groups = array_values( array_intersect( $content_media_metadata_public_groups, $content_media_metadata_retention_groups ) );
            }
            $content_thumbnail_max_width = (int) ( $data['content_thumbnail_max_width'] ?? 320 );
            if ( $content_thumbnail_max_width < 64 || $content_thumbnail_max_width > 2048 ) {
                throw new \InvalidArgumentException( 'content_thumbnail_max_width' );
            }
            $content_thumbnail_max_height = (int) ( $data['content_thumbnail_max_height'] ?? 320 );
            if ( $content_thumbnail_max_height < 64 || $content_thumbnail_max_height > 2048 ) {
                throw new \InvalidArgumentException( 'content_thumbnail_max_height' );
            }

            return(
                [
                    'content_images_mode' => $content_images_mode,
                    'content_image_driver' => $content_image_driver,
                    'content_image_max_width' => $content_image_max_width,
                    'content_image_max_height' => $content_image_max_height,
                    'content_image_max_upload_mb' => $content_image_max_upload_mb,
                    'content_image_allowed_mimes' => $content_image_allowed_mimes,
                    'content_media_metadata_retention_groups' => $content_media_metadata_retention_groups,
                    'content_media_metadata_public_groups' => $content_media_metadata_public_groups,
                    'content_generate_thumbnails' => ! empty( $data['content_generate_thumbnails'] ),
                    'content_thumbnail_max_width' => $content_thumbnail_max_width,
                    'content_thumbnail_max_height' => $content_thumbnail_max_height,
                ]
            );
        }

        $site_name = mb_trim( (string) ( $data['site_name'] ?? '' ) );
        if ( $site_name === '' ) {
            throw new \InvalidArgumentException( 'site_name' );
        }

        $site_slogan = mb_trim( (string) ( $data['site_slogan'] ?? '' ) );
        $login_message = mb_trim( (string) ( $data['login_message'] ?? '' ) );

        $admin_email = trim( (string) ( $data['admin_email'] ?? '' ) );
        if ( $admin_email !== '' && filter_var( $admin_email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'admin_email' );
        }
        $system_notifications_email = trim( (string) ( $data['system_notifications_email'] ?? '' ) );
        if ( $system_notifications_email !== '' && filter_var( $system_notifications_email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'system_notifications_email' );
        }
        $site_background_render_mode = $this->validateBackgroundRenderMode( (string) ( $data['site_background_render_mode'] ?? 'scaled' ), 'site_background_render_mode' );
        $site_banner_image = $this->resolveSystemSiteImageUpload( $this->config->getSiteBannerImage(), 'site_banner_image', [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml' ] );
        $site_favicon_png_image = $this->resolveSystemSiteImageUpload( $this->config->getSiteFaviconPngImage(), 'site_favicon_png_image', [ 'image/png' ] );
        $site_favicon_ico_image = $this->resolveSystemSiteImageUpload( $this->config->getSiteFaviconIcoImage(), 'site_favicon_ico_image', [ 'image/x-icon', 'image/vnd.microsoft.icon' ] );
        $site_og_image = $this->resolveSystemSiteImageUpload( $this->config->getSiteOgImage(), 'site_og_image', [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml' ] );
        $site_background_image = $this->resolveSystemSiteImageUpload( $this->config->getSiteBackgroundImage(), 'site_background_image', [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml' ] );

        return(
            [
                'site_name' => mb_substr( $site_name, 0, 200 ),
                'site_slogan' => mb_substr( $site_slogan, 0, 240 ),
                'admin_email' => mb_substr( $admin_email, 0, 200 ),
                'system_notifications_email' => mb_substr( $system_notifications_email, 0, 200 ),
                'login_message' => mb_substr( $login_message, 0, 2000 ),
                'site_banner_image' => $site_banner_image,
                'site_favicon_png_image' => $site_favicon_png_image,
                'site_favicon_ico_image' => $site_favicon_ico_image,
                'site_og_image' => $site_og_image,
                'site_background_image' => $site_background_image,
                'site_background_render_mode' => $site_background_render_mode,
            ]
        );
    }

    protected function getContentImageMimeOptions() : array {
        return(
            [
                [ 'value' => 'image/jpeg', 'label' => 'JPEG', 'description' => 'Photos and general-purpose uploads' ],
                [ 'value' => 'image/png', 'label' => 'PNG', 'description' => 'Transparency and lossless graphics' ],
                [ 'value' => 'image/gif', 'label' => 'GIF', 'description' => 'Simple graphics and imported animated GIFs' ],
                [ 'value' => 'image/webp', 'label' => 'WebP', 'description' => 'Modern compressed web images' ],
                [ 'value' => 'image/avif', 'label' => 'AVIF', 'description' => 'Modern high-efficiency web images' ],
            ]
        );
    }

    protected function getMediaMetadataGroupOptions() : array {
        return(
            [
                [ 'value' => 'camera_info', 'label' => 'Camera', 'description' => 'Make, model, lens, and similar camera details.' ],
                [ 'value' => 'exposure_info', 'label' => 'Exposure', 'description' => 'Aperture, shutter speed, ISO, focal length, and related capture settings.' ],
                [ 'value' => 'date_time_info', 'label' => 'Date/time', 'description' => 'Date taken and related capture timestamps.' ],
                [ 'value' => 'location_info', 'label' => 'Location', 'description' => 'GPS coordinates and place-derived location data.' ],
                [ 'value' => 'personal_rights_info', 'label' => 'Personal/rights', 'description' => 'Creator, photographer, author, copyright, credit, and contact fields.' ],
            ]
        );
    }

    protected function normalizeSelectedContentImageMimes( mixed $value ) : array {
        $selected = is_array( $value ) ? $value : [];
        $allowed = array_column( $this->getContentImageMimeOptions(), 'value' );
        $normalized = [];
        foreach ( $selected as $mime ) {
            if ( ! is_string( $mime ) ) {
                continue;
            }

            $mime = strtolower( trim( $mime ) );
            if ( $mime === '' || ! in_array( $mime, $allowed, true ) || in_array( $mime, $normalized, true ) ) {
                continue;
            }

            $normalized[] = $mime;
        }

        return( $normalized );
    }

    protected function normalizeSelectedMediaMetadataGroups( mixed $value ) : array {
        $selected = is_array( $value ) ? $value : [];
        $allowed = array_column( $this->getMediaMetadataGroupOptions(), 'value' );
        $normalized = [];
        foreach ( $selected as $group ) {
            if ( ! is_string( $group ) ) {
                continue;
            }

            $group = strtolower( trim( $group ) );
            if ( $group === '' || ! in_array( $group, $allowed, true ) || in_array( $group, $normalized, true ) ) {
                continue;
            }

            $normalized[] = $group;
        }

        return( $normalized );
    }

    protected function getPluginsService() : ?object {
        if ( ! $this->app->has( 'plugins' ) ) {
            return( null );
        }

        $plugins = $this->app->get( 'plugins' );
        return( is_object( $plugins ) ? $plugins : null );
    }

    protected function queueFediverseEntrySync( ?array $previous_entry, array $saved_entry, string $actor_username = '' ) : void {
        if ( ! $this->app->has( 'plugin.fediverse.service' ) ) {
            return;
        }

        $fediverse_service = $this->app->get( 'plugin.fediverse.service' );
        if ( ! is_object( $fediverse_service ) || ! method_exists( $fediverse_service, 'queueEntrySync' ) ) {
            return;
        }

        $root_settings = [];
        $plugins = $this->getPluginsService();
        if ( is_object( $plugins ) && method_exists( $plugins, 'getPluginSystemSettings' ) ) {
            $root_settings = $plugins->getPluginSystemSettings( 'fediverse' );
            if ( ! is_array( $root_settings ) ) {
                $root_settings = [];
            }
        }

        try {
            $fediverse_service->queueEntrySync( $previous_entry, $saved_entry, $root_settings, $actor_username );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to queue Fediverse delivery (' . $e->getMessage() . ')' );
        }
    }

    protected function queueFediverseEntryRemoval( array $previous_entry, string $actor_username = '' ) : void {
        if ( ! $this->app->has( 'plugin.fediverse.service' ) ) {
            return;
        }

        $fediverse_service = $this->app->get( 'plugin.fediverse.service' );
        if ( ! is_object( $fediverse_service ) || ! method_exists( $fediverse_service, 'queueEntryRemoval' ) ) {
            return;
        }

        $root_settings = [];
        $plugins = $this->getPluginsService();
        if ( is_object( $plugins ) && method_exists( $plugins, 'getPluginSystemSettings' ) ) {
            $root_settings = $plugins->getPluginSystemSettings( 'fediverse' );
            if ( ! is_array( $root_settings ) ) {
                $root_settings = [];
            }
        }

        try {
            $fediverse_service->queueEntryRemoval( $previous_entry, $root_settings, $actor_username );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to queue Fediverse removal (' . $e->getMessage() . ')' );
        }
    }

    protected function getSecretLinkService() : ?object {
        if ( ! $this->app->has( 'secret_links' ) ) {
            return( null );
        }

        $secret_link_service = $this->app->get( 'secret_links' );
        return( is_object( $secret_link_service ) ? $secret_link_service : null );
    }

    protected function normalizePluginKey( string $plugin_key ) : string {
        $plugin_key = strtolower( trim( $plugin_key ) );
        $plugin_key = preg_replace( '/[^a-z0-9_-]/', '', $plugin_key ) ?? '';
        return(
            match ( $plugin_key ) {
                'visitor-context' => 'footer',
                default => $plugin_key,
            }
        );
    }

    protected function normalizePluginSettingsMap( mixed $settings ) : array {
        if ( ! is_array( $settings ) ) {
            return( [] );
        }

        $normalized = [];
        foreach ( $settings as $plugin_key => $plugin_settings ) {
            if ( ! is_string( $plugin_key ) || ! is_array( $plugin_settings ) ) {
                continue;
            }

            $normalized_plugin_key = $this->normalizePluginKey( $plugin_key );
            if ( $normalized_plugin_key === '' ) {
                continue;
            }

            $normalized[$normalized_plugin_key] = [];
            foreach ( $plugin_settings as $setting_key => $setting_value ) {
                if ( ! is_string( $setting_key ) ) {
                    continue;
                }
                $normalized_setting_key = $this->normalizePluginKey( $setting_key );
                if ( $normalized_setting_key === '' ) {
                    continue;
                }
                $normalized[$normalized_plugin_key][$normalized_setting_key] = $setting_value;
            }
        }

        return( $normalized );
    }

    protected function mergePluginSettingsMaps( array $existing_settings, array $incoming_settings ) : array {
        $merged = $this->normalizePluginSettingsMap( $existing_settings );
        foreach ( $this->normalizePluginSettingsMap( $incoming_settings ) as $plugin_key => $plugin_settings ) {
            if ( ! isset( $merged[$plugin_key] ) || ! is_array( $merged[$plugin_key] ) ) {
                $merged[$plugin_key] = [];
            }
            foreach ( $plugin_settings as $setting_key => $setting_value ) {
                $merged[$plugin_key][$setting_key] = $setting_value;
            }
        }

        return( $merged );
    }

    protected function getProfilePluginSettingsSections( string $section, array $form_state ) : array {
        $profile_section = $this->normalizeProfileSection( $section );
        if ( ! in_array( $profile_section, [ 'publishing', 'fediverse' ], true ) ) {
            return( [] );
        }

        $plugins = $this->getPluginsService();
        if ( $plugins === null || ! method_exists( $plugins, 'getProfileSettingsSections' ) ) {
            return( [] );
        }

        $definitions = $plugins->getProfileSettingsSections( $profile_section );
        return( $this->attachPluginSettingsSectionValues( is_array( $definitions ) ? $definitions : [], $form_state['plugin_settings'] ?? [] ) );
    }

    protected function getSystemPluginSettingsSections() : array {
        $plugins = $this->getPluginsService();
        if ( $plugins === null || ! method_exists( $plugins, 'getSystemSettingsSections' ) || ! method_exists( $plugins, 'getPluginSystemSettings' ) ) {
            return( [] );
        }

        $definitions = is_array( $plugins->getSystemSettingsSections() ) ? $plugins->getSystemSettingsSections() : [];
        $attached_definitions = [];
        foreach ( $definitions as $definition ) {
            if ( ! is_array( $definition ) ) {
                continue;
            }

            $plugin_key = (string) ( $definition['plugin_key'] ?? '' );
            $attached_definition = $this->attachPluginSettingsSectionValues( [ $definition ], [ $plugin_key => $plugins->getPluginSystemSettings( $plugin_key ) ] );
            if ( is_array( $attached_definition[0] ?? null ) ) {
                $attached_definitions[] = $attached_definition[0];
            }
        }

        return( $attached_definitions );
    }

    protected function getSystemPluginSettingsSectionByKey( string $plugin_key ) : array {
        $definitions = $this->getSystemPluginSettingsSections();
        $requested_plugin_key = $this->normalizePluginKey( $plugin_key );
        if ( $requested_plugin_key === '' ) {
            return( [] );
        }

        foreach ( $definitions as $definition ) {
            if ( ! is_array( $definition ) ) {
                continue;
            }
            if ( (string) ( $definition['plugin_key'] ?? '' ) === $requested_plugin_key ) {
                return( $definition );
            }
        }

        return( [] );
    }

    protected function getSystemPluginSettingsUrl( string $plugin_key ) : string {
        $plugin_key = $this->normalizePluginKey( $plugin_key );
        if ( $plugin_key === '' ) {
            return( $this->getSystemSectionUrl( 'plugins' ) );
        }

        return( $this->app->get( 'admin.url' ) . '/system/plugins/' . rawurlencode( $plugin_key ) );
    }

    protected function getSystemPluginSettingsUrls() : array {
        $urls = [];
        foreach ( $this->getSystemPluginSettingsSections() as $definition ) {
            if ( ! is_array( $definition ) || empty( $definition['plugin_key'] ) ) {
                continue;
            }

            $plugin_key = (string) $definition['plugin_key'];
            $urls[$plugin_key] = $this->getSystemPluginSettingsUrl( $plugin_key );
        }

        $plugins = $this->getPluginsService();
        if ( $plugins !== null && method_exists( $plugins, 'getAdminConfigurationUrls' ) ) {
            foreach ( (array) $plugins->getAdminConfigurationUrls() as $plugin_key => $url ) {
                $plugin_key = $this->normalizePluginKey( (string) $plugin_key );
                $url = trim( (string) $url );
                if ( $plugin_key !== '' && $url !== '' ) {
                    $urls[$plugin_key] = $url;
                }
            }
        }

        return( $urls );
    }

    protected function attachPluginSettingsSectionValues( array $definitions, mixed $form_settings ) : array {
        $plugin_settings = $this->normalizePluginSettingsMap( $form_settings );
        $attached_definitions = [];
        foreach ( $definitions as $definition ) {
            if ( ! is_array( $definition ) ) {
                continue;
            }

            $plugin_key = (string) ( $definition['plugin_key'] ?? '' );
            $current_values = [];
            foreach ( (array) ( $definition['fields'] ?? [] ) as $field_definition ) {
                if ( ! is_array( $field_definition ) || empty( $field_definition['key'] ) ) {
                    continue;
                }

                $field_key = (string) $field_definition['key'];
                $current_values[$field_key] = $plugin_settings[$plugin_key][$field_key] ?? ( $field_definition['default'] ?? '' );
            }
            $definition['values'] = $current_values;
            $attached_definitions[] = $definition;
        }

        return( $attached_definitions );
    }

    protected function validateProfilePluginSettings( array $data, array $current_user, string $section = 'publishing' ) : array {
        $plugins = $this->getPluginsService();
        if ( $plugins === null || ! method_exists( $plugins, 'getProfileSettingsSections' ) ) {
            return( is_array( $current_user['plugin_settings'] ?? null ) ? $current_user['plugin_settings'] : [] );
        }

        $profile_section = $this->normalizeProfileSection( $section );
        if ( ! in_array( $profile_section, [ 'publishing', 'fediverse' ], true ) ) {
            return( is_array( $current_user['plugin_settings'] ?? null ) ? $current_user['plugin_settings'] : [] );
        }

        $validated_settings = is_array( $current_user['plugin_settings'] ?? null ) ? $current_user['plugin_settings'] : [];
        $incoming_settings = $this->normalizePluginSettingsMap( $data['plugin_settings'] ?? [] );

        foreach ( (array) $plugins->getProfileSettingsSections( $profile_section ) as $definition ) {
            if ( ! is_array( $definition ) || empty( $definition['plugin_key'] ) ) {
                continue;
            }

            $plugin_key = (string) $definition['plugin_key'];
            $validated_settings[$plugin_key] = $this->validatePluginSettingsSectionDefinition(
                $definition,
                $incoming_settings[$plugin_key] ?? [],
                is_array( $validated_settings[$plugin_key] ?? null ) ? $validated_settings[$plugin_key] : []
            );
        }

        return( $this->normalizePluginSettingsMap( $validated_settings ) );
    }

    protected function validateSystemPluginSettings( array $data ) : array {
        $plugins = $this->getPluginsService();
        if ( $plugins === null || ! method_exists( $plugins, 'getSystemSettingsSections' ) || ! method_exists( $plugins, 'getPluginSystemSettings' ) ) {
            throw new \InvalidArgumentException( 'plugin_key' );
        }

        $plugin_key = $this->normalizePluginKey( (string) ( $data['plugin_key'] ?? '' ) );
        if ( $plugin_key === '' ) {
            throw new \InvalidArgumentException( 'plugin_key' );
        }

        $section_definition = null;
        foreach ( (array) $plugins->getSystemSettingsSections() as $definition ) {
            if ( is_array( $definition ) && (string) ( $definition['plugin_key'] ?? '' ) === $plugin_key ) {
                $section_definition = $definition;
                break;
            }
        }

        if ( ! is_array( $section_definition ) ) {
            throw new \InvalidArgumentException( 'plugin_key' );
        }

        $incoming_settings = $this->normalizePluginSettingsMap( $data['plugin_settings'] ?? [] );
        $validated_settings = $this->validatePluginSettingsSectionDefinition(
            $section_definition,
            $incoming_settings[$plugin_key] ?? [],
            $plugins->getPluginSystemSettings( $plugin_key )
        );

        return(
            [
                'plugin_key' => $plugin_key,
                'plugin_settings' => $validated_settings,
            ]
        );
    }

    protected function validatePluginSettingsSectionDefinition( array $section_definition, array $incoming_settings, array $existing_settings = [] ) : array {
        $validated_settings = [];
        foreach ( (array) ( $section_definition['fields'] ?? [] ) as $field_definition ) {
            if ( ! is_array( $field_definition ) || empty( $field_definition['key'] ) || empty( $field_definition['type'] ) ) {
                continue;
            }

            $field_key = (string) $field_definition['key'];
            $field_type = (string) $field_definition['type'];
            $default_value = $field_definition['default'] ?? '';
            $existing_value = $existing_settings[$field_key] ?? $default_value;

            if ( $field_type === 'checkbox' ) {
                $validated_settings[$field_key] = ! empty( $incoming_settings[$field_key] );
                continue;
            }

            if ( $field_type === 'select' ) {
                $allowed_values = [];
                foreach ( (array) ( $field_definition['options'] ?? [] ) as $option_definition ) {
                    if ( is_array( $option_definition ) && isset( $option_definition['value'] ) ) {
                        $allowed_values[] = (string) $option_definition['value'];
                    }
                }

                $selected_value = (string) ( $incoming_settings[$field_key] ?? $existing_value );
                if ( ! in_array( $selected_value, $allowed_values, true ) ) {
                    throw new \InvalidArgumentException( $field_key );
                }
                $validated_settings[$field_key] = $selected_value;
                continue;
            }

            if ( $field_type === 'password' ) {
                $submitted_value = trim( (string) ( $incoming_settings[$field_key] ?? '' ) );
                $validated_settings[$field_key] = $submitted_value !== '' ? $submitted_value : trim( (string) $existing_value );
                continue;
            }

            $validated_settings[$field_key] = trim( (string) ( $incoming_settings[$field_key] ?? $existing_value ) );
        }

        return( $validated_settings );
    }

    protected function getSystemSettingsRedirectUrl( string $settings_group, array $data = [] ) : string {
        $settings_group = $this->normalizeSystemSettingsGroup( $settings_group );
        if ( $settings_group !== 'plugins' ) {
            if ( $settings_group === 'content_media' ) {
                return( $this->getSystemSectionUrl( 'content-media' ) );
            }
            if ( $settings_group === 'media' ) {
                return( $this->getSystemSectionUrl( 'media' ) );
            }
            if ( $settings_group === 'themes' ) {
                return( $this->getSystemSectionUrl( 'themes-settings' ) );
            }
            return( $this->getSystemSectionUrl( $settings_group ) );
        }

        $plugin_key = $this->normalizePluginKey( (string) ( $data['plugin_key'] ?? '' ) );
        return( $plugin_key !== '' ? $this->getSystemPluginSettingsUrl( $plugin_key ) : $this->getSystemSectionUrl( 'plugins' ) );
    }

    protected function getUserRepository() : ?TinyMashUserRepository {
        if ( ! $this->app->has( 'user.repository' ) ) {
            return( null );
        }

        $user_repository = $this->app->get( 'user.repository' );
        return( $user_repository instanceof TinyMashUserRepository ? $user_repository : null );
    }

    protected function getMenuService() : ?TinyMashMenuService {
        if ( ! $this->app->has( 'menu.service' ) ) {
            return( null );
        }

        $menu_service = $this->app->get( 'menu.service' );
        return( $menu_service instanceof TinyMashMenuService ? $menu_service : null );
    }

    protected function getSystemMenuLocationDefinitions() : array {
        return(
            [
                'primary' => [ 'key' => 'primary', 'label' => 'Primary' ],
                'footer' => [ 'key' => 'footer', 'label' => 'Footer' ],
            ]
        );
    }

    protected function getSystemMenuLocationsViewData() : array {
        $menu_service = $this->getMenuService();
        $configured_locations = $menu_service instanceof TinyMashMenuService ? $menu_service->getConfiguredLocations() : [ 'primary' => [], 'footer' => [] ];
        $view_data = [];

        foreach ( [ 'primary', 'footer' ] as $location ) {
            $items = is_array( $configured_locations[$location] ?? null ) ? array_values( $configured_locations[$location] ) : [];
            $view_data[$location] = $this->flattenMenuItemsForAdminRows( $items );
        }

        return( $view_data );
    }

    protected function validateMenusSettings( array $data ) : array {
        $locations = is_array( $data['menu_locations'] ?? null ) ? $data['menu_locations'] : [];
        $validated_locations = [];

        foreach ( [ 'primary', 'footer' ] as $location ) {
            $validated_locations[$location] = [];
            $rows = is_array( $locations[$location] ?? null ) ? $locations[$location] : [];
            $current_parent_index = null;

            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $depth = max( 0, min( 1, (int) ( $row['depth'] ?? 0 ) ) );
                $type = strtolower( trim( (string) ( $row['type'] ?? 'page' ) ) );
                if ( ! in_array( $type, [ 'page', 'url', 'separator' ], true ) ) {
                    $type = 'page';
                }

                $path = $this->normalizePublicPagePathInput( (string) ( $row['path'] ?? '' ) );
                $url = trim( (string) ( $row['url'] ?? '' ) );
                $label = mb_substr( trim( (string) ( $row['label'] ?? '' ) ), 0, 120 );
                $new_tab = ! empty( $row['new_tab'] );
                $validated_item = null;

                if ( $type === 'separator' ) {
                    $validated_item = [
                        'type' => 'separator',
                        'path' => '',
                        'url' => '',
                        'label' => '',
                        'new_tab' => false,
                        'children' => [],
                    ];
                } elseif ( $type === 'page' ) {
                    if ( $path === '' && $url === '' && $label === '' ) {
                        continue;
                    }

                    $validated_item = [
                        'type' => 'page',
                        'path' => $this->validateLandingPagePathForScope( $path, 'root', '', 'menu_item_path' ),
                        'url' => '',
                        'label' => $label,
                        'new_tab' => false,
                        'children' => [],
                    ];
                } else {
                    if ( $path === '' && $url === '' && $label === '' ) {
                        continue;
                    }

                    if ( ! $this->isValidMenuUrl( $url ) ) {
                        throw new \InvalidArgumentException( 'menu_item_url' );
                    }

                    $validated_item = [
                        'type' => 'url',
                        'path' => '',
                        'url' => $url,
                        'label' => $label,
                        'new_tab' => $new_tab,
                        'children' => [],
                    ];
                }

                if ( ! is_array( $validated_item ) ) {
                    continue;
                }

                if ( $depth === 1 && $current_parent_index !== null && isset( $validated_locations[$location][$current_parent_index] ) ) {
                    $validated_locations[$location][$current_parent_index]['children'][] = $validated_item;
                    continue;
                }

                $validated_locations[$location][] = $validated_item;
                $current_parent_index = array_key_last( $validated_locations[$location] );
            }
        }

        return( [ 'menu_locations' => $validated_locations ] );
    }

    protected function flattenMenuItemsForAdminRows( array $items, int $depth = 0 ) : array {
        $rows = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

                $rows[] = [
                    'depth' => max( 0, min( 1, $depth ) ),
                    'type' => (string) ( $item['type'] ?? 'page' ),
                    'path' => (string) ( $item['path'] ?? '' ),
                    'url' => (string) ( $item['url'] ?? '' ),
                    'label' => (string) ( $item['label'] ?? '' ),
                'new_tab' => ! empty( $item['new_tab'] ),
            ];

            if ( $depth < 1 && ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
                foreach ( $this->flattenMenuItemsForAdminRows( (array) $item['children'], $depth + 1 ) as $child_row ) {
                    $rows[] = $child_row;
                }
            }
        }

        return( $rows );
    }

    protected function getEmptyAdminMenuRow() : array {
        return(
            [
                'depth' => 0,
                'type' => 'page',
                'path' => '',
                'url' => '',
                'label' => '',
                'new_tab' => false,
            ]
        );
    }

    protected function isValidMenuUrl( string $url ) : bool {
        $url = trim( $url );
        if ( $url === '' ) {
            return( false );
        }

        if ( str_starts_with( $url, '/' ) ) {
            return( true );
        }

        if ( filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
            return( false );
        }

        $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
        return( in_array( $scheme, [ 'http', 'https' ], true ) );
    }

    protected function getNotificationService() : ?TinyMashNotificationService {
        if ( ! $this->app->has( 'notification.service' ) ) {
            return( null );
        }

        $service = $this->app->get( 'notification.service' );
        return( $service instanceof TinyMashNotificationService ? $service : null );
    }

    protected function createSystemNotification( string $type, string $title, string $message, array $options = [] ) : array {
        $service = $this->getNotificationService();
        if ( $service === null ) {
            return( [] );
        }

        try {
            $notification = $service->createNotification( $type, $title, $message, $options );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to create notification (' . $e->getMessage() . ')' );
            return( [] );
        }

        $system_settings = $this->config->getSystemSettings();
        if ( ! $service->shouldSendEmailForType( $type, $system_settings ) ) {
            return( $notification );
        }

        $mailer = $this->getMailer();
        if ( $mailer === null ) {
            return( $notification );
        }

        try {
            $service->sendNotificationEmail(
                $type,
                '[' . $this->config->getSiteName() . '] ' . $title,
                $message,
                $mailer,
                $system_settings,
                $this->config->getSiteName()
            );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to send notification e-mail (' . $e->getMessage() . ')' );
        }

        return( $notification );
    }

}// AdminController
