<?php
namespace app\controllers;

use app\classes\TinyMashConfigIO;

trait AdminSystemConcern {

    public function adminFragment( string $fragment_key ) : void {
        $fragment_key = strtolower( trim( $fragment_key ) );
        if ( ! preg_match( '/^[a-z0-9-]{1,80}$/', $fragment_key ) ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Unknown fragment.' ], 404 );
            return;
        }

        if ( ! $this->security->isLoggedIn() ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Authentication is required.' ], 401 );
            return;
        }

        if ( ! $this->isValidAdminFragmentRequest() ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Invalid fragment request.' ], 400 );
            return;
        }

        if ( ! $this->isValidAdminFragmentCsrf() ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Invalid session token.' ], 403 );
            return;
        }

        if ( $fragment_key !== 'system-media-library' ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Unknown fragment.' ], 404 );
            return;
        }

        if ( ! $this->security->isSuperAdmin() ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Forbidden.' ], 403 );
            return;
        }

        $html = $this->app->view()->renderToString(
            'admin/baseline/partials/system/media-library.latte',
            $this->buildSystemMediaLibraryFragmentViewData()
        );

        $this->emitAdminFragmentJson(
            [
                'fragment' => $fragment_key,
                'html' => $html,
            ]
        );
    }

    public function systemHome( string $section = 'site' ) : void {
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'current_username' => $this->security->getCurrentUsername(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'current_location' => 'forbidden',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $this->renderAdmin( 'system', $this->buildSystemViewData( [ 'system_section' => $this->normalizeSystemSection( $section ) ] ) );
    }

    public function stageSystemUpdate() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'updates' ) );
            return;
        }

        $service = $this->app->has( 'update.stage_service' ) ? $this->app->get( 'update.stage_service' ) : null;
        if ( ! $service instanceof \app\classes\TinyMashUpdateStageService ) {
            $this->setSystemNoticeFlash( 'danger', 'Update package checks are unavailable.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'updates' ) );
            return;
        }

        try {
            $prerequisites = $service->getPrerequisites();
            $missing_prerequisite = $prerequisites['missing'][0] ?? null;
            if ( is_array( $missing_prerequisite ) ) {
                throw new \app\classes\TinyMashUpdateFailure( (string) $missing_prerequisite['failure_code'] );
            }
            $availability = $this->getSystemUpdateAvailability();
            $status = $service->stage( $availability );
            $message = ! empty( $status['preflight_ok'] )
                ? 'The release package passed all checks.'
                : 'The release package is valid, but the installation preflight failed.';
            $this->setSystemNoticeFlash( ! empty( $status['preflight_ok'] ) ? 'success' : 'warning', $message );
        } catch ( \Throwable $exception ) {
            \app\classes\TinyMashUpdateErrorPolicy::logFailure( 'package', $exception );
            $failure = \app\classes\TinyMashUpdateErrorPolicy::describeFailure( $exception );
            $this->setSystemNoticeFlash( 'danger', (string) $failure['message'] );
        }
        $this->app->redirect( $this->getSystemSectionUrl( 'updates' ) );
    }

    public function createSystemUpdateHandoff() : void {
        if ( ! $this->isValidAdminFragmentRequest() ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Request not accepted.' ], 403 );
            return;
        }
        if ( ! $this->security->isLoggedIn() || ! $this->security->isSuperAdmin() ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }
        if ( ! $this->isValidAdminFragmentCsrf() ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Your session token is invalid. Please reload the page and try again.' ], 403 );
            return;
        }

        $data = $this->getRequestDataArray();
        $username = (string) ( $this->security->getCurrentUsername() ?? '' );
        $password = (string) ( $data['current_password'] ?? '' );
        if ( $password === '' || ! $this->security->verifyCredentials( $username, $password ) ) {
            $this->emitAdminFragmentJson( [ 'error' => 'The current password is not correct.' ], 403 );
            return;
        }

        $preparation = $this->app->has( 'update.preparation_service' ) ? $this->app->get( 'update.preparation_service' ) : null;
        $access = $this->app->has( 'update.recovery_access_service' ) ? $this->app->get( 'update.recovery_access_service' ) : null;
        if ( ! $preparation instanceof \app\classes\TinyMashUpdatePreparationService
            || ! $access instanceof \app\classes\TinyMashUpdateRecoveryAccessService ) {
            $this->emitAdminFragmentJson( [ 'error' => 'Update preparation is unavailable.' ], 503 );
            return;
        }

        try {
            $journal = $preparation->prepare( $this->getSystemUpdateAvailability() );
            $credential = $access->issue( 3600 );
            $this->emitAdminFragmentJson( [
                'endpoint' => rtrim( (string) $this->app->get( 'app.url' ), '/' ) . '/_tinymash/update',
                'token' => (string) $credential['token'],
                'expires_at_utc' => (string) $credential['expires_at_utc'],
                'status' => [
                    'installed_version' => (string) ( $journal['installed_version'] ?? '' ),
                    'target_version' => (string) ( $journal['target_version'] ?? '' ),
                    'phase' => (string) ( $journal['phase'] ?? '' ),
                ],
            ] );
        } catch ( \Throwable $exception ) {
            \app\classes\TinyMashUpdateErrorPolicy::logFailure( 'handoff', $exception );
            $this->emitAdminFragmentJson( [ 'error' => 'The update could not be prepared.' ], 409 );
        }
    }

    public function dismissSystemNotification() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'notifications' ) );
            return;
        }

        $notification_id = trim( (string) ( $data['notification_id'] ?? '' ) );
        $service = $this->getNotificationService();
        if ( $notification_id === '' || $service === null || ! $service->dismissNotification( $notification_id, (string) ( $this->security->getCurrentUsername() ?? '' ) ) ) {
            $this->setSystemNoticeFlash( 'danger', 'That notification could not be dismissed.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'notifications' ) );
            return;
        }

        $this->setSystemNoticeFlash( 'success', 'Notification dismissed.' );
        $this->app->redirect( $this->getSystemSectionUrl( 'notifications' ) );
    }

    public function systemPluginSettings( string $plugin_key ) : void {
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'current_username' => $this->security->getCurrentUsername(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'current_location' => 'forbidden',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $plugin_key = $this->normalizePluginKey( $plugin_key );
        $plugin_settings_section = $this->getSystemPluginSettingsSectionByKey( $plugin_key );
        if ( empty( $plugin_settings_section ) ) {
            $this->setSystemNoticeFlash( 'danger', 'That plugin does not expose system settings.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'plugins' ) );
            return;
        }

        $this->renderAdmin(
            'system',
            $this->buildSystemViewData(
                [
                    'system_section' => 'plugins',
                    'current_location' => 'system/plugins/' . $plugin_key,
                    'system_plugin_settings_page' => true,
                    'system_plugin_settings_section' => $plugin_settings_section,
                ]
            )
        );
    }

    public function saveSystemSettings() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        $settings_group = $this->normalizeSystemSettingsGroup( (string) ( $data['settings_group'] ?? 'site' ) );
        $redirect_url = $this->getSystemSettingsRedirectUrl( $settings_group, $data );
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $redirect_url );
            return;
        }

        try {
            $settings = $this->validateSystemSettings( $data, $settings_group );
            $config_io = new TinyMashConfigIO();
            if ( ! $config_io->getConfig() || ! $config_io->updateSystemSettings( $settings, $settings_group ) ) {
                $this->setSystemNoticeFlash( 'danger', 'System settings could not be saved right now.' );
                $this->app->redirect( $redirect_url );
                return;
            }
        } catch ( \InvalidArgumentException $e ) {
            $this->setSystemNoticeFlash( 'danger', $this->getUserValidationMessage( $e->getMessage() ) );
            $this->app->redirect( $redirect_url );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to save system settings (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'System settings could not be saved right now.' );
            $this->app->redirect( $redirect_url );
            return;
        }

        $message = 'System settings were saved.';
        if ( $settings_group === 'content_media' ) {
            $message = 'Content settings were saved.';
        } elseif ( $settings_group === 'media' ) {
            $message = 'Media settings were saved.';
        } elseif ( $settings_group === 'menus' ) {
            $message = 'Menus were saved.';
        }
        if ( $settings_group === 'moderation' ) {
            $message = 'Moderation policy was saved.';
        } elseif ( $settings_group === 'security' ) {
            $message = 'Security settings were saved.';
        } elseif ( $settings_group === 'notifications' ) {
            $message = 'Notification settings were saved.';
        } elseif ( $settings_group === 'themes' ) {
            $message = 'Theme settings were saved.';
        } elseif ( $settings_group === 'plugins' ) {
            $message = 'Plugin settings were saved.';
        } elseif ( $settings_group === 'locale' ) {
            $message = 'Locale settings were saved.';
        } elseif ( $settings_group === 'smtp' ) {
            $message = 'SMTP settings were saved.';
        } elseif ( $settings_group === 'head_tags' ) {
            $message = 'Head tags were saved.';
        }
        $this->clearPublicPageCache();
        $this->setSystemNoticeFlash( 'success', $message );
        $this->app->redirect( $redirect_url );
    }

    public function toggleSystemPluginState() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'plugins' ) );
            return;
        }

        $plugin_key = strtolower( trim( (string) ( $data['plugin_key'] ?? '' ) ) );
        $registered_plugin = null;
        foreach ( $this->getSystemRegisteredPlugins() as $plugin ) {
            if ( is_array( $plugin ) && strtolower( trim( (string) ( $plugin['key'] ?? '' ) ) ) === $plugin_key ) {
                $registered_plugin = $plugin;
                break;
            }
        }

        if ( ! is_array( $registered_plugin ) ) {
            $this->setSystemNoticeFlash( 'danger', 'That plugin is not available.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'plugins' ) );
            return;
        }

        $active = ! empty( $data['active'] );
        $config_io = new TinyMashConfigIO();
        if ( ! $config_io->updatePluginState( $plugin_key, $active ) ) {
            $this->setSystemNoticeFlash( 'danger', 'The plugin state could not be changed right now.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'plugins' ) );
            return;
        }

        $this->setSystemNoticeFlash(
            'success',
            (string) ( $registered_plugin['name'] ?? ucfirst( $plugin_key ) ) . ( $active ? ' was activated.' : ' was deactivated.' )
        );
        $this->app->redirect( $this->getSystemSectionUrl( 'plugins' ) );
    }

    public function sendSystemTestEmail() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'smtp' ) );
            return;
        }

        $recipient_email = trim( (string) ( $data['test_email'] ?? '' ) );
        if ( $recipient_email === '' ) {
            $recipient_email = (string) $this->config->getAdminEmail();
        }

        try {
            $mailer = $this->getMailer();
            if ( $mailer === null ) {
                throw new \RuntimeException( 'Mailer service is not available.' );
            }
            $mailer->sendTestEmail( $this->config->getSystemSettings(), $recipient_email, $this->config->getSiteName() );
        } catch ( \InvalidArgumentException $e ) {
            $this->setSystemNoticeFlash( 'danger', $this->getUserValidationMessage( $e->getMessage() ) );
            $this->app->redirect( $this->getSystemSectionUrl( 'smtp' ) );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to send SMTP test e-mail (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'SMTP test e-mail could not be sent right now.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'smtp' ) );
            return;
        }

        $this->setSystemNoticeFlash( 'success', 'SMTP test e-mail sent to ' . $recipient_email . '.' );
        $this->app->redirect( $this->getSystemSectionUrl( 'smtp' ) );
    }

    public function generateSystemSecretLink() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'security' ) );
            return;
        }

        try {
            if ( $this->config->isSitePublic() ) {
                throw new \InvalidArgumentException( 'secret_links_public_site' );
            }
            if ( ! $this->config->allowsSecretLinks() ) {
                throw new \InvalidArgumentException( 'secret_links_disabled' );
            }

            $secret_link_service = $this->getSecretLinkService();
            if ( ! is_object( $secret_link_service ) ) {
                throw new \RuntimeException( 'Secret link service is unavailable.' );
            }
            $target = strtolower( trim( (string) ( $data['secret_link_target'] ?? 'site' ) ) );
            $expiry_days = $this->validateSecretLinkExpiryDays( $data['secret_link_expiry_days'] ?? $this->config->getSecretLinkDefaultExpiryDays() );
            $current_username = (string) ( $this->security->getCurrentUsername() ?? '' );
            if ( $target === 'site' ) {
                if ( ! method_exists( $secret_link_service, 'generateSiteSecretLink' ) ) {
                    throw new \RuntimeException( 'Site secret links are unavailable.' );
                }
                $secret_link_service->generateSiteSecretLink( $current_username, $expiry_days );
                $message = 'Site secret link generated.';
            } else {
                $user_repository = $this->getUserRepository();
                $user = $user_repository !== null ? $user_repository->getUserByUsername( $target ) : null;
                if ( ! is_array( $user ) ) {
                    throw new \InvalidArgumentException( 'secret_link_target' );
                }
                if ( empty( $user['content_active'] ) ) {
                    throw new \InvalidArgumentException( 'secret_links_author_locked' );
                }
                if ( ! method_exists( $secret_link_service, 'generateAuthorSecretLink' ) ) {
                    throw new \RuntimeException( 'Author secret links are unavailable.' );
                }
                $secret_link_service->generateAuthorSecretLink( (string) $user['author_slug'], $current_username, $expiry_days );
                $message = 'Secret link generated for ' . (string) $user['username'] . '.';
            }

            $this->setSystemNoticeFlash( 'success', $message );
        } catch ( \InvalidArgumentException $e ) {
            $message = match ( $e->getMessage() ) {
                'secret_links_public_site' => 'Secret links are only used when the site is not public.',
                'secret_links_disabled' => 'Secret links are currently disabled.',
                'secret_links_author_locked' => 'That author space is not available.',
                'secret_link_target' => 'Select a valid secret-link target.',
                default => $this->getUserValidationMessage( $e->getMessage() ),
            };
            $this->setSystemNoticeFlash( 'danger', $message );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to generate system secret link (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'Secret link could not be generated right now.' );
        }

        $this->app->redirect( $this->getSystemSectionUrl( 'security' ) );
    }

    public function revokeSystemSecretLink() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'security' ) );
            return;
        }

        try {
            $secret_link_service = $this->getSecretLinkService();
            if ( ! is_object( $secret_link_service ) ) {
                throw new \RuntimeException( 'Secret link service is unavailable.' );
            }
            $target = strtolower( trim( (string) ( $data['secret_link_target'] ?? '' ) ) );
            if ( $target === 'site' ) {
                $revoked = method_exists( $secret_link_service, 'revokeSiteSecretLink' ) && $secret_link_service->revokeSiteSecretLink();
                $message = 'Site secret link revoked.';
            } else {
                $revoked = $target !== ''
                    && method_exists( $secret_link_service, 'revokeAuthorSecretLink' )
                    && $secret_link_service->revokeAuthorSecretLink( $target );
                $message = 'Secret link revoked for ' . $target . '.';
            }
            if ( ! $revoked ) {
                throw new \RuntimeException( 'Secret link revocation failed.' );
            }
            $this->setSystemNoticeFlash( 'success', $message );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to revoke system secret link (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'Secret link could not be revoked right now.' );
        }

        $this->app->redirect( $this->getSystemSectionUrl( 'security' ) );
    }

    public function saveSystemUser() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'system',
                $this->buildSystemViewData(
                    [
                        'system_section' => 'users-edit',
                        'system_notice' => $this->buildSystemNotice( 'danger', 'Your session token is invalid. Please reload the page and try again.' ),
                        'system_user_form' => $this->normalizeSystemUserFormState( $data ),
                    ]
                )
            );
            return;
        }

        try {
            $user_repository = $this->getUserRepository();
            if ( $user_repository === null ) {
                throw new \RuntimeException( 'User repository is not available.' );
            }

            $new_password = (string) ( $data['password'] ?? '' );
            if ( $new_password !== '' && mb_strlen( $new_password ) < $this->config->getMinPasswordLength() ) {
                throw new \InvalidArgumentException( 'password_length' );
            }

            $user_repository->saveUser(
                [
                    'original_username' => (string) ( $data['original_username'] ?? '' ),
                    'username' => (string) ( $data['username'] ?? '' ),
                    'display_name' => (string) ( $data['display_name'] ?? '' ),
                    'email' => (string) ( $data['email'] ?? '' ),
                    'role' => (string) ( $data['role'] ?? 'creator' ),
                    'password' => (string) ( $data['password'] ?? '' ),
                    'confirm_password' => (string) ( $data['confirm_password'] ?? '' ),
                    'active' => ! empty( $data['active'] ),
                    'content_active' => ! empty( $data['content_active'] ),
                ]
            );
        } catch ( \InvalidArgumentException $e ) {
            $this->app->response()->status( 422 );
            $this->renderAdmin(
                'system',
                $this->buildSystemViewData(
                    [
                        'system_section' => 'users-edit',
                        'system_notice' => $this->buildSystemNotice( 'danger', $this->getUserValidationMessage( $e->getMessage() ) ),
                        'system_user_form' => $this->normalizeSystemUserFormState( $data ),
                    ]
                )
            );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to save system user (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'User changes could not be saved right now.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'users' ) );
            return;
        }

        $this->setSystemNoticeFlash( 'success', 'User settings were saved.' );
        $this->app->redirect( $this->getSystemSectionUrl( 'users' ) . '?user=' . rawurlencode( (string) ( $data['username'] ?? '' ) ) );
    }

    public function toggleSystemUserState() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'users' ) );
            return;
        }

        $username = (string) ( $data['username'] ?? '' );
        $field = (string) ( $data['field'] ?? '' );
        $value = ! empty( $data['value'] );

        try {
            $user_repository = $this->getUserRepository();
            if ( $user_repository === null ) {
                throw new \RuntimeException( 'User repository is not available.' );
            }

            if ( $field === 'active' ) {
                $user_repository->setAccountActive( $username, $value );
            } elseif ( $field === 'content_active' ) {
                $user_repository->setContentActive( $username, $value );
            } else {
                throw new \InvalidArgumentException( 'field' );
            }
        } catch ( \Throwable $e ) {
            $this->setSystemNoticeFlash( 'danger', 'User changes could not be saved right now.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'users' ) . '?user=' . rawurlencode( $username ) );
            return;
        }

        $this->setSystemNoticeFlash( 'success', 'User state was updated.' );
        $this->app->redirect( $this->getSystemSectionUrl( 'users' ) . '?user=' . rawurlencode( $username ) );
    }

    public function deleteSystemUser() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'users' ) );
            return;
        }
        $username = trim( (string) ( $data['username'] ?? '' ) );

        try {
            $user_repository = $this->getUserRepository();
            if ( $user_repository === null || $this->content_repository === null ) {
                throw new \RuntimeException( 'Required repositories are not available.' );
            }
            if ( $username === '' ) {
                throw new \InvalidArgumentException( 'username' );
            }
            if ( $username === (string) $this->security->getCurrentUsername() ) {
                throw new \InvalidArgumentException( 'delete_current_user' );
            }

            $user = $user_repository->getUserByUsername( $username );
            if ( ! is_array( $user ) ) {
                throw new \InvalidArgumentException( 'username' );
            }
            if ( $user['role'] === 'superadmin' && $user_repository->countUsersByRole( 'superadmin' ) <= 1 ) {
                throw new \InvalidArgumentException( 'delete_last_superadmin' );
            }

            $secret_link_service = $this->getSecretLinkService();
            if (
                ! is_object( $secret_link_service )
                || ! method_exists( $secret_link_service, 'revokeAuthorSecretLink' )
                || ! $secret_link_service->revokeAuthorSecretLink( (string) $user['author_slug'] )
            ) {
                throw new \RuntimeException( 'The author secret link could not be revoked.' );
            }

            $move_result = $this->content_repository->moveAuthorEntriesToDeletedOwner( (string) $user['author_slug'], $username );
            $deleted_user = $user_repository->deleteUser( $username );
            if ( ! is_array( $deleted_user ) ) {
                throw new \RuntimeException( 'User could not be deleted.' );
            }

            $message = 'Deleted user ' . $username . ' and moved ' . (int) $move_result['moved_entries'] . ' content items into orphan storage.';
            if ( ! empty( $move_result['deleted_author_slug'] ) ) {
                $message .= ' Orphan owner: ' . $move_result['deleted_author_slug'] . '.';
            }
            $this->setSystemNoticeFlash( 'success', $message );
            $this->app->redirect( $this->getSystemSectionUrl( 'orphans' ) );
            return;
        } catch ( \InvalidArgumentException $e ) {
            $this->setSystemNoticeFlash( 'danger', $this->getUserValidationMessage( $e->getMessage() ) );
            $this->app->redirect( $this->getSystemSectionUrl( 'users' ) . '?user=' . rawurlencode( $username ) );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to delete system user (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'User changes could not be saved right now.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'users' ) . '?user=' . rawurlencode( $username ) );
            return;
        }
    }

    public function reassignSystemOrphanContent() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'orphans' ) );
            return;
        }
        $orphan_slug = trim( (string) ( $data['orphan_slug'] ?? '' ) );
        $target_username = trim( (string) ( $data['target_username'] ?? '' ) );

        try {
            if ( $this->content_repository === null ) {
                throw new \RuntimeException( 'Content repository is not available.' );
            }
            $user_repository = $this->getUserRepository();
            if ( $user_repository === null ) {
                throw new \RuntimeException( 'User repository is not available.' );
            }
            $target_user = $user_repository->getUserByUsername( $target_username );
            if ( ! is_array( $target_user ) ) {
                throw new \InvalidArgumentException( 'target_username' );
            }

            $result = $this->content_repository->reassignOrphanedEntries( $orphan_slug, (string) $target_user['author_slug'] );
            $this->setSystemNoticeFlash( 'success', 'Reassigned ' . (int) $result['reassigned_entries'] . ' orphaned content items.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'orphans' ) );
            return;
        } catch ( \InvalidArgumentException $e ) {
            $this->setSystemNoticeFlash( 'danger', $this->getUserValidationMessage( $e->getMessage() ) );
            $this->app->redirect( $this->getSystemSectionUrl( 'orphans' ) );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to reassign orphan content (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'Orphaned content could not be updated right now.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'orphans' ) );
            return;
        }
    }

    public function deleteSystemOrphanContent() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'orphans' ) );
            return;
        }
        $orphan_slug = trim( (string) ( $data['orphan_slug'] ?? '' ) );

        try {
            if ( $this->content_repository === null ) {
                throw new \RuntimeException( 'Content repository is not available.' );
            }
            $result = $this->content_repository->deleteEntriesByAuthorSlug( $orphan_slug );
            $this->setSystemNoticeFlash( 'success', 'Deleted ' . (int) $result['deleted_entries'] . ' orphaned content items.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'orphans' ) );
            return;
        } catch ( \InvalidArgumentException $e ) {
            $this->setSystemNoticeFlash( 'danger', $this->getUserValidationMessage( $e->getMessage() ) );
            $this->app->redirect( $this->getSystemSectionUrl( 'orphans' ) );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to delete orphan content (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'Orphaned content could not be updated right now.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'orphans' ) );
            return;
        }
    }

    public function approveSystemModerationEntry() : void {
        $this->handleSystemModerationDecision( 'published', 'Content approved and published.' );
    }

    public function rejectSystemModerationEntry() : void {
        $this->handleSystemModerationDecision( 'unpublished', 'Content review was rejected and the item is now unpublished.' );
    }

    public function updateSystemMediaLibraryReview() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            return;
        }

        $data = $this->getRequestDataArray();
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
            return;
        }

        $media_id = trim( (string) ( $data['media_id'] ?? '' ) );
        $review_status = strtolower( trim( (string) ( $data['review_status'] ?? '' ) ) );
        $review_store = $this->app->has( 'media.review_store' ) ? $this->app->get( 'media.review_store' ) : null;
        $media_service = $this->app->has( 'media.service' ) ? $this->app->get( 'media.service' ) : null;
        if (
            ! is_object( $review_store )
            || ! method_exists( $review_store, 'setReviewStatus' )
            || ! method_exists( $review_store, 'clearReview' )
            || ! is_object( $media_service )
            || ! method_exists( $media_service, 'getAttachmentMetadataByMediaId' )
        ) {
            $this->setSystemNoticeFlash( 'danger', 'Media review state is not available.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
            return;
        }

        $metadata = $media_service->getAttachmentMetadataByMediaId( $media_id, [] );
        if ( ! is_array( $metadata ) ) {
            $this->setSystemNoticeFlash( 'danger', 'That media item could not be found.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
            return;
        }

        try {
            if ( $review_status === 'unreviewed' ) {
                $review_store->clearReview( $media_id );
                $this->setSystemNoticeFlash( 'success', 'Media review status cleared.' );
            } elseif ( in_array( $review_status, [ 'keep', 'cleanup_candidate' ], true ) ) {
                $review = $review_store->setReviewStatus( $media_id, $review_status, (string) ( $this->security->getCurrentUsername() ?? '' ) );
                $label = is_array( $review ) ? (string) ( $review['status_label'] ?? 'Review status' ) : 'Review status';
                $this->setSystemNoticeFlash( 'success', $label . ' saved.' );
            } else {
                throw new \InvalidArgumentException( 'review_status' );
            }
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to update media review status (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'Media review status could not be saved.' );
        }

        $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
    }

    public function deleteSystemMediaLibraryItem() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            return;
        }

        $data = $this->getRequestDataArray();
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
            return;
        }
        if ( (string) ( $data['confirm_permanent_delete'] ?? '' ) !== 'permanent' ) {
            $this->setSystemNoticeFlash( 'danger', 'Permanent media deletion was not confirmed.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
            return;
        }

        $media_id = trim( (string) ( $data['media_id'] ?? '' ) );
        $review_store = $this->app->has( 'media.review_store' ) ? $this->app->get( 'media.review_store' ) : null;
        $media_service = $this->app->has( 'media.service' ) ? $this->app->get( 'media.service' ) : null;
        if (
            ! is_object( $review_store )
            || ! method_exists( $review_store, 'getReview' )
            || ! method_exists( $review_store, 'clearReview' )
            || ! is_object( $media_service )
            || ! method_exists( $media_service, 'getAttachmentMetadataByMediaId' )
            || ! method_exists( $media_service, 'deleteAttachmentByMediaId' )
        ) {
            $this->setSystemNoticeFlash( 'danger', 'Media deletion is not available.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
            return;
        }

        $metadata = $media_service->getAttachmentMetadataByMediaId( $media_id, [] );
        if ( ! is_array( $metadata ) ) {
            $this->setSystemNoticeFlash( 'danger', 'That media item could not be found.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
            return;
        }

        $review = $review_store->getReview( $media_id );
        if ( (string) ( $review['status'] ?? 'unreviewed' ) !== 'cleanup_candidate' ) {
            $this->setSystemNoticeFlash( 'danger', 'Only media marked as a cleanup candidate can be deleted permanently.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
            return;
        }

        $usage_report = $this->buildSystemMediaLibraryUsageReport( [ $metadata ], 'all' );
        $usage_records_by_id = is_array( $usage_report['records_by_id'] ?? null ) ? $usage_report['records_by_id'] : [];
        $usage_status = (string) ( $usage_records_by_id[$media_id]['status'] ?? 'unknown' );
        if ( $usage_status !== 'unreferenced' ) {
            $usage_label = $this->getSystemMediaLibraryUsageStatusLabel( $usage_status );
            $this->setSystemNoticeFlash( 'danger', 'Media was not deleted because its current usage state is ' . $usage_label . '.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
            return;
        }

        try {
            $result = $media_service->deleteAttachmentByMediaId( $media_id, [] );
            if ( empty( $result['deleted'] ) ) {
                throw new \RuntimeException( 'Media item was not found during deletion.' );
            }
            $review_store->clearReview( $media_id );
            error_log(
                'Media permanently deleted: media_id=' . $media_id
                . ' actor=' . (string) ( $this->security->getCurrentUsername() ?? '' )
                . ' removed_files=' . (int) ( $result['removed_files'] ?? 0 )
            );
            $this->setSystemNoticeFlash( 'success', 'Media deleted permanently.' );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to delete media item (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'Media could not be deleted permanently.' );
        }

        $this->app->redirect( $this->getSystemSectionUrl( 'media-library' ) );
    }

    protected function setSystemNoticeFlash( string $type, string $message ) : void {
        $this->security->setFlash(
            'system.notice',
            [
                'type' => $type,
                'message' => $message,
            ]
        );
    }

    protected function buildSystemViewData( array $overrides = [] ) : array {
        $content_stats = $this->content_repository !== null ? $this->content_repository->getContentStats() : [];
        $system_section = $this->normalizeSystemSection( (string) ( $overrides['system_section'] ?? $this->getSystemSection() ) );
        $system_notifications_view = $this->getSystemNotificationsViewData( $system_section === 'notifications' );
        $system_notifications_unread_count = (int) ( $system_notifications_view['summary']['unread'] ?? 0 );
        $system_users = $this->getSystemUsers();
        $system_update_prerequisites = $system_section === 'updates' ? $this->getSystemUpdatePrerequisites() : [];
        $system_update_availability = $system_section === 'updates' && ! empty( $system_update_prerequisites['ok'] )
            ? $this->getSystemUpdateAvailability()
            : ( $system_section === 'updates'
                ? [ 'status' => 'unavailable', 'installed_version' => defined( 'APP_VERSION' ) ? APP_VERSION : '' ]
                : [] );
        $system_update_stage = $system_section === 'updates' ? $this->getSystemUpdateStageStatus( $system_update_availability ) : [];
        $system_head_tag_rows = [ 'meta' => [], 'link' => [] ];
        foreach ( $this->config->getPublicHeadTags() as $head_tag ) {
            $type = (string) ( $head_tag['type'] ?? '' );
            if ( isset( $system_head_tag_rows[$type] ) ) {
                $system_head_tag_rows[$type][] = $head_tag;
            }
        }
        foreach ( [ 'meta', 'link' ] as $type ) {
            while ( count( $system_head_tag_rows[$type] ) < 6 ) {
                $system_head_tag_rows[$type][] = [];
            }
        }
        $admin_theme_info = $this->getSystemAdminThemeInfo();
        $system_plugin_settings_section = is_array( $overrides['system_plugin_settings_section'] ?? null ) ? $overrides['system_plugin_settings_section'] : [];
        $system_plugin_settings_page = ! empty( $overrides['system_plugin_settings_page'] );
        $system_selected_plugin = [];
        if ( ! empty( $system_plugin_settings_section['plugin_key'] ) ) {
            $system_selected_plugin = $this->getSystemRegisteredPluginByKey( (string) $system_plugin_settings_section['plugin_key'] );
        }
        $view_data = [
            'title' => $system_plugin_settings_page && ! empty( $system_selected_plugin['name'] )
                ? APP_NAME . APP_TITLE_SEP . (string) $system_selected_plugin['name']
                : APP_NAME . APP_TITLE_SEP . 'System',
            'app_url' => $this->app->get( 'app.url' ),
            'admin_url' => $this->app->get( 'admin.url' ),
            'current_role' => $this->security->getCurrentRole(),
            'current_username' => $this->security->getCurrentUsername(),
            'is_superadmin' => true,
            'content_moderation_required' => $this->config->isContentModerationRequired(),
            'content_stats' => $content_stats,
            'current_section' => 'system',
            'current_location' => 'system/' . $system_section,
            'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
            'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
            'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
            'entries_url' => $this->app->get( 'admin.url' ) . '/content',
            'system_settings' => $this->config->getSystemSettings(),
            'system_proxy_diagnostic' => [
                'peer' => is_string( $_SERVER['REMOTE_ADDR'] ?? null ) ? $_SERVER['REMOTE_ADDR'] : '',
                'client' => $this->security->getCurrentIpAddress(),
                'trusted' => \app\classes\TinyMashClientIpResolver::isTrusted(
                    is_string( $_SERVER['REMOTE_ADDR'] ?? null ) ? $_SERVER['REMOTE_ADDR'] : '',
                    $this->config->getTrustedProxyCidrs()
                ),
            ],
            'system_head_tag_rows' => $system_head_tag_rows,
            'system_timezone_options' => timezone_identifiers_list(),
            'system_content_image_mime_options' => $this->getContentImageMimeOptions(),
            'system_media_metadata_group_options' => $this->getMediaMetadataGroupOptions(),
                'system_media_library' => $system_section === 'media-library' ? $this->getSystemMediaLibraryViewData() : [],
                'system_site_image_picker_records' => $system_section === 'site' ? $this->getSystemSiteImagePickerRecords() : [],
            'system_runtime_info' => $this->getSystemRuntimeInfo(),
            'system_settings_url' => $this->app->get( 'admin.url' ) . '/system/settings',
            'system_theme_select_url' => $this->app->get( 'admin.url' ) . '/system/themes/select',
            'system_theme_settings_url' => $this->app->get( 'admin.url' ) . '/system/settings',
            'system_plugin_toggle_url' => $this->app->get( 'admin.url' ) . '/system/plugins/toggle',
            'system_plugins_index_url' => $this->getSystemSectionUrl( 'plugins' ),
            'system_plugin_settings_urls' => $this->getSystemPluginSettingsUrls(),
            'system_smtp_test_url' => $this->app->get( 'admin.url' ) . '/system/smtp/test',
            'system_media_library_fragment_url' => $this->app->get( 'admin.url' ) . '/fragment/system-media-library',
            'system_media_library_review_url' => $this->app->get( 'admin.url' ) . '/system/media-library/review',
            'system_media_library_delete_url' => $this->app->get( 'admin.url' ) . '/system/media-library/delete',
            'system_notice' => $this->getSystemNotice( $system_section ),
            'system_theme_key' => $this->theme !== null ? $this->theme->getThemeKey() : 'baseline',
            'system_theme_name' => $this->theme !== null ? $this->theme->getThemeName() : 'Baseline',
            'system_theme_description' => $this->theme !== null && method_exists( $this->theme, 'getThemeDescription' ) ? $this->theme->getThemeDescription() : '',
            'system_theme_version' => $this->theme !== null && method_exists( $this->theme, 'getThemeVersion' ) ? $this->theme->getThemeVersion() : '',
            'system_theme_supports' => $this->theme !== null && method_exists( $this->theme, 'getSupportDetails' ) ? $this->theme->getSupportDetails() : [],
            'system_theme_settings_schema' => $this->theme !== null ? $this->theme->getThemeSettingsSchema() : [],
            'system_theme_settings' => $this->theme !== null ? $this->theme->getThemeSettings() : [],
            'system_theme_custom_css' => $this->theme !== null && method_exists( $this->theme, 'getCustomCssSettings' ) ? $this->theme->getCustomCssSettings() : [ 'enabled' => false, 'css' => '', 'hash' => '' ],
            'system_theme_setting_lookups' => $this->getSystemThemeSettingLookups(),
            'system_menu_location_definitions' => $this->getSystemMenuLocationDefinitions(),
            'system_menu_locations' => $this->getSystemMenuLocationsViewData(),
            'system_menu_page_lookup_options' => $this->buildPublishedPageLookupOptions( 'root' ),
            'system_available_public_themes' => $this->theme !== null && method_exists( $this->theme, 'getAvailableThemes' ) ? $this->theme->getAvailableThemes() : [],
            'system_admin_theme_key' => (string) ( $admin_theme_info['key'] ?? 'baseline' ),
            'system_admin_theme_name' => (string) ( $admin_theme_info['name'] ?? 'Baseline Admin' ),
            'system_admin_theme_description' => (string) ( $admin_theme_info['description'] ?? '' ),
            'system_admin_theme_version' => (string) ( $admin_theme_info['version'] ?? '' ),
            'system_registered_plugins' => $this->getSystemRegisteredPlugins(),
            'system_plugin_diagnostics' => $this->getSystemPluginDiagnostics(),
            'system_registered_shortcodes' => $this->getSystemRegisteredShortcodes(),
            'system_registered_plugin_capabilities' => $this->getSystemRegisteredPluginCapabilities(),
            'system_plugin_settings_sections' => $this->getSystemPluginSettingsSections(),
            'system_plugin_settings_section' => $system_plugin_settings_section,
            'system_plugin_settings_page' => $system_plugin_settings_page,
            'system_selected_plugin' => $system_selected_plugin,
            'system_users' => $system_users,
            'system_secret_links' => $system_section === 'security' ? $this->getSystemSecretLinksViewData( $system_users ) : [],
            'system_secret_link_generate_url' => $this->app->get( 'admin.url' ) . '/system/secret-links/generate',
            'system_secret_link_revoke_url' => $this->app->get( 'admin.url' ) . '/system/secret-links/revoke',
            'system_user_form' => $this->getSystemUserFormState(),
            'system_user_save_url' => $this->app->get( 'admin.url' ) . '/system/users/save',
            'system_user_toggle_url' => $this->app->get( 'admin.url' ) . '/system/users/toggle',
            'system_user_delete_url' => $this->app->get( 'admin.url' ) . '/system/users/delete',
            'system_orphan_groups' => $this->getSystemOrphanGroups(),
            'system_orphan_reassign_url' => $this->app->get( 'admin.url' ) . '/system/orphans/reassign',
            'system_orphan_delete_url' => $this->app->get( 'admin.url' ) . '/system/orphans/delete',
            'system_moderation_entries' => $this->getSystemModerationEntries(),
            'system_moderation_approve_url' => $this->app->get( 'admin.url' ) . '/system/moderation/approve',
            'system_moderation_reject_url' => $this->app->get( 'admin.url' ) . '/system/moderation/reject',
            'system_reassign_users' => $this->getSystemReassignUsers( $system_users ),
            'system_components_report' => $this->getSystemComponentsReport(),
            'system_update_prerequisites' => $system_update_prerequisites,
            'system_update_availability' => $system_update_availability,
            'system_update_stage' => $system_update_stage,
            'system_update_stage_url' => $this->app->get( 'admin.url' ) . '/system/updates/stage',
            'system_update_handoff_url' => $this->app->get( 'admin.url' ) . '/system/updates/handoff',
            'system_update_health_url' => rtrim( (string) $this->app->get( 'app.url' ), '/' ) . '/_tinymash/health',
            'system_update_return_url' => $this->getSystemSectionUrl( 'updates' ),
            'system_static_export' => $this->getSystemStaticExportViewData(),
            'system_link_integrity' => $system_section === 'link-integrity' ? $this->getSystemLinkIntegrityReport() : [],
            'system_notifications_view' => $system_notifications_view,
            'system_notifications_unread_count' => $system_notifications_unread_count,
            'system_notification_dismiss_url' => $this->app->get( 'admin.url' ) . '/system/notifications/dismiss',
            'system_section' => $system_section,
            'system_section_urls' => $this->getSystemSectionUrls(),
            'csrf_token' => $this->security->getCsrfToken(),
            'help_contexts' => $system_section === 'media-library' ? [ 'admin-media-library' ] : [ 'admin-system' ],
        ];

        return( array_merge( $view_data, $overrides ) );
    }

    protected function buildSystemNotice( string $type, string $message ) : array {
        return(
            [
                'type' => $type,
                'message' => $message,
            ]
        );
    }

    protected function getSystemRegisteredShortcodes() : array {
        if ( ! $this->app->has( 'shortcode.registry' ) ) {
            return( [] );
        }

        $shortcode_registry = $this->app->get( 'shortcode.registry' );
        if ( ! is_object( $shortcode_registry ) || ! method_exists( $shortcode_registry, 'getRegisteredShortcodes' ) ) {
            return( [] );
        }

        $shortcodes = $shortcode_registry->getRegisteredShortcodes();
        return( is_array( $shortcodes ) ? $shortcodes : [] );
    }

    protected function buildSystemMediaLibraryFragmentViewData() : array {
        return(
            [
                'system_media_library' => $this->getSystemMediaLibraryViewData(),
                'system_section_urls' => $this->getSystemSectionUrls(),
                'system_media_library_fragment_url' => $this->app->get( 'admin.url' ) . '/fragment/system-media-library',
                'system_media_library_review_url' => $this->app->get( 'admin.url' ) . '/system/media-library/review',
                'system_media_library_delete_url' => $this->app->get( 'admin.url' ) . '/system/media-library/delete',
                'csrf_token' => $this->security->getCsrfToken(),
            ]
        );
    }

    protected function isValidAdminFragmentRequest() : bool {
        $requested_with = strtolower( trim( (string) ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) ) );
        $accept = strtolower( trim( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) ) );
        if ( $requested_with !== 'xmlhttprequest' || ! str_contains( $accept, 'application/json' ) ) {
            return( false );
        }

        $fetch_site = strtolower( trim( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) ) );
        return( $fetch_site === '' || in_array( $fetch_site, [ 'same-origin', 'same-site', 'none' ], true ) );
    }

    protected function isValidAdminFragmentCsrf() : bool {
        $csrf_token = trim( (string) ( $_SERVER['HTTP_X_TINYMASH_CSRF'] ?? '' ) );
        return( $this->security->validateCsrfToken( $csrf_token ) );
    }

    protected function emitAdminFragmentJson( array $payload, int $status_code = 200 ) : void {
        $url_resolver = $this->app->has( 'url.resolver' ) ? $this->app->get( 'url.resolver' ) : null;
        if ( $url_resolver instanceof \app\classes\TinyMashUrlResolver ) {
            $payload = $url_resolver->resolveResponseData( $payload );
        }
        $this->app->response()->status( $status_code );
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: no-store, max-age=0' );
            header( 'X-Content-Type-Options: nosniff' );
        }
        echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT );
    }

    protected function getSystemSection() : string {
        $data = $this->app->request()->query->getData();
        return( $this->normalizeSystemSection( is_array( $data ) ? (string) ( $data['section'] ?? 'site' ) : 'site' ) );
    }

    protected function normalizeSystemSection( string $section ) : string {
        $section = strtolower( trim( $section ) );
        return( in_array( $section, [ 'site', 'security', 'content-media', 'link-integrity', 'head-tags', 'media', 'media-library', 'locale', 'menus', 'notifications', 'updates', 'components', 'information', 'themes', 'themes-settings', 'plugins', 'moderation', 'users', 'users-edit', 'smtp', 'orphans', 'static-export' ], true ) ? $section : 'site' );
    }

    protected function getSystemSectionUrls() : array {
        return(
            [
                'site' => $this->getSystemSectionUrl( 'site' ),
                'security' => $this->getSystemSectionUrl( 'security' ),
                'content_media' => $this->getSystemSectionUrl( 'content-media' ),
                'link_integrity' => $this->getSystemSectionUrl( 'link-integrity' ),
                'head_tags' => $this->getSystemSectionUrl( 'head-tags' ),
                'media' => $this->getSystemSectionUrl( 'media' ),
                'media_library' => $this->getSystemSectionUrl( 'media-library' ),
                'locale' => $this->getSystemSectionUrl( 'locale' ),
                'menus' => $this->getSystemSectionUrl( 'menus' ),
                'notifications' => $this->getSystemSectionUrl( 'notifications' ),
                'updates' => $this->getSystemSectionUrl( 'updates' ),
                'components' => $this->getSystemSectionUrl( 'components' ),
                'information' => $this->getSystemSectionUrl( 'information' ),
                'themes' => $this->getSystemSectionUrl( 'themes' ),
                'themes_settings' => $this->getSystemSectionUrl( 'themes-settings' ),
                'plugins' => $this->getSystemSectionUrl( 'plugins' ),
                'moderation' => $this->getSystemSectionUrl( 'moderation' ),
                'users' => $this->getSystemSectionUrl( 'users' ),
                'users_edit' => $this->getSystemSectionUrl( 'users-edit' ),
                'smtp' => $this->getSystemSectionUrl( 'smtp' ),
                'orphans' => $this->getSystemSectionUrl( 'orphans' ),
                'components' => $this->getSystemSectionUrl( 'components' ),
                'static_export' => $this->getSystemSectionUrl( 'static-export' ),
            ]
        );
    }

    protected function getSystemLinkIntegrityReport() : array {
        if ( ! $this->app->has( 'link_integrity.service' ) ) {
            return( [ 'error_message' => 'Link integrity reporting is not available.' ] );
        }

        $service = $this->app->get( 'link_integrity.service' );
        if ( ! $service instanceof \app\classes\TinyMashLinkIntegrityService ) {
            return( [ 'error_message' => 'Link integrity reporting is not available.' ] );
        }

        $redirect_resolver = null;
        if ( $this->app->has( 'redirects.service' ) ) {
            $redirects_service = $this->app->get( 'redirects.service' );
            if ( is_object( $redirects_service ) && method_exists( $redirects_service, 'resolveRequestPath' ) ) {
                $redirect_resolver = static fn( string $path ) : mixed => $redirects_service->resolveRequestPath( $path );
            }
        }

        try {
            return( $service->scan( $redirect_resolver ) );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to build link integrity report (' . $e->getMessage() . ')' );
            return( [ 'error_message' => 'The link integrity report could not be generated.' ] );
        }
    }

    protected function getSystemMediaLibraryViewData() : array {
        $query = $this->app->request()->query->getData();
        $query = is_array( $query ) ? $query : [];
        $reset_filters = strtolower( trim( (string) ( $query['media_library_filters'] ?? '' ) ) ) === 'reset';
        if ( $reset_filters ) {
            $this->clearSystemMediaLibraryStickyFilters();
        }

        $sticky_filters = $reset_filters ? [] : $this->getSystemMediaLibraryStickyFilters();
        $has_filter_input = false;
        foreach ( [ 'owner', 'type', 'attachment', 'usage', 'review', 'q', 'per_page' ] as $filter_key ) {
            if ( array_key_exists( $filter_key, $query ) ) {
                $has_filter_input = true;
                break;
            }
        }
        $filter_source = $has_filter_input ? $query : $sticky_filters;
        $owner_filter = strtolower( trim( (string) ( $filter_source['owner'] ?? 'all' ) ) );
        $type_filter = strtolower( trim( (string) ( $filter_source['type'] ?? 'all' ) ) );
        $attachment_filter = strtolower( trim( (string) ( $filter_source['attachment'] ?? 'all' ) ) );
        $usage_filter = strtolower( trim( (string) ( $filter_source['usage'] ?? 'all' ) ) );
        $review_filter = strtolower( trim( (string) ( $filter_source['review'] ?? 'all' ) ) );
        $search_query = $this->normalizeSystemMediaLibrarySearchQuery( (string) ( $filter_source['q'] ?? '' ) );
        $page = max( 1, (int) ( $query['page'] ?? 1 ) );
        $allowed_per_page = [ 12, 24, 48, 96 ];
        $per_page = (int) ( $filter_source['per_page'] ?? 24 );
        if ( ! in_array( $per_page, $allowed_per_page, true ) ) {
            $per_page = 24;
        }
        if ( ! in_array( $type_filter, [ 'all', 'images', 'other' ], true ) ) {
            $type_filter = 'all';
        }
        if ( ! in_array( $attachment_filter, [ 'all', 'attached', 'unattached' ], true ) ) {
            $attachment_filter = 'all';
        }
        if ( ! in_array( $usage_filter, [ 'all', 'used', 'unreferenced', 'direct_marker_only' ], true ) ) {
            $usage_filter = 'all';
        }
        if ( ! in_array( $review_filter, [ 'all', 'unreviewed', 'keep', 'cleanup_candidate' ], true ) ) {
            $review_filter = 'all';
        }

        $media_service = $this->app->has( 'media.service' ) ? $this->app->get( 'media.service' ) : null;
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'listAttachments' ) ) {
            return(
                [
                    'records' => [],
                    'owners' => [],
                    'filters' => [ 'owner' => 'all', 'type' => $type_filter, 'attachment' => $attachment_filter, 'usage' => $usage_filter, 'review' => $review_filter, 'q' => $search_query, 'per_page' => $per_page ],
                    'reset_url' => $this->getSystemSectionUrl( 'media-library', [ 'media_library_filters' => 'reset' ] ),
                    'pagination' => $this->buildSystemMediaLibraryPagination( 0, 1, $per_page, 'all', $type_filter, $attachment_filter, $usage_filter, $review_filter, $search_query ),
                ]
            );
        }

        $all_metadata = [];
        foreach ( $media_service->listAttachments( [], PHP_INT_MAX ) as $metadata ) {
            if ( ! is_array( $metadata ) ) {
                continue;
            }
            if ( trim( (string) ( $metadata['media_id'] ?? '' ) ) === '' ) {
                continue;
            }
            $all_metadata[] = $metadata;
        }

        $owners = [];
        foreach ( $all_metadata as $metadata ) {
            $owner = strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) );
            if ( $owner !== '' ) {
                $owners[$owner] = $owner;
            }
        }
        ksort( $owners, SORT_NATURAL | SORT_FLAG_CASE );

        if ( $owner_filter !== 'all' && ! isset( $owners[$owner_filter] ) ) {
            $owner_filter = 'all';
        }
        if ( ! $reset_filters ) {
            $this->setSystemMediaLibraryStickyFilters(
                [
                    'owner' => $owner_filter,
                    'type' => $type_filter,
                    'attachment' => $attachment_filter,
                    'usage' => $usage_filter,
                    'review' => $review_filter,
                    'q' => $search_query,
                    'per_page' => $per_page,
                ]
            );
        }

        $usage_metadata = $owner_filter === 'all'
            ? $all_metadata
            : array_values(
                array_filter(
                    $all_metadata,
                    static function( array $metadata ) use ( $owner_filter ) : bool {
                        return( strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) ) === $owner_filter );
                    }
                )
            );
        $usage_report = $this->buildSystemMediaLibraryUsageReport( $usage_metadata, $owner_filter );
        $usage_records_by_id = is_array( $usage_report['records_by_id'] ?? null ) ? $usage_report['records_by_id'] : [];
        $review_store = $this->app->has( 'media.review_store' ) ? $this->app->get( 'media.review_store' ) : null;
        $review_records_by_id = is_object( $review_store ) && method_exists( $review_store, 'getReviewsById' )
            ? $review_store->getReviewsById()
            : [];

        $filtered_metadata = array_values(
            array_filter(
                $all_metadata,
                function( array $metadata ) use ( $owner_filter, $type_filter, $attachment_filter, $usage_filter, $review_filter, $usage_records_by_id, $review_records_by_id, $search_query ) : bool {
                    $owner = strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) );
                    if ( $owner_filter !== 'all' && $owner !== $owner_filter ) {
                        return( false );
                    }
                    $is_image = str_starts_with( strtolower( trim( (string) ( $metadata['mime'] ?? '' ) ) ), 'image/' );
                    if ( $type_filter === 'images' && ! $is_image ) {
                        return( false );
                    }
                    if ( $type_filter === 'other' && $is_image ) {
                        return( false );
                    }
                    $has_direct_attachment = $this->systemMediaLibraryHasDirectAttachment( $metadata );
                    if ( $attachment_filter === 'attached' && ! $has_direct_attachment ) {
                        return( false );
                    }
                    if ( $attachment_filter === 'unattached' && $has_direct_attachment ) {
                        return( false );
                    }
                    if ( $usage_filter !== 'all' ) {
                        $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
                        $usage_status = is_array( $usage_records_by_id[$media_id] ?? null ) ? (string) ( $usage_records_by_id[$media_id]['status'] ?? '' ) : '';
                        if ( $usage_status !== $usage_filter ) {
                            return( false );
                        }
                    }
                    if ( $review_filter !== 'all' ) {
                        $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
                        $review_status = is_array( $review_records_by_id[$media_id] ?? null ) ? (string) ( $review_records_by_id[$media_id]['status'] ?? 'unreviewed' ) : 'unreviewed';
                        if ( $review_status !== $review_filter ) {
                            return( false );
                        }
                    }
                    if ( $search_query !== '' && ! $this->systemMediaLibraryMetadataMatchesQuery( $metadata, $search_query ) ) {
                        return( false );
                    }

                    return( true );
                }
            )
        );
        $pagination = $this->buildSystemMediaLibraryPagination( count( $filtered_metadata ), $page, $per_page, $owner_filter, $type_filter, $attachment_filter, $usage_filter, $review_filter, $search_query );
        $paged_metadata = array_slice( $filtered_metadata, (int) $pagination['offset'], $per_page );
        $records = [];
        foreach ( $paged_metadata as $metadata ) {
            $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
            $usage_record = is_array( $usage_records_by_id[$media_id] ?? null ) ? $usage_records_by_id[$media_id] : [];
            $review_record = is_array( $review_records_by_id[$media_id] ?? null ) ? $review_records_by_id[$media_id] : [];
            $record = $this->buildSystemMediaLibraryRecord( $metadata, $usage_record, $review_record );
            if ( ! empty( $record['media_id'] ) ) {
                $records[] = $record;
            }
        }

        return(
            [
                'records' => $records,
                'owners' => array_values( $owners ),
                'filters' => [ 'owner' => $owner_filter, 'type' => $type_filter, 'attachment' => $attachment_filter, 'usage' => $usage_filter, 'review' => $review_filter, 'q' => $search_query, 'per_page' => $per_page ],
                'reset_url' => $this->getSystemSectionUrl( 'media-library', [ 'media_library_filters' => 'reset' ] ),
                'pagination' => $pagination,
            ]
        );
    }

    protected function getSystemMediaLibraryStickyFilters() : array {
        if ( session_status() !== PHP_SESSION_ACTIVE ) {
            return( [] );
        }
        $filters = is_array( $_SESSION['tinymash_system_media_library_filters'] ?? null )
            ? $_SESSION['tinymash_system_media_library_filters']
            : [];

        return(
            [
                'owner' => strtolower( trim( (string) ( $filters['owner'] ?? 'all' ) ) ),
                'type' => strtolower( trim( (string) ( $filters['type'] ?? 'all' ) ) ),
                'attachment' => strtolower( trim( (string) ( $filters['attachment'] ?? 'all' ) ) ),
                'usage' => strtolower( trim( (string) ( $filters['usage'] ?? 'all' ) ) ),
                'review' => strtolower( trim( (string) ( $filters['review'] ?? 'all' ) ) ),
                'q' => $this->normalizeSystemMediaLibrarySearchQuery( (string) ( $filters['q'] ?? '' ) ),
                'per_page' => (int) ( $filters['per_page'] ?? 24 ),
            ]
        );
    }

    protected function setSystemMediaLibraryStickyFilters( array $filters ) : void {
        if ( session_status() !== PHP_SESSION_ACTIVE ) {
            return;
        }
        $_SESSION['tinymash_system_media_library_filters'] = [
            'owner' => strtolower( trim( (string) ( $filters['owner'] ?? 'all' ) ) ),
            'type' => strtolower( trim( (string) ( $filters['type'] ?? 'all' ) ) ),
            'attachment' => strtolower( trim( (string) ( $filters['attachment'] ?? 'all' ) ) ),
            'usage' => strtolower( trim( (string) ( $filters['usage'] ?? 'all' ) ) ),
            'review' => strtolower( trim( (string) ( $filters['review'] ?? 'all' ) ) ),
            'q' => $this->normalizeSystemMediaLibrarySearchQuery( (string) ( $filters['q'] ?? '' ) ),
            'per_page' => (int) ( $filters['per_page'] ?? 24 ),
        ];
    }

    protected function clearSystemMediaLibraryStickyFilters() : void {
        if ( session_status() !== PHP_SESSION_ACTIVE ) {
            return;
        }
        unset( $_SESSION['tinymash_system_media_library_filters'] );
    }

    protected function buildSystemMediaLibraryPagination( int $total_items, int $page, int $per_page, string $owner_filter, string $type_filter, string $attachment_filter, string $usage_filter, string $review_filter, string $search_query ) : array {
        $per_page = max( 1, $per_page );
        $total_pages = max( 1, (int) ceil( max( 0, $total_items ) / $per_page ) );
        $page = min( max( 1, $page ), $total_pages );
        $offset = ( $page - 1 ) * $per_page;
        $base_query = [
            'owner' => $owner_filter !== 'all' ? $owner_filter : '',
            'type' => $type_filter !== 'all' ? $type_filter : '',
            'attachment' => $attachment_filter !== 'all' ? $attachment_filter : '',
            'usage' => $usage_filter !== 'all' ? $usage_filter : '',
            'review' => $review_filter !== 'all' ? $review_filter : '',
            'q' => $search_query !== '' ? $search_query : '',
            'per_page' => $per_page !== 24 ? $per_page : '',
        ];
        $previous_page = $page > 1 ? $page - 1 : 1;
        $next_page = $page < $total_pages ? $page + 1 : $total_pages;

        return(
            [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'offset' => $offset,
                'has_previous' => $page > 1,
                'has_next' => $page < $total_pages,
                'previous_page' => $previous_page,
                'next_page' => $next_page,
                'first_url' => $this->getSystemSectionUrl( 'media-library', array_merge( $base_query, [ 'page' => '' ] ) ),
                'previous_url' => $this->getSystemSectionUrl( 'media-library', array_merge( $base_query, [ 'page' => $previous_page > 1 ? $previous_page : '' ] ) ),
                'next_url' => $this->getSystemSectionUrl( 'media-library', array_merge( $base_query, [ 'page' => $next_page > 1 ? $next_page : '' ] ) ),
                'last_url' => $this->getSystemSectionUrl( 'media-library', array_merge( $base_query, [ 'page' => $total_pages > 1 ? $total_pages : '' ] ) ),
            ]
        );
    }

    protected function getSystemSiteImagePickerRecords() : array {
        $media_service = $this->app->has( 'media.service' ) ? $this->app->get( 'media.service' ) : null;
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'listAttachments' ) ) {
            return( [] );
        }

        $records = [];
        foreach ( $media_service->listAttachments( [ 'root' ], 500 ) as $metadata ) {
            if ( ! is_array( $metadata ) ) {
                continue;
            }
            $record = $this->buildSystemMediaLibraryRecord( $metadata );
            if ( ! empty( $record['is_image'] ) && ! empty( $record['media_id'] ) && ! empty( $record['url'] ) ) {
                $records[] = $record;
            }
        }

        return( $records );
    }

    protected function buildSystemMediaLibraryUsageReport( array $metadata, string $owner_filter ) : array {
        $reporter = $this->app->has( 'media.usage_reporter' ) ? $this->app->get( 'media.usage_reporter' ) : null;
        if ( ! is_object( $reporter ) || ! method_exists( $reporter, 'buildReport' ) ) {
            return(
                [
                    'summary' => [ 'checked' => 0, 'used' => 0, 'unreferenced' => 0, 'direct_marker_only' => 0, 'missing_references' => 0 ],
                    'records' => [],
                    'records_by_id' => [],
                    'missing_references' => [],
                ]
            );
        }

        $report = $reporter->buildReport( $metadata, $owner_filter !== 'all' ? $owner_filter : '' );
        return( is_array( $report ) ? $report : [] );
    }

    protected function buildSystemMediaLibraryRecord( array $metadata, array $usage_record = [], array $review_record = [] ) : array {
        $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
        if ( $media_id === '' ) {
            return( [] );
        }

        $filename = basename( trim( (string) ( $metadata['filename'] ?? ( $metadata['original_filename'] ?? '' ) ) ) );
        $mime = strtolower( trim( (string) ( $metadata['mime'] ?? '' ) ) );
        $bytes = max( 0, (int) ( $metadata['bytes'] ?? 0 ) );
        $width = max( 0, (int) ( $metadata['width'] ?? 0 ) );
        $height = max( 0, (int) ( $metadata['height'] ?? 0 ) );
        $created_at_utc = trim( (string) ( $metadata['created_at_utc'] ?? '' ) );
        $display = is_array( $metadata['display'] ?? null ) ? $metadata['display'] : [];
        $display_width = max( 0, (int) ( $display['width'] ?? 0 ) );
        $display_height = max( 0, (int) ( $display['height'] ?? 0 ) );
        $display_bytes = max( 0, (int) ( $display['bytes'] ?? 0 ) );
        $display_max_dimension = $this->getSystemMediaDisplayDerivativeMaxDimension();
        $display_derivative_expected = str_starts_with( $mime, 'image/' ) && ( $width > $display_max_dimension || $height > $display_max_dimension );
        $has_display_derivative = trim( (string) ( $display['url'] ?? '' ) ) !== '';
        $usage_status = trim( (string) ( $usage_record['status'] ?? '' ) );
        $usage_label = trim( (string) ( $usage_record['status_label'] ?? '' ) );
        if ( $usage_status === '' ) {
            $usage_status = 'unknown';
        }
        if ( $usage_label === '' ) {
            $usage_label = $this->getSystemMediaLibraryUsageStatusLabel( $usage_status );
        }
        $review_status = trim( (string) ( $review_record['status'] ?? 'unreviewed' ) );
        if ( ! in_array( $review_status, [ 'unreviewed', 'keep', 'cleanup_candidate' ], true ) ) {
            $review_status = 'unreviewed';
        }
        $review_label = mb_trim( (string) ( $review_record['status_label'] ?? '' ) );
        if ( $review_label === '' ) {
            $review_label = $this->getSystemMediaLibraryReviewStatusLabel( $review_status );
        }
        $reviewed_at_utc = trim( (string) ( $review_record['reviewed_at_utc'] ?? '' ) );

        return(
            [
                'media_id' => $media_id,
                'owner_username' => strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) ),
                'filename' => $filename,
                'original_filename' => trim( (string) ( $metadata['original_filename'] ?? '' ) ),
                'label' => mb_trim( (string) ( $metadata['alt_text'] ?? '' ) ) !== '' ? mb_trim( (string) $metadata['alt_text'] ) : ( $filename !== '' ? $filename : $media_id ),
                'url' => trim( (string) ( $metadata['url'] ?? '' ) ),
                'thumbnail_url' => trim( (string) ( $metadata['thumbnail']['url'] ?? '' ) ),
                'display_url' => trim( (string) ( $display['url'] ?? '' ) ),
                'has_display_derivative' => $has_display_derivative,
                'display_derivative_expected' => $display_derivative_expected,
                'display_dimensions' => $display_width > 0 && $display_height > 0 ? $display_width . 'x' . $display_height : '',
                'display_size_label' => $this->formatSystemMediaBytes( $display_bytes ),
                'mime' => $mime,
                'is_image' => str_starts_with( $mime, 'image/' ),
                'width' => $width,
                'height' => $height,
                'dimensions' => $width > 0 && $height > 0 ? $width . 'x' . $height : '',
                'bytes' => $bytes,
                'size_label' => $this->formatSystemMediaBytes( $bytes ),
                'created_at_utc' => $created_at_utc,
                'created_at_display' => $this->formatUtcDateTime( $created_at_utc ),
                'source' => trim( (string) ( $metadata['source'] ?? '' ) ),
                'attached_entry_id' => trim( (string) ( $metadata['attached_entry_id'] ?? '' ) ),
                'attached_draft_id' => trim( (string) ( $metadata['attached_draft_id'] ?? '' ) ),
                'attachment_session_id' => trim( (string) ( $metadata['attachment_session_id'] ?? '' ) ),
                'usage_status' => $usage_status,
                'usage_status_label' => $usage_label,
                'usage_badge_class' => $this->getSystemMediaLibraryUsageBadgeClass( $usage_status ),
                'usage_reference_count' => max( 0, (int) ( $usage_record['reference_count'] ?? 0 ) ),
                'usage_reference_categories' => trim( (string) ( $usage_record['reference_categories'] ?? '-' ) ),
                'usage_references' => $this->normalizeSystemMediaLibraryUsageReferences( is_array( $usage_record['references'] ?? null ) ? $usage_record['references'] : [] ),
                'review_status' => $review_status,
                'review_status_label' => $review_label,
                'review_badge_class' => $this->getSystemMediaLibraryReviewBadgeClass( $review_status ),
                'reviewed_at_utc' => $reviewed_at_utc,
                'reviewed_at_display' => $this->formatUtcDateTime( $reviewed_at_utc ),
                'reviewed_by' => trim( (string) ( $review_record['reviewed_by'] ?? '' ) ),
                'metadata_rows' => $this->normalizeSystemMediaMetadataRows( is_array( $metadata['metadata_rows'] ?? null ) ? $metadata['metadata_rows'] : [] ),
                'public_metadata_rows' => $this->normalizeSystemMediaMetadataRows( is_array( $metadata['public_metadata_rows'] ?? null ) ? $metadata['public_metadata_rows'] : [] ),
                'embedded_metadata_stripped' => ! empty( $metadata['embedded_metadata_stripped'] ),
                'embedded_metadata_strip_note' => trim( (string) ( $metadata['embedded_metadata_strip_note'] ?? '' ) ),
            ]
        );
    }

    protected function getSystemMediaLibraryReviewStatusLabel( string $status ) : string {
        return(
            match ( $status ) {
                'keep' => 'Reviewed: keep',
                'cleanup_candidate' => 'Cleanup candidate',
                default => 'Unreviewed',
            }
        );
    }

    protected function getSystemMediaLibraryReviewBadgeClass( string $status ) : string {
        return(
            match ( $status ) {
                'keep' => 'text-bg-primary',
                'cleanup_candidate' => 'text-bg-warning',
                default => 'text-bg-secondary',
            }
        );
    }

    protected function getSystemMediaLibraryUsageStatusLabel( string $status ) : string {
        return(
            match ( $status ) {
                'used' => 'In use',
                'unreferenced' => 'Possibly unused',
                'direct_marker_only' => 'Upload association only',
                default => 'Unknown',
            }
        );
    }

    protected function getSystemMediaLibraryUsageBadgeClass( string $status ) : string {
        return(
            match ( $status ) {
                'used' => 'text-bg-success',
                'unreferenced' => 'text-bg-warning',
                'direct_marker_only' => 'text-bg-info',
                default => 'text-bg-secondary',
            }
        );
    }

    protected function normalizeSystemMediaLibraryUsageReferences( array $references ) : array {
        $normalized_references = [];
        foreach ( $references as $reference ) {
            if ( ! is_array( $reference ) ) {
                continue;
            }
            $category = trim( (string) ( $reference['category'] ?? '' ) );
            $source = trim( (string) ( $reference['source'] ?? '' ) );
            $label = mb_trim( (string) ( $reference['label'] ?? '' ) );
            if ( $category === '' && $source === '' && $label === '' ) {
                continue;
            }
            $normalized_references[] = [
                'category' => $category !== '' ? $category : 'reference',
                'category_label' => $this->getSystemMediaLibraryReferenceCategoryLabel( $category ),
                'source' => $source,
                'label' => $label !== '' ? $label : ( $source !== '' ? $source : 'Reference' ),
            ];
        }

        return( $normalized_references );
    }

    protected function getSystemMediaLibraryReferenceCategoryLabel( string $category ) : string {
        return(
            match ( $category ) {
                'site' => 'Site',
                'profile' => 'Profile',
                'published_content' => 'Content',
                'unpublished_content' => 'Unpublished content',
                'draft' => 'Draft',
                'system_plugin_settings' => 'Plugin',
                'profile_plugin_settings' => 'Profile plugin',
                'direct_attachment' => 'Upload association',
                default => 'Reference',
            }
        );
    }

    protected function getSystemMediaDisplayDerivativeMaxDimension() : int {
        return( 1600 );
    }

    protected function normalizeSystemMediaMetadataRows( array $rows ) : array {
        $normalized_rows = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $label = mb_trim( (string) ( $row['label'] ?? '' ) );
            $value = mb_trim( (string) ( $row['value'] ?? '' ) );
            if ( $label === '' || $value === '' ) {
                continue;
            }
            $normalized_rows[] = [
                'group' => mb_trim( (string) ( $row['group'] ?? '' ) ),
                'label' => $label,
                'value' => $value,
            ];
        }

        return( $normalized_rows );
    }

    protected function normalizeSystemMediaLibrarySearchQuery( string $query ) : string {
        $query = mb_trim( $query );
        $query = preg_replace( '/\s+/u', ' ', $query ) ?? $query;
        return( mb_strlen( $query ) > 120 ? mb_substr( $query, 0, 120 ) : $query );
    }

    protected function systemMediaLibraryHasDirectAttachment( array $metadata ) : bool {
        return(
            trim( (string) ( $metadata['attached_entry_id'] ?? '' ) ) !== ''
            || trim( (string) ( $metadata['attached_draft_id'] ?? '' ) ) !== ''
            || trim( (string) ( $metadata['attachment_session_id'] ?? '' ) ) !== ''
        );
    }

    protected function systemMediaLibraryMetadataMatchesQuery( array $metadata, string $search_query ) : bool {
        $needle = $this->normalizeSystemMediaLibrarySearchNeedle( $search_query );
        if ( $needle === '' ) {
            return( true );
        }

        foreach ( $this->buildSystemMediaLibrarySearchFields( $metadata ) as $field ) {
            if ( str_contains( $this->normalizeSystemMediaLibrarySearchNeedle( $field ), $needle ) ) {
                return( true );
            }
        }

        return( false );
    }

    protected function normalizeSystemMediaLibrarySearchNeedle( string $value ) : string {
        $value = $this->normalizeSystemMediaLibrarySearchQuery( $value );
        return( mb_strtolower( $value, 'UTF-8' ) );
    }

    protected function buildSystemMediaLibrarySearchFields( array $metadata ) : array {
        $fields = [
            (string) ( $metadata['media_id'] ?? '' ),
            (string) ( $metadata['filename'] ?? '' ),
            (string) ( $metadata['original_filename'] ?? '' ),
            (string) ( $metadata['alt_text'] ?? '' ),
            (string) ( $metadata['mime'] ?? '' ),
            (string) ( $metadata['source'] ?? '' ),
            (string) ( $metadata['attached_entry_id'] ?? '' ),
            (string) ( $metadata['attached_draft_id'] ?? '' ),
            (string) ( $metadata['attachment_session_id'] ?? '' ),
        ];

        $width = max( 0, (int) ( $metadata['width'] ?? 0 ) );
        $height = max( 0, (int) ( $metadata['height'] ?? 0 ) );
        if ( $width > 0 && $height > 0 ) {
            $fields[] = $width . 'x' . $height;
        }
        $display = is_array( $metadata['display'] ?? null ) ? $metadata['display'] : [];
        $display_width = max( 0, (int) ( $display['width'] ?? 0 ) );
        $display_height = max( 0, (int) ( $display['height'] ?? 0 ) );
        if ( $display_width > 0 && $display_height > 0 ) {
            $fields[] = $display_width . 'x' . $display_height;
        }
        foreach ( [ 'public_metadata_rows', 'metadata_rows', 'retained_metadata_rows' ] as $row_key ) {
            if ( is_array( $metadata[$row_key] ?? null ) ) {
                $this->collectSystemMediaLibrarySearchFields( $metadata[$row_key], $fields, 0 );
            }
        }

        return( array_values( array_filter( $fields, static fn( string $field ) : bool => trim( $field ) !== '' ) ) );
    }

    protected function collectSystemMediaLibrarySearchFields( array $values, array &$fields, int $depth ) : void {
        if ( $depth > 3 ) {
            return;
        }
        foreach ( $values as $value ) {
            if ( is_scalar( $value ) ) {
                $fields[] = (string) $value;
            } elseif ( is_array( $value ) ) {
                $this->collectSystemMediaLibrarySearchFields( $value, $fields, $depth + 1 );
            }
        }
    }

    protected function formatSystemMediaBytes( int $bytes ) : string {
        if ( $bytes <= 0 ) {
            return( '0 B' );
        }

        $units = [ 'B', 'KB', 'MB', 'GB' ];
        $value = (float) $bytes;
        $unit_index = 0;
        while ( $value >= 1024 && $unit_index < count( $units ) - 1 ) {
            $value /= 1024;
            $unit_index++;
        }

        return( rtrim( rtrim( number_format( $value, $unit_index === 0 ? 0 : 1, '.', '' ), '0' ), '.' ) . ' ' . $units[$unit_index] );
    }

    protected function getSystemComponentsReport() : array {
        if ( ! $this->app->has( 'components.report_service' ) ) {
            return( [] );
        }

        $service = $this->app->get( 'components.report_service' );
        if ( ! is_object( $service ) || ! method_exists( $service, 'readReport' ) ) {
            return( [] );
        }

        $report = $service->readReport();
        if ( ! is_array( $report ) ) {
            return( [] );
        }

        $report['checked_at_display'] = $this->formatUtcDateTime( (string) ( $report['checked_at_utc'] ?? '' ) );
        if ( is_array( $report['advisory_data'] ?? null ) ) {
            $report['advisory_data']['checked_at_display'] = $this->formatUtcDateTime( (string) ( $report['advisory_data']['checked_at_utc'] ?? '' ) );
        }
        return( $report );
    }

    protected function getSystemUpdateAvailability() : array {
        if ( ! $this->app->has( 'update.check_service' ) ) {
            return( [ 'status' => 'unavailable', 'installed_version' => defined( 'APP_VERSION' ) ? APP_VERSION : '' ] );
        }

        $service = $this->app->get( 'update.check_service' );
        if ( ! $service instanceof \app\classes\TinyMashUpdateCheckService ) {
            return( [ 'status' => 'unavailable', 'installed_version' => defined( 'APP_VERSION' ) ? APP_VERSION : '' ] );
        }

        $availability = $service->getAvailability(
            defined( 'APP_VERSION' ) ? APP_VERSION : '',
            PHP_VERSION,
            get_loaded_extensions()
        );
        $availability['verified_at_display'] = $this->formatUtcDateTime( (string) ( $availability['verified_at_utc'] ?? '' ) );
        $availability['released_at_display'] = $this->formatUtcDateTime( (string) ( $availability['release']['released_at_utc'] ?? '' ) );
        return( $availability );
    }

    protected function getSystemUpdatePrerequisites() : array {
        $service = $this->app->has( 'update.stage_service' ) ? $this->app->get( 'update.stage_service' ) : null;
        if ( ! $service instanceof \app\classes\TinyMashUpdateStageService ) {
            $failure = \app\classes\TinyMashUpdateErrorPolicy::describeReason( 'update.package_check_failed' );
            return( [
                'ok' => false,
                'checks' => [],
                'missing' => [ $failure ],
            ] );
        }

        $prerequisites = $service->getPrerequisites();
        foreach ( $prerequisites['checks'] ?? [] as $index => $check ) {
            if ( ! is_array( $check ) ) {
                continue;
            }
            $failure = \app\classes\TinyMashUpdateErrorPolicy::describeReason( (string) ( $check['failure_code'] ?? '' ) );
            $prerequisites['checks'][$index]['message'] = (string) $failure['message'];
        }
        foreach ( $prerequisites['missing'] ?? [] as $index => $check ) {
            if ( ! is_array( $check ) ) {
                continue;
            }
            $failure = \app\classes\TinyMashUpdateErrorPolicy::describeReason( (string) ( $check['failure_code'] ?? '' ) );
            $prerequisites['missing'][$index]['message'] = (string) $failure['message'];
        }
        return( $prerequisites );
    }

    protected function getSystemUpdateStageStatus( array $availability ) : array {
        if ( ! $this->app->has( 'update.stage_service' ) ) {
            return( [] );
        }
        $service = $this->app->get( 'update.stage_service' );
        if ( ! $service instanceof \app\classes\TinyMashUpdateStageService ) {
            return( [] );
        }
        $status = $service->getStatus( $availability );
        if ( $status !== [] ) {
            $status['checked_at_display'] = $this->formatUtcDateTime( (string) ( $status['checked_at_utc'] ?? '' ) );
        }
        return( $status );
    }

    protected function getSystemNotificationsViewData( bool $mark_active_read = false ) : array {
        $service = $this->getNotificationService();
        if ( $service === null ) {
            return(
                [
                    'summary' => [ 'total' => 0, 'unread' => 0, 'dismissed' => 0 ],
                    'active' => [],
                    'dismissed' => [],
                ]
            );
        }

        if ( $mark_active_read && method_exists( $service, 'markAllActiveRead' ) ) {
            $service->markAllActiveRead( (string) ( $this->security->getCurrentUsername() ?? '' ) );
        }

        $active_notifications = method_exists( $service, 'getNotifications' ) ? (array) $service->getNotifications( false, 100 ) : [];
        $dismissed_notifications = array_values(
            array_filter(
                method_exists( $service, 'getNotifications' ) ? (array) $service->getNotifications( true, 200 ) : [],
                static fn( array $notification ) : bool => ! empty( $notification['dismissed_at_utc'] )
            )
        );

        foreach ( $active_notifications as $index => $notification ) {
            if ( ! is_array( $notification ) ) {
                continue;
            }
            $active_notifications[$index]['type_label'] = $this->getSystemNotificationTypeLabel( $notification );
            $active_notifications[$index]['class_label'] = $this->getSystemNotificationClassLabel( $notification );
            $active_notifications[$index]['class_severity'] = $this->getSystemNotificationClassSeverity( $notification );
            $active_notifications[$index]['created_at_display'] = $this->formatUtcDateTime( (string) ( $notification['created_at_utc'] ?? '' ) );
            $active_notifications[$index]['read_at_display'] = $this->formatUtcDateTime( (string) ( $notification['read_at_utc'] ?? '' ) );
        }
        foreach ( $dismissed_notifications as $index => $notification ) {
            if ( ! is_array( $notification ) ) {
                continue;
            }
            $dismissed_notifications[$index]['type_label'] = $this->getSystemNotificationTypeLabel( $notification );
            $dismissed_notifications[$index]['class_label'] = $this->getSystemNotificationClassLabel( $notification );
            $dismissed_notifications[$index]['class_severity'] = $this->getSystemNotificationClassSeverity( $notification );
            $dismissed_notifications[$index]['created_at_display'] = $this->formatUtcDateTime( (string) ( $notification['created_at_utc'] ?? '' ) );
            $dismissed_notifications[$index]['dismissed_at_display'] = $this->formatUtcDateTime( (string) ( $notification['dismissed_at_utc'] ?? '' ) );
        }

        return(
            [
                'summary' => method_exists( $service, 'getSummary' ) ? (array) $service->getSummary( true ) : [ 'total' => 0, 'unread' => 0, 'dismissed' => 0 ],
                'active' => $active_notifications,
                'dismissed' => $dismissed_notifications,
            ]
        );
    }

    protected function getSystemNotificationTypeLabel( array $notification ) : string {
        $type = strtolower( trim( (string) ( $notification['type'] ?? 'notification' ) ) );
        return(
            match ( $type ) {
                'component_updates' => 'Component updates',
                'moderation_required' => 'Moderation',
                'profile_email_changed' => 'Profile e-mail changed',
                'user_lockout' => 'User lockout',
                default => ucwords( str_replace( [ '-', '_' ], ' ', $type ) ),
            }
        );
    }

    protected function getSystemNotificationClassLabel( array $notification ) : string {
        $type = strtolower( trim( (string) ( $notification['type'] ?? '' ) ) );
        $context = is_array( $notification['context'] ?? null ) ? $notification['context'] : [];
        if ( $type === 'component_updates' ) {
            return(
                match ( (string) ( $context['primary_class'] ?? '' ) ) {
                    'security_updates' => 'Security',
                    'version_updates' => 'Major',
                    'safe_updates' => 'Patch/minor',
                    default => '',
                }
            );
        }

        return( '' );
    }

    protected function getSystemNotificationClassSeverity( array $notification ) : string {
        $type = strtolower( trim( (string) ( $notification['type'] ?? '' ) ) );
        $context = is_array( $notification['context'] ?? null ) ? $notification['context'] : [];
        if ( $type === 'component_updates' ) {
            return(
                match ( (string) ( $context['primary_class'] ?? '' ) ) {
                    'security_updates' => 'danger',
                    'version_updates' => 'warning',
                    'safe_updates' => 'info',
                    default => 'secondary',
                }
            );
        }

        return( 'secondary' );
    }

    protected function getSystemRuntimeInfo() : array {
        $project_root = dirname( __DIR__, 2 );
        $project_tmp_directory = $project_root . DIRECTORY_SEPARATOR . 'tmp';
        $storage_directory = $project_root . DIRECTORY_SEPARATOR . 'data';
        $cache_directory = $storage_directory . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'latte-cache';
        $logs_directory = $project_root . DIRECTORY_SEPARATOR . 'logs';
        $config_filename = $project_root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tinymash.json';
        $php_temp_directory = tinymash_get_runtime_temp_directory();
        $media_service = $this->app->has( 'media.service' ) ? $this->app->get( 'media.service' ) : null;
        $media_runtime_info = is_object( $media_service ) && method_exists( $media_service, 'getRuntimeInfo' ) ? $media_service->getRuntimeInfo() : [];
        $media_root = is_object( $media_service ) && method_exists( $media_service, 'getMediaRoot' )
            ? (string) $media_service->getMediaRoot()
            : ( $storage_directory . DIRECTORY_SEPARATOR . 'media' );
        $max_execution_time = (int) ini_get( 'max_execution_time' );
        $max_input_time = (int) ini_get( 'max_input_time' );
        $memory_limit = (string) ini_get( 'memory_limit' );
        $memory_limit_bytes = $this->parsePhpIniBytes( $memory_limit );
        $open_basedir = trim( (string) ini_get( 'open_basedir' ) );

        $extensions = [
            [ 'name' => 'mbstring', 'required' => true, 'loaded' => extension_loaded( 'mbstring' ) ],
            [ 'name' => 'json', 'required' => true, 'loaded' => extension_loaded( 'json' ) ],
            [ 'name' => 'dom', 'required' => true, 'loaded' => extension_loaded( 'dom' ) ],
            [ 'name' => 'session', 'required' => true, 'loaded' => extension_loaded( 'session' ) ],
            [ 'name' => 'openssl', 'required' => true, 'loaded' => extension_loaded( 'openssl' ) ],
            [ 'name' => 'fileinfo', 'required' => true, 'loaded' => extension_loaded( 'fileinfo' ) ],
            [ 'name' => 'curl', 'required' => false, 'loaded' => extension_loaded( 'curl' ) ],
            [ 'name' => 'sodium', 'required' => false, 'loaded' => extension_loaded( 'sodium' ) ],
            [ 'name' => 'imagick', 'required' => false, 'loaded' => extension_loaded( 'imagick' ) ],
            [ 'name' => 'gd', 'required' => false, 'loaded' => extension_loaded( 'gd' ) ],
            [ 'name' => 'simplexml', 'required' => false, 'loaded' => extension_loaded( 'SimpleXML' ) ],
            [ 'name' => 'exif', 'required' => false, 'loaded' => extension_loaded( 'exif' ) ],
            [ 'name' => 'intl', 'required' => false, 'loaded' => extension_loaded( 'intl' ) ],
            [ 'name' => 'zip', 'required' => false, 'loaded' => extension_loaded( 'zip' ) ],
        ];
        $live_update_extensions = array_fill_keys( \app\classes\TinyMashUpdateRequirements::getRequiredExtensions(), true );
        foreach ( $extensions as $index => $extension ) {
            if ( isset( $live_update_extensions[(string) $extension['name']] ) ) {
                $extensions[$index]['required_for'] = 'Live Update';
            }
        }

        $runtime_paths = [
            [
                'label' => 'Upload folder',
                'state' => $this->buildRuntimePathState( $media_root, 'writable', 'not_writable', 'Writable', 'Not writable' ),
            ],
            [
                'label' => 'Temporary folder',
                'state' => $this->buildRuntimePathState( $php_temp_directory, 'writable', 'not_writable', 'Writable', 'Not writable' ),
            ],
            [
                'label' => 'Workspace temp',
                'state' => $this->buildRuntimePathState( $project_tmp_directory, 'writable', 'not_writable', 'Writable', 'Not writable' ),
            ],
            [
                'label' => 'Storage folder',
                'state' => $this->buildRuntimePathState( $storage_directory, 'writable', 'not_writable', 'Writable', 'Not writable' ),
            ],
            [
                'label' => 'Cache folder',
                'state' => $this->buildRuntimePathState( $cache_directory, 'writable', 'not_writable', 'Writable', 'Not writable' ),
            ],
            [
                'label' => 'Logs folder',
                'state' => $this->buildRuntimePathState( $logs_directory, 'writable', 'not_writable', 'Writable', 'Not writable' ),
            ],
            [
                'label' => 'Config file',
                'state' => is_file( $config_filename )
                    ? $this->buildRuntimePathState( $config_filename, 'writable', 'read_only', 'Writable', 'Read-only' )
                    : $this->buildRuntimePathMissingState( 'Missing' ),
            ],
            [
                'label' => 'Path restrictions',
                'state' => $open_basedir !== ''
                    ? $this->buildRuntimeState( 'restricted', 'Restricted', 'text-bg-secondary' )
                    : $this->buildRuntimeState( 'not_restricted', 'Not restricted', 'text-bg-success' ),
            ],
        ];

        return(
            [
                'php_version' => PHP_VERSION,
                'php_version_ok' => PHP_VERSION_ID >= 80400,
                'php_sapi' => PHP_SAPI,
                'default_timezone' => date_default_timezone_get(),
                'max_execution_time' => $max_execution_time,
                'max_execution_time_warning' => $max_execution_time > 0 && $max_execution_time < 60,
                'max_input_time' => $max_input_time,
                'max_input_time_warning' => $max_input_time > 0 && $max_input_time < 60,
                'memory_limit' => $memory_limit,
                'memory_limit_warning' => $memory_limit_bytes > 0 && $memory_limit_bytes < ( 10 * 1024 * 1024 ),
                'runtime_paths' => $runtime_paths,
                'media_runtime' => $media_runtime_info,
                'media_runtime_image_uploads_mode_label' => (string) ( $media_runtime_info['image_uploads_mode_label'] ?? $this->describeContentImageMode( (string) ( $media_runtime_info['image_uploads_mode'] ?? 'disabled' ) ) ),
                'extensions' => $extensions,
            ]
        );
    }

    protected function buildRuntimePathState( string $path, string $ok_key, string $failed_key, string $ok_label, string $failed_label ) : array {
        $path = trim( $path );
        if ( $path === '' || ( ! is_dir( $path ) && ! is_file( $path ) ) ) {
            return( $this->buildRuntimePathMissingState( 'Missing' ) );
        }

        return(
            is_writable( $path )
                ? $this->buildRuntimeState( $ok_key, $ok_label, 'text-bg-success' )
                : $this->buildRuntimeState( $failed_key, $failed_label, 'text-bg-danger' )
        );
    }

    protected function buildRuntimePathMissingState( string $label ) : array {
        return( $this->buildRuntimeState( 'missing', $label, 'text-bg-danger' ) );
    }

    protected function buildRuntimeState( string $key, string $label, string $badge_class ) : array {
        return(
            [
                'key' => $key,
                'label' => $label,
                'badge_class' => $badge_class,
            ]
        );
    }

    protected function parsePhpIniBytes( string $value ) : int {
        $value = trim( $value );
        if ( $value === '' ) {
            return( 0 );
        }

        if ( $value === '-1' ) {
            return( -1 );
        }

        if ( preg_match( '/^([0-9]+)\s*([kmgt])?$/i', $value, $matches ) !== 1 ) {
            return( 0 );
        }

        $bytes = (int) $matches[1];
        $suffix = strtolower( (string) ( $matches[2] ?? '' ) );

        return(
            match ( $suffix ) {
                'k' => $bytes * 1024,
                'm' => $bytes * 1024 * 1024,
                'g' => $bytes * 1024 * 1024 * 1024,
                't' => $bytes * 1024 * 1024 * 1024 * 1024,
                default => $bytes,
            }
        );
    }

    protected function describeContentImageMode( string $mode ) : string {
        $mode = strtolower( trim( $mode ) );

        return(
            match ( $mode ) {
                'admins_only' => 'Admins only',
                'authenticated' => 'All logged-in users',
                default => 'Disabled',
            }
        );
    }

    protected function getSystemSectionUrl( string $section, array $query = [] ) : string {
        $url = $this->app->get( 'admin.url' ) . '/system/' . $this->normalizeSystemSection( $section );
        $query = array_filter(
            $query,
            static function( mixed $value ) : bool {
                return( is_scalar( $value ) && trim( (string) $value ) !== '' );
            }
        );
        if ( empty( $query ) ) {
            return( $url );
        }

        return( $url . '?' . http_build_query( $query ) );
    }

    protected function getSystemNotice( ?string $current_section = null ) : array {
        $flash_notice = $this->security->pullFlash( 'system.notice', [] );
        if ( is_array( $flash_notice ) && ! empty( $flash_notice['type'] ) && ! empty( $flash_notice['message'] ) ) {
            return( $flash_notice );
        }

        $data = $this->app->request()->query->getData();
        if ( ! is_array( $data ) ) {
            return( [] );
        }

        if ( ! empty( $data['notice'] ) ) {
            $notice = (string) $data['notice'];
            if ( $notice === 'user-saved' ) {
                return( $this->buildSystemNotice( 'success', 'User settings were saved.' ) );
            }
            if ( $notice === 'user-state' ) {
                return( $this->buildSystemNotice( 'success', 'User state was updated.' ) );
            }
            if ( $notice === 'user-invalid' ) {
                $field = ! empty( $data['field'] ) ? (string) $data['field'] : 'user';
                return( $this->buildSystemNotice( 'danger', 'Invalid value for ' . $field . '.' ) );
            }
            if ( $notice === 'user-error' ) {
                return( $this->buildSystemNotice( 'danger', 'User changes could not be saved right now.' ) );
            }
            if ( $notice === 'user-delete-invalid' ) {
                $field = ! empty( $data['field'] ) ? (string) $data['field'] : 'user';
                return( $this->buildSystemNotice( 'danger', $this->getUserValidationMessage( $field ) ) );
            }
            if ( $notice === 'user-deleted' ) {
                $username = ! empty( $data['user'] ) ? (string) $data['user'] : 'user';
                $count = ! empty( $data['count'] ) ? (int) $data['count'] : 0;
                $orphan = ! empty( $data['orphan'] ) ? (string) $data['orphan'] : '';
                $message = 'Deleted user ' . $username . ' and moved ' . $count . ' content items into orphan storage.';
                if ( $orphan !== '' ) {
                    $message .= ' Orphan owner: ' . $orphan . '.';
                }
                return( $this->buildSystemNotice( 'success', $message ) );
            }
            if ( $notice === 'smtp-test-sent' ) {
                $email = ! empty( $data['email'] ) ? (string) $data['email'] : 'the requested address';
                return( $this->buildSystemNotice( 'success', 'SMTP test e-mail sent to ' . $email . '.' ) );
            }
            if ( $notice === 'smtp-test-invalid' ) {
                $field = ! empty( $data['field'] ) ? (string) $data['field'] : 'smtp';
                return( $this->buildSystemNotice( 'danger', $this->getUserValidationMessage( $field ) ) );
            }
            if ( $notice === 'smtp-test-error' ) {
                return( $this->buildSystemNotice( 'danger', 'SMTP test e-mail could not be sent right now.' ) );
            }
            if ( $notice === 'orphan-reassigned' ) {
                $count = ! empty( $data['count'] ) ? (int) $data['count'] : 0;
                return( $this->buildSystemNotice( 'success', 'Reassigned ' . $count . ' orphaned content items.' ) );
            }
            if ( $notice === 'orphan-deleted' ) {
                $count = ! empty( $data['count'] ) ? (int) $data['count'] : 0;
                return( $this->buildSystemNotice( 'success', 'Deleted ' . $count . ' orphaned content items.' ) );
            }
            if ( $notice === 'orphan-invalid' ) {
                $field = ! empty( $data['field'] ) ? (string) $data['field'] : 'orphans';
                return( $this->buildSystemNotice( 'danger', $this->getUserValidationMessage( $field ) ) );
            }
            if ( $notice === 'orphan-error' ) {
                return( $this->buildSystemNotice( 'danger', 'Orphaned content could not be updated right now.' ) );
            }
        }

        if ( empty( $data['settings'] ) ) {
            return( [] );
        }

        if ( $data['settings'] === 'saved' ) {
            $section = $this->normalizeSystemSettingsGroup( (string) ( $data['section'] ?? ( $current_section ?? 'site' ) ) );
            $message = 'System settings were saved.';
            if ( $section === 'moderation' ) {
                $message = 'Moderation policy was saved.';
            } elseif ( $section === 'plugins' ) {
                $message = 'Plugin settings were saved.';
            } elseif ( $section === 'smtp' ) {
                $message = 'SMTP settings were saved.';
            }
            return( $this->buildSystemNotice( 'success', $message ) );
        }

        if ( $data['settings'] === 'invalid' ) {
            $field = ! empty( $data['field'] ) ? (string) $data['field'] : 'settings';
            return( $this->buildSystemNotice( 'danger', 'Invalid value for ' . $field . '.' ) );
        }

        return( $this->buildSystemNotice( 'danger', 'System settings could not be saved right now.' ) );
    }

    protected function handleSystemModerationDecision( string $status, string $success_message ) : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'current_location' => 'forbidden',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'moderation' ) );
            return;
        }

        try {
            if ( $this->content_repository === null ) {
                throw new \RuntimeException( 'Content repository is not available.' );
            }

            $entry_id = trim( (string) ( $data['entry_id'] ?? '' ) );
            if ( $entry_id === '' ) {
                throw new \InvalidArgumentException( 'editor_entry_access' );
            }

            $entry = $this->content_repository->getAccessibleEntryById( $entry_id );
            if ( ! is_array( $entry ) || ( $entry['status'] ?? '' ) !== 'pending_review' ) {
                throw new \InvalidArgumentException( 'moderation_status' );
            }

            $previous_entry = $entry;
            $entry = $this->content_repository->updateEntryStatus( $entry_id, $status );
            $this->dispatchContentLifecycle(
                is_array( $previous_entry ) ? $previous_entry : null,
                $entry,
                (string) ( $this->security->getCurrentUsername() ?? '' )
            );
            $this->setSystemNoticeFlash( 'success', $success_message . ' ' . (string) ( $entry['title'] ?? 'Content' ) . '.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'moderation' ) );
            return;
        } catch ( \InvalidArgumentException $e ) {
            $this->setSystemNoticeFlash( 'danger', $this->getEditorValidationMessage( $e->getMessage() ) );
            $this->app->redirect( $this->getSystemSectionUrl( 'moderation' ) );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to update moderation entry (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'Moderation action could not be completed right now.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'moderation' ) );
            return;
        }
    }

    protected function getSystemUsers() : array {
        $user_repository = $this->getUserRepository();
        if ( $user_repository === null ) {
            return( [] );
        }

        $users = $user_repository->getAllUsers();
        if ( ! is_array( $users ) ) {
            return( [] );
        }

        foreach ( $users as $index => $user ) {
            if ( ! is_array( $user ) ) {
                continue;
            }
            $users[$index]['role_label'] = $this->getRoleDisplayLabel( (string) ( $user['role'] ?? '' ) );
        }

        return( $users );
    }

    protected function getSystemSecretLinksViewData( array $users ) : array {
        $service = $this->getSecretLinkService();
        $records = [];
        $users_by_slug = [];
        foreach ( $users as $user ) {
            if ( ! is_array( $user ) || empty( $user['author_slug'] ) ) {
                continue;
            }
            $users_by_slug[(string) $user['author_slug']] = $user;
        }

        if ( is_object( $service ) && method_exists( $service, 'getAllSecretLinks' ) ) {
            foreach ( (array) $service->getAllSecretLinks() as $record ) {
                if ( ! is_array( $record ) ) {
                    continue;
                }
                $scope = (string) ( $record['scope'] ?? 'author' );
                $author_slug = (string) ( $record['author_slug'] ?? '' );
                $user = $users_by_slug[$author_slug] ?? [];
                $records[] = [
                    'target' => $scope === 'site' ? 'site' : $author_slug,
                    'label' => $scope === 'site'
                        ? 'Site'
                        : ( (string) ( $user['display_name'] ?? '' ) !== '' ? (string) $user['display_name'] : $author_slug ),
                    'author_slug' => $author_slug,
                    'url' => method_exists( $service, 'buildSecretLinkUrl' )
                        ? (string) $service->buildSecretLinkUrl( $record, (string) $this->app->get( 'app.url' ) )
                        : '',
                    'created_at_display' => $this->formatUtcDateTime( (string) ( $record['created_at_utc'] ?? '' ) ),
                    'expires_at_display' => ( $record['expires_at_utc'] ?? null ) === null
                        ? 'Never'
                        : $this->formatUtcDateTime( (string) $record['expires_at_utc'] ),
                    'suspended' => $scope === 'author' && ( ! is_array( $user ) || empty( $user['content_active'] ) ),
                ];
            }
        }

        return(
            [
                'available' => ! $this->config->isSitePublic() && $this->config->allowsSecretLinks(),
                'site_public' => $this->config->isSitePublic(),
                'system_enabled' => $this->config->allowsSecretLinks(),
                'default_expiry_days' => $this->config->getSecretLinkDefaultExpiryDays(),
                'records' => $records,
                'users' => array_values( $users_by_slug ),
            ]
        );
    }

    protected function getSystemReassignUsers( array $users ) : array {
        $reassign_users = [];
        foreach ( $users as $user ) {
            if ( ! is_array( $user ) || empty( $user['author_slug'] ) ) {
                continue;
            }
            $reassign_users[] = $user;
        }

        return( $reassign_users );
    }

    protected function getSystemOrphanGroups() : array {
        if ( $this->content_repository === null ) {
            return( [] );
        }

        $groups = $this->content_repository->getOrphanedEntryGroups( $this->config->getDefaultLanguage() );
        foreach ( $groups as $group_index => $group ) {
            foreach ( (array) ( $group['entries'] ?? [] ) as $entry_index => $entry ) {
                $groups[$group_index]['entries'][$entry_index]['updated_at_display'] = $this->formatUtcDateTime( (string) ( $entry['updated_at_utc'] ?? '' ) );
            }
        }

        return( $groups );
    }

    protected function getSystemUserFormState() : array {
        $default_state = [
            'original_username' => '',
            'username' => '',
            'author_slug' => '',
            'display_name' => '',
            'email' => '',
            'role' => 'creator',
            'active' => true,
            'content_active' => true,
        ];

        $data = $this->app->request()->query->getData();
        if ( ! is_array( $data ) || empty( $data['user'] ) ) {
            return( $default_state );
        }

        $user_repository = $this->getUserRepository();
        if ( $user_repository === null ) {
            return( $default_state );
        }

        $user = $user_repository->getUserByUsername( (string) $data['user'] );
        if ( ! is_array( $user ) ) {
            return( $default_state );
        }

        return(
            [
                'original_username' => $user['username'],
                'username' => $user['username'],
                'author_slug' => $user['author_slug'],
                'display_name' => (string) ( $user['display_name'] ?? '' ),
                'email' => $user['email'],
                'role' => $user['role'],
                'active' => $user['active'],
                'content_active' => $user['content_active'],
            ]
        );
    }

    protected function normalizeSystemUserFormState( array $data ) : array {
        $username = trim( (string) ( $data['username'] ?? '' ) );
        $original_username = trim( (string) ( $data['original_username'] ?? '' ) );
        return(
            [
                'original_username' => $original_username,
                'username' => $username,
                'author_slug' => $username,
                'display_name' => mb_trim( (string) ( $data['display_name'] ?? '' ) ),
                'email' => mb_trim( (string) ( $data['email'] ?? '' ) ),
                'role' => strtolower( trim( (string) ( $data['role'] ?? 'creator' ) ) ) === 'superadmin' ? 'superadmin' : 'creator',
                'active' => ! empty( $data['active'] ),
                'content_active' => ! empty( $data['content_active'] ),
            ]
        );
    }

    protected function getUserValidationMessage( string $field ) : string {
        return(
            match ( $field ) {
                'username' => 'Use only lowercase letters, numbers, and underscores for the username.',
                'username_taken' => 'That username is already in use.',
                'author_slug_taken' => 'That Username/Author is already in use.',
                'email_taken' => 'That e-mail address is already in use.',
                'password' => 'A password is required for a new user.',
                'password_length' => 'The new password is shorter than the site minimum password length.',
                'current_password' => 'Enter your current password to change it.',
                'current_password_invalid' => 'The current password is not correct.',
                'new_password' => 'Enter a new password to complete the password change.',
                'confirm_password' => 'The new password confirmation does not match.',
                'author_slug' => 'The Username/Author could not be derived from that input.',
                'email' => 'The e-mail address is not valid.',
                'theme_mode' => 'The selected theme mode is not valid.',
                'editor_mode' => 'The selected Composer mode is not valid.',
                'editor_default_mode' => 'The site default Composer mode is not valid.',
                'default_entry_type' => 'The default content type is not valid.',
                'default_status' => 'The default publishing status is not valid.',
                'site_name' => 'The site name is required.',
                'default_language' => 'The default language is not valid.',
                'time_format' => 'The time format is required.',
                'timezone' => 'The timezone is not valid.',
                'admin_email' => 'The site admin e-mail address is not valid.',
                'system_notifications_email' => 'The system notifications e-mail address is not valid.',
                'password_reset_throttle_window_minutes' => 'Password-reset throttle window must be between 1 and 1440 minutes.',
                'password_reset_max_ip_requests' => 'Password-reset requests per IP must be between 1 and 100.',
                'password_reset_max_identifier_requests' => 'Password-reset requests per username or e-mail must be between 1 and 100.',
                'secret_link_default_expiry_days' => 'Secret-link expiry must be between 0 and 365 days.',
                'secret_link_expiry_days' => 'Secret-link expiry must be between 0 and 365 days.',
                'week_starts_on' => 'The selected week-start day is not valid.',
                'login_message' => 'The login-screen message is not valid.',
                'site_identity_image_upload' => 'That site image upload did not complete successfully.',
                'site_identity_image_type' => 'The selected site image type is not supported for that field.',
                'content_revision_retention_limit' => 'The revision retention limit must be between 0 and 100.',
                'housekeeping_stale_draft_retention_days' => 'The stale draft retention must be between 0 and 365 days.',
                'housekeeping_web_fallback_mode' => 'The web cron fallback mode is not valid.',
                'forwarded_ip_mode' => 'The forwarded visitor IP mode is not valid.',
                'trusted_proxy_cidrs' => 'Enter valid trusted proxy addresses or CIDRs. Forwarded modes require at least one.',
                'public_cache_warm_basic_auth_username' => 'The public cache-warmer HTTP auth username is not valid.',
                'editor_autosave_interval_seconds' => 'The editor autosave interval must be between 30 and 180 seconds.',
                'content_images_mode' => 'The content image upload policy is not valid.',
                'content_image_driver' => 'The preferred image driver is not valid.',
                'content_image_max_width' => 'The maximum image width must be between 256 and 8192 pixels.',
                'content_image_max_height' => 'The maximum image height must be between 256 and 8192 pixels.',
                'content_thumbnail_max_width' => 'The thumbnail width must be between 64 and 2048 pixels.',
                'content_thumbnail_max_height' => 'The thumbnail height must be between 64 and 2048 pixels.',
                'content_image_owner' => 'That image upload could not be assigned to an owner.',
                'content_image_file' => 'Choose an image file to upload.',
                'content_image_upload' => 'That image upload did not complete successfully.',
                'content_image_type' => 'Only ICO, JPEG, PNG, GIF, WebP, AVIF, and SVG images are supported.',
                'content_image_too_large' => 'That image exceeds the allowed maximum upload size for this site.',
                'content_image_max_upload_mb' => 'The maximum uploaded image size must be between 1 and 200 megabytes.',
                'content_image_allowed_mimes' => 'Select at least one allowed content image type.',
                'content_images_disabled' => 'Image uploads are currently disabled for this site.',
                'public_theme_key' => 'The author-space public theme is not valid.',
                'public_screen_mode' => 'The author-space screen mode is not valid.',
                'public_theme_content_width' => 'The author-space content width is not valid.',
                'public_theme_landing_page_path' => 'The author-space landing page must be one of your published pages.',
                'public_theme_setting_content_width' => 'The author-space content width is not valid.',
                'public_theme_setting_landing_page_path' => 'The author-space landing page must be one of your published pages.',
                'public_theme_setting_general_grid_blocks' => 'The author-space block-grid size is not valid.',
                'public_theme_setting_featured_blocks' => 'The author-space featured-row size is not valid.',
                'public_banner_source' => 'Choose whether your author-space banner should inherit the site banner, use none, or use a custom image.',
                'public_banner_image' => 'Choose a custom author-space banner image, or change the source away from custom.',
                'public_banner_image_upload' => 'That author-space banner upload did not complete successfully.',
                'public_banner_image_type' => 'Only JPEG, PNG, GIF, WebP, AVIF, and SVG images are supported for author-space banners.',
                'public_background_source' => 'Choose whether your author-space background should inherit the system background, use none, or use a custom image.',
                'public_background_render_mode' => 'The author-space background display mode is not valid.',
                'public_background_image' => 'Choose a custom author-space background image, or change the source away from custom.',
                'public_background_image_upload' => 'That author-space background upload did not complete successfully.',
                'public_background_image_type' => 'Only JPEG, PNG, GIF, WebP, AVIF, and SVG images are supported for author-space backgrounds.',
                'landing_page_path' => 'The landing page must be one of the published root pages.',
                'site_background_render_mode' => 'The site background display mode is not valid.',
                'autosave_mode' => 'The autosave mode is not valid.',
                'autosave_interval_seconds' => 'The user autosave interval must be between 30 and 180 seconds, or blank to inherit the site default.',
                'classic_smileys_mode' => 'The classic smiley mode is not valid.',
                'smtp_host' => 'SMTP host is required when SMTP delivery is enabled.',
                'smtp_encryption' => 'The SMTP encryption mode is not valid.',
                'smtp_port' => 'SMTP port must be between 1 and 65535.',
                'smtp_from_email' => 'The SMTP from-address is not valid.',
                'smtp_reply_to_email' => 'The SMTP Reply-To address is not valid.',
                'smtp_test_email' => 'The SMTP test-recipient address is not valid.',
                'smtp_disabled' => 'Enable SMTP delivery before sending a test e-mail.',
                'notification_retention_days' => 'Notification retention must be between 7 and 60 days.',
                'menu_item_path' => 'Choose one of the published root pages for that menu item.',
                'menu_item_url' => 'Menu URLs must start with / or use http/https.',
                'custom_css_length' => 'Custom CSS is too large.',
                'custom_css_remote' => 'Custom CSS cannot use @import or remote URLs.',
                'filesystem_umask' => 'Use a valid octal umask like 0007 or 0022.',
                'password_reset_identifier' => 'Enter your username or e-mail address.',
                'password_reset_token' => 'That password-reset link is not valid anymore.',
                'password_reset_disabled' => 'Password resets are not available for that account.',
                'delete_current_user' => 'You cannot delete the currently logged-in account.',
                'delete_last_superadmin' => 'You cannot delete the last remaining admin account.',
                'target_username' => 'Choose an existing user to receive the orphaned content.',
                'moderation_status' => 'Only content currently waiting for review can be approved or rejected from the moderation queue.',
                'orphan_author_slug' => 'That orphaned-content bucket was not found.',
                'target_author_slug' => 'Choose a different destination author for orphan reassignment.',
                'orphan_conflict' => 'The target author already has one or more conflicting content slugs.',
                default => 'The form contains an invalid value for ' . $field . '.',
            }
        );
    }

    protected function getLoginSecuritySummaryViewData() : array {
        $summary = $this->security->getLoginHardeningSummary();
        if ( ! is_array( $summary ) ) {
            return( [] );
        }

        $last_failed_at_utc = trim( (string) ( $summary['last_failed_at_utc'] ?? '' ) );
        return(
            [
                'failed_last_hour' => (int) ( $summary['failed_last_hour'] ?? 0 ),
                'failed_last_24_hours' => (int) ( $summary['failed_last_24_hours'] ?? 0 ),
                'throttled_ips' => (int) ( $summary['throttled_ips'] ?? 0 ),
                'throttled_usernames' => (int) ( $summary['throttled_usernames'] ?? 0 ),
                'recent_failures' => array_map(
                    fn( array $failure ) : array => $failure + [
                        'failed_at_display' => $this->formatUtcDateTime( (string) ( $failure['failed_at_utc'] ?? '' ) ),
                    ],
                    is_array( $summary['recent_failures'] ?? null ) ? $summary['recent_failures'] : []
                ),
                'last_failed_at_utc' => $last_failed_at_utc,
                'last_failed_at_display' => $this->formatUtcDateTime( $last_failed_at_utc ),
            ]
        );
    }

    protected function getRoleDisplayLabel( string $role ) : string {
        $role = strtolower( trim( $role ) );

        return(
            match ( $role ) {
                'superadmin' => 'Admin',
                'creator' => 'Author',
                default => ucfirst( $role !== '' ? $role : 'User' ),
            }
        );
    }
}
