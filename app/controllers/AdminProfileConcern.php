<?php
namespace app\controllers;

trait AdminProfileConcern {

    public function profile( string $section = 'account' ) : void {
        $this->renderAdmin( 'profile', $this->buildProfileViewData( [ 'profile_section' => $this->normalizeProfileSection( $section ) ] ) );
    }

    public function confirmProfileEmailChange() : void {
        $user_repository = $this->getUserRepository();
        if ( $user_repository === null ) {
            $this->app->response()->status( 500 );
            $this->render(
                'email.confirmation.latte',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'E-mail confirmation',
                    'confirmation_state' => 'error',
                    'confirmation_message' => 'The e-mail confirmation flow is not available right now.',
                    'login_url' => $this->config->configGetLoginURL(),
                ]
            );
            return;
        }

        $username = trim( (string) $this->app->request()->query->username );
        $token = trim( (string) $this->app->request()->query->token );
        try {
            $confirmed_user = $user_repository->confirmOwnEmailChange( $username, $token );
            if ( ! is_array( $confirmed_user ) ) {
                $this->app->response()->status( 403 );
                $this->render(
                    'email.confirmation.latte',
                    [
                        'title' => APP_NAME . APP_TITLE_SEP . 'E-mail confirmation',
                        'confirmation_state' => 'invalid',
                        'confirmation_message' => 'That confirmation link is no longer valid.',
                        'login_url' => $this->config->configGetLoginURL(),
                    ]
                );
                return;
            }

            $this->createSystemNotification(
                'profile_email_changed',
                'User changed e-mail address',
                (string) ( $confirmed_user['username'] ?? 'A user' ) . ' confirmed a new e-mail address: ' . (string) ( $confirmed_user['email'] ?? 'unknown' ) . '.',
                [
                    'severity' => 'info',
                    'target_username' => (string) ( $confirmed_user['username'] ?? '' ),
                    'dedupe_key' => 'profile-email-changed:' . (string) ( $confirmed_user['username'] ?? '' ) . ':' . (string) ( $confirmed_user['email'] ?? '' ),
                    'dedupe_window_seconds' => 3600,
                    'context' => [
                        'username' => (string) ( $confirmed_user['username'] ?? '' ),
                        'email' => (string) ( $confirmed_user['email'] ?? '' ),
                    ],
                ]
            );

            if ( $this->security->isLoggedIn() ) {
                $current_username = $this->security->getCurrentUsername();
                if ( is_string( $current_username ) && $current_username !== '' && $current_username === (string) ( $confirmed_user['username'] ?? '' ) ) {
                    $this->setProfileNoticeFlash( 'success', 'E-mail address change confirmed.' );
                    $this->app->redirect( $this->getProfileSectionUrl( 'account' ) );
                    return;
                }
            }

            $this->render(
                'email.confirmation.latte',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'E-mail confirmation',
                    'confirmation_state' => 'success',
                    'confirmation_message' => 'Your e-mail address has been confirmed and updated.',
                    'login_url' => $this->config->configGetLoginURL(),
                ]
            );
        } catch ( \Throwable $e ) {
            $this->app->response()->status( 500 );
            $this->render(
                'email.confirmation.latte',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'E-mail confirmation',
                    'confirmation_state' => 'error',
                    'confirmation_message' => 'The e-mail address could not be confirmed right now.',
                    'login_url' => $this->config->configGetLoginURL(),
                ]
            );
        }
    }

    protected function sendProfileEmailConfirmation( array $pending_email_change ) : void {
        $username = trim( (string) ( $pending_email_change['username'] ?? '' ) );
        $recipient_email = trim( (string) ( $pending_email_change['pending_email'] ?? '' ) );
        $token = trim( (string) ( $pending_email_change['pending_email_change_token'] ?? '' ) );
        if ( $username === '' || $recipient_email === '' || $token === '' ) {
            throw new \InvalidArgumentException( 'email' );
        }

        require_once dirname( __FILE__, 2 ) . '/classes/TinyMashMailer.php';
        $mailer = new \app\classes\TinyMashMailer();
        $base_url = rtrim( (string) ( $this->config->configGetBaseURL() ?: $this->app->get( 'app.url' ) ), '/' );
        $confirm_url = $base_url
            . $this->config->configGetAdminURL()
            . '/profile/email-confirm?username='
            . rawurlencode( $username )
            . '&token='
            . rawurlencode( $token );

        $mailer->sendProfileEmailConfirmation(
            $this->config->getSystemSettings(),
            $recipient_email,
            $confirm_url,
            $this->config->getSiteName()
        );
    }

    protected function getCurrentUserRecord() : ?array {
        $username = $this->security->getCurrentUsername();
        if ( ! is_string( $username ) || $username === '' ) {
            return( null );
        }

        $user_repository = $this->getUserRepository();
        if ( $user_repository === null ) {
            return( null );
        }

        return( $user_repository->getUserByUsername( $username ) );
    }

    protected function buildProfileViewData( array $overrides = [] ) : array {
        $profile_user = $this->getCurrentUserRecord();
        $profile_section = $this->normalizeProfileSection( (string) ( $overrides['profile_section'] ?? $this->getProfileSection() ) );
        $profile_form = $this->getProfileFormState();
        $profile_fediverse_settings_sections = $this->getProfilePluginSettingsSections( 'fediverse', $profile_form );
        if ( $profile_section === 'fediverse' && empty( $profile_fediverse_settings_sections ) ) {
            $profile_section = 'account';
        }
        $profile_plugin_settings_sections = $profile_section === 'fediverse'
            ? $profile_fediverse_settings_sections
            : $this->getProfilePluginSettingsSections( $profile_section, $profile_form );
        $view_data = [
            'title' => APP_NAME . APP_TITLE_SEP . 'Profile',
            'app_url' => $this->app->get( 'app.url' ),
            'admin_url' => $this->app->get( 'admin.url' ),
            'current_role' => $this->security->getCurrentRole(),
            'current_username' => $this->security->getCurrentUsername(),
            'is_superadmin' => $this->security->isSuperAdmin(),
            'current_section' => 'profile',
            'current_location' => 'profile/' . $profile_section,
            'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
            'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
            'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
            'entries_url' => $this->app->get( 'admin.url' ) . '/content',
            'editor_url' => $this->app->get( 'admin.url' ) . '/editor',
            'profile_section' => $profile_section,
            'profile_section_urls' => $this->getProfileSectionUrls(),
            'profile_user' => $profile_user,
            'profile_role_label' => $this->getRoleDisplayLabel( is_array( $profile_user ) ? (string) ( $profile_user['role'] ?? '' ) : '' ),
            'profile_form' => $profile_form,
            'profile_notice' => $this->getProfileNotice(),
            'profile_save_url' => $this->getProfileSectionUrl( $profile_section ) . '/save',
            'profile_autosave' => $this->getCurrentEditorAutosaveSettings(),
            'profile_classic_smileys' => $this->getCurrentClassicSmileysSettings(),
            'profile_publishing_defaults' => $this->getCurrentPublishingDefaults(),
            'profile_public_theme_options' => $this->getProfilePublicThemeOptions(),
            'profile_public_theme_system_label' => $this->getProfilePublicThemeSystemLabel(),
            'profile_has_fediverse_settings' => ! empty( $profile_fediverse_settings_sections ),
            'profile_theme_content_width_options' => $this->getProfileThemeContentWidthOptions(),
            'profile_theme_content_width_system_label' => $this->getProfileThemeContentWidthSystemLabel(),
            'profile_theme_landing_page_options' => $this->getProfileThemeLandingPageOptions(),
            'profile_theme_override_settings' => $this->getProfileThemeOverrideSettings( $profile_form ),
            'profile_public_banner_image' => $this->getCurrentProfilePublicBannerImage(),
            'profile_public_background_image' => $this->getCurrentProfilePublicBackgroundImage(),
            'profile_public_theme_name' => $this->theme !== null ? $this->theme->getThemeName() : 'Public theme',
            'profile_secret_links' => $this->getCurrentProfileSecretLinkViewData(),
            'profile_plugin_settings_sections' => $profile_plugin_settings_sections,
            'profile_timezone_options' => timezone_identifiers_list(),
            'profile_site_timezone' => $this->config->getLocaleTimezone(),
            'profile_can_change_email' => $this->security->isSuperAdmin() || $this->config->canUsersChangeEmail(),
            'profile_secret_link_generate_url' => $this->app->get( 'admin.url' ) . '/profile/publishing/secret-link/generate',
            'profile_secret_link_revoke_url' => $this->app->get( 'admin.url' ) . '/profile/publishing/secret-link/revoke',
            'profile_delete_all_content_url' => $this->app->get( 'admin.url' ) . '/profile/publishing/delete-all-content',
            'help_contexts' => [ $this->getProfileHelpContext( $profile_section ) ],
            'csrf_token' => $this->security->getCsrfToken(),
        ];

        return( array_merge( $view_data, $overrides ) );
    }

}
