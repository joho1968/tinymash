<?php
namespace app\controllers;

use app\classes\TinyMashMailer;
use app\classes\TinyMashPasswordResetService;

trait AdminAuthConcern {

    public function isLoggedIn() : bool {
        return( $this->security->isLoggedIn() );
    }

    protected function getLoginViewData( string $admin_username = '', string $login_error = '', string $return_url = '', string $login_notice = '' ) : array {
        $system_settings = $this->config->getSystemSettings();
        $password_reset_allowed = ! empty( $system_settings['allow_admin_password_resets'] ) || ! empty( $system_settings['allow_author_password_resets'] );
        $login_screen_message = (string) ( $system_settings['login_message'] ?? '' );
        $markdown_renderer = $this->markdown_renderer;
        if ( ! $markdown_renderer instanceof \app\classes\TinyMashMarkdownRenderer && $this->app->has( 'markdown.renderer' ) ) {
            $markdown_renderer = $this->app->get( 'markdown.renderer' );
        }
        if ( $markdown_renderer instanceof \app\classes\TinyMashMarkdownRenderer ) {
            $login_screen_message = $markdown_renderer->replaceEmojiShortcodes( $login_screen_message );
        }

        return(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Login',
                'app_url' => $this->app->get( 'app.url' ),
                'admin_url' => $this->app->get( 'admin.url' ),
                'login_url' => $this->app->get( 'login.url' ),
                'admin_username' => $admin_username,
                'login_error' => $login_error,
                'login_notice' => $login_notice,
                'return_url' => $return_url,
                'maintenance_notice' => $this->config->getMaintenance() ? 'Maintenance mode is active. Only admins can log in right now.' : '',
                'login_screen_message' => $login_screen_message,
                'password_reset_allowed' => $password_reset_allowed,
                'password_reset_url' => (string) $this->config->configGetAdminURL() . '/password-reset',
                'csrf_token' => $this->security->getCsrfToken(),
                'current_location' => 'login',
                'active_field' => 0,
            ]
        );
    }

    public function loginForm() : void {
        $return_url = $this->getLoginReturnUrlFromRequest();
        if ( $this->security->isLoggedIn() ) {
            $this->app->redirect( $return_url !== '' ? $return_url : $this->config->configGetAdminURL() );
            return;
        }
        $restore_notice = $this->security->pullFlash( 'backup.restore_login_notice', [] );
        $login_notice = is_array( $restore_notice ) && array_key_exists( 'plugin_warnings', $restore_notice )
            ? 'Site restore completed. Sign in with an account from the restored backup.'
            : '';
        $plugin_warning_count = max( 0, (int) ( is_array( $restore_notice ) ? ( $restore_notice['plugin_warnings'] ?? 0 ) : 0 ) );
        if ( $plugin_warning_count > 0 ) {
            $login_notice .= ' ' . $plugin_warning_count . ' plugin import' . ( $plugin_warning_count === 1 ? '' : 's' ) . ' reported a warning; review plugin state after login.';
        }
        $this->renderAdmin( 'login', $this->getLoginViewData( '', '', $return_url, $login_notice ) );
    }

    public function login() : void {
        $admin_username = '';
        $login_error = 'Unable to login with those credentials.';
        $return_url = '';
        try {
            $data = $this->app->request()->data->getData();
            if ( ! is_array( $data ) ) {
                $data = [];
            }
            $return_url = $this->sanitizeLoginReturnUrl( (string) ( $data['return_url'] ?? $this->getLoginReturnUrlFromRequest() ) );

            if ( ! empty( $data['tinymash-admin_username'] ) ) {
                $admin_username = mb_trim( (string) $data['tinymash-admin_username'] );
            }

            if ( ! empty( $data['email'] ) ) {
                $this->renderAdmin( 'login', $this->getLoginViewData( '', $login_error, $return_url ) );
                return;
            }

            if ( ! $this->isValidCsrfSubmission( $data ) ) {
                $this->renderAdmin( 'login', $this->getLoginViewData( $admin_username, 'Your session token is invalid. Please try again.', $return_url ) );
                return;
            }

            if ( empty( $admin_username ) || empty( $data['tinymash-admin_password'] ) ) {
                $this->renderAdmin( 'login', $this->getLoginViewData( $admin_username, $login_error, $return_url ) );
                return;
            }

            if ( ! $this->security->login( $admin_username, (string) $data['tinymash-admin_password'] ) ) {
                $login_error = $this->getLoginErrorMessage();
                $this->renderAdmin( 'login', $this->getLoginViewData( $admin_username, $login_error, $return_url ) );
                return;
            }

            if ( $this->config->getMaintenance() && ! $this->security->isSuperAdmin() ) {
                $this->security->logout();
                $this->renderAdmin( 'login', $this->getLoginViewData( $admin_username, 'Maintenance mode is active. Only admins can log in right now.', $return_url ) );
                return;
            }

            $this->app->redirect( $return_url !== '' ? $return_url : $this->config->configGetAdminURL() );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': **EXCEPTION** ' . $e->getMessage() );
        }
        $this->renderAdmin( 'login', $this->getLoginViewData( $admin_username, $login_error, $return_url ) );
    }

    public function passwordResetRequestForm() : void {
        $system_settings = $this->config->getSystemSettings();
        if ( empty( $system_settings['allow_admin_password_resets'] ) && empty( $system_settings['allow_author_password_resets'] ) ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'password-reset-request',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Password reset',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_location' => 'login',
                    'reset_state' => 'disabled',
                    'reset_message' => 'Password resets are not enabled for this site.',
                    'login_url' => $this->config->configGetLoginURL(),
                    'request_url' => (string) $this->config->configGetAdminURL() . '/password-reset',
                    'csrf_token' => $this->security->getCsrfToken(),
                    'identifier' => '',
                ]
            );
            return;
        }

        $flash_notice = $this->security->pullFlash( 'password_reset.request_notice', [] );
        $flash_identifier = trim( (string) $this->security->pullFlash( 'password_reset.request_identifier', '' ) );
        $this->renderAdmin(
            'password-reset-request',
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Password reset',
                'app_url' => $this->app->get( 'app.url' ),
                'admin_url' => $this->app->get( 'admin.url' ),
                'current_location' => 'login',
                'reset_state' => is_array( $flash_notice ) ? (string) ( $flash_notice['type'] ?? 'info' ) : 'info',
                'reset_message' => is_array( $flash_notice ) ? (string) ( $flash_notice['message'] ?? '' ) : '',
                'login_url' => $this->config->configGetLoginURL(),
                'request_url' => (string) $this->config->configGetAdminURL() . '/password-reset',
                'csrf_token' => $this->security->getCsrfToken(),
                'identifier' => $flash_identifier,
            ]
        );
    }

    public function submitPasswordResetRequest() : void {
        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        $identifier = trim( (string) ( $data['identifier'] ?? '' ) );
        $this->security->setFlash( 'password_reset.request_identifier', $identifier );

        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->security->setFlash(
                'password_reset.request_notice',
                [
                    'type' => 'danger',
                    'message' => 'Your session token is invalid. Please try again.',
                ]
            );
            $this->app->redirect( (string) $this->config->configGetAdminURL() . '/password-reset' );
            return;
        }

        $service = $this->getPasswordResetService();
        $mailer = $this->getMailer();
        $system_settings = $this->config->getSystemSettings();
        if ( $service === null || $mailer === null ) {
            $this->security->setFlash(
                'password_reset.request_notice',
                [
                    'type' => 'danger',
                    'message' => 'Password resets are not available right now.',
                ]
            );
            $this->app->redirect( (string) $this->config->configGetAdminURL() . '/password-reset' );
            return;
        }

        $site_url = rtrim( (string) ( $this->config->configGetBaseURL() ?: $this->app->get( 'app.url' ) ), '/' );
        $result = $service->requestReset(
            $identifier,
            $this->security->getCurrentIpAddress(),
            $system_settings,
            $mailer,
            $this->config->getSiteName(),
            $site_url . (string) $this->config->configGetAdminURL() . '/password-reset/confirm',
            $site_url
        );

        $this->security->setFlash(
            'password_reset.request_notice',
            [
                'type' => ! empty( $result['throttled'] ) ? 'warning' : 'success',
                'message' => (string) ( $result['message'] ?? 'If that account exists and is allowed to reset its password, a reset link will be sent.' ),
            ]
        );
        $this->security->setFlash( 'password_reset.request_identifier', '' );
        $this->app->redirect( (string) $this->config->configGetAdminURL() . '/password-reset' );
    }

    public function passwordResetConfirmForm() : void {
        $username = trim( (string) $this->app->request()->query->username );
        $token = trim( (string) $this->app->request()->query->token );
        $request = $this->getPasswordResetService()?->getResetRequest( $username, $token );
        $flash_notice = $this->security->pullFlash( 'password_reset.confirm_notice', [] );

        $this->renderAdmin(
            'password-reset-confirm',
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Set a new password',
                'app_url' => $this->app->get( 'app.url' ),
                'admin_url' => $this->app->get( 'admin.url' ),
                'current_location' => 'login',
                'reset_state' => is_array( $flash_notice ) && ! empty( $flash_notice['type'] )
                    ? (string) $flash_notice['type']
                    : ( is_array( $request ) ? 'info' : 'danger' ),
                'reset_message' => is_array( $flash_notice ) && ! empty( $flash_notice['message'] )
                    ? (string) $flash_notice['message']
                    : ( is_array( $request ) ? '' : $this->getUserValidationMessage( 'password_reset_token' ) ),
                'login_url' => $this->config->configGetLoginURL(),
                'confirm_url' => (string) $this->config->configGetAdminURL() . '/password-reset/confirm',
                'csrf_token' => $this->security->getCsrfToken(),
                'username' => $username,
                'token' => $token,
                'token_valid' => is_array( $request ),
            ]
        );
    }

    public function submitPasswordResetConfirm() : void {
        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        $username = trim( (string) ( $data['username'] ?? '' ) );
        $token = trim( (string) ( $data['token'] ?? '' ) );
        $redirect_url = (string) $this->config->configGetAdminURL() . '/password-reset/confirm?username=' . rawurlencode( $username ) . '&token=' . rawurlencode( $token );

        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->security->setFlash( 'password_reset.confirm_notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please try again.' ] );
            $this->app->redirect( $redirect_url );
            return;
        }

        $new_password = (string) ( $data['new_password'] ?? '' );
        $confirm_password = (string) ( $data['confirm_password'] ?? '' );
        if ( $new_password === '' ) {
            $this->security->setFlash( 'password_reset.confirm_notice', [ 'type' => 'danger', 'message' => $this->getUserValidationMessage( 'new_password' ) ] );
            $this->app->redirect( $redirect_url );
            return;
        }
        if ( $new_password !== $confirm_password ) {
            $this->security->setFlash( 'password_reset.confirm_notice', [ 'type' => 'danger', 'message' => $this->getUserValidationMessage( 'confirm_password' ) ] );
            $this->app->redirect( $redirect_url );
            return;
        }
        if ( mb_strlen( $new_password ) < $this->config->getMinPasswordLength() ) {
            $this->security->setFlash( 'password_reset.confirm_notice', [ 'type' => 'danger', 'message' => $this->getUserValidationMessage( 'password_length' ) ] );
            $this->app->redirect( $redirect_url );
            return;
        }

        $service = $this->getPasswordResetService();
        if ( $service === null || ! $service->completeReset( $username, $token, $new_password ) ) {
            $this->security->setFlash( 'password_reset.confirm_notice', [ 'type' => 'danger', 'message' => $this->getUserValidationMessage( 'password_reset_token' ) ] );
            $this->app->redirect( $redirect_url );
            return;
        }

        $this->security->setFlash( 'password_reset.request_notice', [ 'type' => 'success', 'message' => 'Your password has been reset. You can now log in with the new password.' ] );
        $this->app->redirect( (string) $this->config->configGetLoginURL() );
    }

    protected function getLoginReturnUrlFromRequest() : string {
        $query = $this->app->request()->query->getData();
        if ( is_array( $query ) && isset( $query['return'] ) ) {
            return( $this->sanitizeLoginReturnUrl( (string) $query['return'] ) );
        }

        return( '' );
    }

    protected function sanitizeLoginReturnUrl( string $return_url ) : string {
        return(
            \app\classes\TinyMashSecurity::sanitizeAdminReturnUrl(
                $return_url,
                (string) $this->config->configGetAdminURL(),
                (string) $this->config->configGetLoginURL()
            )
        );
    }

    public function logout() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) || ! $this->isValidCsrfSubmission( $data ) ) {
            $this->app->response()->status( 403 );
            $this->app->response()->header( 'Content-Type', 'text/plain; charset=UTF-8' );
            $this->app->response()->write( 'Invalid session token.' );
            return;
        }

        $this->security->logout();
        $this->app->redirect( $this->config->configGetLoginURL() );
    }

    protected function getLoginErrorMessage() : string {
        $attempt_state = $this->security->getLastLoginAttemptState();
        if ( ! is_array( $attempt_state ) || (string) ( $attempt_state['status'] ?? '' ) !== 'throttled' ) {
            return( 'Unable to login with those credentials.' );
        }

        $retry_after_seconds = max( 0, (int) ( $attempt_state['retry_after_seconds'] ?? 0 ) );
        $retry_after_minutes = (int) max( 1, ceil( $retry_after_seconds / 60 ) );
        return( 'Too many failed login attempts. Please wait ' . $retry_after_minutes . ' minute' . ( $retry_after_minutes === 1 ? '' : 's' ) . ' and try again.' );
    }

    protected function getPasswordResetService() : ?TinyMashPasswordResetService {
        if ( ! $this->app->has( 'password_reset.service' ) ) {
            return( null );
        }

        $service = $this->app->get( 'password_reset.service' );
        return( $service instanceof TinyMashPasswordResetService ? $service : null );
    }

    protected function getMailer() : ?TinyMashMailer {
        if ( ! $this->app->has( 'mailer' ) ) {
            return( null );
        }

        $mailer = $this->app->get( 'mailer' );
        return( $mailer instanceof TinyMashMailer ? $mailer : null );
    }

}
