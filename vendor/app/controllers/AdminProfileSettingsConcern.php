<?php
namespace app\controllers;

trait AdminProfileSettingsConcern {

    public function saveProfile( string $section = 'account' ) : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        $profile_section = $this->normalizeProfileSection( $section );

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setProfileNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->security->setFlash( 'profile.form', $this->normalizeProfileFormState( $data ) );
            $this->app->redirect( $this->getProfileSectionUrl( $profile_section ) );
            return;
        }

        $user_repository = $this->getUserRepository();
        $current_user = $this->getCurrentUserRecord();
        $current_username = $this->security->getCurrentUsername();
        if ( $user_repository === null || ! is_array( $current_user ) || ! is_string( $current_username ) || $current_username === '' ) {
            $this->setProfileNoticeFlash( 'danger', 'Profile settings could not be saved right now.' );
            $this->app->redirect( $this->getProfileSectionUrl( $profile_section ) );
            return;
        }

        $form_state = $this->mergeProfileSectionFormState( $data, $current_user, $profile_section );
        try {
            $validated_profile = $this->validateProfileSettings( $form_state, $current_user, $profile_section );
            $requested_email = strtolower( trim( (string) ( $validated_profile['email'] ?? '' ) ) );
            $current_email = strtolower( trim( (string) ( $current_user['email'] ?? '' ) ) );
            $email_change_requested = $profile_section === 'account'
                && ( $this->security->isSuperAdmin() || $this->config->canUsersChangeEmail() )
                && $requested_email !== ''
                && $requested_email !== $current_email;
            if ( $email_change_requested ) {
                $validated_profile['email'] = $current_email;
            }
            $user_repository->saveOwnProfile(
                $current_username,
                $validated_profile,
                $this->security->isSuperAdmin() || $this->config->canUsersChangeEmail()
            );
            if ( $profile_section === 'account' && $requested_email === '' ) {
                $user_repository->clearPendingEmailChange( $current_username );
            }
            $notice_type = 'success';
            $success_message = $this->getProfileSaveSuccessMessage( $profile_section );
            if ( $email_change_requested ) {
                try {
                    $pending_email_change = $user_repository->requestOwnEmailChange( $current_username, $requested_email );
                    $this->sendProfileEmailConfirmation( $pending_email_change );
                    $success_message .= ' Confirm the new e-mail address from the link sent to ' . $requested_email . '.';
                } catch ( \Throwable $e ) {
                    $user_repository->clearPendingEmailChange( $current_username );
                    $notice_type = 'warning';
                    $success_message .= ' ' . $this->getProfileEmailConfirmationFailureMessage( $e );
                }
            }
            $this->clearPublicPageCache();
            $this->setProfileNoticeFlash( $notice_type, $success_message );
        } catch ( \InvalidArgumentException $e ) {
            $this->security->setFlash( 'profile.form', $form_state );
            $this->setProfileNoticeFlash( 'danger', $this->getUserValidationMessage( $e->getMessage() ) );
        } catch ( \Throwable ) {
            $this->security->setFlash( 'profile.form', $form_state );
            $this->setProfileNoticeFlash( 'danger', 'Profile settings could not be saved right now.' );
        }

        $this->app->redirect( $this->getProfileSectionUrl( $profile_section ) );
    }

    protected function getProfileSection() : string {
        $query_section = (string) $this->app->request()->query->section;
        if ( $query_section !== '' ) {
            return( $this->normalizeProfileSection( $query_section ) );
        }

        return( 'account' );
    }

    protected function normalizeProfileSection( string $section ) : string {
        return( in_array( $section, [ 'account', 'editor', 'publishing', 'appearance', 'fediverse' ], true ) ? $section : 'account' );
    }

    protected function getProfileSectionUrls() : array {
        return(
            [
                'account' => $this->getProfileSectionUrl( 'account' ),
                'editor' => $this->getProfileSectionUrl( 'editor' ),
                'publishing' => $this->getProfileSectionUrl( 'publishing' ),
                'appearance' => $this->getProfileSectionUrl( 'appearance' ),
                'fediverse' => $this->getProfileSectionUrl( 'fediverse' ),
            ]
        );
    }

    protected function getProfileSectionUrl( string $section ) : string {
        return( $this->app->get( 'admin.url' ) . '/profile/' . $this->normalizeProfileSection( $section ) );
    }

    protected function getProfileHelpContext( string $section ) : string {
        return match ( $this->normalizeProfileSection( $section ) ) {
            'editor' => 'admin-profile-editor',
            'publishing' => 'admin-profile-publishing',
            'appearance' => 'admin-profile-appearance',
            'fediverse' => 'admin-profile-fediverse',
            default => 'admin-profile-account',
        };
    }

    protected function getProfileSaveSuccessMessage( string $section ) : string {
        return match ( $this->normalizeProfileSection( $section ) ) {
            'editor' => 'Editor settings were saved.',
            'publishing' => 'Publishing settings were saved.',
            'appearance' => 'Appearance settings were saved.',
            'fediverse' => 'Fediverse settings were saved.',
            default => 'Account settings were saved.',
        };
    }

    protected function getProfileEmailConfirmationFailureMessage( \Throwable $e ) : string {
        $reason = trim( $e->getMessage() );
        if ( in_array( $reason, [ 'smtp_disabled', 'smtp_host', 'smtp_from_email' ], true ) ) {
            return( 'The e-mail address was not changed because the confirmation e-mail could not be sent. Please configure SMTP settings and try again.' );
        }

        return( 'The e-mail address was not changed because the confirmation e-mail could not be sent right now.' );
    }

    protected function mergeProfileSectionFormState( array $data, array $current_user, string $profile_section ) : array {
        $base = $this->normalizeProfileFormState( $current_user );
        $incoming = $this->normalizeProfileFormState( $data );
        $profile_section = $this->normalizeProfileSection( $profile_section );

        $section_fields = match ( $profile_section ) {
            'editor' => [ 'autosave_mode', 'autosave_interval_seconds', 'classic_smileys_mode' ],
            'publishing' => [ 'default_entry_type', 'default_status', 'default_aggregate_to_root', 'discourage_search_indexing' ],
            'appearance' => [ 'public_theme_key', 'public_screen_mode', 'public_theme_content_width', 'public_theme_landing_page_path', 'public_banner_source', 'public_background_source', 'public_background_render_mode' ],
            'fediverse' => [],
            default => [ 'display_name', 'email', 'timezone', 'current_password', 'new_password', 'confirm_password' ],
        };

        if ( $profile_section === 'appearance' ) {
            foreach ( $this->getProfileThemeOverrideSchema( (string) ( $incoming['public_theme_key'] ?? $base['public_theme_key'] ?? '' ) ) as $setting_definition ) {
                $setting_key = (string) ( $setting_definition['key'] ?? '' );
                if ( $setting_key === '' ) {
                    continue;
                }

                $section_fields[] = $this->getProfileThemeOverrideInputName( $setting_key );
            }
        }

        foreach ( $section_fields as $field ) {
            $base[$field] = $incoming[$field] ?? null;
        }

        if ( in_array( $profile_section, [ 'publishing', 'fediverse' ], true ) && array_key_exists( 'plugin_settings', $incoming ) ) {
            $base['plugin_settings'] = $this->mergePluginSettingsMaps(
                is_array( $base['plugin_settings'] ?? null ) ? $base['plugin_settings'] : [],
                is_array( $incoming['plugin_settings'] ?? null ) ? $incoming['plugin_settings'] : []
            );
        }

        return( $base );
    }

    protected function getProfileFormState() : array {
        $flash_state = $this->security->pullFlash( 'profile.form', [] );
        if ( is_array( $flash_state ) && ! empty( $flash_state ) ) {
            return( $flash_state );
        }

        $current_user = $this->getCurrentUserRecord();
        return( $this->normalizeProfileFormState( is_array( $current_user ) ? $current_user : [] ) );
    }

    protected function normalizeProfileFormState( array $data ) : array {
        $autosave_mode = strtolower( trim( (string) ( $data['autosave_mode'] ?? 'inherit' ) ) );
        if ( ! in_array( $autosave_mode, [ 'inherit', 'enabled', 'disabled' ], true ) ) {
            $autosave_mode = 'inherit';
        }

        $classic_smileys_mode = strtolower( trim( (string) ( $data['classic_smileys_mode'] ?? 'inherit' ) ) );
        if ( ! in_array( $classic_smileys_mode, [ 'inherit', 'enabled', 'disabled' ], true ) ) {
            $classic_smileys_mode = 'inherit';
        }

        $default_entry_type = strtolower( trim( (string) ( $data['default_entry_type'] ?? 'post' ) ) );
        if ( ! in_array( $default_entry_type, [ 'post', 'page' ], true ) ) {
            $default_entry_type = 'post';
        }

        $default_status = strtolower( trim( (string) ( $data['default_status'] ?? 'unpublished' ) ) );
        if ( ! in_array( $default_status, [ 'published', 'unpublished' ], true ) ) {
            $default_status = 'unpublished';
        }

        $form_state = [
            'username' => trim( (string) ( $data['username'] ?? ( $this->security->getCurrentUsername() ?? '' ) ) ),
            'display_name' => function_exists( 'mb_trim' ) ? mb_trim( (string) ( $data['display_name'] ?? '' ) ) : trim( (string) ( $data['display_name'] ?? '' ) ),
            'role' => trim( (string) ( $data['role'] ?? $this->security->getCurrentRole() ) ),
            'email' => trim( (string) ( $data['email'] ?? '' ) ),
            'timezone' => trim( (string) ( $data['timezone'] ?? '' ) ),
            'autosave_mode' => $autosave_mode,
            'autosave_interval_seconds' => trim( (string) ( $data['autosave_interval_seconds'] ?? '' ) ),
            'classic_smileys_mode' => $classic_smileys_mode,
            'default_entry_type' => $default_entry_type,
            'default_status' => $default_status,
            'default_aggregate_to_root' => ! empty( $data['default_aggregate_to_root'] ),
            'discourage_search_indexing' => ! empty( $data['discourage_search_indexing'] ),
            'public_theme_key' => $this->normalizeProfilePublicThemeKey( (string) ( $data['public_theme_key'] ?? $this->getCurrentProfilePublicThemeKey() ) ),
            'public_screen_mode' => $this->normalizeProfilePublicScreenMode( (string) ( $data['public_screen_mode'] ?? $this->getCurrentProfilePublicScreenMode() ) ),
            'public_theme_content_width' => trim( (string) ( $data['public_theme_content_width'] ?? $this->getCurrentProfileThemeContentWidth() ) ),
            'public_theme_landing_page_path' => trim( (string) ( $data['public_theme_landing_page_path'] ?? $this->getCurrentProfileThemeLandingPagePath() ) ),
            'public_banner_source' => $this->normalizePublicBackgroundSource( (string) ( $data['public_banner_source'] ?? $this->getCurrentProfilePublicBannerSource() ) ),
            'public_background_source' => $this->normalizePublicBackgroundSource( (string) ( $data['public_background_source'] ?? $this->getCurrentProfilePublicBackgroundSource() ) ),
            'public_background_render_mode' => $this->normalizeBackgroundRenderMode( (string) ( $data['public_background_render_mode'] ?? $this->getCurrentProfilePublicBackgroundRenderMode() ) ),
            'plugin_settings' => $this->normalizePluginSettingsMap( $data['plugin_settings'] ?? [] ),
            'current_password' => '',
            'new_password' => '',
            'confirm_password' => '',
        ];

        foreach ( $this->getProfileThemeOverrideSchema() as $setting_definition ) {
            $setting_key = (string) ( $setting_definition['key'] ?? '' );
            if ( $setting_key === '' ) {
                continue;
            }

            $input_name = $this->getProfileThemeOverrideInputName( $setting_key );
            $legacy_value = match ( $setting_key ) {
                'content_width' => $data['public_theme_content_width'] ?? null,
                'landing_page_path' => $data['public_theme_landing_page_path'] ?? null,
                default => null,
            };
            $form_state[$input_name] = trim(
                (string) (
                    $data[$input_name]
                    ?? $legacy_value
                    ?? $this->getCurrentProfileThemeSettingValue( $setting_key )
                )
            );
        }

        return( $form_state );
    }

    protected function validateProfileSettings( array $data, array $current_user, string $profile_section = 'account' ) : array {
        $profile_section = $this->normalizeProfileSection( $profile_section );
        $email = strtolower( trim( (string) ( $data['email'] ?? '' ) ) );
        $display_name = function_exists( 'mb_trim' ) ? mb_trim( (string) ( $data['display_name'] ?? '' ) ) : trim( (string) ( $data['display_name'] ?? '' ) );
        if ( ! ( $this->security->isSuperAdmin() || $this->config->canUsersChangeEmail() ) ) {
            $email = (string) ( $current_user['email'] ?? '' );
        }
        if ( $email !== '' && filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'email' );
        }

        $timezone = trim( (string) ( $data['timezone'] ?? '' ) );
        if ( $timezone !== '' ) {
            try {
                new \DateTimeZone( $timezone );
            } catch ( \Throwable ) {
                throw new \InvalidArgumentException( 'timezone' );
            }
        }

        $autosave_mode = strtolower( trim( (string) ( $data['autosave_mode'] ?? 'inherit' ) ) );
        if ( ! in_array( $autosave_mode, [ 'inherit', 'enabled', 'disabled' ], true ) ) {
            throw new \InvalidArgumentException( 'autosave_mode' );
        }

        $classic_smileys_mode = strtolower( trim( (string) ( $data['classic_smileys_mode'] ?? 'inherit' ) ) );
        if ( ! in_array( $classic_smileys_mode, [ 'inherit', 'enabled', 'disabled' ], true ) ) {
            throw new \InvalidArgumentException( 'classic_smileys_mode' );
        }

        $autosave_interval_seconds = trim( (string) ( $data['autosave_interval_seconds'] ?? '' ) );
        if ( $autosave_interval_seconds !== '' ) {
            $autosave_interval = (int) $autosave_interval_seconds;
            if ( $autosave_interval < 30 || $autosave_interval > 180 ) {
                throw new \InvalidArgumentException( 'autosave_interval_seconds' );
            }
        }

        $default_entry_type = strtolower( trim( (string) ( $data['default_entry_type'] ?? 'post' ) ) );
        if ( ! in_array( $default_entry_type, [ 'post', 'page' ], true ) ) {
            throw new \InvalidArgumentException( 'default_entry_type' );
        }

        $default_status = strtolower( trim( (string) ( $data['default_status'] ?? 'unpublished' ) ) );
        if ( ! in_array( $default_status, [ 'published', 'unpublished' ], true ) ) {
            throw new \InvalidArgumentException( 'default_status' );
        }

        $current_password = (string) ( $data['current_password'] ?? '' );
        $new_password = (string) ( $data['new_password'] ?? '' );
        $confirm_password = (string) ( $data['confirm_password'] ?? '' );
        if ( $new_password !== '' || $confirm_password !== '' || $current_password !== '' ) {
            if ( $current_password === '' ) {
                throw new \InvalidArgumentException( 'current_password' );
            }
            $current_username = $this->security->getCurrentUsername();
            if ( ! is_string( $current_username ) || ! $this->security->verifyCredentials( $current_username, $current_password ) ) {
                throw new \InvalidArgumentException( 'current_password_invalid' );
            }
            if ( $new_password === '' ) {
                throw new \InvalidArgumentException( 'new_password' );
            }
            if ( ! $this->security->validatePassword( $new_password ) ) {
                throw new \InvalidArgumentException( 'password' );
            }
            if ( mb_strlen( $new_password ) < $this->config->getMinPasswordLength() ) {
                throw new \InvalidArgumentException( 'password_length' );
            }
            if ( $new_password !== $confirm_password ) {
                throw new \InvalidArgumentException( 'confirm_password' );
            }
        }

        $public_background_source = $this->validatePublicBackgroundSource( (string) ( $data['public_background_source'] ?? 'inherit' ) );
        $public_background_render_mode = $this->validateBackgroundRenderMode( (string) ( $data['public_background_render_mode'] ?? 'scaled' ), 'public_background_render_mode' );
        $public_banner_source = $this->validatePublicBackgroundSource( (string) ( $data['public_banner_source'] ?? 'inherit' ), 'public_banner_source' );
        $public_theme_key = $this->validateProfilePublicThemeKey( (string) ( $data['public_theme_key'] ?? 'inherit' ) );
        $public_screen_mode = $this->validateProfilePublicScreenMode( (string) ( $data['public_screen_mode'] ?? 'inherit' ) );
        $public_banner_image = $this->resolveProfilePublicBackgroundImageUpload(
            (string) ( $current_user['public_banner_media_id'] ?? '' ),
            'public_banner_image',
            $this->getCurrentAuthorSlug(),
            [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml' ]
        );
        if ( $public_banner_source === 'custom' && empty( $public_banner_image['media_id'] ) ) {
            throw new \InvalidArgumentException( 'public_banner_image' );
        }
        $public_background_image = $this->resolveProfilePublicBackgroundImageUpload(
            (string) ( $current_user['public_background_media_id'] ?? '' ),
            'public_background_image',
            $this->getCurrentAuthorSlug(),
            [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml' ]
        );
        if ( $public_background_source === 'custom' && empty( $public_background_image['media_id'] ) ) {
            throw new \InvalidArgumentException( 'public_background_image' );
        }

        return(
            [
                'display_name' => mb_substr( $display_name, 0, 120 ),
                'email' => mb_substr( $email, 0, 200 ),
                'timezone' => $timezone,
                'autosave_mode' => $autosave_mode,
                'autosave_interval_seconds' => $autosave_interval_seconds,
                'classic_smileys_mode' => $classic_smileys_mode,
                'default_entry_type' => $default_entry_type,
                'default_status' => $default_status,
                'default_aggregate_to_root' => ! empty( $data['default_aggregate_to_root'] ),
                'discourage_search_indexing' => ! empty( $data['discourage_search_indexing'] ),
                'public_theme_key' => $public_theme_key,
                'public_screen_mode' => $public_screen_mode,
                'public_banner_source' => $public_banner_source,
                'public_banner_media_id' => (string) ( $public_banner_image['media_id'] ?? '' ),
                'public_background_source' => $public_background_source,
                'public_background_media_id' => (string) ( $public_background_image['media_id'] ?? '' ),
                'public_background_render_mode' => $public_background_render_mode,
                'public_theme_settings' => $this->validateProfilePublicThemeSettings( $data ),
                'plugin_settings' => $this->validateProfilePluginSettings( $data, $current_user, $profile_section ),
                'password' => $new_password,
            ]
        );
    }

    protected function getProfileNotice() : array {
        $flash_notice = $this->security->pullFlash( 'profile.notice', [] );
        if ( is_array( $flash_notice ) && ! empty( $flash_notice['type'] ) && ! empty( $flash_notice['message'] ) ) {
            return( $flash_notice );
        }

        return( [] );
    }

    protected function setProfileNoticeFlash( string $type, string $message ) : void {
        $this->security->setFlash(
            'profile.notice',
            [
                'type' => $type,
                'message' => $message,
            ]
        );
    }

    protected function clearPublicPageCache() : void {
        if ( ! $this->app->has( 'public.page_cache' ) ) {
            return;
        }

        $public_page_cache = $this->app->get( 'public.page_cache' );
        if ( is_object( $public_page_cache ) && method_exists( $public_page_cache, 'invalidateAll' ) ) {
            $public_page_cache->invalidateAll();
            return;
        }

        if ( is_object( $public_page_cache ) && method_exists( $public_page_cache, 'clear' ) ) {
            $public_page_cache->clear();
        }
    }

}
