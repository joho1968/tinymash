<?php

require_once __DIR__ . '/TinyMashFediverseService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashNotificationService;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'fediverse' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $content_repository = $plugins->getService( 'content.repository' );
    $user_repository = $plugins->getService( 'user.repository' );
    $markdown_renderer = $plugins->getService( 'markdown.renderer' );
    $theme = $plugins->getService( 'theme' );
    $security = $plugins->getService( 'security' );
    $app = \Flight::app();
    $notification_service = $app->has( 'notification.service' ) ? $app->get( 'notification.service' ) : null;

    if (
        ! $config instanceof TinyMashConfig
        || ! $content_repository instanceof TinyMashContentRepository
        || ! $user_repository instanceof TinyMashUserRepository
        || ! $security instanceof TinyMashSecurity
    ) {
        throw new \RuntimeException( 'Required services for the Fediverse plugin are not available.' );
    }

    if ( ! $markdown_renderer instanceof TinyMashMarkdownRenderer ) {
        $markdown_renderer = null;
    }
    if ( ! $theme instanceof TinyMashTheme ) {
        $theme = null;
    }
    if ( ! $notification_service instanceof TinyMashNotificationService ) {
        $notification_service = null;
    }

    $fediverse_service = new TinyMashFediverseService(
        $config,
        $content_repository,
        $user_repository,
        $markdown_renderer,
        $theme,
        $notification_service,
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'fediverse' . DIRECTORY_SEPARATOR . 'store.json'
    );
    $app->set( 'plugin.fediverse.service', $fediverse_service );

    $plugins->registerSystemSettingsSection(
        $plugin_key,
        [
            'title' => 'Fediverse',
            'summary' => 'Default Mastodon-compatible account and publishing defaults for root-space posts.',
            'fields' => [
                [
                    'key' => 'root_instance_url',
                    'type' => 'text',
                    'label' => 'Root account instance URL',
                    'help' => 'Example: https://mastodon.social',
                    'default' => '',
                ],
                [
                    'key' => 'root_access_token',
                    'type' => 'password',
                    'label' => 'Root account access token',
                    'help' => 'A user access token with permission to post statuses on the chosen Mastodon-compatible account.',
                    'default' => '',
                ],
                [
                    'key' => 'root_status_character_limit',
                    'type' => 'text',
                    'label' => 'Character limit',
                    'help' => 'Used for Fediverse readiness checks and truncation. Mastodon commonly uses 500, while other servers may differ.',
                    'default' => '500',
                ],
                [
                    'key' => 'root_default_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable Mastodon posting by default for root-space posts',
                    'help' => 'Authors can still change this per entry in the editor.',
                    'default' => 0,
                ],
                [
                    'key' => 'root_default_include_link_back',
                    'type' => 'checkbox',
                    'label' => 'Include a link back to the post by default',
                    'help' => 'When enabled, root-space Mastodon posts include the canonical blog-post URL unless the entry overrides it.',
                    'default' => 1,
                ],
            ],
        ]
    );

    $plugins->registerProfileSettingsSection(
        $plugin_key,
        'fediverse',
        [
            'title' => 'Fediverse',
            'summary' => 'Mastodon-compatible account settings and defaults for your author space.',
            'fields' => [
                [
                    'key' => 'instance_url',
                    'type' => 'text',
                    'label' => 'Author account instance URL',
                    'help' => 'Example: https://mastodon.social',
                    'default' => '',
                ],
                [
                    'key' => 'access_token',
                    'type' => 'password',
                    'label' => 'Author account access token',
                    'help' => 'A user access token with permission to post statuses on your Mastodon-compatible account.',
                    'default' => '',
                ],
                [
                    'key' => 'status_character_limit',
                    'type' => 'text',
                    'label' => 'Character limit',
                    'help' => 'Used for Fediverse readiness checks and truncation. Mastodon commonly uses 500, while other servers may differ.',
                    'default' => '500',
                ],
                [
                    'key' => 'default_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable Mastodon posting by default for my public posts',
                    'help' => 'Pages are never posted. Authors can still change this per post in the editor.',
                    'default' => 0,
                ],
                [
                    'key' => 'default_include_link_back',
                    'type' => 'checkbox',
                    'label' => 'Include a link back to the post by default',
                    'help' => 'When enabled, your Mastodon posts include the canonical blog-post URL unless the entry overrides it.',
                    'default' => 1,
                ],
            ],
        ]
    );

    $plugins->registerHousekeepingTask(
        $plugin_key,
        static function( array $context = [] ) use ( $plugins, $plugin_key, $fediverse_service ) : string {
            $result = $fediverse_service->runQueue(
                $plugins->getPluginSystemSettings( $plugin_key ),
                $context
            );
            return( trim( (string) ( $result['message'] ?? 'No Fediverse deliveries were due.' ) ) );
        },
        'delivery',
        'Process Fediverse delivery queue'
    );

    if ( $plugins->isCliRuntime() ) {
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'fediverse',
                'usage' => 'tinymash fediverse run-queue',
                'summary' => 'Run the Fediverse delivery/removal queue without the rest of housekeeping.',
                'order' => 230,
                'handler' => static function( array $arguments ) use ( $plugins, $plugin_key, $fediverse_service ) : void {
                    $action = strtolower( trim( (string) ( $arguments[0] ?? '' ) ) );
                    if ( $action !== 'run-queue' ) {
                        throw new \RuntimeException( 'usage: tinymash fediverse run-queue' );
                    }

                    $result = $fediverse_service->runQueue(
                        $plugins->getPluginSystemSettings( $plugin_key ),
                        [
                            'run_type' => 'fediverse_delivery',
                            'trigger' => 'cli',
                            'mode' => 'fediverse_queue',
                        ]
                    );

                    fwrite( STDOUT, 'claimed: ' . (int) ( $result['claimed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'sent: ' . (int) ( $result['sent'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'retried: ' . (int) ( $result['retried'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'failed: ' . (int) ( $result['failed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'skipped: ' . (int) ( $result['skipped'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'message: ' . trim( (string) ( $result['message'] ?? 'No Fediverse deliveries were due.' ) ) . PHP_EOL );
                },
            ]
        );
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $fediverse_url = $admin_url . '/fediverse';
    $fediverse_readiness_url = $admin_url . '/fediverse/readiness';

    $send_json = static function( array $payload, int $status_code = 200 ) : void {
        \Flight::response()->status( $status_code );
        header( 'Content-Type: application/json; charset=UTF-8' );
        echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    };

    $parse_tags = static function( string $raw_tags ) : array {
        $parts = preg_split( '/[\s,]+/u', $raw_tags ) ?: [];
        $tags = [];
        foreach ( $parts as $tag ) {
            if ( ! is_string( $tag ) ) {
                continue;
            }

            $tag = strtolower( trim( ltrim( $tag, '#' ) ) );
            $tag = preg_replace( '/[^a-z0-9_-]+/u', '', $tag ) ?? '';
            if ( $tag === '' || in_array( $tag, $tags, true ) ) {
                continue;
            }

            $tags[] = $tag;
        }

        return( $tags );
    };

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'author',
        [
            'section' => 'fediverse',
            'label' => 'Fediverse',
            'url' => $fediverse_url,
            'icon' => 'bi-share',
            'order' => 82,
        ]
    );

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'fediverse',
            'label' => 'Fediverse',
            'url' => $fediverse_url,
            'icon' => 'bi-share',
            'order' => 82,
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-fediverse',
        [
            'title' => 'Fediverse',
            'summary' => 'Queue public posts to Mastodon-compatible accounts, per author or for root-space posts.',
            'group' => 'Plugins',
            'order' => 145,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'The Fediverse plugin sends public posts to Mastodon-compatible accounts. Pages are never sent.',
                ],
                [
                    'title' => 'How posting is controlled',
                    'markdown' => "Posting is controlled in two places:\n\n- a per-author or root-space default in settings\n- a per-entry opt-in in the editor's `Fediverse` tab",
                ],
                [
                    'title' => 'What counts as an update',
                    'markdown' => 'Changes to title, summary, content, tags, publish date, and Mastodon entry options can update the remote post. SEO-only changes do not.',
                ],
                [
                    'title' => 'Delivery model',
                    'markdown' => 'tinymash queues delivery work and hands it to housekeeping. Failed deliveries are retried with backoff, and repeated failures create an admin notification.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };

    $render_account_summary = static function( string $heading, array $settings, string $settings_url ) use ( $escape ) : string {
        $configured = trim( (string) ( $settings['instance_url'] ?? '' ) ) !== '' && trim( (string) ( $settings['access_token'] ?? '' ) ) !== '';
        $body_html = '<section class="p-3 bg-body-secondary rounded-3 h-100">';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">' . $escape( $heading ) . '</div>';
        $body_html .= '<div class="fw-semibold mb-1">' . ( $configured ? $escape( (string) ( $settings['instance_url'] ?? '' ) ) : 'Not configured' ) . '</div>';
        $body_html .= '<div class="small text-body-secondary mb-3">'
            . ( $configured ? 'Posting defaults are configured for this Mastodon-compatible account.' : 'No Mastodon-compatible account is configured for this scope yet.' )
            . '</div>';
        $body_html .= '<div class="small text-body-secondary">Default posting: <span class="fw-semibold text-body">' . ( ! empty( $settings['default_enabled'] ) ? 'Enabled' : 'Off' ) . '</span></div>';
        $body_html .= '<div class="small text-body-secondary">Link back: <span class="fw-semibold text-body">' . ( ! empty( $settings['default_include_link_back'] ) ? 'Included' : 'Off' ) . '</span></div>';
        $body_html .= '<div class="small text-body-secondary">Character limit: <span class="fw-semibold text-body">' . $escape( (string) ( $settings['status_character_limit'] ?? 500 ) ) . '</span></div>';
        $body_html .= '<div class="mt-3"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $settings_url ) . '">Open settings</a></div>';
        $body_html .= '</section>';
        return( $body_html );
    };

    $render_page = static function() use ( $plugins, $plugin_key, $fediverse_service, $config, $content_repository, $theme, $security, $escape, $render_account_summary ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( $config->configGetLoginURL() ?: '/login' ) );
            return;
        }

        $current_username = trim( (string) $security->getCurrentUsername() );
        $is_superadmin = $security->isSuperAdmin();
        $author_slug = $is_superadmin ? '' : $current_username;
        $root_settings = $plugins->getPluginSystemSettings( $plugin_key );
        $author_settings = $current_username !== '' ? $fediverse_service->getAuthorSettings( $current_username ) : [];
        $delivery_log = $fediverse_service->getDeliveryLog( $author_slug, 100 );

        $body_html = '<div class="row g-3">';
        if ( $is_superadmin ) {
            $body_html .= '<div class="col-12 col-xl-6">';
            $body_html .= $render_account_summary( 'Root account', $fediverse_service->getRootSettings( $root_settings ), (string) ( $config->configGetAdminURL() ?: '/admin' ) . '/system/plugins/fediverse' );
            $body_html .= '</div>';
        }
        $body_html .= '<div class="col-12' . ( $is_superadmin ? ' col-xl-6' : '' ) . '">';
        $body_html .= $render_account_summary( $is_superadmin ? 'Current author account' : 'Author account', $author_settings, (string) ( $config->configGetAdminURL() ?: '/admin' ) . '/profile/fediverse' );
        $body_html .= '</div>';
        $body_html .= '<div class="col-12">';
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
        $body_html .= '<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">';
        $body_html .= '<div>';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-1">Delivery log</div>';
        $body_html .= '<div class="text-body-secondary small">Queued work, retries, and recent Mastodon delivery results.</div>';
        $body_html .= '</div>';
        $body_html .= '</div>';

        if ( empty( $delivery_log ) ) {
            $body_html .= '<div class="text-body-secondary">No Fediverse deliveries have been recorded yet.</div>';
        } else {
            $body_html .= '<div class="table-responsive">';
            $body_html .= '<table class="table align-middle mb-0">';
            $body_html .= '<thead><tr>';
            $body_html .= '<th scope="col">When</th>';
            $body_html .= '<th scope="col">Entry</th>';
            $body_html .= '<th scope="col">Action</th>';
            $body_html .= '<th scope="col">Status</th>';
            $body_html .= '<th scope="col">Message</th>';
            $body_html .= '</tr></thead><tbody>';

            foreach ( $delivery_log as $log_item ) {
                if ( ! is_array( $log_item ) ) {
                    continue;
                }

                $entry_id = trim( (string) ( $log_item['entry_id'] ?? '' ) );
                $entry = $entry_id !== '' ? $content_repository->getAccessibleEntryById( $entry_id, null, null, true ) : null;
                $entry_title = is_array( $entry ) ? trim( (string) ( $entry['title'] ?? '' ) ) : '';
                if ( $entry_title === '' ) {
                    $entry_title = $entry_id !== '' ? ( 'Entry ' . $entry_id ) : 'Unknown entry';
                }
                $entry_url = is_array( $entry ) && $theme instanceof TinyMashTheme ? trim( (string) $theme->getEntryURL( $entry ) ) : '';
                $recorded_at_utc = trim( (string) ( $log_item['recorded_at_utc'] ?? '' ) );
                $action = trim( (string) ( $log_item['action'] ?? '' ) );
                $status = trim( (string) ( $log_item['status'] ?? '' ) );
                $message = trim( (string) ( $log_item['message'] ?? '' ) );
                $remote_status_url = trim( (string) ( $log_item['context']['remote_status_url'] ?? '' ) );

                $body_html .= '<tr>';
                $body_html .= '<td class="small text-nowrap">' . $escape( $recorded_at_utc !== '' ? $recorded_at_utc : 'Unknown' ) . '</td>';
                $body_html .= '<td>';
                if ( $entry_url !== '' ) {
                    $body_html .= '<a class="text-decoration-none" href="' . $escape( $entry_url ) . '">' . $escape( $entry_title ) . '</a>';
                } else {
                    $body_html .= $escape( $entry_title );
                }
                if ( $entry_id !== '' ) {
                    $body_html .= '<div class="small text-body-secondary font-monospace">' . $escape( $entry_id ) . '</div>';
                }
                $body_html .= '</td>';
                $body_html .= '<td class="text-capitalize">' . $escape( $action !== '' ? $action : 'create' ) . '</td>';
                $body_html .= '<td><span class="badge text-bg-' . $escape(
                    match ( $status ) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'skipped' => 'secondary',
                        'queued', 'processing' => 'warning',
                        default => 'secondary',
                    }
                ) . '">' . $escape( $status !== '' ? $status : 'queued' ) . '</span></td>';
                $body_html .= '<td>';
                $body_html .= $escape( $message !== '' ? $message : 'No message recorded.' );
                if ( $remote_status_url !== '' ) {
                    $body_html .= '<div class="small mt-1"><a class="text-decoration-none" href="' . $escape( $remote_status_url ) . '" target="_blank" rel="noopener noreferrer">Remote post</a></div>';
                }
                $body_html .= '</td>';
                $body_html .= '</tr>';
            }

            $body_html .= '</tbody></table></div>';
        }

        $body_html .= '</section></div></div>';

        $actions_html = '<div class="d-flex flex-wrap gap-2">';
        $actions_html .= '<a class="btn btn-outline-secondary btn-sm" href="' . $escape( (string) ( $config->configGetAdminURL() ?: '/admin' ) . '/profile/fediverse' ) . '">Author settings</a>';
        if ( $is_superadmin ) {
            $actions_html .= '<a class="btn btn-outline-secondary btn-sm" href="' . $escape( (string) ( $config->configGetAdminURL() ?: '/admin' ) . '/system/plugins/fediverse' ) . '">Root settings</a>';
        }
        $actions_html .= '</div>';

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Fediverse',
                'current_section' => 'fediverse',
                'current_location' => 'fediverse',
                'help_contexts' => [ 'plugin-fediverse' ],
                'plugin_page_kicker' => 'Fediverse',
                'plugin_page_title' => 'Mastodon delivery',
                'plugin_page_summary' => 'Queued delivery and recent Mastodon-compatible posting activity.',
                'plugin_page_actions_html' => $actions_html,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerEditorTab(
        $plugin_key,
        [
            'key' => 'mastodon',
            'label' => 'Fediverse',
            'order' => 80,
            'roles' => [ 'author', 'admin' ],
            'renderer' => static function( array $context = [] ) use ( $fediverse_service, $plugins, $plugin_key, $escape, $fediverse_readiness_url ) : string {
                $draft = is_array( $context['draft'] ?? null ) ? $context['draft'] : [];
                $root_settings = $plugins->getPluginSystemSettings( $plugin_key );
                $entry_settings = $fediverse_service->buildEntryEditorSettings( $draft, $root_settings );
                $readiness = $fediverse_service->buildDeliveryReadiness( $draft, $root_settings );
                $eligibility = is_array( $readiness ) ? $readiness : [];
                $post_enabled = ! empty( $entry_settings['post_enabled'] );
                $include_link_back = ! array_key_exists( 'include_link_back', $entry_settings ) || ! empty( $entry_settings['include_link_back'] );
                $instance_url = trim( (string) ( $eligibility['account_settings']['instance_url'] ?? '' ) );
                $character_limit = (int) ( $eligibility['account_settings']['status_character_limit'] ?? 500 );
                $preview = is_array( $eligibility['preview'] ?? null ) ? $eligibility['preview'] : [];
                $warnings = is_array( $eligibility['warnings'] ?? null ) ? $eligibility['warnings'] : [];
                $message_class = ! empty( $eligibility['eligible'] ) ? 'alert-success' : 'alert-secondary';
                $message = trim( (string) ( $eligibility['message'] ?? 'Only published posts can be sent to the Fediverse.' ) );
                $entry_type = strtolower( trim( (string) ( $draft['type'] ?? $draft['entry_type'] ?? 'post' ) ) );
                $entry_status = strtolower( trim( (string) ( $draft['status'] ?? 'unpublished' ) ) );
                $readiness_state = (string) ( $eligibility['state'] ?? 'blocked' );
                $readiness_reason = (string) ( $eligibility['reason'] ?? '' );
                $readiness_badge_class = match ( true ) {
                    $readiness_state === 'ready' => 'text-bg-success',
                    $readiness_state === 'warning' => 'text-bg-warning',
                    $readiness_reason === 'disabled' => 'text-bg-info',
                    in_array( $readiness_reason, [ 'credentials', 'visibility' ], true ) => 'text-bg-danger',
                    default => 'text-bg-secondary',
                };
                $readiness_badge_text = match ( true ) {
                    $readiness_state === 'ready' => 'Ready',
                    $readiness_state === 'warning' => 'Ready with warnings',
                    $readiness_reason === 'disabled' => 'Posting off',
                    $readiness_reason === 'status' => 'Unpublished',
                    $readiness_reason === 'entry_type' => 'Not a post',
                    $readiness_reason === 'credentials' => 'Account missing',
                    $readiness_reason === 'visibility' => 'Not public',
                    default => 'Will not post',
                };

                if ( $entry_type === 'post' && $entry_status === 'published' && $instance_url !== '' ) {
                    if ( $post_enabled ) {
                        $message_class = 'alert-success';
                        $message = 'Fediverse posting is enabled for this entry. Save the post to queue delivery or update.';
                    } elseif ( (string) ( $eligibility['reason'] ?? '' ) === 'disabled' ) {
                        $message_class = 'alert-info';
                        $message = 'Fediverse posting is currently off for this entry. Enable it below and save the post to queue delivery.';
                    }
                }

                $html = '<div class="row g-3">';
                $html .= '<div class="col-12">';
                $html .= '<div class="alert ' . $escape( $message_class ) . ' mb-0" id="tm-editor-fediverse-status-alert" role="alert">';
                $html .= '<span id="tm-editor-fediverse-status-text">' . $escape( $message !== '' ? $message : 'Only published posts can be sent to the Fediverse.' ) . '</span>';
                $html .= '<div class="small mt-1' . ( $instance_url === '' ? ' d-none' : '' ) . '" id="tm-editor-fediverse-status-url-wrap">Fediverse URL: <span class="font-monospace" id="tm-editor-fediverse-status-url">' . $escape( $instance_url ) . '</span></div>';
                $html .= '</div></div>';
                $html .= '<div class="col-12">';
                $html .= '<div class="form-check">';
                $html .= '<input class="form-check-input" id="tm-editor-fediverse-post-enabled" type="checkbox" data-editor-plugin-key="fediverse" data-editor-plugin-field="post_enabled"' . ( $post_enabled ? ' checked' : '' ) . '>';
                $html .= '<label class="form-check-label" for="tm-editor-fediverse-post-enabled">Post this public post to the Fediverse</label>';
                $html .= '</div>';
                $html .= '<div class="form-text">Pages are never sent. Root posts use the root account settings, author-space posts use the author account settings, and changes take effect when you save the post.</div>';
                $html .= '</div>';
                $html .= '<div class="col-12">';
                $html .= '<div class="form-check">';
                $html .= '<input class="form-check-input" id="tm-editor-fediverse-link-back" type="checkbox" data-editor-plugin-key="fediverse" data-editor-plugin-field="include_link_back"' . ( $include_link_back ? ' checked' : '' ) . '>';
                $html .= '<label class="form-check-label" for="tm-editor-fediverse-link-back">Include a link back to the post</label>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '<div class="col-12">';
                $html .= '<section class="border rounded-3 bg-body-tertiary p-3" id="tm-editor-fediverse-readiness-root" data-readiness-url="' . $escape( $fediverse_readiness_url ) . '">';
                $html .= '<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">';
                $html .= '<div>';
                $html .= '<div class="small text-uppercase text-body-secondary mb-1">Fediverse readiness</div>';
                $html .= '<div class="small text-body-secondary">This preview shows the exact text tinymash will send, plus warnings when the post will degrade badly on Mastodon-compatible platforms.</div>';
                $html .= '</div>';
                $html .= '<div class="d-flex flex-wrap gap-2 align-items-center">';
                $html .= '<span class="badge ' . $escape( $readiness_badge_class ) . '" id="tm-editor-fediverse-readiness-badge">' . $escape( $readiness_badge_text ) . '</span>';
                $html .= '<span class="small text-body-secondary font-monospace" id="tm-editor-fediverse-character-count">' . $escape( (string) ( (int) ( $preview['character_count'] ?? 0 ) ) . ' / ' . $character_limit ) . '</span>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '<div class="small text-body-secondary mb-2">Outbound preview</div>';
                $html .= '<pre class="form-control font-monospace bg-body overflow-auto" id="tm-editor-fediverse-preview" style="min-height: 10rem; white-space: pre-wrap;">' . $escape( (string) ( $preview['status_text'] ?? '' ) ) . '</pre>';
                $html .= '<div class="row g-2 mt-1 small text-body-secondary">';
                $html .= '<div class="col-12 col-lg-4">Character limit: <span class="fw-semibold text-body" id="tm-editor-fediverse-character-limit">' . $escape( (string) $character_limit ) . '</span></div>';
                $html .= '<div class="col-12 col-lg-4">Link back: <span class="fw-semibold text-body" id="tm-editor-fediverse-link-back-state">' . ( ! empty( $preview['include_link_back'] ) ? 'Included' : 'Off' ) . '</span></div>';
                $html .= '<div class="col-12 col-lg-4">Truncation: <span class="fw-semibold text-body" id="tm-editor-fediverse-truncation-state">' . ( ! empty( $preview['will_truncate'] ) ? 'Yes, from ' . (int) ( $preview['raw_character_count'] ?? 0 ) . ' characters' : 'No' ) . '</span></div>';
                $html .= '</div>';
                $html .= '<div class="mt-3">';
                $html .= '<div class="small text-uppercase text-body-secondary mb-2">Warnings</div>';
                $html .= '<ul class="mb-0 ps-3 small text-body-secondary" id="tm-editor-fediverse-warnings">';
                if ( empty( $warnings ) ) {
                    $html .= '<li>No current rendering warnings.</li>';
                } else {
                    foreach ( $warnings as $warning ) {
                        if ( ! is_string( $warning ) || trim( $warning ) === '' ) {
                            continue;
                        }
                        $html .= '<li>' . $escape( trim( $warning ) ) . '</li>';
                    }
                }
                $html .= '</ul>';
                $html .= '</div>';
                $html .= '<div class="small text-body-secondary mt-3" id="tm-editor-fediverse-readiness-message">' . $escape( $message !== '' ? $message : 'Only published posts can be sent to the Fediverse.' ) . '</div>';
                $html .= '</section>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '<script>(function(){'
                    . 'const root=document.getElementById("tm-editor-fediverse-readiness-root");'
                    . 'if(!root||root.dataset.bound==="1"){return;}'
                    . 'root.dataset.bound="1";'
                    . 'const editorRoot=document.getElementById("tm-editor-root");'
                    . 'const titleField=document.getElementById("tm-editor-title");'
                    . 'const summaryField=document.getElementById("tm-editor-summary");'
                    . 'const markdownField=document.getElementById("tm-editor-markdown");'
                    . 'const entryTypeField=document.getElementById("tm-editor-entry-type");'
                    . 'const statusField=document.getElementById("tm-editor-status-field");'
                    . 'const scopeField=document.getElementById("tm-editor-scope");'
                    . 'const authorSlugField=document.getElementById("tm-editor-author-slug");'
                    . 'const slugField=document.getElementById("tm-editor-slug");'
                    . 'const tagsField=document.getElementById("tm-editor-tags");'
                    . 'const postEnabledField=document.getElementById("tm-editor-fediverse-post-enabled");'
                    . 'const linkBackField=document.getElementById("tm-editor-fediverse-link-back");'
                    . 'const statusAlert=document.getElementById("tm-editor-fediverse-status-alert");'
                    . 'const statusText=document.getElementById("tm-editor-fediverse-status-text");'
                    . 'const statusUrlWrap=document.getElementById("tm-editor-fediverse-status-url-wrap");'
                    . 'const statusUrl=document.getElementById("tm-editor-fediverse-status-url");'
                    . 'const readinessBadge=document.getElementById("tm-editor-fediverse-readiness-badge");'
                    . 'const previewField=document.getElementById("tm-editor-fediverse-preview");'
                    . 'const warningsList=document.getElementById("tm-editor-fediverse-warnings");'
                    . 'const readinessMessage=document.getElementById("tm-editor-fediverse-readiness-message");'
                    . 'const characterCount=document.getElementById("tm-editor-fediverse-character-count");'
                    . 'const characterLimit=document.getElementById("tm-editor-fediverse-character-limit");'
                    . 'const linkBackState=document.getElementById("tm-editor-fediverse-link-back-state");'
                    . 'const truncationState=document.getElementById("tm-editor-fediverse-truncation-state");'
                    . 'const csrfToken=editorRoot?String(editorRoot.dataset.csrfToken||""):"";'
                    . 'if(!editorRoot||!titleField||!summaryField||!markdownField||!entryTypeField||!statusField||!scopeField||!slugField||!postEnabledField||!linkBackField||!statusAlert||!statusText||!statusUrlWrap||!statusUrl||!readinessBadge||!previewField||!warningsList||!readinessMessage||!characterCount||!characterLimit||!linkBackState||!truncationState){return;}'
                    . 'let timer=null;let controller=null;'
                    . 'function badge(state,reason){if(state==="ready"){return["text-bg-success","Ready"];}if(state==="warning"){return["text-bg-warning","Ready with warnings"];}if(reason==="disabled"){return["text-bg-info","Posting off"];}if(reason==="status"){return["text-bg-secondary","Unpublished"];}if(reason==="entry_type"){return["text-bg-secondary","Not a post"];}if(reason==="credentials"){return["text-bg-danger","Account missing"];}if(reason==="visibility"){return["text-bg-danger","Not public"];}return["text-bg-secondary","Will not post"];}'
                    . 'function render(payload){const preview=payload&&payload.preview&&typeof payload.preview==="object"?payload.preview:{};const warnings=Array.isArray(payload&&payload.warnings)?payload.warnings:[];const badgeState=badge(String(payload&&payload.state||"blocked"),String(payload&&payload.reason||""));const instanceUrl=String(payload&&payload.account_settings&&payload.account_settings.instance_url||"");const message=String(payload&&payload.message||"");const rawCount=Number.parseInt(preview.raw_character_count||0,10)||0;readinessBadge.className="badge "+badgeState[0];readinessBadge.textContent=badgeState[1];previewField.textContent=String(preview.status_text||"");characterCount.textContent=String(Number.parseInt(preview.character_count||0,10)||0)+" / "+String(Number.parseInt(preview.character_limit||500,10)||500);characterLimit.textContent=String(Number.parseInt(preview.character_limit||500,10)||500);linkBackState.textContent=preview.include_link_back?"Included":"Off";truncationState.textContent=preview.will_truncate?"Yes, from "+String(rawCount)+" characters":"No";readinessMessage.textContent=message;statusText.textContent=message;statusAlert.className="alert mb-0 "+(payload&&payload.eligible?"alert-success":"alert-secondary");statusUrl.textContent=instanceUrl;statusUrlWrap.classList.toggle("d-none",instanceUrl==="");if(warnings.length===0){warningsList.innerHTML="<li>No current rendering warnings.</li>";return;}warningsList.innerHTML=warnings.map((warning)=>"<li>"+String(warning).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;")+"</li>").join("");}'
                    . 'function collect(){return{tinymash_csrf:csrfToken,title:String(titleField.value||""),summary:String(summaryField.value||""),content:String(markdownField.value||""),entry_type:String(entryTypeField.value||"post"),status:String(statusField.value||"unpublished"),scope:String(scopeField.value||"root"),author_slug:authorSlugField?String(authorSlugField.value||""):"",slug:String(slugField.value||""),tags:String(tagsField?tagsField.value||"":""),
plugin_settings:{fediverse:{post_enabled:postEnabledField.checked?"1":"0",include_link_back:linkBackField.checked?"1":"0"}}};}'
                    . 'function schedule(){if(timer){window.clearTimeout(timer);}timer=window.setTimeout(refresh,250);}'
                    . 'async function refresh(){if(controller){controller.abort();}controller=new AbortController();try{const response=await fetch(String(root.dataset.readinessUrl||""),{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin",body:JSON.stringify(collect()),signal:controller.signal});const payload=await response.json().catch(()=>({}));if(!response.ok){throw new Error(String(payload&&payload.error||"Fediverse readiness check failed."));}render(payload);}catch(error){if(error&&error.name==="AbortError"){return;}readinessMessage.textContent=error&&error.message?error.message:"Fediverse readiness check failed.";}}'
                    . '[titleField,summaryField,markdownField,entryTypeField,statusField,scopeField,authorSlugField,slugField,tagsField,postEnabledField,linkBackField].forEach((field)=>{if(!field){return;}field.addEventListener("input",schedule);field.addEventListener("change",schedule);});'
                    . 'document.addEventListener("tinymash:editor-plugin-pane-selected",(event)=>{const detail=event&&event.detail?event.detail:{};if(detail.plugin==="fediverse"||detail.plugin==="mastodon"||detail.mode==="plugin-fediverse"||detail.mode==="plugin-mastodon"||detail.pane&&detail.pane.contains(root)){refresh();}});'
                    . '})();</script>';
                return( $html );
            },
        ]
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $fediverse_readiness_url,
        static function() use ( $plugins, $login_url, $security, $fediverse_service, $plugin_key, $send_json, $parse_tags ) : void {
            if ( ! $security->isLoggedIn() ) {
                $send_json( [ 'error' => 'Authentication required.' ], 403 );
                return;
            }

            $raw_body = file_get_contents( 'php://input' );
            $payload = json_decode( is_string( $raw_body ) ? $raw_body : '', true );
            $payload = is_array( $payload ) ? $payload : [];
            if ( ! $security->validateCsrfToken( isset( $payload['tinymash_csrf'] ) ? (string) $payload['tinymash_csrf'] : '' ) ) {
                $send_json( [ 'error' => 'Invalid CSRF token.' ], 403 );
                return;
            }

            $author_slug = '';
            if ( ! $security->isSuperAdmin() ) {
                $author_slug = strtolower( trim( (string) $security->getCurrentUsername() ) );
            } else {
                $author_slug = strtolower( trim( (string) ( $payload['author_slug'] ?? '' ) ) );
            }

            $draft = [
                'title' => trim( (string) ( $payload['title'] ?? '' ) ),
                'summary' => trim( (string) ( $payload['summary'] ?? '' ) ),
                'content' => (string) ( $payload['content'] ?? '' ),
                'entry_type' => strtolower( trim( (string) ( $payload['entry_type'] ?? 'post' ) ) ),
                'type' => strtolower( trim( (string) ( $payload['entry_type'] ?? 'post' ) ) ),
                'status' => strtolower( trim( (string) ( $payload['status'] ?? 'unpublished' ) ) ),
                'scope' => strtolower( trim( (string) ( $payload['scope'] ?? ( $security->isSuperAdmin() ? 'root' : 'author' ) ) ) ),
                'author_slug' => $author_slug,
                'slug' => trim( (string) ( $payload['slug'] ?? '' ) ),
                'tags' => $parse_tags( (string) ( $payload['tags'] ?? '' ) ),
                'plugin_settings' => [
                    'fediverse' => [
                        'post_enabled' => ! empty( $payload['plugin_settings']['fediverse']['post_enabled'] ?? null ) ? '1' : '0',
                        'include_link_back' => ! empty( $payload['plugin_settings']['fediverse']['include_link_back'] ?? null ) ? '1' : '0',
                    ],
                ],
            ];

            if ( $draft['scope'] !== 'author' ) {
                $draft['scope'] = 'root';
                $draft['author_slug'] = '';
            }
            if ( ! $security->isSuperAdmin() ) {
                $draft['scope'] = 'author';
                $draft['author_slug'] = $author_slug;
            }

            $readiness = $fediverse_service->buildDeliveryReadiness(
                $draft,
                $plugins->getPluginSystemSettings( $plugin_key )
            );
            $send_json( $readiness, 200 );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $fediverse_url,
        static function() use ( $plugins, $login_url, $security, $render_page ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }

            $render_page();
        }
    );
};
