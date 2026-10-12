<?php

require_once __DIR__ . '/TinyMashCommentsService.php';
require_once __DIR__ . '/TinyMashCommentsSubmissionService.php';
require_once __DIR__ . '/TinyMashCommentsPrivacyService.php';
require_once __DIR__ . '/TinyMashCommentsAdminRenderer.php';
require_once __DIR__ . '/TinyMashCommentsPublicRenderer.php';
require_once dirname( __DIR__, 2 ) . '/classes/TinyMashPluginStateBackup.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashConfigIO;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashMailer;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashNotificationService;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashPluginStateBackup;
use app\classes\TinyMashSecretLinkService;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'comments' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $content_repository = $plugins->getService( 'content.repository' );
    $user_repository = $plugins->getService( 'user.repository' );
    $theme = $plugins->getService( 'theme' );
    $security = $plugins->getService( 'security' );
    $mailer = $plugins->getService( 'mailer' );
    $markdown_renderer = $plugins->getService( 'markdown.renderer' );
    $secret_links = $plugins->getService( 'secret_links' );
    $date_formatter = $plugins->getService( 'date.formatter' );
    $notification_service = \Flight::app()->has( 'notification.service' ) ? \Flight::app()->get( 'notification.service' ) : null;
    if (
        ! $config instanceof TinyMashConfig
        || ! $content_repository instanceof TinyMashContentRepository
        || ! $user_repository instanceof TinyMashUserRepository
        || ! $theme instanceof TinyMashTheme
        || ! $security instanceof TinyMashSecurity
        || ! $mailer instanceof TinyMashMailer
        || ! $markdown_renderer instanceof TinyMashMarkdownRenderer
    ) {
        throw new \RuntimeException( 'Required services for the Comments plugin are not available.' );
    }
    if ( ! $secret_links instanceof TinyMashSecretLinkService ) {
        $secret_links = null;
    }
    if ( ! $notification_service instanceof TinyMashNotificationService ) {
        $notification_service = null;
    }
    if ( ! $date_formatter instanceof TinyMashDateFormatter ) {
        $date_formatter = null;
    }

    $storage_directory = $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'comments';
    $service = new TinyMashCommentsService( $storage_directory );
    $service->initialize();
    $backup = new TinyMashPluginStateBackup( 'comments', $storage_directory );
    $plugins->registerExportContributor(
        $plugin_key,
        static function( array $context ) use ( $backup ) : string {
            $scope = (string) ( $context['scope'] ?? '' );
            $author = $scope === 'author' ? (string) ( $context['author_username'] ?? '' ) : '';
            $directory = (string) ( $context['plugins_directory'] ?? '' ) . DIRECTORY_SEPARATOR . 'comments';
            $count = $backup->export( $scope, $directory, $author );
            return( 'Exported ' . $count . ' Comments state file(s).' );
        },
        'both',
        'Comments data'
    );
    $plugins->registerImportContributor(
        $plugin_key,
        static function( array $context ) use ( $backup ) : string {
            $scope = (string) ( $context['scope'] ?? '' );
            $author = $scope === 'author' ? (string) ( $context['author_username'] ?? '' ) : '';
            $directory = (string) ( $context['plugins_directory'] ?? '' ) . DIRECTORY_SEPARATOR . 'comments';
            $count = $backup->import( $scope, $directory, $author, ! empty( $context['replace_existing'] ) );
            return( 'Imported ' . $count . ' Comments state file(s).' );
        },
        'both',
        'Comments data'
    );
    $submission_service = new TinyMashCommentsSubmissionService(
        $storage_directory,
        $service,
        $config,
        $content_repository,
        $user_repository,
        $theme,
        $mailer,
        $notification_service,
        $secret_links
    );
    $submission_service->initialize();
    $privacy_service = new TinyMashCommentsPrivacyService( $storage_directory, $service, $config, $mailer, $notification_service );
    $privacy_service->initialize();
    $public_renderer = new TinyMashCommentsPublicRenderer( $service, $markdown_renderer, $date_formatter );
    \Flight::app()->set( 'plugin.comments.service', $service );
    \Flight::app()->set( 'plugin.comments.submission_service', $submission_service );
    \Flight::app()->set( 'plugin.comments.privacy_service', $privacy_service );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-comments',
        [
            'title' => 'Comments',
            'summary' => 'Moderate reader responses to posts and pages.',
            'documentation_url' => 'https://tinymash.joho.se/docs/plugins/comments',
            'documentation_label' => 'Comments',
            'group' => 'Plugins',
            'order' => 142,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [ 'title' => 'Moderation', 'markdown' => 'Public comments require approval before publication.' ],
                [ 'title' => 'Access', 'markdown' => 'Post and page defaults can differ. A secret link grants content access but does not identify its holder.' ],
                [ 'title' => 'Notifications', 'markdown' => 'Commenters may opt in to direct-reply e-mail. Each message includes a one-click unsubscribe link.' ],
            ],
        ]
    );
    $plugins->registerPublicAsset( $plugin_key, 'css', '/plugins/comments/comments.css' );

    $site_settings = $plugins->getPluginSystemSettings( $plugin_key );
    $get_author_settings = static function( array $entry ) use ( $user_repository ) : array {
        if ( (string) ( $entry['scope'] ?? '' ) !== 'author' ) {
            return( [] );
        }
        return( $user_repository->getPluginSettings( (string) ( $entry['author_slug'] ?? '' ), 'comments' ) );
    };
    $visitor_type = static function( array $entry ) use ( $security, $secret_links ) : string {
        if ( $security->isRequestAuthenticated() ) {
            return( 'signed_in' );
        }
        if ( ! $secret_links instanceof TinyMashSecretLinkService ) {
            return( 'public' );
        }
        if ( (string) ( $entry['scope'] ?? '' ) === 'author' ) {
            return( $secret_links->currentVisitorCanAccessAuthor( (string) ( $entry['author_slug'] ?? '' ) ) ? 'secret_link' : 'public' );
        }
        return( $secret_links->currentVisitorCanAccessSite() ? 'secret_link' : 'public' );
    };
    $visitor_can_access_entry = static function( array $entry ) use ( $config, $security, $secret_links, $user_repository ) : bool {
        $author_slug = (string) ( $entry['author_slug'] ?? '' );
        if ( (string) ( $entry['scope'] ?? '' ) === 'author' && ! $user_repository->isAuthorContentPublic( $author_slug ) ) {
            return( false );
        }
        if ( $config->isSitePublic() || $security->isRequestAuthenticated() ) {
            return( true );
        }
        if ( ! $secret_links instanceof TinyMashSecretLinkService ) {
            return( false );
        }
        return(
            (string) ( $entry['scope'] ?? '' ) === 'author'
                ? $secret_links->currentVisitorCanAccessAuthor( $author_slug )
                : $secret_links->currentVisitorCanAccessSite()
        );
    };

    $plugins->registerPublicEntryRenderer(
        $plugin_key,
        static function( array $entry, array $context = [] ) use ( $plugins, $submission_service, $public_renderer, $site_settings, $get_author_settings, $visitor_type, $security, $theme ) : array {
            $surface = (string) ( $context['surface'] ?? '' );
            if ( ! in_array( $surface, [ 'content', 'static_export' ], true ) ) {
                return( [] );
            }
            $entry_url = $theme->getEntryURL( $entry );
            $query = $surface === 'content' ? $plugins->getRequestQueryData() : [];
            $form_html = $surface === 'content'
                ? $submission_service->renderForm( $entry, $visitor_type( $entry ), $security->getCsrfToken(), $site_settings, $get_author_settings( $entry ), '/comments/submit', (string) ( $query['reply'] ?? '' ), $entry_url, '/comments/privacy' )
                : '';
            $html = $public_renderer->render( $entry, $form_html, $form_html !== '' ? $entry_url : '', $surface === 'content' ? '/comments/privacy' : '' );
            return( $html !== '' ? [ 'after_content_html' => $html ] : [] );
        },
        true
    );

    $plugins->registerHousekeepingTask(
        $plugin_key,
        static function( array $context = [] ) use ( $submission_service, $privacy_service, $site_settings ) : string {
            $result = $submission_service->runQueue( $context );
            $privacy_result = $privacy_service->runQueue( $context );
            $submission_service->prune( $site_settings );
            $privacy_service->prune();
            return( ( (int) $result['delivered'] + (int) $privacy_result['delivered'] ) . ' comment e-mail(s) delivered.' );
        },
        'delivery',
        'Process comment e-mail queue'
    );

    if ( $plugins->isCliRuntime() ) {
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'comments',
                'summary' => 'Run the comment e-mail queue.',
                'arguments' => [ [ 'name' => 'action', 'required' => true, 'description' => 'Operation to run', 'choices' => [ 'run-queue' ] ] ],
                'examples' => [ 'php8.4 bin/tinymash.php comments run-queue' ],
                'order' => 236,
                'handler' => static function() use ( $submission_service, $privacy_service ) : void {
                    $result = $submission_service->runQueue( [ 'trigger' => 'cli' ] );
                    $privacy_result = $privacy_service->runQueue( [ 'trigger' => 'cli' ] );
                    foreach ( [ 'claimed', 'delivered', 'retried', 'failed', 'suppressed' ] as $name ) {
                        fwrite( STDOUT, $name . ': ' . ( (int) ( $result[$name] ?? 0 ) + (int) ( $privacy_result[$name] ?? 0 ) ) . PHP_EOL );
                    }
                },
            ]
        );
        return;
    }

    $escape = static fn( string $value ) : string => htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/comments';
    $admin_renderer = new TinyMashCommentsAdminRenderer();
    $get_account_name = static function( string $username ) use ( $user_repository ) : string {
        $user = $user_repository->getUserByUsername( $username );
        $display_name = is_array( $user ) ? mb_trim( (string) ( $user['display_name'] ?? '' ) ) : '';
        return( $display_name !== '' ? $display_name : $username );
    };
    $plugins->registerEditorTab(
        $plugin_key,
        [
            'key' => 'comments',
            'label' => 'Comments',
            'order' => 85,
            'roles' => [ 'author', 'admin' ],
            'renderer' => static function( array $context = [] ) : string {
                $draft = is_array( $context['draft'] ?? null ) ? $context['draft'] : [];
                $settings = is_array( $draft['plugin_settings']['comments'] ?? null ) ? $draft['plugin_settings']['comments'] : [];
                $mode = strtolower( trim( (string) ( $settings['mode'] ?? 'inherit' ) ) );
                if ( ! in_array( $mode, [ 'inherit', 'open', 'closed' ], true ) ) {
                    $mode = 'inherit';
                }
                $html = '<div class="d-grid gap-3"><div><label class="form-label" for="tm-editor-comments-mode">Comments</label><select class="form-select" id="tm-editor-comments-mode" data-editor-plugin-key="comments" data-editor-plugin-field="mode">';
                foreach ( [ 'inherit' => 'Inherit', 'open' => 'Open', 'closed' => 'Closed' ] as $value => $label ) {
                    $html .= '<option value="' . $value . '"' . ( $mode === $value ? ' selected' : '' ) . '>' . $label . '</option>';
                }
                $html .= '</select><div class="form-text">Inherit follows the post or page default for this space.</div></div></div>';
                return( $html );
            },
        ]
    );
    $plugins->registerAdminNavigationItem( $plugin_key, 'author', [ 'section' => 'comments', 'label' => 'Comments', 'url' => $plugin_url, 'icon' => 'bi-chat-left-text', 'order' => 85 ] );
    $plugins->registerAdminNavigationItem( $plugin_key, 'admin', [ 'section' => 'comments', 'label' => 'Comments', 'url' => $plugin_url, 'icon' => 'bi-chat-left-text', 'order' => 85 ] );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );

    $normalize_status = static function( string $status ) : string {
        $status = strtolower( trim( $status ) );
        return( in_array( $status, [ 'awaiting_verification', 'pending', 'approved', 'spam', 'trash' ], true ) ? $status : 'pending' );
    };
    $resolve_scope = static function( array $data = [] ) use ( $security, $service, $user_repository ) : string {
        $username = mb_strtolower( mb_trim( (string) $security->getCurrentUsername() ) );
        if ( ! $security->isSuperAdmin() ) {
            return( $service->normalizeScope( 'author:' . $username ) );
        }
        $scope = $service->normalizeScope( (string) ( $data['scope'] ?? 'root' ) );
        if ( str_starts_with( $scope, 'author:' ) && ! is_array( $user_repository->getUserByAuthorSlug( substr( $scope, 7 ) ) ) ) {
            return( '' );
        }
        return( $scope );
    };
    $scope_url = static function( string $scope, string $status ) use ( $plugin_url ) : string {
        return( $plugin_url . '?scope=' . rawurlencode( $scope ) . '&status=' . rawurlencode( $status ) );
    };
    $clear_public_cache = static function() use ( $project_root ) : void {
        ( new TinyMashPublicPageCache( $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'public-page-cache' ) )->clear();
    };
    $render_admin = static function( string $scope, string $status = 'pending', array $notice = [] ) use ( $plugins, $plugin_key, $plugin_url, $security, $service, $user_repository, $content_repository, $theme, $date_formatter, $admin_renderer, $normalize_status ) : void {
        $status = $normalize_status( $status );
        $scope = $service->normalizeScope( $scope );
        if ( $scope === '' ) {
            $scope = $security->isSuperAdmin() ? 'root' : 'author:' . mb_strtolower( mb_trim( (string) $security->getCurrentUsername() ) );
        }
        try {
            $workspace = $service->getModerationWorkspace( $scope, $status );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            $workspace = [ 'status' => $status, 'counts' => [], 'comments' => [] ];
            $notice = [ 'type' => 'danger', 'message' => 'Comment records could not be read.' ];
        }
        $system_settings = $plugins->getPluginSystemSettings( $plugin_key );
        $settings = $scope === 'root'
            ? $service->normalizeSettings( $system_settings )
            : $service->normalizeSettings( $user_repository->getPluginSettings( substr( $scope, 7 ), $plugin_key ) );
        $retention_settings = $service->normalizeRetentionSettings( $system_settings );
        $retention_preview = [];
        if ( $security->isSuperAdmin() && $scope === 'root' ) {
            try {
                $retention_preview = $service->previewRetention( $retention_settings );
            } catch ( \RuntimeException ) {
                $notice = [ 'type' => 'danger', 'message' => 'Comment retention records could not be read.' ];
            }
        }
        $entries = [];
        foreach ( (array) ( $workspace['comments'] ?? [] ) as $comment ) {
            $entry_id = is_array( $comment ) ? (string) ( $comment['entry_id'] ?? '' ) : '';
            if ( $entry_id === '' || isset( $entries[$entry_id] ) ) {
                continue;
            }
            $entry = $content_repository->getAccessibleEntryById( $entry_id );
            $entries[$entry_id] = is_array( $entry )
                ? [ 'title' => (string) ( $entry['title'] ?? '' ), 'url' => $theme->getEntryURL( $entry ) ]
                : [];
        }
        $scope_label = $scope === 'root' ? 'Root' : $user_repository->getDisplayLabelByAuthorSlug( substr( $scope, 7 ) );
        $format_date = static function( string $value ) use ( $date_formatter ) : string {
            if ( $value === '' ) {
                return( '-' );
            }
            return( $date_formatter instanceof TinyMashDateFormatter ? $date_formatter->formatUtcDateTime( $value ) : $value );
        };
        $body = $admin_renderer->render(
            [
                'scope' => $scope,
                'status' => $status,
                'workspace' => $workspace,
                'settings' => $settings,
                'retention_settings' => $retention_settings,
                'retention_preview' => $retention_preview,
                'users' => $security->isSuperAdmin() ? $user_repository->getAllUsers() : [],
                'entries' => $entries,
                'plugin_url' => $plugin_url,
                'csrf_token' => $security->getCsrfToken(),
                'is_admin' => $security->isSuperAdmin(),
                'scope_label' => $scope_label,
                'format_date' => $format_date,
            ]
        );
        $plugins->renderAdminPage( [ 'title' => APP_NAME . APP_TITLE_SEP . 'Comments', 'current_section' => 'comments', 'current_location' => 'comments', 'plugin_page_kicker' => 'Comments', 'plugin_page_title' => $scope_label, 'plugin_page_summary' => 'Settings and moderation for posts and pages.', 'plugin_page_notice' => $notice, 'plugin_page_body_html' => $body ] );
    };

    $plugins->registerRoute( $plugin_key, 'get', $plugin_url, static function() use ( $plugins, $security, $login_url, $resolve_scope, $normalize_status, $render_admin ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }
        $query = $plugins->getRequestQueryData();
        $scope = $resolve_scope( $query );
        $render_admin( $scope, $normalize_status( (string) ( $query['status'] ?? 'pending' ) ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/settings', static function() use ( $plugins, $plugin_key, $security, $service, $user_repository, $resolve_scope, $normalize_status, $render_admin, $scope_url, $clear_public_cache ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( \Flight::get( 'login.url' ) ?: '/login' ) );
            return;
        }
        $data = $plugins->getRequestData();
        $scope = $resolve_scope( $data );
        $status = $normalize_status( (string) ( $data['status'] ?? 'pending' ) );
        if ( $scope === '' ) {
            $render_admin( 'root', $status, [ 'type' => 'danger', 'message' => 'Select an existing space.' ] );
            return;
        }
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_admin( $scope, $status, [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            return;
        }
        $settings = $service->normalizeSettings( $data );
        $saved = false;
        if ( $scope === 'root' && $security->isSuperAdmin() ) {
            $config_io = new TinyMashConfigIO();
            $settings = array_merge( $settings, $service->normalizeRetentionSettings( $plugins->getPluginSystemSettings( $plugin_key ) ) );
            $saved = (bool) $config_io->getConfig() && $config_io->updatePluginSettings( $plugin_key, $settings );
        } elseif ( str_starts_with( $scope, 'author:' ) && $service->actorCanModerateScope( (string) $security->getCurrentUsername(), $security->isSuperAdmin(), $scope ) ) {
            $saved = $user_repository->updatePluginSettings( substr( $scope, 7 ), $plugin_key, $settings );
        }
        if ( ! $saved ) {
            $render_admin( $scope, $status, [ 'type' => 'danger', 'message' => 'Comment settings could not be saved.' ] );
            return;
        }
        $clear_public_cache();
        $plugins->redirect( $scope_url( $scope, $status ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/retention-settings', static function() use ( $plugins, $plugin_key, $security, $service, $normalize_status, $render_admin, $scope_url ) : void {
        if ( ! $security->isSuperAdmin() ) {
            $plugins->redirect( (string) ( \Flight::get( 'login.url' ) ?: '/login' ) );
            return;
        }
        $data = $plugins->getRequestData();
        $status = $normalize_status( (string) ( $data['status'] ?? 'pending' ) );
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_admin( 'root', $status, [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            return;
        }
        $settings = array_merge(
            $service->normalizeSettings( $plugins->getPluginSystemSettings( $plugin_key ) ),
            $service->normalizeRetentionSettings( $data )
        );
        $config_io = new TinyMashConfigIO();
        if ( ! (bool) $config_io->getConfig() || ! $config_io->updatePluginSettings( $plugin_key, $settings ) ) {
            $render_admin( 'root', $status, [ 'type' => 'danger', 'message' => 'Comment retention settings could not be saved.' ] );
            return;
        }
        $plugins->redirect( $scope_url( 'root', $status ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/retention-cleanup', static function() use ( $plugins, $plugin_key, $security, $service, $normalize_status, $render_admin ) : void {
        if ( ! $security->isSuperAdmin() ) {
            $plugins->redirect( (string) ( \Flight::get( 'login.url' ) ?: '/login' ) );
            return;
        }
        $data = $plugins->getRequestData();
        $status = $normalize_status( (string) ( $data['status'] ?? 'pending' ) );
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) || empty( $data['confirm_cleanup'] ) ) {
            $render_admin( 'root', $status, [ 'type' => 'danger', 'message' => 'Confirm cleanup and try again.' ] );
            return;
        }
        try {
            $result = $service->prune( $plugins->getPluginSystemSettings( $plugin_key ) );
            $message = (int) $result['removed_total'] . ' comment(s) removed and ' . (int) $result['anonymized_total'] . ' thread parent(s) anonymized.';
            $render_admin( 'root', $status, [ 'type' => 'success', 'message' => $message ] );
        } catch ( \RuntimeException ) {
            $render_admin( 'root', $status, [ 'type' => 'danger', 'message' => 'Comment cleanup could not be completed.' ] );
        }
    } );

    $moderate = static function( string $action ) use ( $plugins, $security, $service, $resolve_scope, $normalize_status, $render_admin, $scope_url, $clear_public_cache ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( \Flight::get( 'login.url' ) ?: '/login' ) );
            return;
        }
        $data = $plugins->getRequestData();
        $scope = $resolve_scope( $data );
        $status = $normalize_status( (string) ( $data['status'] ?? 'pending' ) );
        if ( $scope === '' || ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_admin( $scope, $status, [ 'type' => 'danger', 'message' => 'The moderation request is invalid. Reload the page and try again.' ] );
            return;
        }
        $entry_id = (string) ( $data['entry_id'] ?? '' );
        $comment_id = (string) ( $data['comment_id'] ?? '' );
        try {
            $comment = $service->getComment( $entry_id, $comment_id );
            if ( ! is_array( $comment ) || (string) $comment['scope'] !== $scope ) {
                throw new \RuntimeException( 'comment_scope' );
            }
            $changed = $action === 'restore'
                ? $service->restoreComment( $entry_id, $comment_id, (string) $security->getCurrentUsername(), $security->isSuperAdmin() )
                : $service->deleteComment( $entry_id, $comment_id, (string) $security->getCurrentUsername(), $security->isSuperAdmin() );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            $changed = false;
        }
        if ( ! $changed ) {
            $render_admin( $scope, $status, [ 'type' => 'danger', 'message' => 'The comment could not be changed.' ] );
            return;
        }
        $clear_public_cache();
        $plugins->redirect( $scope_url( $scope, $status ) );
    };
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/moderate', static function() use ( $plugins, $security, $service, $submission_service, $resolve_scope, $normalize_status, $render_admin, $scope_url, $clear_public_cache ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( \Flight::get( 'login.url' ) ?: '/login' ) );
            return;
        }
        $data = $plugins->getRequestData();
        $scope = $resolve_scope( $data );
        $status = $normalize_status( (string) ( $data['status'] ?? 'pending' ) );
        if ( $scope === '' || ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_admin( $scope, $status, [ 'type' => 'danger', 'message' => 'The moderation request is invalid. Reload the page and try again.' ] );
            return;
        }
        $entry_id = (string) ( $data['entry_id'] ?? '' );
        $comment_id = (string) ( $data['comment_id'] ?? '' );
        $previous_status = '';
        $target_status = (string) ( $data['target_status'] ?? '' );
        try {
            $comment = $service->getComment( $entry_id, $comment_id );
            if ( ! is_array( $comment ) || (string) $comment['scope'] !== $scope ) {
                throw new \RuntimeException( 'comment_scope' );
            }
            $previous_status = (string) ( $comment['status'] ?? '' );
            $changed = $service->moderateComment( $entry_id, $comment_id, $target_status, (string) $security->getCurrentUsername(), $security->isSuperAdmin() );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            $changed = false;
        }
        if ( ! $changed ) {
            $render_admin( $scope, $status, [ 'type' => 'danger', 'message' => 'The comment could not be changed.' ] );
            return;
        }
        if ( $target_status === 'approved' && $previous_status !== 'approved' ) {
            try {
                $submission_service->queueApprovedReply( $entry_id, $comment_id );
            } catch ( \InvalidArgumentException | \RuntimeException ) {
                error_log( 'tinymash comments: Reply notification could not be queued.' );
            }
        }
        $clear_public_cache();
        $plugins->redirect( $scope_url( $scope, $status ) );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/restore', static function() use ( $moderate ) : void {
        $moderate( 'restore' );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/delete', static function() use ( $moderate ) : void {
        $moderate( 'delete' );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $plugin_url . '/reply', static function() use ( $plugins, $security, $service, $submission_service, $get_account_name, $resolve_scope, $normalize_status, $render_admin, $scope_url, $clear_public_cache ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( \Flight::get( 'login.url' ) ?: '/login' ) );
            return;
        }
        $data = $plugins->getRequestData();
        $scope = $resolve_scope( $data );
        $status = $normalize_status( (string) ( $data['status'] ?? 'approved' ) );
        if ( $scope === '' || ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_admin( $scope, $status, [ 'type' => 'danger', 'message' => 'The reply request is invalid. Reload the page and try again.' ] );
            return;
        }
        $entry_id = (string) ( $data['entry_id'] ?? '' );
        $comment_id = (string) ( $data['comment_id'] ?? '' );
        $username = (string) $security->getCurrentUsername();
        $display_name = $get_account_name( $username );
        try {
            $parent = $service->getComment( $entry_id, $comment_id );
            if ( ! is_array( $parent ) || (string) $parent['scope'] !== $scope || (string) $parent['status'] !== 'approved' || ! $service->actorCanModerateScope( $username, $security->isSuperAdmin(), $scope ) ) {
                throw new \RuntimeException( 'comment_scope' );
            }
            $reply = $service->createAccountComment( $entry_id, $scope, $comment_id, $username, $display_name, (string) ( $data['body'] ?? '' ), $security->isSuperAdmin() );
            $changed = (string) ( $reply['status'] ?? '' ) === 'approved';
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            $changed = false;
        }
        if ( ! $changed ) {
            $render_admin( $scope, $status, [ 'type' => 'danger', 'message' => 'The reply could not be posted.' ] );
            return;
        }
        try {
            $submission_service->queueApprovedReply( $entry_id, (string) ( $reply['id'] ?? '' ) );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            error_log( 'tinymash comments: Reply notification could not be queued.' );
        }
        $clear_public_cache();
        $plugins->redirect( $scope_url( $scope, 'approved' ) );
    } );

    $render_public = static function( string $title, string $message, string $form_html = '', array $entry = [], string $return_label = 'Return to the entry' ) use ( $plugins, $theme ) : void {
        header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        $author_slug = (string) ( $entry['scope'] ?? '' ) === 'author' ? (string) ( $entry['author_slug'] ?? '' ) : '';
        $return_url = $entry !== [] ? $theme->getEntryURL( $entry ) : ( $author_slug !== '' ? $theme->getAuthorURL( $author_slug ) : '/' );
        $view = $theme->getBaseViewData( $title, null, $author_slug !== '' ? $author_slug : null );
        $view['theme_public_sidebar_fragments'] = [];
        $view['theme_public_footer_fragments'] = [];
        $plugins->renderPublicPage(
            'public/comments.latte',
            array_merge(
                $view,
                [
                    'comments_title' => $title,
                    'comments_message' => $message,
                    'comments_form_html' => $form_html,
                    'comments_return_url' => $return_url,
                    'comments_return_label' => $return_label,
                ]
            )
        );
    };

    $plugins->registerRoute( $plugin_key, 'post', '/comments/submit', static function() use ( $plugins, $security, $content_repository, $submission_service, $site_settings, $get_author_settings, $get_account_name, $visitor_type, $visitor_can_access_entry, $render_public ) : void {
        $data = $plugins->getRequestData();
        $entry = $content_repository->getPublishedEntryById( (string) ( $data['entry_id'] ?? '' ) );
        if ( ! is_array( $entry ) || ! $visitor_can_access_entry( $entry ) ) {
            $render_public( 'Comment not sent', 'The comment form is unavailable.' );
            return;
        }
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_public( 'Comment not sent', 'The form expired. Return to the entry and try again.', '', $entry );
            return;
        }
        $username = (string) ( $security->getCurrentUsername() ?? '' );
        $display_name = $username !== '' ? $get_account_name( $username ) : '';
        $type = $visitor_type( $entry );
        $result = $submission_service->requestSubmission(
            $data,
            $entry,
            $type,
            $security->getCurrentIpAddress(),
            $username,
            $display_name,
            $security->isSuperAdmin(),
            $site_settings,
            $get_author_settings( $entry )
        );
        $validation_error = mb_trim( (string) ( $result['validation_error'] ?? '' ) );
        if ( $validation_error !== '' ) {
            $render_public( 'Comment not sent', $validation_error, '', $entry );
            return;
        }
        $published = ! empty( $result['published'] );
        $render_public(
            $published ? 'Comment published' : ( $type === 'signed_in' ? 'Comment received' : 'Check your e-mail' ),
            $published ? 'Your comment is now public.' : ( $type === 'signed_in' ? 'Your comment was received for review.' : 'If the comment can be accepted, a confirmation link will be sent.' ),
            '',
            $entry
        );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/comments/verify', static function() use ( $plugins, $security, $submission_service, $content_repository, $escape, $render_public ) : void {
        $query = $plugins->getRequestQueryData();
        $entry_id = (string) ( $query['entry'] ?? '' );
        $comment_id = (string) ( $query['comment'] ?? '' );
        $token = (string) ( $query['token'] ?? '' );
        $entry = $content_repository->getPublishedEntryById( $entry_id );
        if ( ! is_array( $entry ) ) {
            $render_public( 'Comment link unavailable', 'This confirmation link is invalid or has expired.' );
            return;
        }
        if ( ! is_array( $submission_service->getVerification( $entry_id, $comment_id, $token ) ) ) {
            if ( is_array( $submission_service->getVerificationForReissue( $entry_id, $comment_id, $token ) ) ) {
                $form = '<form method="post" action="/comments/verify/reissue"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="entry" value="' . $escape( $entry_id ) . '"><input type="hidden" name="comment" value="' . $escape( $comment_id ) . '"><input type="hidden" name="token" value="' . $escape( $token ) . '"><button class="btn btn-primary btn-sm" type="submit">Send a new confirmation link</button></form>';
                $render_public( 'Comment link expired', 'Request a new confirmation link for this comment.', $form, $entry );
                return;
            }
            $render_public( 'Comment link unavailable', 'This confirmation link is invalid or has expired.' );
            return;
        }
        $form = '<form method="post" action="/comments/verify"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="entry" value="' . $escape( $entry_id ) . '"><input type="hidden" name="comment" value="' . $escape( $comment_id ) . '"><input type="hidden" name="token" value="' . $escape( $token ) . '"><button class="btn btn-primary btn-sm" type="submit">Confirm comment</button></form>';
        $render_public( 'Confirm comment', 'Confirm your e-mail address to send the comment for review.', $form, $entry );
    } );
    $plugins->registerRoute( $plugin_key, 'post', '/comments/verify', static function() use ( $plugins, $security, $submission_service, $content_repository, $render_public ) : void {
        $data = $plugins->getRequestData();
        $entry_id = (string) ( $data['entry'] ?? '' );
        $entry = $content_repository->getPublishedEntryById( $entry_id );
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_public( 'Comment not confirmed', 'The form expired. Open the link from your e-mail and try again.', '', is_array( $entry ) ? $entry : [] );
            return;
        }
        $confirmed = is_array( $entry ) && $submission_service->confirmVerification( $entry_id, (string) ( $data['comment'] ?? '' ), (string) ( $data['token'] ?? '' ) );
        $render_public(
            $confirmed ? 'Comment confirmed' : 'Comment not confirmed',
            $confirmed ? 'Your comment was sent for review.' : 'This confirmation link is invalid or has expired.',
            '',
            is_array( $entry ) ? $entry : []
        );
    } );
    $plugins->registerRoute( $plugin_key, 'post', '/comments/verify/reissue', static function() use ( $plugins, $security, $submission_service, $content_repository, $render_public ) : void {
        $data = $plugins->getRequestData();
        $entry_id = (string) ( $data['entry'] ?? '' );
        $entry = $content_repository->getPublishedEntryById( $entry_id );
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_public( 'Link not requested', 'The form expired. Open the link from your e-mail and try again.', '', is_array( $entry ) ? $entry : [] );
            return;
        }
        if ( is_array( $entry ) ) {
            $submission_service->requestVerificationReissue(
                $entry_id,
                (string) ( $data['comment'] ?? '' ),
                (string) ( $data['token'] ?? '' ),
                $security->getCurrentIpAddress()
            );
        }
        $render_public( 'Check your e-mail', 'If the comment can still be confirmed, a new link will be sent.', '', is_array( $entry ) ? $entry : [] );
    } );

    $plugins->registerRoute( $plugin_key, 'get', '/comments/unsubscribe', static function() use ( $plugins, $security, $service, $escape, $render_public ) : void {
        $query = $plugins->getRequestQueryData();
        $entry_id = (string) ( $query['entry'] ?? '' );
        $comment_id = (string) ( $query['comment'] ?? '' );
        $token = (string) ( $query['token'] ?? '' );
        try {
            $request = $service->getReplyUnsubscribeRequest( $entry_id, $comment_id, $token );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            $request = null;
        }
        if ( ! is_array( $request ) ) {
            $render_public( 'Unsubscribe link unavailable', 'This unsubscribe link is invalid or has already been used.', '', [], 'Return to site' );
            return;
        }
        $form = '<form method="post" action="/comments/unsubscribe"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="entry" value="' . $escape( $entry_id ) . '"><input type="hidden" name="comment" value="' . $escape( $comment_id ) . '"><input type="hidden" name="token" value="' . $escape( $token ) . '"><button class="btn btn-primary btn-sm" type="submit">Stop notifications</button></form>';
        $render_public( 'Stop reply notifications', 'Stop e-mail notifications for replies to this comment.', $form, [], 'Return to site' );
    } );
    $plugins->registerRoute( $plugin_key, 'post', '/comments/unsubscribe', static function() use ( $plugins, $security, $service, $render_public ) : void {
        $request_data = $plugins->getRequestData();
        $data = array_merge( $plugins->getRequestQueryData(), $request_data );
        $one_click = (string) ( $request_data['List-Unsubscribe'] ?? '' ) === 'One-Click';
        $csrf_valid = $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) );
        $removed = false;
        if ( $one_click || $csrf_valid ) {
            try {
                $removed = $service->unsubscribeFromReplies( (string) ( $data['entry'] ?? '' ), (string) ( $data['comment'] ?? '' ), (string) ( $data['token'] ?? '' ) );
            } catch ( \InvalidArgumentException | \RuntimeException ) {
                $removed = false;
            }
        }
        $render_public(
            $removed ? 'Notifications stopped' : 'Unsubscribe link unavailable',
            $removed ? 'You will no longer receive replies to this comment by e-mail.' : 'This unsubscribe link is invalid or has already been used.',
            '',
            [],
            'Return to site'
        );
    } );

    $privacy_request_form = static function() use ( $escape, $security ) : string {
        return(
            '<form class="tm-comments-form" method="post" action="/comments/privacy/request">'
            . '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">'
            . '<label><span>E-mail address</span><input class="form-control form-control-sm" name="email" type="email" autocomplete="email" maxlength="254" required></label>'
            . '<div><button class="btn btn-primary btn-sm" type="submit">Send privacy link</button></div>'
            . '</form>'
        );
    };
    $plugins->registerRoute( $plugin_key, 'get', '/comments/privacy', static function() use ( $plugins, $security, $privacy_service, $privacy_request_form, $escape, $render_public ) : void {
        $query = $plugins->getRequestQueryData();
        $request_id = (string) ( $query['request'] ?? '' );
        $token = (string) ( $query['token'] ?? '' );
        if ( $request_id === '' || $token === '' ) {
            $render_public( 'Comment privacy', 'Enter the e-mail address used for your comments.', $privacy_request_form(), [], 'Return to site' );
            return;
        }
        $access = $privacy_service->getAccess( $request_id, $token );
        if ( ! is_array( $access ) ) {
            $render_public( 'Privacy link unavailable', 'This privacy link is invalid or has expired.', '', [], 'Return to site' );
            return;
        }
        $fields = '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="request" value="' . $escape( $request_id ) . '"><input type="hidden" name="token" value="' . $escape( $token ) . '">';
        $forms = '<div class="d-flex flex-wrap gap-2"><form method="post" action="/comments/privacy/export">' . $fields . '<button class="btn btn-primary btn-sm" type="submit">Download data</button></form>';
        $forms .= '<form method="post" action="/comments/privacy/erase">' . $fields . '<button class="btn btn-outline-danger btn-sm" type="submit">Erase comments</button></form></div>';
        $count = (int) ( $access['comment_count'] ?? 0 );
        $message = $count === 1 ? 'One comment is associated with this address.' : $count . ' comments are associated with this address.';
        $message .= ' Download a copy or erase the comments. Comments with replies are anonymized to preserve the thread. This link can be used once.';
        $render_public( 'Comment privacy', $message, $forms, [], 'Return to site' );
    } );
    $plugins->registerRoute( $plugin_key, 'post', '/comments/privacy/request', static function() use ( $plugins, $security, $privacy_service, $render_public ) : void {
        $data = $plugins->getRequestData();
        if ( $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            try {
                $privacy_service->requestAccess( (string) ( $data['email'] ?? '' ), $security->getCurrentIpAddress() );
            } catch ( \InvalidArgumentException | \RuntimeException ) {
                error_log( 'tinymash comments: Privacy request could not be queued.' );
            }
        }
        $render_public( 'Check your e-mail', 'If comments are associated with that address, a privacy link will be sent.', '', [], 'Return to site' );
    } );
    $plugins->registerRoute( $plugin_key, 'post', '/comments/privacy/export', static function() use ( $plugins, $security, $privacy_service, $render_public ) : void {
        $data = $plugins->getRequestData();
        $export = null;
        if ( $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            try {
                $export = $privacy_service->exportData( (string) ( $data['request'] ?? '' ), (string) ( $data['token'] ?? '' ) );
            } catch ( \InvalidArgumentException | \RuntimeException ) {
                $export = null;
            }
        }
        if ( ! is_array( $export ) ) {
            $render_public( 'Export unavailable', 'This privacy link is invalid or has expired.', '', [], 'Return to site' );
            return;
        }
        header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="tinymash-comments.json"' );
        echo json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
    } );
    $plugins->registerRoute( $plugin_key, 'post', '/comments/privacy/erase', static function() use ( $plugins, $security, $privacy_service, $clear_public_cache, $render_public ) : void {
        $data = $plugins->getRequestData();
        $result = null;
        if ( $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            try {
                $result = $privacy_service->erase( (string) ( $data['request'] ?? '' ), (string) ( $data['token'] ?? '' ) );
            } catch ( \InvalidArgumentException | \RuntimeException ) {
                $result = null;
            }
        }
        if ( ! is_array( $result ) ) {
            $render_public( 'Erasure unavailable', 'This privacy link is invalid or has expired.', '', [], 'Return to site' );
            return;
        }
        $clear_public_cache();
        $render_public( 'Comments erased', 'Comments were removed or anonymized where replies had to be preserved.', '', [], 'Return to site' );
    } );
};
