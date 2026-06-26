<?php
namespace app\classes;

use flight\Engine;
use flight\net\Router;

class TinyMashConfig {

    public const PUBLIC_HEAD_LINK_RELS = [
        'alternate', 'author', 'authorization_endpoint', 'license', 'me', 'micropub',
        'openid.delegate', 'openid.server', 'pingback', 'search', 'token_endpoint', 'webmention',
    ];

    protected Engine $app;
    protected Router $router;
    protected string $config_json_filename = '';
    protected string|bool $config_json = false;
    protected array $config = [];
    protected bool $config_read = false;
    protected bool $config_error = false;

    protected bool $site_maintenance = false;
    protected bool $content_moderation_required = false;
    protected string $public_theme_key = 'baseline';

    public function __construct( Engine $app, Router $router ) {
        $this->app = $app;
        $this->router = $router;
        $this->config_json_filename = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tinymash.json';
    }

    public function getConfigFilename() : string {
        return( $this->config_json_filename );
    }

    public function getConfig() : bool {
        if ( $this->config_read ) {
            error_log( basename( __FILE__ ) . ': We have already read the configuration file' );
            return( true );
        }
        $this->config_json = file_get_contents( $this->getConfigFilename(), false );
        if ( $this->config_json !== false && mb_strlen( $this->config_json ) > 0 ) {
            $this->config = @ json_decode( $this->config_json, true, 16 );
            if ( is_array( $this->config ) && ! empty( $this->config ) ) {
                if ( empty( $this->config['site'] ) || ! is_array( $this->config['site'] ) ) {
                    $this->config['site'] = [];
                }

                //Check some basic values
                if ( empty( $this->config['site']['name'] ) ) {
                    $this->config['site']['name'] = 'tinymash';
                }
                if ( ! isset( $this->config['site']['slogan'] ) ) {
                    $this->config['site']['slogan'] = '';
                }
                if ( ! empty( $this->config['site']['base_url'] ) ) {
                    $this->app->set( 'app.url', $this->config['site']['base_url'] );
                } else {
                    $this->config['site']['base_url'] = $this->app->get( 'app.url' );
                }
                if ( ! empty( $this->config['site']['login_url'] ) ) {
                    $this->app->set( 'login.url', $this->config['site']['login_url'] );
                } else {
                    $this->config['site']['login_url'] = $this->app->get( 'login.url' );
                }
                if ( ! empty( $this->config['site']['admin_url'] ) ) {
                    $this->app->set( 'admin.url', $this->config['site']['admin_url'] );
                } else {
                    $this->config['site']['admin_url'] = $this->app->get( 'admin.url' );
                }
                if ( empty( $this->config['site']['security_min_password'] ) ) {
                    $this->config['site']['security_min_password'] = 8;
                }
                $this->config['site']['default_language'] = self::normalizeHtmlLanguageTag( (string) ( $this->config['site']['default_language'] ?? 'en' ) );
                if ( ! isset( $this->config['site']['allow_registrations'] ) ) {
                    $this->config['site']['allow_registrations'] = false;
                }
                if ( ! isset( $this->config['site']['is_public'] ) ) {
                    $this->config['site']['is_public'] = false;
                }
                if ( ! isset( $this->config['site']['maintenance'] ) ) {
                    $this->config['site']['maintenance'] = false;
                }
                if ( ! isset( $this->config['site']['admin_email'] ) ) {
                    $this->config['site']['admin_email'] = '';
                }
                if ( ! isset( $this->config['site']['system_notifications_email'] ) ) {
                    $this->config['site']['system_notifications_email'] = '';
                }
                if ( ! isset( $this->config['site']['allow_admin_password_resets'] ) ) {
                    $this->config['site']['allow_admin_password_resets'] = false;
                }
                if ( ! isset( $this->config['site']['allow_author_password_resets'] ) ) {
                    $this->config['site']['allow_author_password_resets'] = true;
                }
                if ( empty( $this->config['site']['password_reset_throttle_window_minutes'] ) ) {
                    $this->config['site']['password_reset_throttle_window_minutes'] = 60;
                }
                if ( empty( $this->config['site']['password_reset_max_ip_requests'] ) ) {
                    $this->config['site']['password_reset_max_ip_requests'] = 5;
                }
                if ( empty( $this->config['site']['password_reset_max_identifier_requests'] ) ) {
                    $this->config['site']['password_reset_max_identifier_requests'] = 3;
                }
                if ( empty( $this->config['site']['week_starts_on'] ) ) {
                    $this->config['site']['week_starts_on'] = 'monday';
                }
                if ( ! isset( $this->config['site']['allow_username_change'] ) ) {
                    $this->config['site']['allow_username_change'] = false;
                }
                if ( ! isset( $this->config['site']['allow_email_change'] ) ) {
                    $this->config['site']['allow_email_change'] = false;
                }
                if ( ! isset( $this->config['site']['allow_secret_links'] ) ) {
                    $this->config['site']['allow_secret_links'] = false;
                }
                if ( ! isset( $this->config['site']['filesystem_umask'] ) ) {
                    $this->config['site']['filesystem_umask'] = '0007';
                }
                if ( ! isset( $this->config['site']['rendered_content_cache_enabled'] ) ) {
                    $this->config['site']['rendered_content_cache_enabled'] = true;
                }
                if ( ! isset( $this->config['site']['unknown_shortcode_mode'] ) ) {
                    $this->config['site']['unknown_shortcode_mode'] = 'code';
                }
                if ( empty( $this->config['site']['forwarded_ip_mode'] ) ) {
                    $this->config['site']['forwarded_ip_mode'] = 'off';
                }
                if ( ! isset( $this->config['site']['public_cache_warm_basic_auth_username'] ) ) {
                    $this->config['site']['public_cache_warm_basic_auth_username'] = '';
                }
                if ( ! isset( $this->config['site']['public_cache_warm_basic_auth_password'] ) ) {
                    $this->config['site']['public_cache_warm_basic_auth_password'] = '';
                }
                if ( ! isset( $this->config['site']['public_cache_warm_insecure_tls'] ) ) {
                    $this->config['site']['public_cache_warm_insecure_tls'] = false;
                }
                if ( ! isset( $this->config['site']['discourage_search_indexing'] ) ) {
                    $this->config['site']['discourage_search_indexing'] = false;
                }
                if ( empty( $this->config['site']['secret_link_default_expiry_days'] ) ) {
                    $this->config['site']['secret_link_default_expiry_days'] = 60;
                }
                if ( ! isset( $this->config['site']['login_message'] ) ) {
                    $this->config['site']['login_message'] = '';
                }
                if ( ! isset( $this->config['site']['head_tags'] ) ) {
                    $this->config['site']['head_tags'] = [];
                }
                $this->config['site']['head_tags'] = self::normalizePublicHeadTags( $this->config['site']['head_tags'] );
                if ( empty( $this->config['site']['images'] ) || ! is_array( $this->config['site']['images'] ) ) {
                    $this->config['site']['images'] = [];
                }
                if ( ! isset( $this->config['site']['images']['banner'] ) ) {
                    $this->config['site']['images']['banner'] = [];
                }
                if ( ! isset( $this->config['site']['images']['favicon_png'] ) ) {
                    $this->config['site']['images']['favicon_png'] = [];
                }
                if ( ! isset( $this->config['site']['images']['favicon_ico'] ) ) {
                    $this->config['site']['images']['favicon_ico'] = [];
                }
                if ( ! isset( $this->config['site']['images']['og'] ) ) {
                    $this->config['site']['images']['og'] = [];
                }
                if ( ! isset( $this->config['site']['images']['background'] ) ) {
                    $this->config['site']['images']['background'] = [];
                }
                if ( empty( $this->config['site']['background_render_mode'] ) ) {
                    $this->config['site']['background_render_mode'] = 'scaled';
                }
                if ( empty( $this->config['smtp'] ) || ! is_array( $this->config['smtp'] ) ) {
                    $this->config['smtp'] = [];
                }
                if ( ! isset( $this->config['smtp']['enabled'] ) ) {
                    $this->config['smtp']['enabled'] = false;
                }
                if ( ! isset( $this->config['smtp']['host'] ) ) {
                    $this->config['smtp']['host'] = '';
                }
                if ( empty( $this->config['smtp']['port'] ) ) {
                    $this->config['smtp']['port'] = 587;
                }
                if ( ! isset( $this->config['smtp']['username'] ) ) {
                    $this->config['smtp']['username'] = '';
                }
                if ( ! isset( $this->config['smtp']['password'] ) ) {
                    $this->config['smtp']['password'] = '';
                }
                if ( empty( $this->config['smtp']['encryption'] ) || ! in_array( strtolower( (string) $this->config['smtp']['encryption'] ), [ 'none', 'ssl', 'tls' ], true ) ) {
                    $this->config['smtp']['encryption'] = 'tls';
                }
                if ( ! isset( $this->config['smtp']['from_email'] ) ) {
                    $this->config['smtp']['from_email'] = '';
                }
                if ( ! isset( $this->config['smtp']['from_name'] ) ) {
                    $this->config['smtp']['from_name'] = '';
                }
                if ( ! isset( $this->config['smtp']['reply_to_email'] ) ) {
                    $this->config['smtp']['reply_to_email'] = '';
                }
                if ( ! in_array( strtolower( (string) $this->config['site']['week_starts_on'] ), [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ], true ) ) {
                    $this->config['site']['week_starts_on'] = 'monday';
                }
                $this->app->set( 'password.min', $this->config['site']['security_min_password'] );
                $this->app->set( 'site.name', (string) $this->config['site']['name'] );
                $this->app->set( 'site.slogan', (string) $this->config['site']['slogan'] );
                $this->app->set( 'site.default_language', self::normalizeHtmlLanguageTag( (string) $this->config['site']['default_language'] ) );
                $this->app->set( 'site.allow_registrations', $this->normalizeBoolean( $this->config['site']['allow_registrations'] ) );
                $this->app->set( 'site.is_public', $this->normalizeBoolean( $this->config['site']['is_public'] ) );
                $this->app->set( 'site.admin_email', (string) $this->config['site']['admin_email'] );
                $this->app->set( 'site.allow_admin_password_resets', $this->normalizeBoolean( $this->config['site']['allow_admin_password_resets'] ) );
                $this->app->set( 'site.allow_author_password_resets', $this->normalizeBoolean( $this->config['site']['allow_author_password_resets'] ) );
                $this->app->set( 'site.password_reset_throttle_window_minutes', $this->normalizePasswordResetThrottleWindowMinutes( $this->config['site']['password_reset_throttle_window_minutes'] ) );
                $this->app->set( 'site.password_reset_max_ip_requests', $this->normalizePasswordResetThrottleCount( $this->config['site']['password_reset_max_ip_requests'], 5 ) );
                $this->app->set( 'site.password_reset_max_identifier_requests', $this->normalizePasswordResetThrottleCount( $this->config['site']['password_reset_max_identifier_requests'], 3 ) );
                $this->config['site']['filesystem_umask'] = $this->normalizeFilesystemUmask( $this->config['site']['filesystem_umask'] );
                $this->app->set( 'site.filesystem_umask', (string) $this->config['site']['filesystem_umask'] );
                tinymash_apply_runtime_umask( $this->config['site']['filesystem_umask'] );
                $this->app->set( 'site.rendered_content_cache_enabled', $this->normalizeBoolean( $this->config['site']['rendered_content_cache_enabled'] ?? true ) );
                $this->config['site']['forwarded_ip_mode'] = $this->normalizeForwardedIpMode( $this->config['site']['forwarded_ip_mode'] ?? 'off' );
                $this->app->set( 'site.forwarded_ip_mode', (string) $this->config['site']['forwarded_ip_mode'] );
                $this->config['site']['public_cache_warm_basic_auth_username'] = trim( (string) ( $this->config['site']['public_cache_warm_basic_auth_username'] ?? '' ) );
                $this->config['site']['public_cache_warm_basic_auth_password'] = (string) ( $this->config['site']['public_cache_warm_basic_auth_password'] ?? '' );
                $this->config['site']['public_cache_warm_insecure_tls'] = $this->normalizeBoolean( $this->config['site']['public_cache_warm_insecure_tls'] ?? false );
                $this->app->set( 'site.week_starts_on', (string) $this->config['site']['week_starts_on'] );
                $this->app->set( 'site.allow_username_change', $this->normalizeBoolean( $this->config['site']['allow_username_change'] ) );
                $this->app->set( 'site.allow_email_change', $this->normalizeBoolean( $this->config['site']['allow_email_change'] ) );
                if ( ! empty( $this->config['site']['maintenance'] ) && ( $this->config['site']['maintenance'] === true || $this->config['site']['maintenance'] === 'true' || $this->config['site']['maintenance'] === '1' || $this->config['site']['maintenance'] === 1 ) ) {
                    $this->site_maintenance = true;
                } else {
                    $this->site_maintenance = false;
                }
                if ( ! empty( $this->config['content']['require_moderation'] ) && ( $this->config['content']['require_moderation'] === true || $this->config['content']['require_moderation'] === 'true' || $this->config['content']['require_moderation'] === '1' || $this->config['content']['require_moderation'] === 1 ) ) {
                    $this->content_moderation_required = true;
                } else {
                    $this->content_moderation_required = false;
                }
                if ( ! isset( $this->config['content']['revision_retention_limit'] ) ) {
                    $this->config['content']['revision_retention_limit'] = 20;
                }
                if ( ! isset( $this->config['content']['tags_enabled'] ) ) {
                    $this->config['content']['tags_enabled'] = true;
                }
                if ( ! isset( $this->config['content']['show_page_timestamps'] ) ) {
                    $this->config['content']['show_page_timestamps'] = false;
                }
                if ( ! isset( $this->config['content']['trash_retention_days'] ) ) {
                    $this->config['content']['trash_retention_days'] = 30;
                }
                if ( empty( $this->config['housekeeping'] ) || ! is_array( $this->config['housekeeping'] ) ) {
                    $this->config['housekeeping'] = [];
                }
                if ( ! isset( $this->config['housekeeping']['stale_draft_retention_days'] ) ) {
                    $this->config['housekeeping']['stale_draft_retention_days'] = 0;
                }
                if ( ! isset( $this->config['housekeeping']['last_run_utc'] ) ) {
                    $this->config['housekeeping']['last_run_utc'] = '';
                }
                if ( ! isset( $this->config['housekeeping']['last_trigger'] ) ) {
                    $this->config['housekeeping']['last_trigger'] = '';
                }
                if ( ! isset( $this->config['housekeeping']['last_mode'] ) ) {
                    $this->config['housekeeping']['last_mode'] = '';
                }
                if ( empty( $this->config['housekeeping']['web_fallback_mode'] ) ) {
                    $this->config['housekeeping']['web_fallback_mode'] = 'auto';
                }
                if ( empty( $this->config['locale'] ) || ! is_array( $this->config['locale'] ) ) {
                    $this->config['locale'] = [];
                }
                if ( empty( $this->config['locale']['date'] ) ) {
                    $this->config['locale']['date'] = 'd-M-Y';
                }
                if ( empty( $this->config['locale']['time'] ) ) {
                    $this->config['locale']['time'] = 'H:i (T)';
                }
                if ( empty( $this->config['locale']['timezone'] ) ) {
                    $this->config['locale']['timezone'] = 'UTC';
                }
                if ( empty( $this->config['editor'] ) || ! is_array( $this->config['editor'] ) ) {
                    $this->config['editor'] = [];
                }
                if ( empty( $this->config['notifications'] ) || ! is_array( $this->config['notifications'] ) ) {
                    $this->config['notifications'] = [];
                }
                if ( ! isset( $this->config['notifications']['retention_days'] ) ) {
                    $this->config['notifications']['retention_days'] = 30;
                }
                if ( empty( $this->config['notifications']['email_events'] ) || ! is_array( $this->config['notifications']['email_events'] ) ) {
                    $this->config['notifications']['email_events'] = [];
                }
                foreach ( [ 'moderation_required', 'profile_email_changed', 'user_lockout', 'component_updates' ] as $notification_event_key ) {
                    if ( ! isset( $this->config['notifications']['email_events'][$notification_event_key] ) ) {
                        $this->config['notifications']['email_events'][$notification_event_key] = in_array( $notification_event_key, [ 'user_lockout', 'component_updates' ], true );
                    }
                }
                if ( empty( $this->config['themes'] ) || ! is_array( $this->config['themes'] ) ) {
                    $this->config['themes'] = [];
                }
                if ( empty( $this->config['menus'] ) || ! is_array( $this->config['menus'] ) ) {
                    $this->config['menus'] = [];
                }
                if ( empty( $this->config['menus']['locations'] ) || ! is_array( $this->config['menus']['locations'] ) ) {
                    $this->config['menus']['locations'] = [];
                }
                foreach ( [ 'primary', 'footer' ] as $menu_location ) {
                    if ( ! isset( $this->config['menus']['locations'][$menu_location] ) || ! is_array( $this->config['menus']['locations'][$menu_location] ) ) {
                        $this->config['menus']['locations'][$menu_location] = [];
                    }
                }
                if ( empty( $this->config['themes']['public'] ) || ! is_array( $this->config['themes']['public'] ) ) {
                    $this->config['themes']['public'] = [];
                }
                if ( empty( $this->config['themes']['public']['current'] ) ) {
                    $this->config['themes']['public']['current'] = 'baseline';
                }
                if ( empty( $this->config['themes']['public']['settings'] ) || ! is_array( $this->config['themes']['public']['settings'] ) ) {
                    $this->config['themes']['public']['settings'] = [];
                }
                if ( empty( $this->config['themes']['public']['settings']['baseline'] ) || ! is_array( $this->config['themes']['public']['settings']['baseline'] ) ) {
                    $this->config['themes']['public']['settings']['baseline'] = [];
                }
                if ( empty( $this->config['themes']['public']['settings']['baseline']['page_sidebar_style'] ) ) {
                    $this->config['themes']['public']['settings']['baseline']['page_sidebar_style'] = 'flat';
                }
                if ( empty( $this->config['themes']['public']['settings']['baseline']['top_navigation_depth'] ) ) {
                    $this->config['themes']['public']['settings']['baseline']['top_navigation_depth'] = '1';
                }
                if ( empty( $this->config['themes']['public']['settings']['baseline']['page_breadcrumbs'] ) ) {
                    $this->config['themes']['public']['settings']['baseline']['page_breadcrumbs'] = 'enabled';
                }
                if ( empty( $this->config['themes']['public']['settings']['baseline']['page_children_after_content'] ) ) {
                    $this->config['themes']['public']['settings']['baseline']['page_children_after_content'] = 'more';
                }
                if ( empty( $this->config['plugins'] ) || ! is_array( $this->config['plugins'] ) ) {
                    $this->config['plugins'] = [];
                }
                if ( empty( $this->config['plugins']['states'] ) || ! is_array( $this->config['plugins']['states'] ) ) {
                    $this->config['plugins']['states'] = [];
                }
                if ( empty( $this->config['plugins']['settings'] ) || ! is_array( $this->config['plugins']['settings'] ) ) {
                    $this->config['plugins']['settings'] = [];
                }
                if ( ! isset( $this->config['editor']['autosave_enabled'] ) ) {
                    $this->config['editor']['autosave_enabled'] = true;
                }
                if ( empty( $this->config['editor']['autosave_interval_seconds'] ) ) {
                    $this->config['editor']['autosave_interval_seconds'] = 120;
                }
                if ( ! isset( $this->config['editor']['classic_smileys_enabled'] ) ) {
                    $this->config['editor']['classic_smileys_enabled'] = true;
                }
                if ( empty( $this->config['media'] ) || ! is_array( $this->config['media'] ) ) {
                    $this->config['media'] = [];
                }
                if ( empty( $this->config['media']['content_images_mode'] ) || ! in_array( strtolower( (string) $this->config['media']['content_images_mode'] ), [ 'disabled', 'admins_only', 'authenticated' ], true ) ) {
                    $this->config['media']['content_images_mode'] = 'authenticated';
                }
                if ( empty( $this->config['media']['image_driver'] ) || ! in_array( strtolower( (string) $this->config['media']['image_driver'] ), [ 'auto', 'imagick', 'gd', 'none' ], true ) ) {
                    $this->config['media']['image_driver'] = 'auto';
                }
                if ( empty( $this->config['media']['image_max_width'] ) ) {
                    $this->config['media']['image_max_width'] = 2560;
                }
                if ( empty( $this->config['media']['image_max_height'] ) ) {
                    $this->config['media']['image_max_height'] = 2560;
                }
                if ( empty( $this->config['media']['image_max_upload_mb'] ) ) {
                    $this->config['media']['image_max_upload_mb'] = 10;
                }
                if ( empty( $this->config['media']['allowed_image_mimes'] ) || ! is_array( $this->config['media']['allowed_image_mimes'] ) ) {
                    $this->config['media']['allowed_image_mimes'] = $this->getDefaultAllowedContentImageMimes();
                }
                if ( ! isset( $this->config['media']['metadata_retention_groups'] ) || ! is_array( $this->config['media']['metadata_retention_groups'] ) ) {
                    $this->config['media']['metadata_retention_groups'] = $this->getDefaultMediaMetadataRetentionPolicy();
                }
                if ( ! isset( $this->config['media']['metadata_public_groups'] ) || ! is_array( $this->config['media']['metadata_public_groups'] ) ) {
                    $this->config['media']['metadata_public_groups'] = $this->getDefaultMediaMetadataPublicPolicy();
                }
                if ( ! isset( $this->config['media']['generate_thumbnails'] ) ) {
                    $this->config['media']['generate_thumbnails'] = true;
                }
                if ( empty( $this->config['media']['thumbnail_max_width'] ) ) {
                    $this->config['media']['thumbnail_max_width'] = 320;
                }
                if ( empty( $this->config['media']['thumbnail_max_height'] ) ) {
                    $this->config['media']['thumbnail_max_height'] = 320;
                }
                $this->config['editor']['autosave_interval_seconds'] = $this->normalizeAutosaveIntervalSeconds( $this->config['editor']['autosave_interval_seconds'] );
                $this->config['editor']['classic_smileys_enabled'] = $this->normalizeBoolean( $this->config['editor']['classic_smileys_enabled'] );
                $this->config['content']['revision_retention_limit'] = $this->normalizeRevisionRetentionLimit( $this->config['content']['revision_retention_limit'] );
                $this->config['content']['trash_retention_days'] = $this->normalizeTrashRetentionDays( $this->config['content']['trash_retention_days'] );
                $this->config['housekeeping']['stale_draft_retention_days'] = $this->normalizeHousekeepingDraftRetentionDays( $this->config['housekeeping']['stale_draft_retention_days'] );
                $this->config['housekeeping']['last_run_utc'] = trim( (string) ( $this->config['housekeeping']['last_run_utc'] ?? '' ) );
                $this->config['housekeeping']['last_trigger'] = $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_trigger'] ?? '' );
                $this->config['housekeeping']['last_mode'] = $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_mode'] ?? '' );
                $this->config['housekeeping']['web_fallback_mode'] = $this->normalizeHousekeepingWebFallbackMode( $this->config['housekeeping']['web_fallback_mode'] ?? 'auto' );
                $this->config['notifications']['retention_days'] = $this->normalizeNotificationRetentionDays( $this->config['notifications']['retention_days'] ?? 30 );
                foreach ( [ 'moderation_required', 'profile_email_changed', 'user_lockout', 'component_updates' ] as $notification_event_key ) {
                    $this->config['notifications']['email_events'][$notification_event_key] = $this->normalizeBoolean( $this->config['notifications']['email_events'][$notification_event_key] ?? false );
                }
                $this->config['site']['images']['banner'] = $this->normalizeSiteImage( $this->config['site']['images']['banner'] ?? [] );
                $this->config['site']['images']['favicon_png'] = $this->normalizeSiteImage( $this->config['site']['images']['favicon_png'] ?? [] );
                $this->config['site']['images']['favicon_ico'] = $this->normalizeSiteImage( $this->config['site']['images']['favicon_ico'] ?? [] );
                $this->config['site']['images']['og'] = $this->normalizeSiteImage( $this->config['site']['images']['og'] ?? [] );
                $this->config['site']['images']['background'] = $this->normalizeSiteImage( $this->config['site']['images']['background'] ?? [] );
                $this->config['site']['background_render_mode'] = $this->normalizeBackgroundRenderMode( $this->config['site']['background_render_mode'] ?? 'scaled' );
                $this->config['media']['image_max_width'] = $this->normalizeImageDimension( $this->config['media']['image_max_width'] );
                $this->config['media']['image_max_height'] = $this->normalizeImageDimension( $this->config['media']['image_max_height'] );
                $this->config['media']['image_max_upload_mb'] = $this->normalizeImageUploadMegabytes( $this->config['media']['image_max_upload_mb'] );
                $this->config['media']['allowed_image_mimes'] = $this->normalizeAllowedContentImageMimes( $this->config['media']['allowed_image_mimes'] );
                $this->config['media']['metadata_retention_groups'] = $this->normalizeMediaMetadataPolicy( $this->config['media']['metadata_retention_groups'], $this->getDefaultMediaMetadataRetentionPolicy() );
                $this->config['media']['metadata_public_groups'] = $this->normalizeMediaMetadataPolicy( $this->config['media']['metadata_public_groups'], $this->getDefaultMediaMetadataPublicPolicy(), $this->config['media']['metadata_retention_groups'] );
                $this->config['media']['generate_thumbnails'] = $this->normalizeBoolean( $this->config['media']['generate_thumbnails'] );
                $this->config['media']['thumbnail_max_width'] = $this->normalizeThumbnailDimension( $this->config['media']['thumbnail_max_width'] );
                $this->config['media']['thumbnail_max_height'] = $this->normalizeThumbnailDimension( $this->config['media']['thumbnail_max_height'] );
                $this->public_theme_key = $this->normalizePublicThemeKey( (string) $this->config['themes']['public']['current'] );

                $this->config_error = false;
                $this->config_read = true;
                return( true );
            }
        }
        $this->config_error = true;
        error_log( basename( __FILE__ ) . ': Unable to read "' . $this->getConfigFilename() . '"' );
        return( false );
    }

    public function configGetBaseURL() : string|bool {
        if ( $this->config_read ) {
            return ( $this->config['site']['base_url'] );
        }
        return( false );
    }
    public function configGetAdminURL() : string|bool {
        if ( $this->config_read ) {
            return ( $this->config['site']['admin_url'] );
        }
        return( false );
    }
    public function configGetLoginURL() : string|bool {
        if ( $this->config_read ) {
            return ( $this->config['site']['login_url'] );
        }
        return( false );
    }
    public function getMinPasswordLength() : int {
        if ( $this->config_read ) {
            return ( $this->config['site']['security_min_password'] );
        }
        return( 8 );
    }

    public function getMaintenance() : bool {
        return( $this->site_maintenance );
    }
    public function setMaintenance( bool $flag ) : void {
        $this->site_maintenance = $flag;
    }

    public function isContentModerationRequired() : bool {
        return( $this->content_moderation_required );
    }

    public function getLocaleDateFormat() : string {
        if ( $this->config_read && ! empty( $this->config['locale']['date'] ) ) {
            return( (string) $this->config['locale']['date'] );
        }

        return( 'd-M-Y' );
    }

    public function getLocaleTimeFormat() : string {
        if ( $this->config_read && ! empty( $this->config['locale']['time'] ) ) {
            return( (string) $this->config['locale']['time'] );
        }

        return( 'H:i (T)' );
    }

    public function getLocaleTimezone() : string {
        if ( $this->config_read && ! empty( $this->config['locale']['timezone'] ) ) {
            return( (string) $this->config['locale']['timezone'] );
        }

        return( 'UTC' );
    }

    public function getSiteName() : string {
        if ( $this->config_read && ! empty( $this->config['site']['name'] ) ) {
            return( (string) $this->config['site']['name'] );
        }

        return( 'tinymash' );
    }

    public function getSiteSlogan() : string {
        if ( $this->config_read && isset( $this->config['site']['slogan'] ) ) {
            return( (string) $this->config['site']['slogan'] );
        }

        return( '' );
    }

    public function getPublicHeadTags() : array {
        if ( ! $this->config_read ) {
            return( [] );
        }

        return( self::normalizePublicHeadTags( $this->config['site']['head_tags'] ?? [] ) );
    }

    public static function normalizePublicHeadTags( mixed $tags ) : array {
        if ( ! is_array( $tags ) ) {
            return( [] );
        }

        $normalized_tags = [];
        $seen_tags = [];
        foreach ( $tags as $tag ) {
            $normalized_tag = self::normalizePublicHeadTag( $tag );
            if ( $normalized_tag === null ) {
                continue;
            }

            $tag_key = json_encode( $normalized_tag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( ! is_string( $tag_key ) || isset( $seen_tags[$tag_key] ) ) {
                continue;
            }

            $seen_tags[$tag_key] = true;
            $normalized_tags[] = $normalized_tag;
            if ( count( $normalized_tags ) >= 12 ) {
                break;
            }
        }

        return( $normalized_tags );
    }

    public static function normalizePublicHeadTag( mixed $tag ) : ?array {
        if ( ! is_array( $tag ) ) {
            return( null );
        }

        $type = strtolower( trim( (string) ( $tag['type'] ?? '' ) ) );
        if ( $type === 'meta' ) {
            $name = strtolower( trim( (string) ( $tag['name'] ?? '' ) ) );
            $property = strtolower( trim( (string) ( $tag['property'] ?? '' ) ) );
            $content = function_exists( 'mb_trim' ) ? mb_trim( (string) ( $tag['content'] ?? '' ) ) : trim( (string) ( $tag['content'] ?? '' ) );
            if (
                ( $name === '' && $property === '' )
                || ( $name !== '' && $property !== '' )
                || $content === ''
                || mb_strlen( $content ) > 1000
            ) {
                return( null );
            }

            $key = $name !== '' ? $name : $property;
            if ( preg_match( '/^[a-z][a-z0-9:._-]{0,100}$/', $key ) !== 1 ) {
                return( null );
            }

            return( $name !== ''
                ? [ 'type' => 'meta', 'name' => $name, 'content' => $content ]
                : [ 'type' => 'meta', 'property' => $property, 'content' => $content ]
            );
        }

        if ( $type !== 'link' ) {
            return( null );
        }

        $rel = strtolower( trim( (string) ( $tag['rel'] ?? '' ) ) );
        $href = trim( (string) ( $tag['href'] ?? '' ) );
        if ( ! in_array( $rel, self::PUBLIC_HEAD_LINK_RELS, true ) || ! self::isValidPublicHeadLinkUrl( $href ) ) {
            return( null );
        }

        return( [ 'type' => 'link', 'rel' => $rel, 'href' => $href ] );
    }

    protected static function isValidPublicHeadLinkUrl( string $url ) : bool {
        if ( $url === '' || mb_strlen( $url ) > 2000 || preg_match( '/[\x00-\x1F\x7F]/', $url ) === 1 ) {
            return( false );
        }

        if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
            return( true );
        }

        return( filter_var( $url, FILTER_VALIDATE_URL ) !== false && preg_match( '/^https?:\/\//i', $url ) === 1 );
    }

    public function getDefaultLanguage() : string {
        if ( $this->config_read && ! empty( $this->config['site']['default_language'] ) ) {
            return( self::normalizeHtmlLanguageTag( (string) $this->config['site']['default_language'] ) );
        }

        return( 'en' );
    }

    public static function normalizeHtmlLanguageTag( string $language_tag ) : string {
        $language_tag = str_replace( '_', '-', trim( $language_tag ) );
        $language_tag = preg_replace( '/[^A-Za-z0-9-]+/', '-', $language_tag ) ?? '';
        $language_tag = trim( preg_replace( '/-+/', '-', $language_tag ) ?? '', '-' );
        if ( $language_tag === '' ) {
            return( 'en' );
        }

        $raw_parts = explode( '-', $language_tag );
        $primary_language = strtolower( trim( (string) array_shift( $raw_parts ) ) );
        if ( preg_match( '/^[a-z]{2,3}$/', $primary_language ) !== 1 ) {
            return( 'en' );
        }

        $parts = [ $primary_language ];
        $extension_mode = false;
        $private_use_mode = false;
        foreach ( $raw_parts as $raw_part ) {
            $part = trim( (string) $raw_part );
            if ( $part === '' ) {
                continue;
            }

            if ( $private_use_mode ) {
                if ( preg_match( '/^[A-Za-z0-9]{1,8}$/', $part ) === 1 ) {
                    $parts[] = strtolower( $part );
                }
                continue;
            }

            if ( strtolower( $part ) === 'x' ) {
                $parts[] = 'x';
                $private_use_mode = true;
                $extension_mode = false;
                continue;
            }

            if ( preg_match( '/^[A-Za-z0-9]$/', $part ) === 1 ) {
                $parts[] = strtolower( $part );
                $extension_mode = true;
                continue;
            }

            if ( $extension_mode ) {
                if ( preg_match( '/^[A-Za-z0-9]{2,8}$/', $part ) === 1 ) {
                    $parts[] = strtolower( $part );
                }
                continue;
            }

            if ( preg_match( '/^[A-Za-z]{4}$/', $part ) === 1 ) {
                $parts[] = ucfirst( strtolower( $part ) );
                continue;
            }

            if ( preg_match( '/^[A-Za-z]{2}$/', $part ) === 1 ) {
                $parts[] = strtoupper( $part );
                continue;
            }

            if ( preg_match( '/^[0-9]{3}$/', $part ) === 1 ) {
                $parts[] = $part;
                continue;
            }

            if ( preg_match( '/^[A-Za-z0-9]{5,8}$/', $part ) === 1 || preg_match( '/^[0-9][A-Za-z0-9]{3}$/', $part ) === 1 ) {
                $parts[] = strtolower( $part );
            }
        }

        return( implode( '-', $parts ) );
    }

    public function allowsRegistrations() : bool {
        if ( $this->config_read && isset( $this->config['site']['allow_registrations'] ) ) {
            return( $this->normalizeBoolean( $this->config['site']['allow_registrations'] ) );
        }

        return( false );
    }

    public function isSitePublic() : bool {
        if ( $this->config_read && isset( $this->config['site']['is_public'] ) ) {
            return( $this->normalizeBoolean( $this->config['site']['is_public'] ) );
        }

        return( false );
    }

    public function getAdminEmail() : string {
        if ( $this->config_read && isset( $this->config['site']['admin_email'] ) ) {
            return( (string) $this->config['site']['admin_email'] );
        }

        return( '' );
    }

    public function getWeekStartsOn() : string {
        if ( $this->config_read && ! empty( $this->config['site']['week_starts_on'] ) ) {
            return( (string) $this->config['site']['week_starts_on'] );
        }

        return( 'monday' );
    }

    public function canUsersChangeUsername() : bool {
        if ( $this->config_read && isset( $this->config['site']['allow_username_change'] ) ) {
            return( $this->normalizeBoolean( $this->config['site']['allow_username_change'] ) );
        }

        return( false );
    }

    public function canUsersChangeEmail() : bool {
        if ( $this->config_read && isset( $this->config['site']['allow_email_change'] ) ) {
            return( $this->normalizeBoolean( $this->config['site']['allow_email_change'] ) );
        }

        return( false );
    }

    public function allowsAdminPasswordResets() : bool {
        if ( $this->config_read ) {
            return( $this->normalizeBoolean( $this->config['site']['allow_admin_password_resets'] ?? false ) );
        }

        return( false );
    }

    public function allowsAuthorPasswordResets() : bool {
        if ( $this->config_read ) {
            return( $this->normalizeBoolean( $this->config['site']['allow_author_password_resets'] ?? true ) );
        }

        return( true );
    }

    public function getPasswordResetThrottleWindowMinutes() : int {
        if ( $this->config_read ) {
            return( $this->normalizePasswordResetThrottleWindowMinutes( $this->config['site']['password_reset_throttle_window_minutes'] ?? 60 ) );
        }

        return( 60 );
    }

    public function getPasswordResetMaxIpRequests() : int {
        if ( $this->config_read ) {
            return( $this->normalizePasswordResetThrottleCount( $this->config['site']['password_reset_max_ip_requests'] ?? 5, 5 ) );
        }

        return( 5 );
    }

    public function getPasswordResetMaxIdentifierRequests() : int {
        if ( $this->config_read ) {
            return( $this->normalizePasswordResetThrottleCount( $this->config['site']['password_reset_max_identifier_requests'] ?? 3, 3 ) );
        }

        return( 3 );
    }

    public function getNotificationRetentionDays() : int {
        if ( $this->config_read ) {
            return( $this->normalizeNotificationRetentionDays( $this->config['notifications']['retention_days'] ?? 30 ) );
        }

        return( 30 );
    }

    public function getNotificationEmailEvents() : array {
        $default_events = [
            'moderation_required' => false,
            'profile_email_changed' => false,
            'user_lockout' => true,
            'component_updates' => true,
        ];
        if ( ! $this->config_read ) {
            return( $default_events );
        }

        $configured_events = is_array( $this->config['notifications']['email_events'] ?? null ) ? $this->config['notifications']['email_events'] : [];
        foreach ( array_keys( $default_events ) as $event_key ) {
            $default_events[$event_key] = $this->normalizeBoolean( $configured_events[$event_key] ?? $default_events[$event_key] );
        }

        return( $default_events );
    }

    public function allowsSecretLinks() : bool {
        if ( $this->config_read && isset( $this->config['site']['allow_secret_links'] ) ) {
            return( $this->normalizeBoolean( $this->config['site']['allow_secret_links'] ) );
        }

        return( false );
    }

    public function discouragesSearchIndexing() : bool {
        if ( $this->config_read ) {
            return( $this->normalizeBoolean( $this->config['site']['discourage_search_indexing'] ?? false ) );
        }

        return( false );
    }

    public function getSecretLinkDefaultExpiryDays() : int {
        if ( $this->config_read && isset( $this->config['site']['secret_link_default_expiry_days'] ) ) {
            return( $this->normalizeSecretLinkExpiryDays( $this->config['site']['secret_link_default_expiry_days'] ) );
        }

        return( 60 );
    }

    public function getFilesystemUmask() : string {
        if ( $this->config_read ) {
            return( $this->normalizeFilesystemUmask( $this->config['site']['filesystem_umask'] ?? '0007' ) );
        }

        return( '0007' );
    }

    public function isRenderedContentCacheEnabled() : bool {
        if ( $this->config_read ) {
            return( $this->normalizeBoolean( $this->config['site']['rendered_content_cache_enabled'] ?? true ) );
        }

        return( true );
    }

    public function getUnknownShortcodeMode() : string {
        if ( $this->config_read ) {
            $mode = strtolower( trim( (string) ( $this->config['site']['unknown_shortcode_mode'] ?? 'code' ) ) );
            return( $mode === 'hide' ? 'hide' : 'code' );
        }

        return( 'code' );
    }

    public function getForwardedIpMode() : string {
        if ( $this->config_read ) {
            return( $this->normalizeForwardedIpMode( $this->config['site']['forwarded_ip_mode'] ?? 'off' ) );
        }

        return( 'off' );
    }

    public function getPublicPageCacheWarmBasicAuthUsername() : string {
        if ( $this->config_read ) {
            return( trim( (string) ( $this->config['site']['public_cache_warm_basic_auth_username'] ?? '' ) ) );
        }

        return( '' );
    }

    public function getPublicPageCacheWarmBasicAuthPassword() : string {
        if ( $this->config_read ) {
            return( (string) ( $this->config['site']['public_cache_warm_basic_auth_password'] ?? '' ) );
        }

        return( '' );
    }

    public function getPublicPageCacheWarmUserpass() : string {
        $username = $this->getPublicPageCacheWarmBasicAuthUsername();
        if ( $username === '' ) {
            return( '' );
        }

        return( $username . ':' . $this->getPublicPageCacheWarmBasicAuthPassword() );
    }

    public function allowsInsecureTlsForPublicPageCacheWarm() : bool {
        if ( $this->config_read ) {
            return( $this->normalizeBoolean( $this->config['site']['public_cache_warm_insecure_tls'] ?? false ) );
        }

        return( false );
    }

    public function isEditorAutosaveEnabled() : bool {
        if ( $this->config_read && isset( $this->config['editor']['autosave_enabled'] ) ) {
            return( $this->normalizeBoolean( $this->config['editor']['autosave_enabled'] ) );
        }

        return( true );
    }

    public function getEditorAutosaveIntervalSeconds() : int {
        if ( $this->config_read && isset( $this->config['editor']['autosave_interval_seconds'] ) ) {
            return( $this->normalizeAutosaveIntervalSeconds( $this->config['editor']['autosave_interval_seconds'] ) );
        }

        return( 120 );
    }

    public function isClassicSmileysEnabled() : bool {
        if ( $this->config_read && isset( $this->config['editor']['classic_smileys_enabled'] ) ) {
            return( $this->normalizeBoolean( $this->config['editor']['classic_smileys_enabled'] ) );
        }

        return( true );
    }

    public function getContentRevisionRetentionLimit() : int {
        if ( $this->config_read && isset( $this->config['content']['revision_retention_limit'] ) ) {
            return( $this->normalizeRevisionRetentionLimit( $this->config['content']['revision_retention_limit'] ) );
        }

        return( 20 );
    }

    public function getContentTrashRetentionDays() : int {
        if ( $this->config_read && isset( $this->config['content']['trash_retention_days'] ) ) {
            return( $this->normalizeTrashRetentionDays( $this->config['content']['trash_retention_days'] ) );
        }

        return( 30 );
    }

    public function areTagsEnabled() : bool {
        if ( $this->config_read ) {
            return( $this->normalizeBoolean( $this->config['content']['tags_enabled'] ?? true ) );
        }

        return( true );
    }

    public function showsPageTimestamps() : bool {
        if ( $this->config_read ) {
            return( $this->normalizeBoolean( $this->config['content']['show_page_timestamps'] ?? false ) );
        }

        return( false );
    }

    public function getHousekeepingDraftRetentionDays() : int {
        if ( $this->config_read && isset( $this->config['housekeeping']['stale_draft_retention_days'] ) ) {
            return( $this->normalizeHousekeepingDraftRetentionDays( $this->config['housekeeping']['stale_draft_retention_days'] ) );
        }

        return( 0 );
    }

    public function getHousekeepingLastRunUtc() : string {
        if ( $this->config_read ) {
            return( trim( (string) ( $this->config['housekeeping']['last_run_utc'] ?? '' ) ) );
        }

        return( '' );
    }

    public function getHousekeepingLastTrigger() : string {
        if ( $this->config_read ) {
            return( $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_trigger'] ?? '' ) );
        }

        return( '' );
    }

    public function getHousekeepingLastMode() : string {
        if ( $this->config_read ) {
            return( $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_mode'] ?? '' ) );
        }

        return( '' );
    }

    public function getHousekeepingWebFallbackMode() : string {
        if ( $this->config_read ) {
            return( $this->normalizeHousekeepingWebFallbackMode( $this->config['housekeeping']['web_fallback_mode'] ?? 'auto' ) );
        }

        return( 'auto' );
    }

    public function getSiteBannerImage() : array {
        if ( $this->config_read ) {
            return( $this->resolveSiteImageWithDefault( $this->config['site']['images']['banner'] ?? [], 'assets/tinymash/identity/tinymash_banner.png' ) );
        }

        return( [] );
    }

    public function getSiteFaviconPngImage() : array {
        if ( $this->config_read ) {
            return( $this->resolveSiteImageWithDefault( $this->config['site']['images']['favicon_png'] ?? [], 'assets/tinymash/identity/tinymash_ico.png' ) );
        }

        return( [] );
    }

    public function getSiteFaviconIcoImage() : array {
        if ( $this->config_read ) {
            return( $this->resolveSiteImageWithDefault( $this->config['site']['images']['favicon_ico'] ?? [], 'assets/tinymash/identity/favicon_ico.ico', 'image/x-icon' ) );
        }

        return( [] );
    }

    public function getSiteOgImage() : array {
        if ( $this->config_read ) {
            return( $this->resolveSiteImageWithDefault( $this->config['site']['images']['og'] ?? [], 'assets/tinymash/identity/tinymash_og.png' ) );
        }

        return( [] );
    }

    public function getSiteBackgroundImage() : array {
        if ( $this->config_read ) {
            return( $this->normalizeSiteImage( $this->config['site']['images']['background'] ?? [] ) );
        }

        return( [] );
    }

    public function getSiteBackgroundRenderMode() : string {
        if ( $this->config_read ) {
            return( $this->normalizeBackgroundRenderMode( $this->config['site']['background_render_mode'] ?? 'scaled' ) );
        }

        return( 'scaled' );
    }

    public function getContentImagesMode() : string {
        if ( $this->config_read && ! empty( $this->config['media']['content_images_mode'] ) ) {
            return( (string) $this->config['media']['content_images_mode'] );
        }

        return( 'authenticated' );
    }

    public function getContentImageDriverPreference() : string {
        if ( $this->config_read && ! empty( $this->config['media']['image_driver'] ) ) {
            return( (string) $this->config['media']['image_driver'] );
        }

        return( 'auto' );
    }

    public function getContentImageMaxWidth() : int {
        if ( $this->config_read && isset( $this->config['media']['image_max_width'] ) ) {
            return( $this->normalizeImageDimension( $this->config['media']['image_max_width'] ) );
        }

        return( 2560 );
    }

    public function getContentImageMaxHeight() : int {
        if ( $this->config_read && isset( $this->config['media']['image_max_height'] ) ) {
            return( $this->normalizeImageDimension( $this->config['media']['image_max_height'] ) );
        }

        return( 2560 );
    }

    public function getContentImageMaxUploadMegabytes() : int {
        if ( $this->config_read && isset( $this->config['media']['image_max_upload_mb'] ) ) {
            return( $this->normalizeImageUploadMegabytes( $this->config['media']['image_max_upload_mb'] ) );
        }

        return( 10 );
    }

    public function getContentImageMaxUploadBytes() : int {
        return( $this->getContentImageMaxUploadMegabytes() * 1024 * 1024 );
    }

    public function getAllowedContentImageMimes() : array {
        if ( $this->config_read && isset( $this->config['media']['allowed_image_mimes'] ) ) {
            return( $this->normalizeAllowedContentImageMimes( $this->config['media']['allowed_image_mimes'] ) );
        }

        return( $this->getDefaultAllowedContentImageMimes() );
    }

    public function getMediaMetadataRetentionPolicy() : array {
        if ( $this->config_read && isset( $this->config['media']['metadata_retention_groups'] ) ) {
            return( $this->normalizeMediaMetadataPolicy( $this->config['media']['metadata_retention_groups'], $this->getDefaultMediaMetadataRetentionPolicy() ) );
        }

        return( $this->getDefaultMediaMetadataRetentionPolicy() );
    }

    public function getMediaMetadataPublicPolicy() : array {
        $retention_policy = $this->getMediaMetadataRetentionPolicy();
        if ( $this->config_read && isset( $this->config['media']['metadata_public_groups'] ) ) {
            return( $this->normalizeMediaMetadataPolicy( $this->config['media']['metadata_public_groups'], $this->getDefaultMediaMetadataPublicPolicy(), $retention_policy ) );
        }

        return( $this->normalizeMediaMetadataPolicy( $this->getDefaultMediaMetadataPublicPolicy(), $this->getDefaultMediaMetadataPublicPolicy(), $retention_policy ) );
    }

    public function getMediaMetadataPolicy() : array {
        return(
            [
                'retention_groups' => $this->getMediaMetadataRetentionPolicy(),
                'public_groups' => $this->getMediaMetadataPublicPolicy(),
            ]
        );
    }

    public function generatesThumbnails() : bool {
        if ( $this->config_read ) {
            return( $this->normalizeBoolean( $this->config['media']['generate_thumbnails'] ?? true ) );
        }

        return( true );
    }

    public function getThumbnailMaxWidth() : int {
        if ( $this->config_read && isset( $this->config['media']['thumbnail_max_width'] ) ) {
            return( $this->normalizeThumbnailDimension( $this->config['media']['thumbnail_max_width'] ) );
        }

        return( 320 );
    }

    public function getThumbnailMaxHeight() : int {
        if ( $this->config_read && isset( $this->config['media']['thumbnail_max_height'] ) ) {
            return( $this->normalizeThumbnailDimension( $this->config['media']['thumbnail_max_height'] ) );
        }

        return( 320 );
    }

    public function getSystemSettings() : array {
        return(
            [
                'site_name' => $this->getSiteName(),
                'site_slogan' => $this->getSiteSlogan(),
                'default_language' => $this->getDefaultLanguage(),
                'time_format' => $this->getLocaleTimeFormat(),
                'site_public' => $this->isSitePublic(),
                'allow_registrations' => $this->allowsRegistrations(),
                'maintenance' => $this->getMaintenance(),
                'admin_email' => $this->getAdminEmail(),
                'system_notifications_email' => (string) ( $this->config['site']['system_notifications_email'] ?? '' ),
                'allow_admin_password_resets' => $this->allowsAdminPasswordResets(),
                'allow_author_password_resets' => $this->allowsAuthorPasswordResets(),
                'password_reset_throttle_window_minutes' => $this->getPasswordResetThrottleWindowMinutes(),
                'password_reset_max_ip_requests' => $this->getPasswordResetMaxIpRequests(),
                'password_reset_max_identifier_requests' => $this->getPasswordResetMaxIdentifierRequests(),
                'timezone' => $this->getLocaleTimezone(),
                'week_starts_on' => $this->getWeekStartsOn(),
                'allow_username_change' => $this->canUsersChangeUsername(),
                'allow_email_change' => $this->canUsersChangeEmail(),
                'allow_secret_links' => $this->allowsSecretLinks(),
                'discourage_search_indexing' => $this->discouragesSearchIndexing(),
                'secret_link_default_expiry_days' => $this->getSecretLinkDefaultExpiryDays(),
                'filesystem_umask' => $this->getFilesystemUmask(),
                'rendered_content_cache_enabled' => $this->isRenderedContentCacheEnabled(),
                'forwarded_ip_mode' => $this->getForwardedIpMode(),
                'public_cache_warm_basic_auth_username' => $this->getPublicPageCacheWarmBasicAuthUsername(),
                'public_cache_warm_basic_auth_password' => $this->getPublicPageCacheWarmBasicAuthPassword(),
                'public_cache_warm_insecure_tls' => $this->allowsInsecureTlsForPublicPageCacheWarm(),
                'login_message' => (string) ( $this->config['site']['login_message'] ?? '' ),
                'head_tags' => $this->getPublicHeadTags(),
                'site_banner_image' => $this->getSiteBannerImage(),
                'site_favicon_png_image' => $this->getSiteFaviconPngImage(),
                'site_favicon_ico_image' => $this->getSiteFaviconIcoImage(),
                'site_og_image' => $this->getSiteOgImage(),
                'site_background_image' => $this->getSiteBackgroundImage(),
                'site_background_render_mode' => $this->getSiteBackgroundRenderMode(),
                'require_moderation' => $this->isContentModerationRequired(),
                'content_tags_enabled' => $this->areTagsEnabled(),
                'content_show_page_timestamps' => $this->showsPageTimestamps(),
                'content_revision_retention_limit' => $this->getContentRevisionRetentionLimit(),
                'content_trash_retention_days' => $this->getContentTrashRetentionDays(),
                'housekeeping_stale_draft_retention_days' => $this->getHousekeepingDraftRetentionDays(),
                'housekeeping_web_fallback_mode' => $this->getHousekeepingWebFallbackMode(),
                'notification_retention_days' => $this->getNotificationRetentionDays(),
                'notification_email_events' => $this->getNotificationEmailEvents(),
                'editor_autosave_enabled' => $this->isEditorAutosaveEnabled(),
                'editor_autosave_interval_seconds' => $this->getEditorAutosaveIntervalSeconds(),
                'editor_classic_smileys_enabled' => $this->isClassicSmileysEnabled(),
                'content_images_mode' => $this->getContentImagesMode(),
                'content_image_driver' => $this->getContentImageDriverPreference(),
                'content_image_max_width' => $this->getContentImageMaxWidth(),
                'content_image_max_height' => $this->getContentImageMaxHeight(),
                'content_image_max_upload_mb' => $this->getContentImageMaxUploadMegabytes(),
                'content_image_allowed_mimes' => $this->getAllowedContentImageMimes(),
                'content_media_metadata_retention_groups' => $this->getMediaMetadataRetentionPolicy(),
                'content_media_metadata_public_groups' => $this->getMediaMetadataPublicPolicy(),
                'content_generate_thumbnails' => $this->generatesThumbnails(),
                'content_thumbnail_max_width' => $this->getThumbnailMaxWidth(),
                'content_thumbnail_max_height' => $this->getThumbnailMaxHeight(),
                'smtp_enabled' => $this->normalizeBoolean( $this->config['smtp']['enabled'] ?? false ),
                'smtp_host' => (string) ( $this->config['smtp']['host'] ?? '' ),
                'smtp_port' => (int) ( $this->config['smtp']['port'] ?? 587 ),
                'smtp_username' => (string) ( $this->config['smtp']['username'] ?? '' ),
                'smtp_password' => (string) ( $this->config['smtp']['password'] ?? '' ),
                'smtp_encryption' => (string) ( $this->config['smtp']['encryption'] ?? 'tls' ),
                'smtp_from_email' => (string) ( $this->config['smtp']['from_email'] ?? '' ),
                'smtp_from_name' => (string) ( $this->config['smtp']['from_name'] ?? '' ),
                'smtp_reply_to_email' => (string) ( $this->config['smtp']['reply_to_email'] ?? '' ),
            ]
        );
    }

    public function getMenusConfig() : array {
        if ( ! $this->config_read ) {
            return( [ 'primary' => [], 'footer' => [] ] );
        }

        $locations = is_array( $this->config['menus']['locations'] ?? null ) ? $this->config['menus']['locations'] : [];
        return(
            [
                'primary' => is_array( $locations['primary'] ?? null ) ? $locations['primary'] : [],
                'footer' => is_array( $locations['footer'] ?? null ) ? $locations['footer'] : [],
            ]
        );
    }

    public function getPublicThemeKey() : string {
        return( $this->public_theme_key !== '' ? $this->public_theme_key : 'baseline' );
    }

    public function getPublicThemeSettings( string $theme_key = '' ) : array {
        $theme_key = $this->normalizePublicThemeKey( $theme_key !== '' ? $theme_key : $this->getPublicThemeKey() );
        if ( $this->config_read
            && ! empty( $this->config['themes']['public']['settings'][$theme_key] )
            && is_array( $this->config['themes']['public']['settings'][$theme_key] ) ) {
            return( $this->config['themes']['public']['settings'][$theme_key] );
        }

        return( [] );
    }

    public function getPluginStates() : array {
        if ( ! $this->config_read ) {
            return( [] );
        }

        $states = is_array( $this->config['plugins']['states'] ?? null ) ? $this->config['plugins']['states'] : [];
        $normalized_states = [];
        foreach ( $states as $plugin_key => $state ) {
            if ( ! is_string( $plugin_key ) ) {
                continue;
            }

            $canonical_key = $this->canonicalizePluginKey( $plugin_key );
            if ( $canonical_key === '' ) {
                continue;
            }

            $normalized_states[$canonical_key] = $this->normalizeBoolean( $state );
        }

        return( $normalized_states );
    }

    public function isPluginActive( string $plugin_key, bool $default_state = false ) : bool {
        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        if ( $plugin_key === '' || ! $this->config_read ) {
            return( $default_state );
        }

        $plugin_states = $this->getPluginStates();
        if ( ! array_key_exists( $plugin_key, $plugin_states ) ) {
            return( $default_state );
        }

        return( $this->normalizeBoolean( $plugin_states[$plugin_key] ) );
    }

    public function getPluginSettings( string $plugin_key ) : array {
        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        if ( $plugin_key === '' || ! $this->config_read ) {
            return( [] );
        }

        $plugin_settings = $this->config['plugins']['settings'][$plugin_key] ?? [];
        if ( ! is_array( $plugin_settings ) && $plugin_key === 'footer' ) {
            $plugin_settings = $this->config['plugins']['settings']['visitor-context'] ?? [];
        }
        return( is_array( $plugin_settings ) ? $plugin_settings : [] );
    }

    protected function normalizeBoolean( mixed $value ) : bool {
        return( $value === true || $value === 1 || $value === '1' || $value === 'true' );
    }

    protected function normalizeAutosaveIntervalSeconds( mixed $value ) : int {
        $interval_seconds = (int) $value;
        if ( $interval_seconds < 30 ) {
            return( 120 );
        }
        if ( $interval_seconds > 180 ) {
            return( 180 );
        }

        return( $interval_seconds );
    }

    protected function normalizeRevisionRetentionLimit( mixed $value ) : int {
        $revision_limit = (int) $value;
        if ( $revision_limit < 0 ) {
            return( 20 );
        }
        if ( $revision_limit > 100 ) {
            return( 100 );
        }

        return( $revision_limit );
    }

    protected function normalizeTrashRetentionDays( mixed $value ) : int {
        $retention_days = (int) $value;
        if ( $retention_days < 0 ) {
            return( 30 );
        }
        if ( $retention_days > 90 ) {
            return( 90 );
        }

        return( $retention_days );
    }

    protected function normalizeHousekeepingDraftRetentionDays( mixed $value ) : int {
        $retention_days = (int) $value;
        if ( $retention_days < 0 ) {
            return( 0 );
        }
        if ( $retention_days > 365 ) {
            return( 365 );
        }

        return( $retention_days );
    }

    protected function normalizeHousekeepingWebFallbackMode( mixed $value ) : string {
        $mode = strtolower( trim( (string) $value ) );
        if ( ! in_array( $mode, [ 'auto', 'off' ], true ) ) {
            return( 'auto' );
        }

        return( $mode );
    }

    protected function normalizeForwardedIpMode( mixed $value ) : string {
        $mode = strtolower( trim( (string) $value ) );
        if ( ! in_array( $mode, [ 'off', 'cf-connecting-ip', 'x-forwarded-for-first' ], true ) ) {
            return( 'off' );
        }

        return( $mode );
    }

    protected function normalizeHousekeepingRunDescriptor( mixed $value ) : string {
        $descriptor = strtolower( trim( (string) $value ) );
        if ( $descriptor === '' ) {
            return( '' );
        }

        if ( preg_match( '/^[a-z0-9_-]{1,32}$/', $descriptor ) !== 1 ) {
            return( '' );
        }

        return( $descriptor );
    }

    protected function normalizeNotificationRetentionDays( mixed $value ) : int {
        $retention_days = (int) $value;
        if ( $retention_days < 7 ) {
            return( 30 );
        }
        if ( $retention_days > 60 ) {
            return( 60 );
        }

        return( $retention_days );
    }

    protected function normalizePasswordResetThrottleWindowMinutes( mixed $value ) : int {
        $minutes = (int) $value;
        if ( $minutes < 1 ) {
            return( 60 );
        }
        if ( $minutes > 1440 ) {
            return( 1440 );
        }

        return( $minutes );
    }

    protected function normalizePasswordResetThrottleCount( mixed $value, int $default ) : int {
        $count = (int) $value;
        if ( $count < 1 ) {
            return( $default );
        }
        if ( $count > 100 ) {
            return( 100 );
        }

        return( $count );
    }

    protected function normalizeSiteImage( mixed $site_image ) : array {
        if ( ! is_array( $site_image ) ) {
            return( [] );
        }

        $normalized_site_image = [
            'media_id' => trim( (string) ( $site_image['media_id'] ?? '' ) ),
            'owner_username' => strtolower( trim( (string) ( $site_image['owner_username'] ?? '' ) ) ),
            'filename' => basename( trim( (string) ( $site_image['filename'] ?? '' ) ) ),
            'url' => trim( (string) ( $site_image['url'] ?? '' ) ),
            'alt_text' => trim( (string) ( $site_image['alt_text'] ?? '' ) ),
            'mime' => trim( (string) ( $site_image['mime'] ?? '' ) ),
            'width' => max( 0, (int) ( $site_image['width'] ?? 0 ) ),
            'height' => max( 0, (int) ( $site_image['height'] ?? 0 ) ),
            'bytes' => max( 0, (int) ( $site_image['bytes'] ?? 0 ) ),
            'derivative_key' => trim( (string) ( $site_image['derivative_key'] ?? '' ) ),
        ];

        if ( $normalized_site_image['media_id'] === '' || $normalized_site_image['url'] === '' ) {
            return( [] );
        }

        return( $normalized_site_image );
    }

    protected function normalizeBackgroundRenderMode( mixed $value ) : string {
        $mode = strtolower( trim( (string) $value ) );
        return( in_array( $mode, [ 'as_is', 'centered', 'scaled', 'tiled' ], true ) ? $mode : 'scaled' );
    }

    protected function resolveSiteImageWithDefault( mixed $configured_image, string $default_public_path, string $default_mime = '' ) : array {
        $configured_image = $this->normalizeSiteImage( $configured_image );
        if ( ! empty( $configured_image ) ) {
            return( $configured_image );
        }

        return( $this->buildDefaultSiteImage( $default_public_path, $default_mime ) );
    }

    protected function buildDefaultSiteImage( string $default_public_path, string $default_mime = '' ) : array {
        $default_public_path = '/' . ltrim( str_replace( DIRECTORY_SEPARATOR, '/', trim( $default_public_path ) ), '/' );
        $default_filename = basename( $default_public_path );
        if ( $default_filename === '' ) {
            return( [] );
        }

        $filesystem_path = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, ltrim( $default_public_path, '/' ) );
        if ( ! is_file( $filesystem_path ) || ! is_readable( $filesystem_path ) ) {
            return( [] );
        }

        $width = 0;
        $height = 0;
        $mime = trim( $default_mime );
        $image_info = @ getimagesize( $filesystem_path );
        if ( is_array( $image_info ) ) {
            $width = max( 0, (int) ( $image_info[0] ?? 0 ) );
            $height = max( 0, (int) ( $image_info[1] ?? 0 ) );
            if ( $mime === '' && ! empty( $image_info['mime'] ) ) {
                $mime = trim( (string) $image_info['mime'] );
            }
        }

        if ( $mime === '' && function_exists( 'mime_content_type' ) ) {
            $detected_mime = @ mime_content_type( $filesystem_path );
            if ( is_string( $detected_mime ) ) {
                $mime = trim( $detected_mime );
            }
        }

        return(
            [
                'media_id' => '',
                'owner_username' => '',
                'filename' => $default_filename,
                'url' => $default_public_path,
                'alt_text' => '',
                'mime' => $mime,
                'width' => $width,
                'height' => $height,
                'bytes' => max( 0, (int) @ filesize( $filesystem_path ) ),
                'derivative_key' => '',
            ]
        );
    }

    protected function normalizeImageDimension( mixed $value ) : int {
        $dimension = (int) $value;
        if ( $dimension < 256 ) {
            return( 2560 );
        }
        if ( $dimension > 8192 ) {
            return( 8192 );
        }

        return( $dimension );
    }

    protected function normalizeThumbnailDimension( mixed $value ) : int {
        $dimension = (int) $value;
        if ( $dimension < 64 ) {
            return( 320 );
        }
        if ( $dimension > 2048 ) {
            return( 2048 );
        }

        return( $dimension );
    }

    protected function normalizeImageUploadMegabytes( mixed $value ) : int {
        $megabytes = (int) $value;
        if ( $megabytes < 1 ) {
            return( 10 );
        }
        if ( $megabytes > 200 ) {
            return( 200 );
        }

        return( $megabytes );
    }

    protected function getDefaultAllowedContentImageMimes() : array {
        return(
            [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/avif',
            ]
        );
    }

    protected function normalizeAllowedContentImageMimes( mixed $value ) : array {
        $allowed_mimes = is_array( $value ) ? $value : [];
        $normalized_mimes = [];
        $allowed_values = $this->getDefaultAllowedContentImageMimes();

        foreach ( $allowed_mimes as $mime ) {
            if ( ! is_string( $mime ) ) {
                continue;
            }

            $normalized_mime = strtolower( trim( $mime ) );
            if ( $normalized_mime === '' || ! in_array( $normalized_mime, $allowed_values, true ) || in_array( $normalized_mime, $normalized_mimes, true ) ) {
                continue;
            }

            $normalized_mimes[] = $normalized_mime;
        }

        return( ! empty( $normalized_mimes ) ? $normalized_mimes : $allowed_values );
    }

    protected function getMediaMetadataGroupKeys() : array {
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

    protected function getDefaultMediaMetadataRetentionPolicy() : array {
        return(
            [
                'camera_info',
                'exposure_info',
                'date_time_info',
            ]
        );
    }

    protected function getDefaultMediaMetadataPublicPolicy() : array {
        return( [] );
    }

    protected function normalizeMediaMetadataPolicy( mixed $value, array $default_groups, ?array $allowed_groups = null ) : array {
        $incoming_groups = is_array( $value ) ? $value : $default_groups;
        $valid_groups = is_array( $allowed_groups ) ? $allowed_groups : $this->getMediaMetadataGroupKeys();
        $normalized_groups = [];

        foreach ( $incoming_groups as $group ) {
            if ( ! is_string( $group ) ) {
                continue;
            }

            $normalized_group = strtolower( trim( $group ) );
            if ( $normalized_group === '' || ! in_array( $normalized_group, $valid_groups, true ) || in_array( $normalized_group, $normalized_groups, true ) ) {
                continue;
            }

            $normalized_groups[] = $normalized_group;
        }

        return( $normalized_groups );
    }

    protected function normalizeSecretLinkExpiryDays( mixed $value ) : int {
        $days = (int) $value;
        if ( $days < 1 ) {
            return( 60 );
        }
        if ( $days > 365 ) {
            return( 365 );
        }

        return( $days );
    }

    protected function normalizeFilesystemUmask( mixed $value ) : string {
        return( tinymash_normalize_umask( $value, '0007' ) );
    }

    protected function normalizePublicThemeKey( string $theme_key ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $theme_key ) ?: 'baseline' );
    }

    protected function normalizePluginKey( string $plugin_key ) : string {
        $plugin_key = strtolower( trim( $plugin_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $plugin_key ) ?? '' );
    }

    protected function canonicalizePluginKey( string $plugin_key ) : string {
        $plugin_key = $this->normalizePluginKey( $plugin_key );
        return(
            match ( $plugin_key ) {
                'visitor-context' => 'footer',
                default => $plugin_key,
            }
        );
    }

}// AdminController
