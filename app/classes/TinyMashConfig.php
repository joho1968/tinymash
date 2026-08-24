<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashConfigNormalization.php';
require_once __DIR__ . '/TinyMashConfigFileReader.php';

use flight\Engine;
use flight\net\Router;

class TinyMashConfig {

    use TinyMashConfigNormalization;
    use TinyMashConfigFileReader;

    public const PUBLIC_HEAD_LINK_RELS = [
        'alternate', 'author', 'authorization_endpoint', 'license', 'me', 'micropub',
        'openid.delegate', 'openid.server', 'pingback', 'search', 'token_endpoint', 'webmention',
    ];
    public const PUBLIC_HEAD_RESERVED_META_NAMES = [ 'color-scheme', 'theme-color', 'title' ];

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

        $this->config_json = $this->readConfigJson();
        if ( ! is_string( $this->config_json ) || $this->config_json === '' ) {
            return( $this->failConfigRead() );
        }

        $decoded_config = @ json_decode( $this->config_json, true, 16 );
        if ( ! is_array( $decoded_config ) || empty( $decoded_config ) ) {
            return( $this->failConfigRead() );
        }

        $this->config = $decoded_config;
        if ( ! isset( $this->config['site'] ) || ! is_array( $this->config['site'] ) ) {
            $this->config['site'] = [];
        }
        $route_settings = [
            'base_url' => 'app.url',
            'login_url' => 'login.url',
            'admin_url' => 'admin.url',
        ];
        // Empty route values inherit the active application paths.
        foreach ( $route_settings as $config_key => $app_key ) {
            if ( empty( $this->config['site'][$config_key] ) ) {
                $this->config['site'][$config_key] = (string) $this->app->get( $app_key );
            }
        }
        $this->applyDefaults();

        foreach ( $route_settings as $config_key => $app_key ) {
            $this->app->set( $app_key, $this->config['site'][$config_key] );
        }
        $this->app->set( 'password.min', $this->config['site']['security_min_password'] );
        $this->app->set( 'site.name', $this->config['site']['name'] );
        $this->app->set( 'site.slogan', $this->config['site']['slogan'] );
        $this->app->set( 'site.default_language', $this->config['site']['default_language'] );
        $this->app->set( 'site.allow_registrations', $this->config['site']['allow_registrations'] );
        $this->app->set( 'site.is_public', $this->config['site']['is_public'] );
        $this->app->set( 'site.admin_email', $this->config['site']['admin_email'] );
        $this->app->set( 'site.allow_admin_password_resets', $this->config['site']['allow_admin_password_resets'] );
        $this->app->set( 'site.allow_author_password_resets', $this->config['site']['allow_author_password_resets'] );
        $this->app->set( 'site.password_reset_throttle_window_minutes', $this->config['site']['password_reset_throttle_window_minutes'] );
        $this->app->set( 'site.password_reset_max_ip_requests', $this->config['site']['password_reset_max_ip_requests'] );
        $this->app->set( 'site.password_reset_max_identifier_requests', $this->config['site']['password_reset_max_identifier_requests'] );
        $this->app->set( 'site.filesystem_umask', $this->config['site']['filesystem_umask'] );
        tinymash_apply_runtime_umask( $this->config['site']['filesystem_umask'] );
        $this->app->set( 'site.rendered_content_cache_enabled', $this->config['site']['rendered_content_cache_enabled'] );
        $this->app->set( 'site.forwarded_ip_mode', $this->config['site']['forwarded_ip_mode'] );
        $this->app->set( 'site.week_starts_on', $this->config['site']['week_starts_on'] );
        $this->app->set( 'site.allow_username_change', $this->config['site']['allow_username_change'] );
        $this->app->set( 'site.allow_email_change', $this->config['site']['allow_email_change'] );

        $this->site_maintenance = $this->config['site']['maintenance'];
        $this->content_moderation_required = $this->config['content']['require_moderation'];
        $this->public_theme_key = $this->normalizePublicThemeKey( $this->config['themes']['public']['current'] );
        $this->config_error = false;
        $this->config_read = true;
        return( true );
    }

    protected function failConfigRead() : bool {
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
            if ( $name !== '' && in_array( $name, self::PUBLIC_HEAD_RESERVED_META_NAMES, true ) ) {
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

        $normalized_link = [ 'type' => 'link', 'rel' => $rel, 'href' => $href ];
        $mime = strtolower( trim( (string) ( $tag['mime'] ?? '' ) ) );
        if ( $mime !== '' ) {
            if ( preg_match( '~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~', $mime ) !== 1 ) {
                return( null );
            }
            $normalized_link['mime'] = $mime;
        }

        $title = function_exists( 'mb_trim' ) ? mb_trim( (string) ( $tag['title'] ?? '' ) ) : trim( (string) ( $tag['title'] ?? '' ) );
        if ( $title !== '' ) {
            if ( mb_strlen( $title ) > 200 ) {
                return( null );
            }
            $normalized_link['title'] = $title;
        }

        return( $normalized_link );
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

    public function getEditorDefaultMode() : string {
        if ( $this->config_read ) {
            return( $this->normalizeEditorMode( $this->config['editor']['default_mode'] ?? 'simple' ) );
        }

        return( 'simple' );
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
                'editor_default_mode' => $this->getEditorDefaultMode(),
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

}
