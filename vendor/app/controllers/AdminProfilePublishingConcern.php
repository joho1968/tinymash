<?php
namespace app\controllers;

trait AdminProfilePublishingConcern {

    public function generateProfileSecretLink() : void {
        $this->handleProfileSecretLinkAction( 'generate' );
    }

    public function revokeProfileSecretLink() : void {
        $this->handleProfileSecretLinkAction( 'revoke' );
    }

    public function deleteAllProfileContent() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setProfileNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getProfileSectionUrl( 'publishing' ) );
            return;
        }

        $current_user = $this->getCurrentUserRecord();
        $current_username = $this->security->getCurrentUsername();
        $author_slug = is_array( $current_user ) ? strtolower( trim( (string) ( $current_user['username'] ?? '' ) ) ) : '';
        if ( ! is_array( $current_user ) || ! is_string( $current_username ) || $current_username === '' || $author_slug === '' || $this->content_repository === null ) {
            $this->setProfileNoticeFlash( 'danger', 'Your author content could not be deleted right now.' );
            $this->app->redirect( $this->getProfileSectionUrl( 'publishing' ) );
            return;
        }

        $this->security->setFlash( 'profile.form', $this->mergeProfileSectionFormState( $data, $current_user, 'publishing' ) );
        $confirmation = strtoupper( trim( (string) ( $data['delete_all_confirmation'] ?? '' ) ) );
        if ( $confirmation !== 'DELETE ALL' ) {
            $this->setProfileNoticeFlash( 'danger', 'Type DELETE ALL to confirm removal of all posts and pages in your author space.' );
            $this->app->redirect( $this->getProfileSectionUrl( 'publishing' ) );
            return;
        }

        try {
            $result = $this->content_repository->deleteAllAuthorContent( $author_slug );
            $deleted_posts = (int) ( $result['deleted_posts'] ?? 0 );
            $deleted_pages = (int) ( $result['deleted_pages'] ?? 0 );
            $deleted_entries = (int) ( $result['deleted_entries'] ?? 0 );
            if ( $deleted_entries < 1 ) {
                $this->setProfileNoticeFlash( 'warning', 'No posts or pages were deleted from your author space.' );
            } else {
                $this->setProfileNoticeFlash( 'success', 'Deleted ' . $deleted_entries . ' author entries (' . $deleted_posts . ' posts, ' . $deleted_pages . ' pages).' );
            }
        } catch ( \InvalidArgumentException ) {
            $this->setProfileNoticeFlash( 'danger', 'Your author content could not be deleted right now.' );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to delete all author content (' . $e->getMessage() . ')' );
            $this->setProfileNoticeFlash( 'danger', 'Your author content could not be deleted right now.' );
        }

        $this->app->redirect( $this->getProfileSectionUrl( 'publishing' ) );
    }

    protected function getCurrentPublishingDefaults() : array {
        $user = $this->getCurrentUserRecord();
        return(
            [
                'default_entry_type' => is_array( $user ) && ! empty( $user['default_entry_type'] ) ? (string) $user['default_entry_type'] : 'post',
                'default_status' => is_array( $user ) && ! empty( $user['default_status'] ) ? (string) $user['default_status'] : 'unpublished',
                'default_aggregate_to_root' => is_array( $user ) ? ! empty( $user['default_aggregate_to_root'] ) : false,
                'discourage_search_indexing' => is_array( $user ) ? ! empty( $user['discourage_search_indexing'] ) : false,
            ]
        );
    }

    protected function getCurrentProfilePublicThemeKey() : string {
        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) ) {
            return( 'inherit' );
        }

        return( $this->normalizeProfilePublicThemeKey( (string) ( $user['public_theme_key'] ?? 'inherit' ) ) );
    }

    protected function getCurrentProfilePublicScreenMode() : string {
        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) ) {
            return( 'inherit' );
        }

        return( $this->normalizeProfilePublicScreenMode( (string) ( $user['public_screen_mode'] ?? 'inherit' ) ) );
    }

    protected function getProfilePublicThemeOptions() : array {
        if ( $this->theme === null || ! method_exists( $this->theme, 'getAvailableThemes' ) ) {
            return( [] );
        }

        $options = [];
        foreach ( $this->theme->getAvailableThemes() as $theme_definition ) {
            if ( ! is_array( $theme_definition ) || empty( $theme_definition['key'] ) ) {
                continue;
            }

            $options[] = [
                'value' => (string) $theme_definition['key'],
                'label' => (string) ( $theme_definition['name'] ?? ucfirst( (string) $theme_definition['key'] ) ),
            ];
        }

        return( $options );
    }

    protected function getProfilePublicThemeSystemLabel() : string {
        return( $this->theme !== null ? $this->theme->getThemeName() : 'Site theme' );
    }

    protected function getCurrentProfileThemeContentWidth() : string {
        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) || $this->theme === null ) {
            return( '' );
        }

        $theme_key = $this->getProfileResolvedAppearanceThemeKey();
        return( trim( (string) ( $user['public_theme_settings'][$theme_key]['content_width'] ?? '' ) ) );
    }

    protected function getCurrentProfileThemeLandingPagePath() : string {
        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) || $this->theme === null ) {
            return( '' );
        }

        $theme_key = $this->getProfileResolvedAppearanceThemeKey();
        return( trim( (string) ( $user['public_theme_settings'][$theme_key]['landing_page_path'] ?? '' ) ) );
    }

    protected function getCurrentProfileThemeSettingValue( string $setting_key ) : string {
        $setting_key = trim( $setting_key );
        if ( $setting_key === '' ) {
            return( '' );
        }

        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) || $this->theme === null ) {
            return( '' );
        }

        $theme_key = $this->getProfileResolvedAppearanceThemeKey();
        return( trim( (string) ( $user['public_theme_settings'][$theme_key][$setting_key] ?? '' ) ) );
    }

    protected function getCurrentProfilePublicBackgroundSource() : string {
        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) ) {
            return( 'inherit' );
        }

        return( $this->normalizePublicBackgroundSource( (string) ( $user['public_background_source'] ?? 'inherit' ) ) );
    }

    protected function getCurrentProfilePublicBannerSource() : string {
        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) ) {
            return( 'inherit' );
        }

        return( $this->normalizePublicBackgroundSource( (string) ( $user['public_banner_source'] ?? 'inherit' ) ) );
    }

    protected function getCurrentProfilePublicBackgroundRenderMode() : string {
        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) ) {
            return( 'scaled' );
        }

        return( $this->normalizeBackgroundRenderMode( (string) ( $user['public_background_render_mode'] ?? 'scaled' ) ) );
    }

    protected function getCurrentProfilePublicBackgroundMediaId() : string {
        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) ) {
            return( '' );
        }

        return( trim( (string) ( $user['public_background_media_id'] ?? '' ) ) );
    }

    protected function getCurrentProfilePublicBannerMediaId() : string {
        $user = $this->getCurrentUserRecord();
        if ( ! is_array( $user ) ) {
            return( '' );
        }

        return( trim( (string) ( $user['public_banner_media_id'] ?? '' ) ) );
    }

    protected function getCurrentProfilePublicBackgroundImage() : array {
        $media_id = $this->getCurrentProfilePublicBackgroundMediaId();
        if ( $media_id === '' ) {
            return( [] );
        }

        return( $this->resolveOwnedMediaImageByMediaId( $media_id, [ $this->getCurrentAuthorSlug() ] ) );
    }

    protected function getCurrentProfilePublicBannerImage() : array {
        $media_id = $this->getCurrentProfilePublicBannerMediaId();
        if ( $media_id === '' ) {
            return( [] );
        }

        return( $this->resolveOwnedMediaImageByMediaId( $media_id, [ $this->getCurrentAuthorSlug() ] ) );
    }

    protected function getProfileThemeContentWidthOptions() : array {
        if ( $this->theme === null ) {
            return( [] );
        }

        foreach ( $this->theme->getThemeSettingsSchemaForKey( $this->getProfileResolvedAppearanceThemeKey() ) as $setting_definition ) {
            if ( ! is_array( $setting_definition ) || (string) ( $setting_definition['key'] ?? '' ) !== 'content_width' || (string) ( $setting_definition['type'] ?? '' ) !== 'select' ) {
                continue;
            }

            $options = [];
            foreach ( (array) ( $setting_definition['options'] ?? [] ) as $option_definition ) {
                if ( ! is_array( $option_definition ) || ! isset( $option_definition['value'] ) ) {
                    continue;
                }

                $options[] = [
                    'value' => (string) $option_definition['value'],
                    'label' => (string) ( $option_definition['label'] ?? (string) $option_definition['value'] ),
                ];
            }

            return( $options );
        }

        return( [] );
    }

    protected function getProfileThemeContentWidthSystemLabel() : string {
        if ( $this->theme === null ) {
            return( 'Theme default' );
        }

        $theme_key = $this->getProfileResolvedAppearanceThemeKey();
        return( $this->getThemeSettingOptionLabel( 'content_width', (string) ( $this->theme->getThemeSettingsForKey( $theme_key )['content_width'] ?? 'theme_default' ), $theme_key ) );
    }

    protected function getProfileThemeLandingPageOptions() : array {
        if ( $this->theme === null || ! $this->themeSupportsAuthorLandingPageOverride( $this->getProfileResolvedAppearanceThemeKey() ) ) {
            return( [] );
        }

        return( $this->buildPublishedPageLookupOptions( 'author', $this->getCurrentAuthorSlug() ) );
    }

    protected function getThemeSettingOptionLabel( string $setting_key, string $setting_value, string $theme_key = '' ) : string {
        if ( $this->theme === null ) {
            return( $setting_value );
        }

        foreach ( $this->theme->getThemeSettingsSchemaForKey( $theme_key !== '' ? $theme_key : $this->getProfileResolvedAppearanceThemeKey() ) as $setting_definition ) {
            if ( ! is_array( $setting_definition ) || (string) ( $setting_definition['key'] ?? '' ) !== $setting_key ) {
                continue;
            }

            foreach ( (array) ( $setting_definition['options'] ?? [] ) as $option_definition ) {
                if ( ! is_array( $option_definition ) || ! isset( $option_definition['value'] ) ) {
                    continue;
                }

                if ( (string) $option_definition['value'] === $setting_value ) {
                    return( (string) ( $option_definition['label'] ?? $setting_value ) );
                }
            }
        }

        return( $setting_value );
    }

    protected function getProfileThemeOverrideInputName( string $setting_key ) : string {
        return( 'public_theme_setting_' . strtolower( trim( $setting_key ) ) );
    }

    protected function getProfileThemeOverrideSchema( string $theme_key = '' ) : array {
        if ( $this->theme === null ) {
            return( [] );
        }

        $theme_key = $this->resolveProfilePublicThemeKey( $theme_key );
        $override_schema = [];
        foreach ( $this->theme->getThemeSettingsSchemaForKey( $theme_key ) as $setting_definition ) {
            if ( ! is_array( $setting_definition ) || empty( $setting_definition['key'] ) || empty( $setting_definition['author_override'] ) ) {
                continue;
            }

            $override_schema[] = $setting_definition;
        }

        return( $override_schema );
    }

    protected function getProfileThemeOverrideSettings( array $form_state = [] ) : array {
        if ( $this->theme === null ) {
            return( [] );
        }

        $theme_key = $this->resolveProfilePublicThemeKey( (string) ( $form_state['public_theme_key'] ?? $this->getCurrentProfilePublicThemeKey() ) );
        $theme_settings = $this->theme->getThemeSettingsForKey( $theme_key );
        $override_settings = [];
        foreach ( $this->getProfileThemeOverrideSchema( $theme_key ) as $setting_definition ) {
            $setting_key = (string) ( $setting_definition['key'] ?? '' );
            if ( $setting_key === '' ) {
                continue;
            }

            $input_name = $this->getProfileThemeOverrideInputName( $setting_key );
            $system_value = trim( (string) ( $theme_settings[$setting_key] ?? ( $setting_definition['default'] ?? '' ) ) );
            $current_value = trim( (string) ( $form_state[$input_name] ?? $this->getCurrentProfileThemeSettingValue( $setting_key ) ) );
            $setting_definition['input_name'] = $input_name;
            $setting_definition['current_value'] = $current_value;
            $setting_definition['system_value'] = $system_value;
            $setting_definition['system_label'] = (string) ( $setting_definition['type'] ?? '' ) === 'select'
                ? $this->getThemeSettingOptionLabel( $setting_key, $system_value, $theme_key )
                : ( $system_value !== '' ? '/' . ltrim( $system_value, '/' ) : 'Automatic' );
            $lookup_options = (string) ( $setting_definition['lookup'] ?? '' ) === 'published_pages'
                ? $this->buildPublishedPageLookupOptions( 'author', $this->getCurrentAuthorSlug() )
                : [];
            $setting_definition['lookup_options'] = $lookup_options;
            if ( (string) ( $setting_definition['lookup'] ?? '' ) === 'published_pages' && empty( $lookup_options ) && $current_value === '' ) {
                $setting_definition['disabled'] = true;
                $setting_definition['help'] = trim( (string) ( $setting_definition['help'] ?? '' ) . ' Publish an author-space page before choosing a landing page.' );
            }
            $override_settings[] = $setting_definition;
        }

        return( $override_settings );
    }

    protected function validateProfilePublicThemeSettings( array $data ) : array {
        if ( $this->theme === null ) {
            return( [] );
        }

        $validated_settings = [];
        $theme_key = $this->resolveProfilePublicThemeKey( (string) ( $data['public_theme_key'] ?? $this->getCurrentProfilePublicThemeKey() ) );
        foreach ( $this->getProfileThemeOverrideSchema( $theme_key ) as $setting_definition ) {
            $setting_key = (string) ( $setting_definition['key'] ?? '' );
            $setting_type = (string) ( $setting_definition['type'] ?? '' );
            if ( $setting_key === '' ) {
                continue;
            }

            $input_name = $this->getProfileThemeOverrideInputName( $setting_key );
            if ( ! empty( $setting_definition['disabled'] ) ) {
                $validated_settings[$setting_key] = $this->getCurrentProfileThemeSettingValue( $setting_key );
                continue;
            }

            if ( $setting_type === 'select' ) {
                $allowed_values = [];
                foreach ( (array) ( $setting_definition['options'] ?? [] ) as $option_definition ) {
                    if ( is_array( $option_definition ) && isset( $option_definition['value'] ) ) {
                        $allowed_values[] = (string) $option_definition['value'];
                    }
                }

                $value = trim( (string) ( $data[$input_name] ?? '' ) );
                if ( $value !== '' && ! in_array( $value, $allowed_values, true ) ) {
                    throw new \InvalidArgumentException( $input_name );
                }
                $validated_settings[$setting_key] = $value;
                continue;
            }

            if ( $setting_type === 'text' && (string) ( $setting_definition['lookup'] ?? '' ) === 'published_pages' ) {
                $validated_settings[$setting_key] = $this->validateLandingPagePathForScope(
                    (string) ( $data[$input_name] ?? '' ),
                    'author',
                    $this->getCurrentAuthorSlug(),
                    $input_name
                );
                continue;
            }

            $validated_settings[$setting_key] = trim( (string) ( $data[$input_name] ?? '' ) );
        }

        return( [ $theme_key => $validated_settings ] );
    }

    protected function getProfileResolvedAppearanceThemeKey() : string {
        return( $this->resolveProfilePublicThemeKey( $this->getCurrentProfilePublicThemeKey() ) );
    }

    protected function resolveProfilePublicThemeKey( string $theme_key ) : string {
        $theme_key = $this->normalizeProfilePublicThemeKey( $theme_key );
        if ( $theme_key === 'inherit' ) {
            return( $this->theme !== null ? $this->theme->getSiteThemeKey() : 'baseline' );
        }

        return( $theme_key );
    }

    protected function normalizeProfilePublicThemeKey( string $theme_key ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        if ( $theme_key === '' || $theme_key === 'inherit' ) {
            return( 'inherit' );
        }

        return( preg_replace( '/[^a-z0-9_-]/', '', $theme_key ) ?: 'inherit' );
    }

    protected function validateProfilePublicThemeKey( string $theme_key ) : string {
        $theme_key = $this->normalizeProfilePublicThemeKey( $theme_key );
        if ( $theme_key === 'inherit' ) {
            return( 'inherit' );
        }

        foreach ( $this->getProfilePublicThemeOptions() as $theme_option ) {
            if ( (string) ( $theme_option['value'] ?? '' ) === $theme_key ) {
                return( $theme_key );
            }
        }

        throw new \InvalidArgumentException( 'public_theme_key' );
    }

    protected function normalizeProfilePublicScreenMode( string $mode ) : string {
        $mode = strtolower( trim( $mode ) );
        return( in_array( $mode, [ 'inherit', 'auto', 'light', 'dark' ], true ) ? $mode : 'inherit' );
    }

    protected function validateProfilePublicScreenMode( string $mode ) : string {
        $mode = $this->normalizeProfilePublicScreenMode( $mode );
        if ( ! in_array( $mode, [ 'inherit', 'auto', 'light', 'dark' ], true ) ) {
            throw new \InvalidArgumentException( 'public_screen_mode' );
        }

        return( $mode );
    }

    protected function normalizePublicBackgroundSource( string $source ) : string {
        $source = strtolower( trim( $source ) );
        return( in_array( $source, [ 'inherit', 'none', 'custom' ], true ) ? $source : 'inherit' );
    }

    protected function validatePublicBackgroundSource( string $source, string $error_key = 'public_background_source' ) : string {
        $normalized_source = strtolower( trim( $source ) );
        if ( ! in_array( $normalized_source, [ 'inherit', 'none', 'custom' ], true ) ) {
            throw new \InvalidArgumentException( $error_key );
        }

        return( $normalized_source );
    }

    protected function normalizeBackgroundRenderMode( string $mode ) : string {
        $mode = strtolower( trim( $mode ) );
        return( in_array( $mode, [ 'as_is', 'centered', 'scaled', 'tiled' ], true ) ? $mode : 'scaled' );
    }

    protected function validateBackgroundRenderMode( string $mode, string $error_key = 'site_background_render_mode' ) : string {
        $normalized_mode = strtolower( trim( $mode ) );
        if ( ! in_array( $normalized_mode, [ 'as_is', 'centered', 'scaled', 'tiled' ], true ) ) {
            throw new \InvalidArgumentException( $error_key );
        }

        return( $normalized_mode );
    }

    protected function resolveOwnedMediaImageByMediaId( string $media_id, array $owner_usernames = [] ) : array {
        $media_id = trim( $media_id );
        if ( $media_id === '' || ! $this->app->has( 'media.service' ) ) {
            return( [] );
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'getAttachmentMetadataByMediaId' ) ) {
            return( [] );
        }

        $metadata = $media_service->getAttachmentMetadataByMediaId( $media_id, $owner_usernames );
        if ( ! is_array( $metadata ) ) {
            return( [] );
        }

        return( $this->normalizeSystemSiteImageValue( $metadata ) );
    }

    protected function resolveProfilePublicBackgroundImageUpload( string $current_media_id, string $field_key, string $owner_username, array $allowed_mimes ) : array {
        $current_image = $this->resolveOwnedMediaImageByMediaId( $current_media_id, [ $owner_username ] );
        if ( ! empty( $_POST[$field_key . '_clear'] ) ) {
            $current_image = [];
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
            throw new \InvalidArgumentException( $field_key . '_upload' );
        }

        if ( ! $this->app->has( 'media.service' ) ) {
            throw new \InvalidArgumentException( $field_key . '_upload' );
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'storeUploadedImage' ) ) {
            throw new \InvalidArgumentException( $field_key . '_upload' );
        }

        try {
            $uploaded_image = $media_service->storeUploadedImage(
                $file,
                $owner_username,
                [
                    'allowed_mimes' => $allowed_mimes,
                    'sanitize_svg' => in_array( 'image/svg+xml', $allowed_mimes, true ),
                ]
            );
        } catch ( \InvalidArgumentException $e ) {
            $message = (string) $e->getMessage();
            if ( in_array( $message, [ 'content_image_type', 'content_image_upload', 'content_image_file' ], true ) ) {
                throw new \InvalidArgumentException( $message === 'content_image_type' ? $field_key . '_type' : $field_key . '_upload' );
            }
            throw $e;
        }

        $mime = strtolower( trim( (string) ( $uploaded_image['mime'] ?? '' ) ) );
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            throw new \InvalidArgumentException( $field_key . '_type' );
        }

        return( $this->normalizeSystemSiteImageValue( $uploaded_image ) );
    }

    protected function getCurrentProfileSecretLinkViewData() : array {
        $user = $this->getCurrentUserRecord();
        $author_slug = is_array( $user ) ? strtolower( trim( (string) ( $user['username'] ?? '' ) ) ) : '';
        $content_active = is_array( $user ) ? ! empty( $user['content_active'] ) : false;
        $secret_link_service = $this->getSecretLinkService();
        $secret_link_record = null;
        if ( is_object( $secret_link_service ) && method_exists( $secret_link_service, 'getAuthorSecretLink' ) && $author_slug !== '' ) {
            $secret_link_record = $secret_link_service->getAuthorSecretLink( $author_slug );
        }

        $app_url = rtrim( (string) $this->app->get( 'app.url' ), '/' );
        $secret_link_url = '';
        if ( is_object( $secret_link_service ) && method_exists( $secret_link_service, 'buildSecretLinkUrl' ) && is_array( $secret_link_record ) ) {
            $secret_link_url = (string) $secret_link_service->buildSecretLinkUrl( $secret_link_record, $app_url );
        }

        return(
            [
                'site_public' => $this->config->isSitePublic(),
                'system_enabled' => $this->config->allowsSecretLinks(),
                'default_expiry_days' => $this->config->getSecretLinkDefaultExpiryDays(),
                'author_slug' => $author_slug,
                'content_active' => $content_active,
                'record' => is_array( $secret_link_record ) ? $secret_link_record : [],
                'url' => $secret_link_url,
                'created_at_display' => is_array( $secret_link_record ) ? $this->formatUtcDateTime( (string) ( $secret_link_record['created_at_utc'] ?? '' ) ) : '',
                'expires_at_display' => is_array( $secret_link_record ) ? $this->formatUtcDateTime( (string) ( $secret_link_record['expires_at_utc'] ?? '' ) ) : '',
            ]
        );
    }

    protected function handleProfileSecretLinkAction( string $action ) : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setProfileNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getProfileSectionUrl( 'publishing' ) );
            return;
        }

        $current_user = $this->getCurrentUserRecord();
        $current_username = $this->security->getCurrentUsername();
        $author_slug = is_array( $current_user ) ? strtolower( trim( (string) ( $current_user['username'] ?? '' ) ) ) : '';
        if ( ! is_array( $current_user ) || ! is_string( $current_username ) || $current_username === '' || $author_slug === '' ) {
            $this->setProfileNoticeFlash( 'danger', 'Secret-link settings could not be saved right now.' );
            $this->app->redirect( $this->getProfileSectionUrl( 'publishing' ) );
            return;
        }

        $this->security->setFlash( 'profile.form', $this->mergeProfileSectionFormState( $data, $current_user, 'publishing' ) );

        $secret_link_service = $this->getSecretLinkService();
        if ( ! is_object( $secret_link_service ) ) {
            $this->setProfileNoticeFlash( 'danger', 'Secret-link settings could not be saved right now.' );
            $this->app->redirect( $this->getProfileSectionUrl( 'publishing' ) );
            return;
        }

        try {
            if ( $action === 'generate' ) {
                if ( $this->config->isSitePublic() ) {
                    throw new \InvalidArgumentException( 'secret_links_public_site' );
                }
                if ( ! $this->config->allowsSecretLinks() ) {
                    throw new \InvalidArgumentException( 'secret_links_disabled' );
                }
                if ( empty( $current_user['content_active'] ) ) {
                    throw new \InvalidArgumentException( 'secret_links_author_locked' );
                }
                if ( ! method_exists( $secret_link_service, 'generateAuthorSecretLink' ) ) {
                    throw new \RuntimeException( 'Secret link generation is unavailable.' );
                }

                $secret_link_service->generateAuthorSecretLink( $author_slug, $current_username, $this->config->getSecretLinkDefaultExpiryDays() );
                $this->setProfileNoticeFlash( 'success', 'Secret link generated for ~ /home/' . $author_slug . '.' );
            } else {
                if ( ! method_exists( $secret_link_service, 'revokeAuthorSecretLink' ) ) {
                    throw new \RuntimeException( 'Secret link revocation is unavailable.' );
                }
                $secret_link_service->revokeAuthorSecretLink( $author_slug );
                $this->setProfileNoticeFlash( 'success', 'Secret link revoked for ~ /home/' . $author_slug . '.' );
            }
        } catch ( \InvalidArgumentException $e ) {
            $message = match ( $e->getMessage() ) {
                'secret_links_public_site' => 'Secret links are only used when the site is not public.',
                'secret_links_disabled' => 'Secret links are currently disabled by the site administrator.',
                'secret_links_author_locked' => 'Your public author space is currently locked.',
                default => 'Secret-link settings could not be saved right now.',
            };
            $this->setProfileNoticeFlash( 'danger', $message );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to update secret link (' . $e->getMessage() . ')' );
            $this->setProfileNoticeFlash( 'danger', 'Secret-link settings could not be saved right now.' );
        }

        $this->app->redirect( $this->getProfileSectionUrl( 'publishing' ) );
    }

    protected function getCurrentUserPluginSettings() : array {
        $user = $this->getCurrentUserRecord();
        return( is_array( $user['plugin_settings'] ?? null ) ? $user['plugin_settings'] : [] );
    }

}
