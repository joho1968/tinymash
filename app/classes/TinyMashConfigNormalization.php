<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashClientIpResolver.php';

trait TinyMashConfigNormalization {

    protected function applyDefaults() : void {
        if ( ! isset( $this->config['site'] ) || ! is_array( $this->config['site'] ) ) {
            $this->config['site'] = [];
        }
        if ( ! isset( $this->config['locale'] ) || ! is_array( $this->config['locale'] ) ) {
            $this->config['locale'] = [];
        }
        if ( ! isset( $this->config['content'] ) || ! is_array( $this->config['content'] ) ) {
            $this->config['content'] = [];
        }
        if ( ! isset( $this->config['notifications'] ) || ! is_array( $this->config['notifications'] ) ) {
            $this->config['notifications'] = [];
        }
        if ( ! isset( $this->config['housekeeping'] ) || ! is_array( $this->config['housekeeping'] ) ) {
            $this->config['housekeeping'] = [];
        }
        if ( empty( $this->config['housekeeping']['web_fallback_mode'] ) ) {
            $this->config['housekeeping']['web_fallback_mode'] = 'auto';
        }
        if ( ! isset( $this->config['housekeeping']['last_trigger'] ) ) {
            $this->config['housekeeping']['last_trigger'] = '';
        }
        if ( ! isset( $this->config['housekeeping']['last_mode'] ) ) {
            $this->config['housekeeping']['last_mode'] = '';
        }
        if ( ! isset( $this->config['site']['images'] ) || ! is_array( $this->config['site']['images'] ) ) {
            $this->config['site']['images'] = [];
        }
        if ( ! isset( $this->config['editor'] ) || ! is_array( $this->config['editor'] ) ) {
            $this->config['editor'] = [];
        }
        if ( ! isset( $this->config['media'] ) || ! is_array( $this->config['media'] ) ) {
            $this->config['media'] = [];
        }
        if ( ! isset( $this->config['themes'] ) || ! is_array( $this->config['themes'] ) ) {
            $this->config['themes'] = [];
        }
        if ( ! isset( $this->config['menus'] ) || ! is_array( $this->config['menus'] ) ) {
            $this->config['menus'] = [];
        }
        if ( ! isset( $this->config['menus']['locations'] ) || ! is_array( $this->config['menus']['locations'] ) ) {
            $this->config['menus']['locations'] = [];
        }
        foreach ( [ 'primary', 'footer' ] as $menu_location ) {
            if ( ! isset( $this->config['menus']['locations'][$menu_location] ) || ! is_array( $this->config['menus']['locations'][$menu_location] ) ) {
                $this->config['menus']['locations'][$menu_location] = [];
            }
        }
        if ( ! isset( $this->config['themes']['public'] ) || ! is_array( $this->config['themes']['public'] ) ) {
            $this->config['themes']['public'] = [];
        }
        if ( ! isset( $this->config['themes']['public']['current'] ) || ! is_string( $this->config['themes']['public']['current'] ) || trim( (string) $this->config['themes']['public']['current'] ) === '' ) {
            $this->config['themes']['public']['current'] = 'baseline';
        }
        if ( ! isset( $this->config['themes']['public']['settings'] ) || ! is_array( $this->config['themes']['public']['settings'] ) ) {
            $this->config['themes']['public']['settings'] = [];
        }
        if ( ! isset( $this->config['themes']['public']['settings']['baseline'] ) || ! is_array( $this->config['themes']['public']['settings']['baseline'] ) ) {
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
        if ( ! isset( $this->config['smtp'] ) || ! is_array( $this->config['smtp'] ) ) {
            $this->config['smtp'] = [];
        }
        if ( ! isset( $this->config['plugins'] ) || ! is_array( $this->config['plugins'] ) ) {
            $this->config['plugins'] = [];
        }
        if ( ! isset( $this->config['plugins']['states'] ) || ! is_array( $this->config['plugins']['states'] ) ) {
            $this->config['plugins']['states'] = [];
        }
        if ( ! isset( $this->config['plugins']['settings'] ) || ! is_array( $this->config['plugins']['settings'] ) ) {
            $this->config['plugins']['settings'] = [];
        }

        $this->config['site']['name'] = (string) ( $this->config['site']['name'] ?? 'tinymash' );
        if ( empty( $this->config['site']['name'] ) ) {
            $this->config['site']['name'] = 'tinymash';
        }
        $this->config['site']['slogan'] = (string) ( $this->config['site']['slogan'] ?? '' );
        $this->config['site']['base_url'] = (string) ( $this->config['site']['base_url'] ?? '' );
        $this->config['site']['login_url'] = (string) ( $this->config['site']['login_url'] ?? '/admin/login' );
        $this->config['site']['admin_url'] = (string) ( $this->config['site']['admin_url'] ?? '/admin' );
        $this->config['site']['security_min_password'] = (int) ( $this->config['site']['security_min_password'] ?? 8 );
        if ( $this->config['site']['security_min_password'] < 1 ) {
            $this->config['site']['security_min_password'] = 8;
        }
        $this->config['site']['default_language'] = TinyMashConfig::normalizeHtmlLanguageTag( (string) ( $this->config['site']['default_language'] ?? 'en' ) );
        $this->config['site']['allow_registrations'] = $this->normalizeBoolean( $this->config['site']['allow_registrations'] ?? false );
        $this->config['site']['is_public'] = $this->normalizeBoolean( $this->config['site']['is_public'] ?? false );
        $this->config['site']['maintenance'] = $this->normalizeBoolean( $this->config['site']['maintenance'] ?? false );
        $this->config['site']['admin_email'] = (string) ( $this->config['site']['admin_email'] ?? '' );
        $this->config['site']['system_notifications_email'] = (string) ( $this->config['site']['system_notifications_email'] ?? '' );
        $this->config['site']['allow_admin_password_resets'] = $this->normalizeBoolean( $this->config['site']['allow_admin_password_resets'] ?? false );
        $this->config['site']['allow_author_password_resets'] = $this->normalizeBoolean( $this->config['site']['allow_author_password_resets'] ?? true );
        $this->config['site']['password_reset_throttle_window_minutes'] = $this->normalizePasswordResetThrottleWindowMinutes( $this->config['site']['password_reset_throttle_window_minutes'] ?? 60 );
        $this->config['site']['password_reset_max_ip_requests'] = $this->normalizePasswordResetThrottleCount( $this->config['site']['password_reset_max_ip_requests'] ?? 5, 5 );
        $this->config['site']['password_reset_max_identifier_requests'] = $this->normalizePasswordResetThrottleCount( $this->config['site']['password_reset_max_identifier_requests'] ?? 3, 3 );
        $this->config['site']['week_starts_on'] = $this->normalizeWeekStartsOn( $this->config['site']['week_starts_on'] ?? 'monday' );
        $this->config['site']['allow_username_change'] = $this->normalizeBoolean( $this->config['site']['allow_username_change'] ?? false );
        $this->config['site']['allow_email_change'] = $this->normalizeBoolean( $this->config['site']['allow_email_change'] ?? false );
        $this->config['site']['allow_secret_links'] = $this->normalizeBoolean( $this->config['site']['allow_secret_links'] ?? false );
        $this->config['site']['discourage_search_indexing'] = $this->normalizeBoolean( $this->config['site']['discourage_search_indexing'] ?? false );
        $this->config['site']['secret_link_default_expiry_days'] = $this->normalizeSecretLinkExpiryDays( $this->config['site']['secret_link_default_expiry_days'] ?? 60 );
        $this->config['site']['filesystem_umask'] = $this->normalizeFilesystemUmask( $this->config['site']['filesystem_umask'] ?? '0007' );
        $this->config['site']['rendered_content_cache_enabled'] = $this->normalizeBoolean( $this->config['site']['rendered_content_cache_enabled'] ?? true );
        $this->config['site']['unknown_shortcode_mode'] = $this->normalizeUnknownShortcodeMode( $this->config['site']['unknown_shortcode_mode'] ?? 'code' );
        $this->config['site']['forwarded_ip_mode'] = $this->normalizeForwardedIpMode( $this->config['site']['forwarded_ip_mode'] ?? 'off' );
        try {
            $this->config['site']['trusted_proxy_cidrs'] = TinyMashClientIpResolver::parseTrustedRanges( $this->config['site']['trusted_proxy_cidrs'] ?? [] );
        } catch ( \InvalidArgumentException ) {
            $this->config['site']['trusted_proxy_cidrs'] = [];
        }
        $this->config['site']['public_cache_warm_basic_auth_username'] = trim( (string) ( $this->config['site']['public_cache_warm_basic_auth_username'] ?? '' ) );
        $this->config['site']['public_cache_warm_basic_auth_password'] = (string) ( $this->config['site']['public_cache_warm_basic_auth_password'] ?? '' );
        if ( $this->config['site']['public_cache_warm_basic_auth_username'] === '' ) {
            $this->config['site']['public_cache_warm_basic_auth_password'] = '';
        }
        $this->config['site']['public_cache_warm_insecure_tls'] = $this->normalizeBoolean( $this->config['site']['public_cache_warm_insecure_tls'] ?? false );
        $this->config['site']['login_message'] = (string) ( $this->config['site']['login_message'] ?? '' );
        $this->config['site']['head_tags'] = TinyMashConfig::normalizePublicHeadTags( $this->config['site']['head_tags'] ?? [] );
        $this->config['site']['images']['banner'] = $this->normalizeSiteImage( $this->config['site']['images']['banner'] ?? [] );
        $this->config['site']['images']['favicon_png'] = $this->normalizeSiteImage( $this->config['site']['images']['favicon_png'] ?? [] );
        $this->config['site']['images']['favicon_ico'] = $this->normalizeSiteImage( $this->config['site']['images']['favicon_ico'] ?? [] );
        $this->config['site']['images']['og'] = $this->normalizeSiteImage( $this->config['site']['images']['og'] ?? [] );
        $this->config['site']['images']['background'] = $this->normalizeSiteImage( $this->config['site']['images']['background'] ?? [] );
        $this->config['site']['background_render_mode'] = $this->normalizeBackgroundRenderMode( $this->config['site']['background_render_mode'] ?? 'scaled' );
        $this->config['locale']['date'] = (string) ( $this->config['locale']['date'] ?? '' );
        $this->config['locale']['time'] = (string) ( $this->config['locale']['time'] ?? '' );
        $this->config['locale']['timezone'] = (string) ( $this->config['locale']['timezone'] ?? '' );
        if ( $this->config['locale']['date'] === '' ) {
            $this->config['locale']['date'] = 'd-M-Y';
        }
        if ( $this->config['locale']['time'] === '' ) {
            $this->config['locale']['time'] = 'H:i (T)';
        }
        if ( $this->config['locale']['timezone'] === '' ) {
            $this->config['locale']['timezone'] = 'UTC';
        }
        $this->config['content']['require_moderation'] = $this->normalizeBoolean( $this->config['content']['require_moderation'] ?? false );
        $this->config['content']['tags_enabled'] = $this->normalizeBoolean( $this->config['content']['tags_enabled'] ?? true );
        $this->config['content']['show_page_timestamps'] = $this->normalizeBoolean( $this->config['content']['show_page_timestamps'] ?? false );
        $this->config['content']['revision_retention_limit'] = $this->normalizeRevisionRetentionLimit( $this->config['content']['revision_retention_limit'] ?? 20 );
        $this->config['content']['trash_retention_days'] = $this->normalizeTrashRetentionDays( $this->config['content']['trash_retention_days'] ?? 30 );
        $this->config['housekeeping']['stale_draft_retention_days'] = $this->normalizeHousekeepingDraftRetentionDays( $this->config['housekeeping']['stale_draft_retention_days'] ?? 0 );
        $this->config['housekeeping']['last_run_utc'] = trim( (string) ( $this->config['housekeeping']['last_run_utc'] ?? '' ) );
        $this->config['housekeeping']['last_trigger'] = $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_trigger'] ?? '' );
        $this->config['housekeeping']['last_mode'] = $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_mode'] ?? '' );
        $this->config['housekeeping']['web_fallback_mode'] = $this->normalizeHousekeepingWebFallbackMode( $this->config['housekeeping']['web_fallback_mode'] ?? 'auto' );
        $this->config['notifications']['retention_days'] = $this->normalizeNotificationRetentionDays( $this->config['notifications']['retention_days'] ?? 30 );
        if ( ! isset( $this->config['notifications']['email_events'] ) || ! is_array( $this->config['notifications']['email_events'] ) ) {
            $this->config['notifications']['email_events'] = [];
        }
        foreach ( [ 'moderation_required', 'profile_email_changed', 'user_lockout', 'component_updates' ] as $notification_event_key ) {
            $this->config['notifications']['email_events'][$notification_event_key] = $this->normalizeBoolean( $this->config['notifications']['email_events'][$notification_event_key] ?? in_array( $notification_event_key, [ 'user_lockout', 'component_updates' ], true ) );
        }
        $this->config['editor']['autosave_enabled'] = $this->normalizeBoolean( $this->config['editor']['autosave_enabled'] ?? true );
        $this->config['editor']['autosave_interval_seconds'] = $this->normalizeAutosaveIntervalSeconds( $this->config['editor']['autosave_interval_seconds'] ?? 120 );
        $this->config['editor']['classic_smileys_enabled'] = $this->normalizeBoolean( $this->config['editor']['classic_smileys_enabled'] ?? true );
        $this->config['editor']['default_mode'] = $this->normalizeEditorMode( $this->config['editor']['default_mode'] ?? 'simple' );
        $this->config['media']['content_images_mode'] = (string) ( in_array( strtolower( (string) ( $this->config['media']['content_images_mode'] ?? 'authenticated' ) ), [ 'disabled', 'admins_only', 'authenticated' ], true ) ? strtolower( (string) ( $this->config['media']['content_images_mode'] ?? 'authenticated' ) ) : 'authenticated' );
        $this->config['media']['image_driver'] = (string) ( in_array( strtolower( (string) ( $this->config['media']['image_driver'] ?? 'auto' ) ), [ 'auto', 'imagick', 'gd', 'none' ], true ) ? strtolower( (string) ( $this->config['media']['image_driver'] ?? 'auto' ) ) : 'auto' );
        $this->config['media']['image_max_width'] = $this->normalizeImageDimension( $this->config['media']['image_max_width'] ?? 2560 );
        $this->config['media']['image_max_height'] = $this->normalizeImageDimension( $this->config['media']['image_max_height'] ?? 2560 );
        $this->config['media']['image_max_upload_mb'] = $this->normalizeImageUploadMegabytes( $this->config['media']['image_max_upload_mb'] ?? 10 );
        $this->config['media']['allowed_image_mimes'] = $this->normalizeAllowedContentImageMimes( $this->config['media']['allowed_image_mimes'] ?? [] );
        $this->config['media']['metadata_retention_groups'] = $this->normalizeMediaMetadataPolicy( $this->config['media']['metadata_retention_groups'] ?? $this->getDefaultMediaMetadataRetentionPolicy(), $this->getDefaultMediaMetadataRetentionPolicy() );
        $this->config['media']['metadata_public_groups'] = $this->normalizeMediaMetadataPolicy( $this->config['media']['metadata_public_groups'] ?? $this->getDefaultMediaMetadataPublicPolicy(), $this->getDefaultMediaMetadataPublicPolicy(), $this->config['media']['metadata_retention_groups'] );
        $this->config['media']['generate_thumbnails'] = $this->normalizeBoolean( $this->config['media']['generate_thumbnails'] ?? true );
        $this->config['media']['thumbnail_max_width'] = $this->normalizeThumbnailDimension( $this->config['media']['thumbnail_max_width'] ?? 320 );
        $this->config['media']['thumbnail_max_height'] = $this->normalizeThumbnailDimension( $this->config['media']['thumbnail_max_height'] ?? 320 );
        $this->config['smtp']['enabled'] = $this->normalizeBoolean( $this->config['smtp']['enabled'] ?? false );
        $this->config['smtp']['host'] = (string) ( $this->config['smtp']['host'] ?? '' );
        $this->config['smtp']['port'] = (int) ( $this->config['smtp']['port'] ?? 587 );
        if ( $this->config['smtp']['port'] < 1 ) {
            $this->config['smtp']['port'] = 587;
        }
        $this->config['smtp']['username'] = (string) ( $this->config['smtp']['username'] ?? '' );
        $this->config['smtp']['password'] = (string) ( $this->config['smtp']['password'] ?? '' );
        $this->config['smtp']['encryption'] = strtolower( (string) ( $this->config['smtp']['encryption'] ?? 'tls' ) );
        if ( ! in_array( $this->config['smtp']['encryption'], [ 'none', 'ssl', 'tls' ], true ) ) {
            $this->config['smtp']['encryption'] = 'tls';
        }
        $this->config['smtp']['from_email'] = (string) ( $this->config['smtp']['from_email'] ?? '' );
        $this->config['smtp']['from_name'] = (string) ( $this->config['smtp']['from_name'] ?? '' );
        $this->config['smtp']['reply_to_email'] = (string) ( $this->config['smtp']['reply_to_email'] ?? '' );
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

    protected function normalizeEditorMode( mixed $mode ) : string {
        $mode = strtolower( trim( (string) $mode ) );
        return( in_array( $mode, [ 'simple', 'focused', 'full' ], true ) ? $mode : 'simple' );
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

    protected function normalizeWeekStartsOn( mixed $value ) : string {
        $day = strtolower( trim( (string) $value ) );
        $valid_days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
        return( in_array( $day, $valid_days, true ) ? $day : 'monday' );
    }

    protected function normalizeForwardedIpMode( mixed $value ) : string {
        $mode = strtolower( trim( (string) $value ) );
        if ( $mode === 'x-forwarded-for-first' ) {
            $mode = 'x-forwarded-for';
        }
        if ( ! in_array( $mode, [ 'off', 'cf-connecting-ip', 'x-forwarded-for' ], true ) ) {
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

    protected function normalizeSecretLinkExpiryDays( mixed $value ) : int {
        if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^\d{1,3}$/D', trim( $value ) ) === 1 ) ) {
            return( 60 );
        }
        $days = (int) $value;
        if ( $days > 365 ) {
            return( 365 );
        }

        return( $days );
    }

    protected function normalizeFilesystemUmask( mixed $value ) : string {
        return( tinymash_normalize_umask( $value, '0007' ) );
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
            'alt_text' => mb_trim( (string) ( $site_image['alt_text'] ?? '' ) ),
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
        $allowed_values = $this->getDefaultAllowedContentImageMimes();
        $incoming = is_array( $value ) ? $value : [];
        $normalized_mimes = [];

        foreach ( $incoming as $mime ) {
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

    protected function normalizePublicThemeKey( string $theme_key ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $theme_key ) ?: 'baseline' );
    }

    protected function normalizePluginKey( string $plugin_key ) : string {
        $plugin_key = strtolower( trim( $plugin_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $plugin_key ) ?? '' );
    }

    protected function normalizeUnknownShortcodeMode( mixed $mode ) : string {
        $mode = strtolower( trim( (string) $mode ) );
        return( $mode === 'hide' ? 'hide' : 'code' );
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

}
