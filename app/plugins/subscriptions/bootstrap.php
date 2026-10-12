<?php

require_once __DIR__ . '/TinyMashSubscriptionsService.php';
require_once dirname( __DIR__, 2 ) . '/classes/TinyMashPluginStateBackup.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashConfigIO;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashMailer;
use app\classes\TinyMashNotificationService;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashPluginStateBackup;
use app\classes\TinyMashSecretLinkService;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'subscriptions' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $content_repository = $plugins->getService( 'content.repository' );
    $user_repository = $plugins->getService( 'user.repository' );
    $theme = $plugins->getService( 'theme' );
    $security = $plugins->getService( 'security' );
    $mailer = $plugins->getService( 'mailer' );
    $secret_link_service = $plugins->getService( 'secret_links' );
    $date_formatter = $plugins->getService( 'date.formatter' );
    $notification_service = \Flight::app()->has( 'notification.service' ) ? \Flight::app()->get( 'notification.service' ) : null;

    if (
        ! $config instanceof TinyMashConfig
        || ! $content_repository instanceof TinyMashContentRepository
        || ! $user_repository instanceof TinyMashUserRepository
        || ! $security instanceof TinyMashSecurity
        || ! $mailer instanceof TinyMashMailer
    ) {
        throw new \RuntimeException( 'Required services for the Subscriptions plugin are not available.' );
    }
    if ( ! $date_formatter instanceof TinyMashDateFormatter ) {
        $date_formatter = null;
    }
    if ( ! $notification_service instanceof TinyMashNotificationService ) {
        $notification_service = null;
    }
    if ( ! $secret_link_service instanceof TinyMashSecretLinkService ) {
        $secret_link_service = null;
    }

    $service = new TinyMashSubscriptionsService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'subscriptions',
        $config,
        $content_repository,
        $user_repository,
        $theme,
        $mailer,
        $notification_service,
        $secret_link_service
    );
    $service->initialize();
    \Flight::set( 'plugin.subscriptions.service', $service );
    $backup = new TinyMashPluginStateBackup(
        'subscriptions',
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'subscriptions'
    );
    $plugins->registerExportContributor(
        $plugin_key,
        static function( array $context ) use ( $backup ) : string {
            $count = $backup->export(
                'site',
                (string) ( $context['plugins_directory'] ?? '' ) . DIRECTORY_SEPARATOR . 'subscriptions'
            );
            return( 'Exported ' . $count . ' Subscriptions state file(s).' );
        },
        'site',
        'Subscriptions data'
    );
    $plugins->registerImportContributor(
        $plugin_key,
        static function( array $context ) use ( $backup ) : string {
            $count = $backup->import(
                'site',
                (string) ( $context['plugins_directory'] ?? '' ) . DIRECTORY_SEPARATOR . 'subscriptions',
                '',
                ! empty( $context['replace_existing'] )
            );
            return( 'Imported ' . $count . ' Subscriptions state file(s).' );
        },
        'site',
        'Subscriptions data'
    );

    $plugin_settings = $service->normalizeSettings( $plugins->getPluginSystemSettings( $plugin_key ) );
    $plugins->registerPublicAsset( $plugin_key, 'css', '/plugins/subscriptions/subscriptions.css' );
    $plugins->registerShortcode(
        $plugin_key,
        'subscribe',
        static function( array $shortcode ) use ( $service, $plugin_settings ) : array {
            $context = is_array( $shortcode['context'] ?? null ) ? $shortcode['context'] : [];
            if ( ! empty( $context['static_export'] ) || in_array( (string) ( $context['surface'] ?? '' ), [ 'summary', 'static_export' ], true ) ) {
                return( [ 'html' => '' ] );
            }
            $scope = $service->signupFormScopeForContext( $context );
            return( [ 'html' => $service->renderSignupForm( $scope, '/subscriptions/subscribe', $plugin_settings ) ] );
        },
        [ 'label' => 'Subscribe', 'description' => 'Shows a confirmed e-mail signup form.', 'example' => '[subscribe]', 'dynamic' => false ]
    );
    if ( ! empty( $plugin_settings['footer_form_enabled'] ) ) {
        $plugins->registerPublicSlotRenderer(
            $plugin_key,
            'footer',
            static function( array $context = [] ) use ( $service, $plugin_settings ) : string {
                if ( ! empty( $context['static_export'] ) ) {
                    return( '' );
                }
                $author_slug = strtolower( trim( (string) ( $context['author_slug'] ?? '' ) ) );
                $scope = $author_slug !== '' ? 'author:' . $author_slug : 'site';
                return( $service->renderSignupForm( $scope, '/subscriptions/subscribe', $plugin_settings ) );
            }
        );
    }

    $plugins->registerContentLifecycleHandler(
        $plugin_key,
        static function( ?array $previous_entry, ?array $saved_entry, array $context = [] ) use ( $plugins, $plugin_key, $service ) : array {
            if ( ! is_array( $saved_entry ) ) {
                return( [ 'queued' => 0, 'reason' => 'missing_entry' ] );
            }
            return( $service->queuePublication( $previous_entry, $saved_entry, $plugins->getPluginSystemSettings( $plugin_key ), $context ) );
        }
    );
    $plugins->registerHousekeepingTask(
        $plugin_key,
        static function( array $context = [] ) use ( $plugins, $plugin_key, $service ) : string {
            $result = $service->runQueue( $plugins->getPluginSystemSettings( $plugin_key ), $context );
            $service->prune();
            return( (string) ( $result['message'] ?? 'No subscription deliveries were due.' ) );
        },
        'delivery',
        'Process subscription e-mail queue'
    );

    if ( $plugins->isCliRuntime() ) {
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'subscriptions',
                'summary' => 'Run the subscription e-mail queue.',
                'arguments' => [ [ 'name' => 'action', 'required' => true, 'description' => 'Operation to run', 'choices' => [ 'run-queue' ] ] ],
                'examples' => [ 'php8.4 bin/tinymash.php subscriptions run-queue' ],
                'order' => 235,
                'handler' => static function() use ( $plugins, $plugin_key, $service ) : void {
                    $result = $service->runQueue( $plugins->getPluginSystemSettings( $plugin_key ), [ 'trigger' => 'cli' ] );
                    fwrite( STDOUT, 'claimed: ' . (int) ( $result['claimed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'delivered: ' . (int) ( $result['delivered'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'retried: ' . (int) ( $result['retried'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'failed: ' . (int) ( $result['failed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'suppressed: ' . (int) ( $result['suppressed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'message: ' . (string) ( $result['message'] ?? '' ) . PHP_EOL );
                },
            ]
        );
        return;
    }
    if ( ! $theme instanceof TinyMashTheme ) {
        throw new \RuntimeException( 'The public theme service for the Subscriptions plugin is not available.' );
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/subscriptions';
    $escape = static fn( string $value ) : string => htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    $format_date = static function( string $value ) use ( $date_formatter ) : string {
        if ( $value === '' ) {
            return( '-' );
        }
        return( $date_formatter instanceof TinyMashDateFormatter ? $date_formatter->formatUtcDateTime( $value ) : $value );
    };

    $plugins->registerAdminNavigationItem( $plugin_key, 'author', [ 'section' => 'subscriptions', 'label' => 'Subscriptions', 'url' => $plugin_url, 'icon' => 'bi-envelope', 'order' => 84 ] );
    $plugins->registerAdminNavigationItem( $plugin_key, 'admin', [ 'section' => 'subscriptions', 'label' => 'Subscriptions', 'url' => $plugin_url, 'icon' => 'bi-envelope', 'order' => 84 ] );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );
    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-subscriptions',
        [
            'title' => 'Subscriptions',
            'summary' => 'Send confirmed readers an e-mail when a post is first published.',
            'documentation_url' => 'https://tinymash.joho.se/docs/plugins/subscriptions',
            'documentation_label' => 'Subscriptions',
            'group' => 'Plugins',
            'order' => 145,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [ 'title' => 'Consent', 'markdown' => 'Public signup requires e-mail confirmation. A manual add is active immediately and must only be used when the address owner has already agreed to receive these messages.' ],
                [ 'title' => 'Delivery', 'markdown' => 'New-post messages are queued and sent by housekeeping or `subscriptions run-queue`. Editing a published post does not send it again.' ],
            ],
        ]
    );

    $resolve_scope = static function( array $data = [] ) use ( $security, $service, $user_repository ) : string {
        $username = strtolower( trim( (string) $security->getCurrentUsername() ) );
        if ( ! $security->isSuperAdmin() ) {
            return( 'author:' . $username );
        }
        $scope = $service->normalizeScope( (string) ( $data['scope'] ?? 'site' ) );
        if ( str_starts_with( $scope, 'author:' ) && ! is_array( $user_repository->getUserByAuthorSlug( substr( $scope, 7 ) ) ) ) {
            return( '' );
        }
        return( $scope );
    };

    $render_admin = static function( string $scope, array $notice = [] ) use ( $plugins, $plugin_key, $plugin_url, $security, $service, $user_repository, $escape, $format_date ) : void {
        $is_admin = $security->isSuperAdmin();
        $summary = $service->getSummary();
        $subscribers = $service->getSubscribersForScope( $scope );
        $settings = $service->normalizeSettings( $plugins->getPluginSystemSettings( $plugin_key ) );
        $author_settings = str_starts_with( $scope, 'author:' ) ? $service->getAuthorSettings( substr( $scope, 7 ) ) : [];
        $body = '<div class="d-grid gap-4" style="grid-template-columns: minmax(0, 1fr); min-width: 0;">';
        $body .= '<section><div class="d-flex flex-wrap gap-3 small text-body-secondary"><span>Confirmed: <strong>' . (int) $summary['confirmed'] . '</strong></span><span>Pending: <strong>' . (int) $summary['pending'] . '</strong></span><span>Queued: <strong>' . (int) $summary['queued'] . '</strong></span><span>Retrying: <strong>' . (int) $summary['retrying'] . '</strong></span><span>Failed: <strong>' . (int) $summary['failed'] . '</strong></span></div>';
        $body .= '</section>';
        if ( $author_settings !== [] && ( empty( $author_settings['signup_enabled'] ) || empty( $author_settings['publishing_enabled'] ) ) ) {
            $disabled_parts = [];
            if ( empty( $author_settings['signup_enabled'] ) ) {
                $disabled_parts[] = 'new subscriptions';
            }
            if ( empty( $author_settings['publishing_enabled'] ) ) {
                $disabled_parts[] = 'new-post notifications';
            }
            $body .= '<p class="small text-body-secondary mb-0">' . $escape( ucfirst( implode( ' and ', $disabled_parts ) ) ) . ' are off for this author. Subscriber records remain saved.</p>';
        }

        if ( $is_admin ) {
            $body .= '<section class="bg-body-secondary rounded-3 p-3"><h2 class="h5">Site settings</h2><form method="post" action="' . $escape( $plugin_url . '/settings' ) . '" class="d-grid gap-3"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
            foreach ( [ 'signup_enabled' => 'Accept new subscriptions', 'delivery_enabled' => 'Notify subscribers about new posts', 'site_scope_enabled' => 'Allow site-wide subscriptions', 'footer_form_enabled' => 'Show signup in public footers', 'owner_notifications_enabled' => 'Notify the site owner after confirmation', 'paused' => 'Pause signup and delivery' ] as $key => $label ) {
                $body .= '<div class="form-check"><input class="form-check-input" id="tm-subscriptions-' . $escape( $key ) . '" name="' . $escape( $key ) . '" type="checkbox" value="1"' . ( ! empty( $settings[$key] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-subscriptions-' . $escape( $key ) . '">' . $escape( $label ) . '</label></div>';
            }
            $body .= '<div><label class="form-label" for="tm-subscriptions-limit">Maximum confirmed addresses</label><input class="form-control" id="tm-subscriptions-limit" name="recipient_limit" type="number" min="1" max="2000" value="' . (int) $settings['recipient_limit'] . '"><div class="form-text">Direct SMTP delivery is intended for smaller lists.</div></div><div><button class="btn btn-primary" type="submit">Save settings</button></div></form>';
            $body .= '</section>';
        }

        if ( $is_admin ) {
            $body .= '<section><h2 class="h5">Space</h2><form method="get" action="' . $escape( $plugin_url ) . '" class="d-flex flex-wrap gap-2 align-items-end"><div><label class="form-label" for="tm-subscriptions-scope">Managed space</label><select class="form-select" id="tm-subscriptions-scope" name="scope"><option value="site"' . ( $scope === 'site' ? ' selected' : '' ) . '>Root and site-wide</option>';
            foreach ( $user_repository->getAllUsers() as $user ) {
                $author_slug = strtolower( trim( (string) ( $user['author_slug'] ?? $user['username'] ?? '' ) ) );
                if ( $author_slug === '' || str_starts_with( $author_slug, '_deleted_' ) ) {
                    continue;
                }
                $author_scope = 'author:' . $author_slug;
                $body .= '<option value="' . $escape( $author_scope ) . '"' . ( $scope === $author_scope ? ' selected' : '' ) . '>' . $escape( $user_repository->getDisplayLabelByAuthorSlug( $author_slug ) ) . '</option>';
            }
            $body .= '</select></div><button class="btn btn-outline-secondary" type="submit">Open</button></form></section>';
        }

        if ( str_starts_with( $scope, 'author:' ) ) {
            $body .= '<section class="bg-body-secondary rounded-3 p-3"><h2 class="h5">Space settings</h2><form method="post" action="' . $escape( $plugin_url . '/space-settings' ) . '" class="d-grid gap-3"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="scope" value="' . $escape( $scope ) . '">';
            foreach ( [ 'signup_enabled' => 'Accept new subscriptions', 'publishing_enabled' => 'Notify subscribers about new posts' ] as $key => $label ) {
                $body .= '<div class="form-check"><input class="form-check-input" id="tm-subscriptions-space-' . $escape( $key ) . '" name="' . $escape( $key ) . '" type="checkbox" value="1"' . ( ! empty( $author_settings[$key] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-subscriptions-space-' . $escape( $key ) . '">' . $escape( $label ) . '</label></div>';
            }
            $body .= '<div><button class="btn btn-primary" type="submit">Save space settings</button></div></form></section>';
        }

        $body .= '<section class="bg-body-secondary rounded-3 p-3"><h2 class="h5">Add subscriber</h2><p class="small text-body-secondary">The subscription becomes active immediately. Add an address only when its owner has agreed to receive these messages.</p><form method="post" action="' . $escape( $plugin_url . '/add' ) . '" class="row g-3 align-items-end"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="scope" value="' . $escape( $scope ) . '"><div class="col-12 col-lg-7"><label class="form-label" for="tm-subscriptions-add-email">E-mail address</label><input class="form-control" id="tm-subscriptions-add-email" name="email" type="email" autocomplete="email" required></div><div class="col-12"><div class="form-check"><input class="form-check-input" id="tm-subscriptions-consent" name="consent_confirmed" type="checkbox" value="1" required><label class="form-check-label" for="tm-subscriptions-consent">The address owner has agreed to receive these messages.</label></div></div><div class="col-12"><button class="btn btn-primary" type="submit">Add subscriber</button></div></form></section>';

        $body .= '<section><h2 class="h5">Subscribers</h2>';
        if ( empty( $subscribers ) ) {
            $body .= '<p class="text-body-secondary mb-0">No subscribers are recorded for this space.</p>';
        } else {
            $body .= '<div class="table-responsive"><table class="table align-middle"><thead><tr><th scope="col">Address</th><th scope="col">Status</th><th scope="col">Consent</th><th scope="col">Recorded</th><th scope="col">Actions</th></tr></thead><tbody>';
            foreach ( $subscribers as $subscriber ) {
                $body .= '<tr><td>' . $escape( (string) $subscriber['email'] ) . '</td><td>' . $escape( ucfirst( (string) $subscriber['status'] ) ) . '</td><td>' . $escape( str_replace( '_', ' ', (string) $subscriber['consent_source'] ) ) . '</td><td>' . $escape( $format_date( (string) $subscriber['consent_recorded_at_utc'] ) ) . '</td><td class="text-end">';
                if ( (string) $subscriber['status'] !== 'unsubscribed' ) {
                    $body .= '<form method="post" action="' . $escape( $plugin_url . '/remove' ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="subscriber_id" value="' . $escape( (string) $subscriber['id'] ) . '"><input type="hidden" name="scope" value="' . $escape( $scope ) . '"><button class="btn btn-sm btn-outline-danger" type="submit">Remove</button></form>';
                }
                $body .= '</td></tr>';
            }
            $body .= '</tbody></table></div>';
        }
        $body .= '</section></div>';

        $plugins->renderAdminPage( [ 'title' => APP_NAME . APP_TITLE_SEP . 'Subscriptions', 'current_section' => 'subscriptions', 'current_location' => 'subscriptions', 'plugin_page_kicker' => 'Subscriptions', 'plugin_page_title' => $service->getScopeLabel( $scope ), 'plugin_page_summary' => 'Confirmed signup and queued new-post e-mail delivery.', 'plugin_page_notice' => $notice, 'plugin_page_body_html' => $body ] );
    };

    $render_public = static function( string $title, string $message, string $form_html = '', string $author_slug = '' ) use ( $plugins, $theme ) : void {
        header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        $view = $theme->getBaseViewData( $title, null, $author_slug !== '' ? $author_slug : null );
        $view['theme_public_sidebar_fragments'] = [];
        $view['theme_public_footer_fragments'] = [];
        $plugins->renderPublicPage( 'public/subscriptions.latte', array_merge( $view, [ 'subscriptions_title' => $title, 'subscriptions_message' => $message, 'subscriptions_form_html' => $form_html, 'subscriptions_return_url' => $author_slug !== '' ? $theme->getAuthorURL( $author_slug ) : '/' ] ) );
    };

    $plugins->registerRoute( $plugin_key, 'get', $plugin_url, static function() use ( $plugins, $security, $login_url, $resolve_scope, $render_admin ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }
        $scope = $resolve_scope( $plugins->getRequestQueryData() );
        $render_admin( $scope !== '' ? $scope : 'site' );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/settings', static function() use ( $plugins, $plugin_key, $plugin_url, $security, $render_admin ) : void {
        if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            return;
        }
        $data = $plugins->getRequestData();
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_admin( 'site', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            return;
        }
        $limit = (int) ( $data['recipient_limit'] ?? 500 );
        if ( $limit < 1 || $limit > 2000 ) {
            $render_admin( 'site', [ 'type' => 'danger', 'message' => 'The confirmed-address limit must be between 1 and 2000.' ] );
            return;
        }
        $config_io = new TinyMashConfigIO();
        if ( ! $config_io->getConfig() || ! $config_io->updatePluginSettings( $plugin_key, [ 'signup_enabled' => ! empty( $data['signup_enabled'] ), 'delivery_enabled' => ! empty( $data['delivery_enabled'] ), 'site_scope_enabled' => ! empty( $data['site_scope_enabled'] ), 'footer_form_enabled' => ! empty( $data['footer_form_enabled'] ), 'owner_notifications_enabled' => ! empty( $data['owner_notifications_enabled'] ), 'paused' => ! empty( $data['paused'] ), 'recipient_limit' => $limit ] ) ) {
            $render_admin( 'site', [ 'type' => 'danger', 'message' => 'Subscription settings could not be saved.' ] );
            return;
        }
        ( new TinyMashPublicPageCache( dirname( __DIR__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'public-page-cache' ) )->clear();
        $plugins->redirect( $plugin_url );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/space-settings', static function() use ( $plugins, $plugin_url, $security, $service, $resolve_scope, $render_admin ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( \Flight::get( 'login.url' ) ?: '/login' ) );
            return;
        }
        $data = $plugins->getRequestData();
        $scope = $resolve_scope( $data );
        if ( ! str_starts_with( $scope, 'author:' ) ) {
            $render_admin( $scope ?: 'site', [ 'type' => 'danger', 'message' => 'Select an existing author space.' ] );
            return;
        }
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_admin( $scope, [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            return;
        }
        if ( ! $service->updateAuthorSettings( substr( $scope, 7 ), $data, (string) $security->getCurrentUsername(), $security->isSuperAdmin() ) ) {
            $render_admin( $scope, [ 'type' => 'danger', 'message' => 'Space settings could not be saved.' ] );
            return;
        }
        ( new TinyMashPublicPageCache( dirname( __DIR__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'public-page-cache' ) )->clear();
        $plugins->redirect( $plugin_url . '?scope=' . rawurlencode( $scope ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/add', static function() use ( $plugins, $plugin_key, $plugin_url, $security, $service, $resolve_scope, $render_admin ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( \Flight::get( 'login.url' ) ?: '/login' ) );
            return;
        }
        $data = $plugins->getRequestData();
        $scope = $resolve_scope( $data );
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_admin( $scope, [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            return;
        }
        if ( $scope === '' ) {
            $render_admin( 'site', [ 'type' => 'danger', 'message' => 'Select an existing space.' ] );
            return;
        }
        if ( empty( $data['consent_confirmed'] ) ) {
            $render_admin( $scope, [ 'type' => 'danger', 'message' => 'Confirm that the address owner agreed to receive these messages.' ] );
            return;
        }
        try {
            $service->addManualSubscriber( (string) ( $data['email'] ?? '' ), $scope, (string) $security->getCurrentUsername(), $security->isSuperAdmin(), $plugins->getPluginSystemSettings( $plugin_key ) );
        } catch ( \InvalidArgumentException ) {
            $render_admin( $scope, [ 'type' => 'danger', 'message' => 'Enter a valid e-mail address.' ] );
            return;
        } catch ( \RuntimeException $e ) {
            $message = $e->getMessage() === 'recipient_limit' ? 'The confirmed-address limit has been reached.' : 'You do not have permission to manage that space.';
            $render_admin( $scope, [ 'type' => 'danger', 'message' => $message ] );
            return;
        }
        $plugins->redirect( $plugin_url . '?scope=' . rawurlencode( $scope ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/remove', static function() use ( $plugins, $plugin_url, $security, $service, $resolve_scope, $render_admin ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( \Flight::get( 'login.url' ) ?: '/login' ) );
            return;
        }
        $data = $plugins->getRequestData();
        $scope = $resolve_scope( $data );
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_admin( $scope, [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            return;
        }
        if ( $scope === '' ) {
            $render_admin( 'site', [ 'type' => 'danger', 'message' => 'Select an existing space.' ] );
            return;
        }
        if ( ! $service->removeSubscriberScope( (string) ( $data['subscriber_id'] ?? '' ), $scope, (string) $security->getCurrentUsername(), $security->isSuperAdmin() ) ) {
            $render_admin( $scope, [ 'type' => 'danger', 'message' => 'The subscriber could not be removed.' ] );
            return;
        }
        $plugins->redirect( $plugin_url . '?scope=' . rawurlencode( $scope ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', '/subscriptions/subscribe', static function() use ( $plugins, $security, $service, $plugin_settings, $render_public ) : void {
        $data = $plugins->getRequestData();
        if ( mb_trim( (string) ( $data['website'] ?? '' ) ) === '' ) {
            $service->requestPublicSignup( (string) ( $data['email'] ?? '' ), (string) ( $data['scope'] ?? '' ), $security->getCurrentIpAddress(), $plugin_settings );
        }
        $render_public( 'Check your e-mail', 'If the address can be subscribed, a confirmation link will be sent.' );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/subscriptions/confirm', static function() use ( $plugins, $security, $service, $escape, $render_public ) : void {
        $query = $plugins->getRequestQueryData();
        $subscriber = (string) ( $query['subscriber'] ?? '' );
        $scope = (string) ( $query['scope'] ?? '' );
        $token = (string) ( $query['token'] ?? '' );
        $request = $service->getConfirmation( $subscriber, $scope, $token );
        if ( ! is_array( $request ) ) {
            $render_public( 'Subscription link unavailable', 'This confirmation link is invalid or has expired.' );
            return;
        }
        $form = '<form method="post" action="/subscriptions/confirm" class="tm-subscriptions-form"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="subscriber" value="' . $escape( $subscriber ) . '"><input type="hidden" name="scope" value="' . $escape( $scope ) . '"><input type="hidden" name="token" value="' . $escape( $token ) . '"><button type="submit">Confirm subscription</button></form>';
        $render_public( 'Confirm subscription', 'Confirm that you want to receive ' . $service->getScopeLabel( $scope ) . '.', $form, str_starts_with( $scope, 'author:' ) ? substr( $scope, 7 ) : '' );
    } );
    $plugins->registerRoute( $plugin_key, 'post', '/subscriptions/confirm', static function() use ( $plugins, $plugin_key, $security, $service, $render_public ) : void {
        $data = $plugins->getRequestData();
        $scope = (string) ( $data['scope'] ?? '' );
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_public( 'Subscription not confirmed', 'The form expired. Open the link from your e-mail and try again.' );
            return;
        }
        $confirmed = $service->confirmSubscription( (string) ( $data['subscriber'] ?? '' ), $scope, (string) ( $data['token'] ?? '' ), $plugins->getPluginSystemSettings( $plugin_key ) );
        $render_public( $confirmed ? 'Subscription confirmed' : 'Subscription not confirmed', $confirmed ? 'You will receive an e-mail when a new post is published.' : 'This confirmation link is invalid, expired, or the subscriber limit has been reached.', '', str_starts_with( $scope, 'author:' ) ? substr( $scope, 7 ) : '' );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/subscriptions/unsubscribe', static function() use ( $plugins, $service, $escape, $render_public ) : void {
        $query = $plugins->getRequestQueryData();
        $subscriber = (string) ( $query['subscriber'] ?? '' );
        $scope = (string) ( $query['scope'] ?? '' );
        $token = (string) ( $query['token'] ?? '' );
        if ( ! is_array( $service->getUnsubscribeRequest( $subscriber, $scope, $token ) ) ) {
            $render_public( 'Unsubscribe link unavailable', 'This unsubscribe link is invalid or has already been used.' );
            return;
        }
        $form = '<form method="post" action="/subscriptions/unsubscribe" class="tm-subscriptions-form"><input type="hidden" name="subscriber" value="' . $escape( $subscriber ) . '"><input type="hidden" name="scope" value="' . $escape( $scope ) . '"><input type="hidden" name="token" value="' . $escape( $token ) . '"><button type="submit">Unsubscribe</button></form>';
        $render_public( 'Unsubscribe', 'Stop receiving ' . $service->getScopeLabel( $scope ) . '.', $form, str_starts_with( $scope, 'author:' ) ? substr( $scope, 7 ) : '' );
    } );
    $plugins->registerRoute( $plugin_key, 'post', '/subscriptions/unsubscribe', static function() use ( $plugins, $service, $render_public ) : void {
        $data = array_merge( $plugins->getRequestQueryData(), $plugins->getRequestData() );
        $scope = (string) ( $data['scope'] ?? '' );
        $removed = $service->unsubscribe( (string) ( $data['subscriber'] ?? '' ), $scope, (string) ( $data['token'] ?? '' ) );
        $render_public( $removed ? 'Unsubscribed' : 'Unsubscribe link unavailable', $removed ? 'You will no longer receive these notifications.' : 'This unsubscribe link is invalid or has already been used.', '', str_starts_with( $scope, 'author:' ) ? substr( $scope, 7 ) : '' );
    } );
};
