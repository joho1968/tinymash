<?php
namespace app\classes;

class TinyMashConfigIO {

    protected string $config_json_filename = '';
    protected string|bool $config_json = false;
    protected array $config = [];
    protected bool $config_error = false;
    protected string $config_stamp = '';

    protected bool $site_maintenance = false;

    public function __construct( ) {
        $this->config_json_filename = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tinymash.json';
    }

    public function getConfigFilename() : string {
        return( $this->config_json_filename );
    }

    public function getConfig() : bool {
        $this->config_json = $this->readConfigJson();
        $this->config_stamp = '';
        if ( $this->config_json !== false && mb_strlen( $this->config_json ) > 0 ) {
            $this->config = @ json_decode( $this->config_json, true, 16 );
            if ( is_array( $this->config ) && ! empty( $this->config ) ) {
                $this->applyDefaults();
                if ( ! empty( $this->config['file']['stamp'] ) ) {
                    $this->config_stamp = $this->config['file']['stamp'];
                }
                if ( $this->normalizeBoolean( $this->config['site']['maintenance'] ?? false ) ) {
                    $this->site_maintenance = true;
                } else {
                    $this->site_maintenance = false;
                }
                $this->config_error = false;
                return( true );
            }
        }
        $this->config_error = true;
        error_log( basename( __FILE__ ) . ': Unable to read "' . $this->getConfigFilename() . '"' );
        return( false );
    }

    public function putConfig() : bool {
        if ( empty( $this->config ) ) {
            error_log( basename( __FILE__ ) . ': No configuration read, no changes made to "' . $this->getConfigFilename() . '"' );
            return( false );
        }
        if ( ! isset( $this->config['site'] ) || ! is_array( $this->config['site'] ) ) {
            $this->config['site'] = [];
        }
        if ( ! isset( $this->config['locale'] ) || ! is_array( $this->config['locale'] ) ) {
            $this->config['locale'] = [];
        }
        if ( ! isset( $this->config['content'] ) || ! is_array( $this->config['content'] ) ) {
            $this->config['content'] = [];
        }
        if ( ! isset( $this->config['editor'] ) || ! is_array( $this->config['editor'] ) ) {
            $this->config['editor'] = [];
        }
        if ( ! isset( $this->config['notifications'] ) || ! is_array( $this->config['notifications'] ) ) {
            $this->config['notifications'] = [];
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
        if ( ! isset( $this->config['themes']['public'] ) || ! is_array( $this->config['themes']['public'] ) ) {
            $this->config['themes']['public'] = [];
        }
        if ( ! isset( $this->config['themes']['public']['settings'] ) || ! is_array( $this->config['themes']['public']['settings'] ) ) {
            $this->config['themes']['public']['settings'] = [];
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
        $this->config['site']['maintenance'] = $this->site_maintenance;

        $json = json_encode( $this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || $json === '' ) {
            error_log( basename( __FILE__ ) . ': Unable to encode configuration for "' . $this->getConfigFilename() . '"' );
            return( false );
        }

        $handle = @ fopen( $this->getConfigFilename(), 'c+' );
        if ( $handle === false ) {
            error_log( basename( __FILE__ ) . ': Unable to write configuration to "' . $this->getConfigFilename() . '"' );
            return( false );
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                error_log( basename( __FILE__ ) . ': Unable to lock configuration file "' . $this->getConfigFilename() . '"' );
                return( false );
            }

            ftruncate( $handle, 0 );
            rewind( $handle );
            if ( fwrite( $handle, $json . PHP_EOL ) === false ) {
                error_log( basename( __FILE__ ) . ': Unable to write configuration to "' . $this->getConfigFilename() . '"' );
                return( false );
            }
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        $this->config_json = $json;
        return( true );
    }

    public function getConfigCurrentStamp() : false|string {
        $tmp_config_json = $this->readConfigJson();
        if ( $tmp_config_json !== false && mb_strlen( $tmp_config_json ) > 0 ) {
            $tmp_config = @ json_decode( $tmp_config_json, true, 16 );
            if ( is_array( $tmp_config ) && !empty( $tmp_config ) ) {
                if ( ! empty( $tmp_config['file']['stamp'] ) ) {
                    return( $tmp_config['file']['stamp'] );
                }
                return( '' );
            }
        }
        return( false );
    }

    public function getStamp() : string {
        return( $this->config_stamp );
    }

    public function configGetBaseURL() : string|bool {
        if ( ! empty( $this->config['site']['base_url'] )  ) {
            return ( $this->config['site']['base_url'] );
        }
        return( false );
    }
    public function configGetAdminURL() : string|bool {
        if ( ! empty( $this->config['site']['admin_url'] ) ) {
            return ( $this->config['site']['admin_url'] );
        }
        return( false );
    }
    public function configGetLoginURL() : string|bool {
        if ( ! empty( $this->config['site']['login_url'] ) ) {
            return ( $this->config['site']['login_url'] );
        }
        return( false );
    }
    public function getMinPasswordLength() : int {
        if ( ! empty( $this->config['site']['security_min_password'] )  ) {
            return ( $this->config['site']['security_min_password'] );
        }
        return( 8 );
    }

    public function getMaintenance() : bool {
        return( $this->site_maintenance );
    }
    public function getContentRevisionRetentionLimit() : int {
        if ( empty( $this->config ) ) {
            $this->getConfig();
        }

        $this->applyDefaults();
        return( $this->normalizeRevisionRetentionLimit( $this->config['content']['revision_retention_limit'] ?? 20 ) );
    }

    public function getHousekeepingDraftRetentionDays() : int {
        if ( empty( $this->config ) ) {
            $this->getConfig();
        }

        $this->applyDefaults();
        return( $this->normalizeHousekeepingDraftRetentionDays( $this->config['housekeeping']['stale_draft_retention_days'] ?? 0 ) );
    }

    public function getHousekeepingLastRunUtc() : string {
        if ( empty( $this->config ) ) {
            $this->getConfig();
        }

        $this->applyDefaults();
        return( trim( (string) ( $this->config['housekeeping']['last_run_utc'] ?? '' ) ) );
    }

    public function getHousekeepingLastTrigger() : string {
        if ( empty( $this->config ) ) {
            $this->getConfig();
        }

        $this->applyDefaults();
        return( $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_trigger'] ?? '' ) );
    }

    public function getHousekeepingLastMode() : string {
        if ( empty( $this->config ) ) {
            $this->getConfig();
        }

        $this->applyDefaults();
        return( $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_mode'] ?? '' ) );
    }

    public function getHousekeepingWebFallbackMode() : string {
        if ( empty( $this->config ) ) {
            $this->getConfig();
        }

        $this->applyDefaults();
        return( $this->normalizeHousekeepingWebFallbackMode( $this->config['housekeeping']['web_fallback_mode'] ?? 'auto' ) );
    }

    public function setMaintenance( bool $flag ) : void {
        $this->site_maintenance = $flag;
    }

    public function setHousekeepingLastRunUtc( string $utc_datetime ) : bool {
        return( $this->setHousekeepingLastRunMetadata( $utc_datetime ) );
    }

    public function setHousekeepingLastRunMetadata( string $utc_datetime, string $trigger = '', string $mode = '' ) : bool {
        if ( empty( $this->config ) && ! $this->getConfig() ) {
            return( false );
        }

        $this->applyDefaults();
        $this->config['housekeeping']['last_run_utc'] = trim( $utc_datetime );
        $this->config['housekeeping']['last_trigger'] = $this->normalizeHousekeepingRunDescriptor( $trigger );
        $this->config['housekeeping']['last_mode'] = $this->normalizeHousekeepingRunDescriptor( $mode );
        return( $this->putConfig() );
    }

    public function getSystemSettings() : array {
        if ( empty( $this->config ) ) {
            $this->getConfig();
        }

        $this->applyDefaults();
        return(
            [
                'site_name' => (string) ( $this->config['site']['name'] ?? 'tinymash' ),
                'site_slogan' => (string) ( $this->config['site']['slogan'] ?? '' ),
                'default_language' => TinyMashConfig::normalizeHtmlLanguageTag( (string) ( $this->config['site']['default_language'] ?? 'en' ) ),
                'time_format' => (string) ( $this->config['locale']['time'] ?? 'H:i (T)' ),
                'site_public' => $this->normalizeBoolean( $this->config['site']['is_public'] ?? false ),
                'allow_registrations' => $this->normalizeBoolean( $this->config['site']['allow_registrations'] ?? false ),
                'maintenance' => $this->normalizeBoolean( $this->config['site']['maintenance'] ?? false ),
                'admin_email' => (string) ( $this->config['site']['admin_email'] ?? '' ),
                'system_notifications_email' => (string) ( $this->config['site']['system_notifications_email'] ?? '' ),
                'allow_admin_password_resets' => $this->normalizeBoolean( $this->config['site']['allow_admin_password_resets'] ?? false ),
                'allow_author_password_resets' => $this->normalizeBoolean( $this->config['site']['allow_author_password_resets'] ?? true ),
                'password_reset_throttle_window_minutes' => $this->normalizePasswordResetThrottleWindowMinutes( $this->config['site']['password_reset_throttle_window_minutes'] ?? 60 ),
                'password_reset_max_ip_requests' => $this->normalizePasswordResetThrottleCount( $this->config['site']['password_reset_max_ip_requests'] ?? 5, 5 ),
                'password_reset_max_identifier_requests' => $this->normalizePasswordResetThrottleCount( $this->config['site']['password_reset_max_identifier_requests'] ?? 3, 3 ),
                'timezone' => (string) ( $this->config['locale']['timezone'] ?? 'UTC' ),
                'week_starts_on' => (string) ( $this->config['site']['week_starts_on'] ?? 'monday' ),
                'allow_username_change' => $this->normalizeBoolean( $this->config['site']['allow_username_change'] ?? false ),
                'allow_email_change' => $this->normalizeBoolean( $this->config['site']['allow_email_change'] ?? false ),
                'allow_secret_links' => $this->normalizeBoolean( $this->config['site']['allow_secret_links'] ?? false ),
                'discourage_search_indexing' => $this->normalizeBoolean( $this->config['site']['discourage_search_indexing'] ?? false ),
                'secret_link_default_expiry_days' => $this->normalizeSecretLinkExpiryDays( $this->config['site']['secret_link_default_expiry_days'] ?? 60 ),
                'filesystem_umask' => $this->normalizeFilesystemUmask( $this->config['site']['filesystem_umask'] ?? '0007' ),
                'rendered_content_cache_enabled' => $this->normalizeBoolean( $this->config['site']['rendered_content_cache_enabled'] ?? true ),
                'forwarded_ip_mode' => $this->normalizeForwardedIpMode( $this->config['site']['forwarded_ip_mode'] ?? 'off' ),
                'public_cache_warm_basic_auth_username' => trim( (string) ( $this->config['site']['public_cache_warm_basic_auth_username'] ?? '' ) ),
                'public_cache_warm_basic_auth_password' => (string) ( $this->config['site']['public_cache_warm_basic_auth_password'] ?? '' ),
                'public_cache_warm_insecure_tls' => $this->normalizeBoolean( $this->config['site']['public_cache_warm_insecure_tls'] ?? false ),
                'login_message' => (string) ( $this->config['site']['login_message'] ?? '' ),
                'site_banner_image' => $this->normalizeSiteImage( $this->config['site']['images']['banner'] ?? [] ),
                'site_favicon_png_image' => $this->normalizeSiteImage( $this->config['site']['images']['favicon_png'] ?? [] ),
                'site_favicon_ico_image' => $this->normalizeSiteImage( $this->config['site']['images']['favicon_ico'] ?? [] ),
                'site_og_image' => $this->normalizeSiteImage( $this->config['site']['images']['og'] ?? [] ),
                'site_background_image' => $this->normalizeSiteImage( $this->config['site']['images']['background'] ?? [] ),
                'site_background_render_mode' => $this->normalizeBackgroundRenderMode( $this->config['site']['background_render_mode'] ?? 'scaled' ),
                'require_moderation' => $this->normalizeBoolean( $this->config['content']['require_moderation'] ?? false ),
                'content_tags_enabled' => $this->normalizeBoolean( $this->config['content']['tags_enabled'] ?? true ),
                'content_show_page_timestamps' => $this->normalizeBoolean( $this->config['content']['show_page_timestamps'] ?? false ),
                'content_revision_retention_limit' => $this->normalizeRevisionRetentionLimit( $this->config['content']['revision_retention_limit'] ?? 20 ),
                'housekeeping_stale_draft_retention_days' => $this->normalizeHousekeepingDraftRetentionDays( $this->config['housekeeping']['stale_draft_retention_days'] ?? 0 ),
                'housekeeping_last_run_utc' => trim( (string) ( $this->config['housekeeping']['last_run_utc'] ?? '' ) ),
                'housekeeping_last_trigger' => $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_trigger'] ?? '' ),
                'housekeeping_last_mode' => $this->normalizeHousekeepingRunDescriptor( $this->config['housekeeping']['last_mode'] ?? '' ),
                'housekeeping_web_fallback_mode' => $this->normalizeHousekeepingWebFallbackMode( $this->config['housekeeping']['web_fallback_mode'] ?? 'auto' ),
                'notification_retention_days' => $this->normalizeNotificationRetentionDays( $this->config['notifications']['retention_days'] ?? 30 ),
                'notification_email_events' => [
                    'moderation_required' => $this->normalizeBoolean( $this->config['notifications']['email_events']['moderation_required'] ?? false ),
                    'profile_email_changed' => $this->normalizeBoolean( $this->config['notifications']['email_events']['profile_email_changed'] ?? false ),
                    'user_lockout' => $this->normalizeBoolean( $this->config['notifications']['email_events']['user_lockout'] ?? true ),
                    'component_updates' => $this->normalizeBoolean( $this->config['notifications']['email_events']['component_updates'] ?? true ),
                ],
                'editor_autosave_enabled' => $this->normalizeBoolean( $this->config['editor']['autosave_enabled'] ?? true ),
                'editor_autosave_interval_seconds' => $this->normalizeAutosaveIntervalSeconds( $this->config['editor']['autosave_interval_seconds'] ?? 120 ),
                'editor_classic_smileys_enabled' => $this->normalizeBoolean( $this->config['editor']['classic_smileys_enabled'] ?? true ),
                'content_images_mode' => (string) ( $this->config['media']['content_images_mode'] ?? 'authenticated' ),
                'content_image_driver' => (string) ( $this->config['media']['image_driver'] ?? 'auto' ),
                'content_image_max_width' => $this->normalizeImageDimension( $this->config['media']['image_max_width'] ?? 2560 ),
                'content_image_max_height' => $this->normalizeImageDimension( $this->config['media']['image_max_height'] ?? 2560 ),
                'content_image_max_upload_mb' => $this->normalizeImageUploadMegabytes( $this->config['media']['image_max_upload_mb'] ?? 10 ),
                'content_image_allowed_mimes' => $this->normalizeAllowedContentImageMimes( $this->config['media']['allowed_image_mimes'] ?? [] ),
                'content_media_metadata_retention_groups' => $this->normalizeMediaMetadataPolicy( $this->config['media']['metadata_retention_groups'] ?? $this->getDefaultMediaMetadataRetentionPolicy(), $this->getDefaultMediaMetadataRetentionPolicy() ),
                'content_media_metadata_public_groups' => $this->normalizeMediaMetadataPolicy(
                    $this->config['media']['metadata_public_groups'] ?? $this->getDefaultMediaMetadataPublicPolicy(),
                    $this->getDefaultMediaMetadataPublicPolicy(),
                    $this->normalizeMediaMetadataPolicy( $this->config['media']['metadata_retention_groups'] ?? $this->getDefaultMediaMetadataRetentionPolicy(), $this->getDefaultMediaMetadataRetentionPolicy() )
                ),
                'content_generate_thumbnails' => $this->normalizeBoolean( $this->config['media']['generate_thumbnails'] ?? true ),
                'content_thumbnail_max_width' => $this->normalizeThumbnailDimension( $this->config['media']['thumbnail_max_width'] ?? 320 ),
                'content_thumbnail_max_height' => $this->normalizeThumbnailDimension( $this->config['media']['thumbnail_max_height'] ?? 320 ),
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

    public function updateSystemSettings( array $settings, string $settings_group = 'site' ) : bool {
        if ( empty( $this->config ) && ! $this->getConfig() ) {
            return( false );
        }

        $this->applyDefaults();
        if ( $settings_group === 'themes_select' ) {
            $this->config['themes']['public']['current'] = $this->normalizePublicThemeKey( (string) ( $settings['theme_key'] ?? $this->config['themes']['public']['current'] ?? 'baseline' ) );
        } elseif ( $settings_group === 'themes' ) {
            $theme_key = $this->normalizePublicThemeKey( (string) ( $settings['theme_key'] ?? $this->config['themes']['public']['current'] ?? 'baseline' ) );
            if ( ! isset( $this->config['themes']['public']['settings'][$theme_key] ) || ! is_array( $this->config['themes']['public']['settings'][$theme_key] ) ) {
                $this->config['themes']['public']['settings'][$theme_key] = [];
            }
            foreach ( (array) ( $settings['theme_settings'] ?? [] ) as $setting_key => $setting_value ) {
                if ( ! is_string( $setting_key ) ) {
                    continue;
                }
                $this->config['themes']['public']['settings'][$theme_key][$setting_key] = $setting_value;
            }
        } elseif ( $settings_group === 'plugins' ) {
            $plugin_key = $this->normalizePluginKey( (string) ( $settings['plugin_key'] ?? '' ) );
            if ( $plugin_key === '' ) {
                return( false );
            }
            if ( ! isset( $this->config['plugins']['settings'][$plugin_key] ) || ! is_array( $this->config['plugins']['settings'][$plugin_key] ) ) {
                $this->config['plugins']['settings'][$plugin_key] = [];
            }
            foreach ( (array) ( $settings['plugin_settings'] ?? [] ) as $setting_key => $setting_value ) {
                if ( ! is_string( $setting_key ) ) {
                    continue;
                }
                $this->config['plugins']['settings'][$plugin_key][$setting_key] = $setting_value;
            }
        } elseif ( $settings_group === 'content_media' ) {
            $this->config['content']['tags_enabled'] = (bool) ( $settings['content_tags_enabled'] ?? true );
            $this->config['content']['show_page_timestamps'] = (bool) ( $settings['content_show_page_timestamps'] ?? false );
            $this->config['content']['revision_retention_limit'] = $this->normalizeRevisionRetentionLimit( $settings['content_revision_retention_limit'] ?? $this->config['content']['revision_retention_limit'] ?? 20 );
            $this->config['housekeeping']['stale_draft_retention_days'] = $this->normalizeHousekeepingDraftRetentionDays( $settings['housekeeping_stale_draft_retention_days'] ?? $this->config['housekeeping']['stale_draft_retention_days'] ?? 0 );
            $this->config['site']['rendered_content_cache_enabled'] = (bool) ( $settings['rendered_content_cache_enabled'] ?? true );
            $this->config['editor']['autosave_enabled'] = (bool) ( $settings['editor_autosave_enabled'] ?? true );
            $this->config['editor']['autosave_interval_seconds'] = $this->normalizeAutosaveIntervalSeconds( $settings['editor_autosave_interval_seconds'] ?? $this->config['editor']['autosave_interval_seconds'] ?? 120 );
            $this->config['editor']['classic_smileys_enabled'] = (bool) ( $settings['editor_classic_smileys_enabled'] ?? true );
        } elseif ( $settings_group === 'media' ) {
            $this->config['media']['content_images_mode'] = (string) ( $settings['content_images_mode'] ?? $this->config['media']['content_images_mode'] ?? 'authenticated' );
            $this->config['media']['image_driver'] = (string) ( $settings['content_image_driver'] ?? $this->config['media']['image_driver'] ?? 'auto' );
            $this->config['media']['image_max_width'] = $this->normalizeImageDimension( $settings['content_image_max_width'] ?? $this->config['media']['image_max_width'] ?? 2560 );
            $this->config['media']['image_max_height'] = $this->normalizeImageDimension( $settings['content_image_max_height'] ?? $this->config['media']['image_max_height'] ?? 2560 );
            $this->config['media']['image_max_upload_mb'] = $this->normalizeImageUploadMegabytes( $settings['content_image_max_upload_mb'] ?? $this->config['media']['image_max_upload_mb'] ?? 10 );
            $this->config['media']['allowed_image_mimes'] = $this->normalizeAllowedContentImageMimes( $settings['content_image_allowed_mimes'] ?? $this->config['media']['allowed_image_mimes'] ?? [] );
            $this->config['media']['metadata_retention_groups'] = $this->normalizeMediaMetadataPolicy( $settings['content_media_metadata_retention_groups'] ?? $this->config['media']['metadata_retention_groups'] ?? $this->getDefaultMediaMetadataRetentionPolicy(), $this->getDefaultMediaMetadataRetentionPolicy() );
            $this->config['media']['metadata_public_groups'] = $this->normalizeMediaMetadataPolicy( $settings['content_media_metadata_public_groups'] ?? $this->config['media']['metadata_public_groups'] ?? $this->getDefaultMediaMetadataPublicPolicy(), $this->getDefaultMediaMetadataPublicPolicy(), $this->config['media']['metadata_retention_groups'] );
            $this->config['media']['generate_thumbnails'] = (bool) ( $settings['content_generate_thumbnails'] ?? true );
            $this->config['media']['thumbnail_max_width'] = $this->normalizeThumbnailDimension( $settings['content_thumbnail_max_width'] ?? $this->config['media']['thumbnail_max_width'] ?? 320 );
            $this->config['media']['thumbnail_max_height'] = $this->normalizeThumbnailDimension( $settings['content_thumbnail_max_height'] ?? $this->config['media']['thumbnail_max_height'] ?? 320 );
        } elseif ( $settings_group === 'smtp' ) {
            $this->config['smtp']['enabled'] = (bool) ( $settings['smtp_enabled'] ?? false );
            $this->config['smtp']['host'] = (string) ( $settings['smtp_host'] ?? $this->config['smtp']['host'] );
            $this->config['smtp']['port'] = (int) ( $settings['smtp_port'] ?? $this->config['smtp']['port'] );
            $this->config['smtp']['username'] = (string) ( $settings['smtp_username'] ?? $this->config['smtp']['username'] );
            if ( array_key_exists( 'smtp_password', $settings ) && (string) $settings['smtp_password'] !== '' ) {
                $this->config['smtp']['password'] = (string) $settings['smtp_password'];
            }
            $this->config['smtp']['encryption'] = (string) ( $settings['smtp_encryption'] ?? $this->config['smtp']['encryption'] );
            $this->config['smtp']['from_email'] = (string) ( $settings['smtp_from_email'] ?? $this->config['smtp']['from_email'] );
            $this->config['smtp']['from_name'] = (string) ( $settings['smtp_from_name'] ?? $this->config['smtp']['from_name'] );
            $this->config['smtp']['reply_to_email'] = (string) ( $settings['smtp_reply_to_email'] ?? $this->config['smtp']['reply_to_email'] );
        } elseif ( $settings_group === 'notifications' ) {
            $this->config['notifications']['retention_days'] = $this->normalizeNotificationRetentionDays( $settings['notification_retention_days'] ?? $this->config['notifications']['retention_days'] ?? 30 );
            if ( ! isset( $this->config['notifications']['email_events'] ) || ! is_array( $this->config['notifications']['email_events'] ) ) {
                $this->config['notifications']['email_events'] = [];
            }
            foreach ( [ 'moderation_required', 'profile_email_changed', 'user_lockout', 'component_updates' ] as $notification_event_key ) {
                $this->config['notifications']['email_events'][$notification_event_key] = ! empty( $settings['notification_email_events'][$notification_event_key] );
            }
        } elseif ( $settings_group === 'menus' ) {
            $locations = is_array( $settings['menu_locations'] ?? null ) ? $settings['menu_locations'] : [];
            $this->config['menus']['locations']['primary'] = is_array( $locations['primary'] ?? null ) ? array_values( $locations['primary'] ) : [];
            $this->config['menus']['locations']['footer'] = is_array( $locations['footer'] ?? null ) ? array_values( $locations['footer'] ) : [];
        } elseif ( $settings_group === 'moderation' ) {
            $this->config['content']['require_moderation'] = (bool) ( $settings['require_moderation'] ?? false );
        } elseif ( $settings_group === 'security' ) {
            $this->config['site']['allow_registrations'] = (bool) ( $settings['allow_registrations'] ?? false );
            $this->config['site']['is_public'] = (bool) ( $settings['site_public'] ?? false );
            $this->config['site']['maintenance'] = (bool) ( $settings['maintenance'] ?? false );
            $this->config['site']['allow_admin_password_resets'] = (bool) ( $settings['allow_admin_password_resets'] ?? false );
            $this->config['site']['allow_author_password_resets'] = (bool) ( $settings['allow_author_password_resets'] ?? true );
            $this->config['site']['allow_username_change'] = (bool) ( $settings['allow_username_change'] ?? false );
            $this->config['site']['allow_email_change'] = (bool) ( $settings['allow_email_change'] ?? false );
            $this->config['site']['allow_secret_links'] = (bool) ( $settings['allow_secret_links'] ?? false );
            $this->config['site']['discourage_search_indexing'] = (bool) ( $settings['discourage_search_indexing'] ?? false );
            $this->config['site']['secret_link_default_expiry_days'] = $this->normalizeSecretLinkExpiryDays( $settings['secret_link_default_expiry_days'] ?? $this->config['site']['secret_link_default_expiry_days'] ?? 60 );
            $this->config['site']['password_reset_throttle_window_minutes'] = $this->normalizePasswordResetThrottleWindowMinutes( $settings['password_reset_throttle_window_minutes'] ?? $this->config['site']['password_reset_throttle_window_minutes'] ?? 60 );
            $this->config['site']['password_reset_max_ip_requests'] = $this->normalizePasswordResetThrottleCount( $settings['password_reset_max_ip_requests'] ?? $this->config['site']['password_reset_max_ip_requests'] ?? 5, 5 );
            $this->config['site']['password_reset_max_identifier_requests'] = $this->normalizePasswordResetThrottleCount( $settings['password_reset_max_identifier_requests'] ?? $this->config['site']['password_reset_max_identifier_requests'] ?? 3, 3 );
            $this->config['site']['filesystem_umask'] = $this->normalizeFilesystemUmask( $settings['filesystem_umask'] ?? $this->config['site']['filesystem_umask'] ?? '0007' );
            $this->config['site']['forwarded_ip_mode'] = $this->normalizeForwardedIpMode( $settings['forwarded_ip_mode'] ?? $this->config['site']['forwarded_ip_mode'] ?? 'off' );
            $this->config['site']['public_cache_warm_basic_auth_username'] = trim( (string) ( $settings['public_cache_warm_basic_auth_username'] ?? $this->config['site']['public_cache_warm_basic_auth_username'] ?? '' ) );
            if ( array_key_exists( 'public_cache_warm_basic_auth_password', $settings ) && (string) $settings['public_cache_warm_basic_auth_password'] !== '' ) {
                $this->config['site']['public_cache_warm_basic_auth_password'] = (string) $settings['public_cache_warm_basic_auth_password'];
            }
            if ( $this->config['site']['public_cache_warm_basic_auth_username'] === '' ) {
                $this->config['site']['public_cache_warm_basic_auth_password'] = '';
            }
            $this->config['site']['public_cache_warm_insecure_tls'] = (bool) ( $settings['public_cache_warm_insecure_tls'] ?? false );
            $this->config['housekeeping']['web_fallback_mode'] = $this->normalizeHousekeepingWebFallbackMode( $settings['housekeeping_web_fallback_mode'] ?? $this->config['housekeeping']['web_fallback_mode'] ?? 'auto' );
        } elseif ( $settings_group === 'locale' ) {
            $this->config['site']['default_language'] = TinyMashConfig::normalizeHtmlLanguageTag( (string) ( $settings['default_language'] ?? $this->config['site']['default_language'] ) );
            $this->config['site']['week_starts_on'] = (string) ( $settings['week_starts_on'] ?? $this->config['site']['week_starts_on'] );
            $this->config['locale']['time'] = (string) ( $settings['time_format'] ?? $this->config['locale']['time'] );
            $this->config['locale']['timezone'] = (string) ( $settings['timezone'] ?? $this->config['locale']['timezone'] );
        } else {
            $this->config['site']['name'] = (string) ( $settings['site_name'] ?? $this->config['site']['name'] );
            $this->config['site']['slogan'] = (string) ( $settings['site_slogan'] ?? $this->config['site']['slogan'] );
            $this->config['site']['admin_email'] = (string) ( $settings['admin_email'] ?? $this->config['site']['admin_email'] );
            $this->config['site']['system_notifications_email'] = (string) ( $settings['system_notifications_email'] ?? $this->config['site']['system_notifications_email'] );
            $this->config['site']['login_message'] = (string) ( $settings['login_message'] ?? $this->config['site']['login_message'] );
            $this->config['site']['images']['banner'] = $this->normalizeSiteImage( $settings['site_banner_image'] ?? $this->config['site']['images']['banner'] ?? [] );
            $this->config['site']['images']['favicon_png'] = $this->normalizeSiteImage( $settings['site_favicon_png_image'] ?? $this->config['site']['images']['favicon_png'] ?? [] );
            $this->config['site']['images']['favicon_ico'] = $this->normalizeSiteImage( $settings['site_favicon_ico_image'] ?? $this->config['site']['images']['favicon_ico'] ?? [] );
            $this->config['site']['images']['og'] = $this->normalizeSiteImage( $settings['site_og_image'] ?? $this->config['site']['images']['og'] ?? [] );
            $this->config['site']['images']['background'] = $this->normalizeSiteImage( $settings['site_background_image'] ?? $this->config['site']['images']['background'] ?? [] );
            $this->config['site']['background_render_mode'] = $this->normalizeBackgroundRenderMode( $settings['site_background_render_mode'] ?? $this->config['site']['background_render_mode'] ?? 'scaled' );
        }
        $this->site_maintenance = (bool) $this->config['site']['maintenance'];

        return( $this->putConfig() );
    }

    public function updatePluginState( string $plugin_key, bool $active ) : bool {
        if ( empty( $this->config ) && ! $this->getConfig() ) {
            return( false );
        }

        $this->applyDefaults();
        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        if ( $plugin_key === '' ) {
            return( false );
        }

        $this->config['plugins']['states'][$plugin_key] = $active;
        unset( $this->config['plugins']['states']['visitor-context'] );
        return( $this->putConfig() );
    }

    public function updatePluginSettings( string $plugin_key, array $settings ) : bool {
        if ( empty( $this->config ) && ! $this->getConfig() ) {
            return( false );
        }

        $this->applyDefaults();
        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        if ( $plugin_key === '' ) {
            return( false );
        }

        if ( ! isset( $this->config['plugins']['settings'][$plugin_key] ) || ! is_array( $this->config['plugins']['settings'][$plugin_key] ) ) {
            $this->config['plugins']['settings'][$plugin_key] = [];
        }

        unset( $this->config['plugins']['settings']['visitor-context'] );

        foreach ( $settings as $setting_key => $setting_value ) {
            if ( ! is_string( $setting_key ) ) {
                continue;
            }
            $this->config['plugins']['settings'][$plugin_key][$setting_key] = $setting_value;
        }

        return( $this->putConfig() );
    }

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
        $this->config['site']['slogan'] = (string) ( $this->config['site']['slogan'] ?? '' );
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
        $this->config['site']['week_starts_on'] = (string) ( $this->config['site']['week_starts_on'] ?? 'monday' );
        $this->config['site']['allow_username_change'] = $this->normalizeBoolean( $this->config['site']['allow_username_change'] ?? false );
        $this->config['site']['allow_email_change'] = $this->normalizeBoolean( $this->config['site']['allow_email_change'] ?? false );
        $this->config['site']['allow_secret_links'] = $this->normalizeBoolean( $this->config['site']['allow_secret_links'] ?? false );
        $this->config['site']['discourage_search_indexing'] = $this->normalizeBoolean( $this->config['site']['discourage_search_indexing'] ?? false );
        $this->config['site']['secret_link_default_expiry_days'] = $this->normalizeSecretLinkExpiryDays( $this->config['site']['secret_link_default_expiry_days'] ?? 60 );
        $this->config['site']['filesystem_umask'] = $this->normalizeFilesystemUmask( $this->config['site']['filesystem_umask'] ?? '0007' );
        $this->config['site']['rendered_content_cache_enabled'] = $this->normalizeBoolean( $this->config['site']['rendered_content_cache_enabled'] ?? true );
        $this->config['site']['forwarded_ip_mode'] = $this->normalizeForwardedIpMode( $this->config['site']['forwarded_ip_mode'] ?? 'off' );
        $this->config['site']['public_cache_warm_basic_auth_username'] = trim( (string) ( $this->config['site']['public_cache_warm_basic_auth_username'] ?? '' ) );
        $this->config['site']['public_cache_warm_basic_auth_password'] = (string) ( $this->config['site']['public_cache_warm_basic_auth_password'] ?? '' );
        if ( $this->config['site']['public_cache_warm_basic_auth_username'] === '' ) {
            $this->config['site']['public_cache_warm_basic_auth_password'] = '';
        }
        $this->config['site']['public_cache_warm_insecure_tls'] = $this->normalizeBoolean( $this->config['site']['public_cache_warm_insecure_tls'] ?? false );
        $this->config['site']['login_message'] = (string) ( $this->config['site']['login_message'] ?? '' );
        $this->config['site']['images']['banner'] = $this->normalizeSiteImage( $this->config['site']['images']['banner'] ?? [] );
        $this->config['site']['images']['favicon_png'] = $this->normalizeSiteImage( $this->config['site']['images']['favicon_png'] ?? [] );
        $this->config['site']['images']['favicon_ico'] = $this->normalizeSiteImage( $this->config['site']['images']['favicon_ico'] ?? [] );
        $this->config['site']['images']['og'] = $this->normalizeSiteImage( $this->config['site']['images']['og'] ?? [] );
        $this->config['site']['images']['background'] = $this->normalizeSiteImage( $this->config['site']['images']['background'] ?? [] );
        $this->config['site']['background_render_mode'] = $this->normalizeBackgroundRenderMode( $this->config['site']['background_render_mode'] ?? 'scaled' );
        $this->config['locale']['time'] = (string) ( $this->config['locale']['time'] ?? 'H:i (T)' );
        $this->config['locale']['timezone'] = (string) ( $this->config['locale']['timezone'] ?? 'UTC' );
        $this->config['content']['require_moderation'] = $this->normalizeBoolean( $this->config['content']['require_moderation'] ?? false );
        $this->config['content']['tags_enabled'] = $this->normalizeBoolean( $this->config['content']['tags_enabled'] ?? true );
        $this->config['content']['show_page_timestamps'] = $this->normalizeBoolean( $this->config['content']['show_page_timestamps'] ?? false );
        $this->config['content']['revision_retention_limit'] = $this->normalizeRevisionRetentionLimit( $this->config['content']['revision_retention_limit'] ?? 20 );
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
        $this->config['smtp']['username'] = (string) ( $this->config['smtp']['username'] ?? '' );
        $this->config['smtp']['password'] = (string) ( $this->config['smtp']['password'] ?? '' );
        $this->config['smtp']['encryption'] = (string) ( $this->config['smtp']['encryption'] ?? 'tls' );
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

    protected function canonicalizePluginKey( string $plugin_key ) : string {
        $plugin_key = $this->normalizePluginKey( $plugin_key );
        return(
            match ( $plugin_key ) {
                'visitor-context' => 'footer',
                default => $plugin_key,
            }
        );
    }

    protected function readConfigJson() : string|bool {
        $handle = @ fopen( $this->getConfigFilename(), 'rb' );
        if ( $handle === false ) {
            return( false );
        }

        try {
            if ( ! @ flock( $handle, LOCK_SH ) ) {
                return( false );
            }
            $config_json = stream_get_contents( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        return( is_string( $config_json ) ? $config_json : false );
    }

}// AdminController
