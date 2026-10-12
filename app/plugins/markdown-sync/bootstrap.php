<?php

require_once __DIR__ . '/TinyMashMarkdownCollectionService.php';
require_once __DIR__ . '/TinyMashMarkdownSyncRequestAuthentication.php';
require_once __DIR__ . '/TinyMashMarkdownSyncService.php';
require_once __DIR__ . '/TinyMashMarkdownSyncClient.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'markdown-sync' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $content_repository = $plugins->getService( 'content.repository' );
    $date_formatter = $plugins->getService( 'date.formatter' );
    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $content_repository instanceof TinyMashContentRepository || ! $date_formatter instanceof TinyMashDateFormatter ) {
        throw new \RuntimeException( 'Required services for the Markdown Sync plugin are not available.' );
    }

    $collection_service = new TinyMashMarkdownCollectionService();
    $sync_service = new TinyMashMarkdownSyncService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'markdown-sync' . DIRECTORY_SEPARATOR . 'connections.json',
        $content_repository,
        $collection_service,
        static fn( ?array $previous_entry, ?array $saved_entry, array $context ) : array => $plugins->runContentLifecycleHandlers( $previous_entry, $saved_entry, $context ),
        (string) ( parse_url( (string) ( $config->configGetBaseURL() ?: '' ), PHP_URL_PATH ) ?: '' )
    );
    \Flight::set( 'markdown-sync.service', $sync_service );

    $parse_cli_options = static function( array $arguments ) : array {
        $options = [
            'action' => strtolower( trim( (string) ( $arguments[0] ?? '' ) ) ),
            'source_directory' => trim( (string) ( $arguments[1] ?? '' ) ),
            'profile' => '',
            'prune' => false,
        ];
        foreach ( array_slice( $arguments, 2 ) as $argument ) {
            $argument = (string) $argument;
            if ( str_starts_with( $argument, '--profile=' ) ) {
                $options['profile'] = trim( substr( $argument, 10 ) );
                continue;
            }
            if ( $argument === '--prune' ) {
                $options['prune'] = true;
                continue;
            }
            throw new \RuntimeException( 'Unknown option "' . $argument . '".' );
        }
        return( $options );
    };

    $write_cli_result = static function( array $result ) : void {
        fwrite( STDOUT, 'collection: ' . (string) ( $result['collection_id'] ?? '-' ) . PHP_EOL );
        fwrite( STDOUT, 'target: ' . (string) ( $result['target_path'] ?? '-' ) . PHP_EOL );
        $counts = is_array( $result['counts'] ?? null ) ? $result['counts'] : [];
        foreach ( [ 'create', 'update', 'move', 'unchanged', 'stale', 'trash', 'conflict' ] as $action ) {
            $count = (int) ( $counts[$action] ?? 0 );
            if ( $count > 0 ) {
                fwrite( STDOUT, $action . ': ' . $count . PHP_EOL );
            }
        }
        foreach ( is_array( $result['items'] ?? null ) ? $result['items'] : [] as $item ) {
            if ( ! is_array( $item ) || (string) ( $item['action'] ?? '' ) === 'unchanged' ) {
                continue;
            }
            $line = (string) ( $item['action'] ?? 'unknown' ) . ' ' . ( (string) ( $item['relative_path'] ?? '' ) !== '' ? (string) $item['relative_path'] : '.' );
            if ( ! empty( $item['reason'] ) ) {
                $line .= ' (' . (string) $item['reason'] . ')';
            }
            fwrite( STDOUT, $line . PHP_EOL );
        }
        if ( ! empty( $result['applied'] ) ) {
            fwrite( STDOUT, 'saved: ' . (int) ( $result['saved'] ?? 0 ) . PHP_EOL );
            fwrite( STDOUT, 'trashed: ' . (int) ( $result['trashed'] ?? 0 ) . PHP_EOL );
        }
    };

    if ( $plugins->isCliRuntime() ) {
        $client = new TinyMashMarkdownSyncClient( $collection_service );
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'markdown-sync',
                'summary' => 'Plan or push a local Markdown collection to a configured tinymash receiver.',
                'arguments' => [
                    [ 'name' => 'action', 'required' => true, 'description' => 'Preview changes or apply them', 'choices' => [ 'plan', 'push' ] ],
                    [ 'name' => 'source-directory', 'required' => true, 'description' => 'Collection directory containing the root index.md' ],
                ],
                'options' => [
                    [ 'name' => 'profile', 'value' => true, 'required' => true, 'description' => 'Owner-readable JSON file containing the receiver URL, token, and optional HTTP Basic credentials' ],
                    [ 'name' => 'prune', 'description' => 'Move managed receiver pages missing from the source to Trash' ],
                ],
                'examples' => [
                    'php8.4 bin/tinymash.php markdown-sync plan site-docs --profile=.markdown-sync.json',
                    'php8.4 bin/tinymash.php markdown-sync push site-docs --profile=.markdown-sync.json --prune',
                ],
                'notes' => 'The receiver chooses the destination when it creates the token. Use http_basic in the profile when the whole receiving site has HTTP Basic authentication. The profile file must use mode 600 on Unix-like systems.',
                'order' => 230,
                'handler' => static function( array $arguments ) use ( $parse_cli_options, $client, $write_cli_result ) : void {
                    $options = $parse_cli_options( $arguments );
                    if ( (string) $options['profile'] === '' ) {
                        throw new \RuntimeException( 'The --profile option is required.' );
                    }
                    $result = $client->run(
                        (string) $options['action'],
                        (string) $options['source_directory'],
                        (string) $options['profile'],
                        ! empty( $options['prune'] )
                    );
                    $write_cli_result( $result );
                },
            ]
        );
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/markdown-sync';
    $create_url = $plugin_url . '/create';
    $rotate_url = $plugin_url . '/rotate';
    $toggle_url = $plugin_url . '/toggle';
    $delete_url = $plugin_url . '/delete';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'markdown-sync',
            'label' => 'Markdown Sync',
            'url' => $plugin_url,
            'icon' => 'bi-file-earmark-arrow-up',
            'order' => 92,
        ]
    );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );
    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-markdown-sync',
        [
            'title' => 'Markdown Sync',
            'summary' => 'Publish a local Markdown collection below a selected page.',
            'documentation_url' => 'https://tinymash.joho.se/docs/plugins/markdown-sync-plugin',
            'documentation_label' => 'Markdown Sync',
            'group' => 'Plugins',
            'order' => 145,
            'roles' => [ 'admin' ],
            'sections' => [
                [ 'title' => 'Connections', 'markdown' => 'An admin selects the destination page when creating a connection. The token cannot choose or change that destination.' ],
                [ 'title' => 'Plans', 'markdown' => 'Run `markdown-sync plan` before pushing. A push is rejected if receiver content changed after the plan was created.' ],
                [ 'title' => 'Links', 'markdown' => 'Use file-relative links between collection pages. The receiver resolves them below the selected destination; a missing page link stops the plan.' ],
                [ 'title' => 'Removal', 'markdown' => 'Source pages are retained by default. Use `--prune` to move missing managed pages to Trash.' ],
            ],
        ]
    );

    $escape = static fn( string $value ) : string => htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    $require_admin = static function() use ( $plugins, $security, $login_url ) : bool {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return( false );
        }
        if ( ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            return( false );
        }
        return( true );
    };

    $render_page = static function() use ( $plugins, $security, $sync_service, $date_formatter, $plugin_url, $create_url, $rotate_url, $toggle_url, $delete_url, $escape ) : void {
        $notice = $security->pullFlash( 'markdown-sync.notice', [] );
        $token = (string) $security->pullFlash( 'markdown-sync.token', '' );
        $body = '<div class="d-grid gap-4">';
        if ( $token !== '' ) {
            $body .= '<section class="bg-body-secondary rounded-3 p-3 d-grid gap-2"><div class="fw-semibold">Copy this token now</div><code class="d-block text-break user-select-all">' . $escape( $token ) . '</code><div class="small text-body-secondary">It is not shown again. Store it in the sender connection profile.</div></section>';
        }

        $body .= '<section class="bg-body-secondary rounded-3 p-3">';
        $body .= '<form method="post" action="' . $escape( $create_url ) . '" class="row g-3 align-items-end">';
        $body .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $body .= '<div class="col-12 col-lg-4"><label class="form-label" for="tm-markdown-sync-label">Connection name</label><input class="form-control" id="tm-markdown-sync-label" name="label" maxlength="120" required></div>';
        $body .= '<div class="col-12 col-lg-6"><label class="form-label" for="tm-markdown-sync-target">Destination page</label><select class="form-select" id="tm-markdown-sync-target" name="target_entry_id" required><option value="">Choose a root page</option>';
        foreach ( $sync_service->getTargetOptions() as $option ) {
            $body .= '<option value="' . $escape( (string) $option['id'] ) . '">' . $escape( (string) $option['label'] . ' — ' . (string) $option['path'] ) . '</option>';
        }
        $body .= '</select></div><div class="col-12 col-lg-2"><button class="btn btn-primary w-100" type="submit">Create</button></div>';
        $body .= '<div class="col-12 form-text mt-2">The destination is fixed to this connection. The sender cannot change it.</div></form></section>';

        $connections = $sync_service->listConnections();
        $format_time = static fn( string $timestamp ) : string => $timestamp !== '' ? $date_formatter->formatUtcDateTime( $timestamp ) : 'Never';
        if ( empty( $connections ) ) {
            $body .= '<p class="text-body-secondary mb-0">No connections have been created.</p>';
        } else {
            $build_actions = static function( array $connection, bool $justify_end ) use ( $security, $toggle_url, $rotate_url, $delete_url, $escape ) : string {
                $enabled = ! empty( $connection['enabled'] );
                $actions = '<div class="d-flex flex-wrap gap-2' . ( $justify_end ? ' justify-content-end' : '' ) . '">';
                $actions .= '<form method="post" action="' . $escape( $toggle_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="connection_id" value="' . $escape( (string) $connection['id'] ) . '"><input type="hidden" name="enabled" value="' . ( $enabled ? '0' : '1' ) . '"><button class="btn btn-outline-secondary btn-sm" type="submit">' . ( $enabled ? 'Disable' : 'Enable' ) . '</button></form>';
                $actions .= '<form method="post" action="' . $escape( $rotate_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="connection_id" value="' . $escape( (string) $connection['id'] ) . '"><button class="btn btn-outline-secondary btn-sm" type="submit">Rotate token</button></form>';
                if ( empty( $connection['pages'] ) ) {
                    $actions .= '<form method="post" action="' . $escape( $delete_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="connection_id" value="' . $escape( (string) $connection['id'] ) . '"><button class="btn btn-outline-danger btn-sm" type="submit">Delete</button></form>';
                }
                return( $actions . '</div>' );
            };

            $body .= '<div class="d-none d-lg-block table-responsive"><table class="table align-middle mb-0"><thead><tr><th scope="col">Connection</th><th scope="col">Destination</th><th scope="col">Activity</th><th scope="col" class="text-end">Actions</th></tr></thead><tbody>';
            foreach ( $connections as $connection ) {
                $enabled = ! empty( $connection['enabled'] );
                $target = is_array( $connection['target'] ?? null ) ? $connection['target'] : [];
                $body .= '<tr><td><div class="fw-semibold">' . $escape( (string) $connection['label'] ) . '</div><div class="small text-body-secondary">' . ( $enabled ? 'Enabled' : 'Disabled' ) . ( ! empty( $connection['collection_id'] ) ? ' · ' . $escape( (string) $connection['collection_id'] ) : '' ) . '</div></td>';
                $body .= '<td>' . ( ! empty( $connection['target_available'] ) ? $escape( (string) ( $target['path'] ?? '' ) ) : '<span class="text-danger">Destination unavailable</span>' ) . '</td>';
                $body .= '<td><div>Used: ' . $escape( $format_time( (string) ( $connection['last_used_at_utc'] ?? '' ) ) ) . '</div><div class="small text-body-secondary">Synced: ' . $escape( $format_time( (string) ( $connection['last_sync_at_utc'] ?? '' ) ) ) . '</div></td><td>' . $build_actions( $connection, true ) . '</td></tr>';
            }
            $body .= '</tbody></table></div>';
            $body .= '<div class="d-grid gap-3 d-lg-none">';
            foreach ( $connections as $connection ) {
                $enabled = ! empty( $connection['enabled'] );
                $target = is_array( $connection['target'] ?? null ) ? $connection['target'] : [];
                $body .= '<section class="border-bottom pb-3 d-grid gap-2"><div><div class="fw-semibold">' . $escape( (string) $connection['label'] ) . '</div><div class="small text-body-secondary">' . ( $enabled ? 'Enabled' : 'Disabled' ) . ( ! empty( $connection['collection_id'] ) ? ' · ' . $escape( (string) $connection['collection_id'] ) : '' ) . '</div></div>';
                $body .= '<dl class="row g-1 small mb-1"><dt class="col-4">Destination</dt><dd class="col-8 mb-0">' . ( ! empty( $connection['target_available'] ) ? $escape( (string) ( $target['path'] ?? '' ) ) : '<span class="text-danger">Unavailable</span>' ) . '</dd><dt class="col-4">Last use</dt><dd class="col-8 mb-0">' . $escape( $format_time( (string) ( $connection['last_used_at_utc'] ?? '' ) ) ) . '</dd><dt class="col-4">Last sync</dt><dd class="col-8 mb-0">' . $escape( $format_time( (string) ( $connection['last_sync_at_utc'] ?? '' ) ) ) . '</dd></dl>';
                $body .= $build_actions( $connection, false ) . '</section>';
            }
            $body .= '</div>';
        }
        $body .= '</div>';

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Markdown Sync',
                'current_section' => 'markdown-sync',
                'current_location' => 'markdown-sync',
                'help_contexts' => [ 'plugin-markdown-sync' ],
                'plugin_page_kicker' => 'Markdown Sync',
                'plugin_page_title' => 'Markdown connections',
                'plugin_page_summary' => 'Receive a local Markdown collection below a selected page.',
                'plugin_page_notice' => is_array( $notice ) ? $notice : [],
                'plugin_page_body_html' => $body,
            ]
        );
    };

    $validate_admin_post = static function() use ( $plugins, $security, $require_admin, $plugin_url ) : ?array {
        if ( ! $require_admin() ) {
            return( null );
        }
        $data = $plugins->getRequestData();
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $security->setFlash( 'markdown-sync.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Reload the page and try again.' ] );
            $plugins->redirect( $plugin_url );
            return( null );
        }
        return( $data );
    };

    $plugins->registerRoute( $plugin_key, 'get', $plugin_url, static function() use ( $require_admin, $render_page ) : void {
        if ( $require_admin() ) {
            $render_page();
        }
    } );
    $plugins->registerRoute( $plugin_key, 'post', $create_url, static function() use ( $validate_admin_post, $sync_service, $security, $plugins, $plugin_url ) : void {
        $data = $validate_admin_post();
        if ( ! is_array( $data ) ) {
            return;
        }
        try {
            $created = $sync_service->createConnection( (string) ( $data['label'] ?? '' ), (string) ( $data['target_entry_id'] ?? '' ) );
            $security->setFlash( 'markdown-sync.token', (string) $created['token'] );
            $security->setFlash( 'markdown-sync.notice', [ 'type' => 'success', 'message' => 'Connection created.' ] );
        } catch ( \Throwable $exception ) {
            error_log( 'markdown-sync plugin: create failed (' . $exception->getMessage() . ')' );
            $security->setFlash( 'markdown-sync.notice', [ 'type' => 'danger', 'message' => 'The connection could not be created. Check the name and destination.' ] );
        }
        $plugins->redirect( $plugin_url );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $rotate_url, static function() use ( $validate_admin_post, $sync_service, $security, $plugins, $plugin_url ) : void {
        $data = $validate_admin_post();
        if ( ! is_array( $data ) ) {
            return;
        }
        try {
            $token = $sync_service->rotateToken( (string) ( $data['connection_id'] ?? '' ) );
            $security->setFlash( 'markdown-sync.token', $token );
            $security->setFlash( 'markdown-sync.notice', [ 'type' => 'success', 'message' => 'Token rotated. The previous token no longer works.' ] );
        } catch ( \Throwable $exception ) {
            error_log( 'markdown-sync plugin: rotation failed (' . $exception->getMessage() . ')' );
            $security->setFlash( 'markdown-sync.notice', [ 'type' => 'danger', 'message' => 'The token could not be rotated.' ] );
        }
        $plugins->redirect( $plugin_url );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $toggle_url, static function() use ( $validate_admin_post, $sync_service, $security, $plugins, $plugin_url ) : void {
        $data = $validate_admin_post();
        if ( ! is_array( $data ) ) {
            return;
        }
        try {
            $enabled = (string) ( $data['enabled'] ?? '' ) === '1';
            $sync_service->setConnectionEnabled( (string) ( $data['connection_id'] ?? '' ), $enabled );
            $security->setFlash( 'markdown-sync.notice', [ 'type' => 'success', 'message' => $enabled ? 'Connection enabled.' : 'Connection disabled.' ] );
        } catch ( \Throwable $exception ) {
            error_log( 'markdown-sync plugin: state change failed (' . $exception->getMessage() . ')' );
            $security->setFlash( 'markdown-sync.notice', [ 'type' => 'danger', 'message' => 'The connection could not be updated.' ] );
        }
        $plugins->redirect( $plugin_url );
    } );
    $plugins->registerRoute( $plugin_key, 'post', $delete_url, static function() use ( $validate_admin_post, $sync_service, $security, $plugins, $plugin_url ) : void {
        $data = $validate_admin_post();
        if ( ! is_array( $data ) ) {
            return;
        }
        try {
            $deleted = $sync_service->deleteUnusedConnection( (string) ( $data['connection_id'] ?? '' ) );
            $security->setFlash(
                'markdown-sync.notice',
                $deleted
                    ? [ 'type' => 'success', 'message' => 'Unused connection deleted.' ]
                    : [ 'type' => 'warning', 'message' => 'That connection could not be found.' ]
            );
        } catch ( \Throwable $exception ) {
            error_log( 'markdown-sync plugin: deletion failed (' . $exception->getMessage() . ')' );
            $security->setFlash( 'markdown-sync.notice', [ 'type' => 'danger', 'message' => 'Only a connection without synchronized pages can be deleted.' ] );
        }
        $plugins->redirect( $plugin_url );
    } );

    $emit_json = static function( int $status, array $payload ) use ( $plugins ) : void {
        $plugins->setResponseStatus( $status );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Cache-Control: no-store, max-age=0' );
        header( 'X-Content-Type-Options: nosniff' );
        echo json_encode( $plugins->resolveOutputData( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
    };
    $receive_request = static function() use ( $sync_service ) : array {
        $content_length = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
        if ( $content_length <= 0 || $content_length > 11534336 ) {
            throw new \LengthException( 'request_size' );
        }
        $sync_token = TinyMashMarkdownSyncRequestAuthentication::extractToken( $_SERVER );
        $connection = $sync_service->authenticateToken( $sync_token, (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        $raw_body = file_get_contents( 'php://input' );
        $request = is_string( $raw_body ) ? json_decode( $raw_body, true, 512 ) : null;
        if ( ! is_array( $request ) || ! is_array( $request['bundle'] ?? null ) ) {
            throw new \InvalidArgumentException( 'request_json' );
        }
        if ( array_diff( array_keys( $request ), [ 'bundle', 'plan_id', 'prune' ] ) !== [] ) {
            throw new \InvalidArgumentException( 'request_field' );
        }
        return( [
            'connection' => $connection,
            'bundle' => $request['bundle'],
            'plan_id' => trim( (string) ( $request['plan_id'] ?? '' ) ),
            'prune' => ! empty( $request['prune'] ),
        ] );
    };
    $handle_api = static function( string $action ) use ( $receive_request, $sync_service, $emit_json ) : void {
        try {
            $request = $receive_request();
            if ( $action === 'apply' ) {
                @set_time_limit( 300 );
            }
            $result = $action === 'apply'
                ? $sync_service->applyPlan( $request['connection'], (string) $request['plan_id'], $request['bundle'], ! empty( $request['prune'] ) )
                : $sync_service->createPlan( $request['connection'], $request['bundle'], ! empty( $request['prune'] ) );
            $emit_json( 200, [ 'ok' => true, 'result' => $result ] );
        } catch ( \Throwable $exception ) {
            $error = $exception->getMessage();
            $status = match ( $error ) {
                'authentication_failed' => 401,
                'authentication_limited' => 429,
                'target_unavailable', 'target_owned', 'connection_unavailable' => 409,
                'plan_expired', 'plan_changed', 'receiver_changed', 'plan_conflicts', 'collection_mismatch', 'collection_root_mismatch' => 409,
                'request_size' => 413,
                'request_json', 'request_field', 'bundle_field', 'bundle_format', 'collection_id', 'page_count', 'page_record', 'page_field', 'page_id', 'page_id_duplicate', 'page_path_duplicate', 'page_depth', 'page_title', 'page_summary', 'page_markdown', 'bundle_size', 'page_status', 'page_order', 'page_root', 'page_parent', 'page_path', 'page_link' => 400,
                default => 500,
            };
            $messages = [
                'authentication_failed' => 'The connection token is invalid or disabled.',
                'authentication_limited' => 'Too many failed authentication attempts. Try again later.',
                'target_unavailable' => 'The connection destination is unavailable.',
                'target_owned' => 'The connection destination is managed by another Markdown connection.',
                'connection_unavailable' => 'The connection is unavailable.',
                'plan_expired' => 'The plan expired. Run the command again.',
                'plan_changed' => 'The source or prune option changed after planning.',
                'receiver_changed' => 'Receiver content changed after planning. Run the command again.',
                'plan_conflicts' => 'The plan contains conflicts. No content was changed.',
                'collection_mismatch' => 'This token is already bound to a different collection.',
                'collection_root_mismatch' => 'The collection root ID does not match the previous sync.',
                'request_size' => 'The request is too large.',
                'request_json' => 'The request body is invalid.',
                'request_field' => 'The request contains an unsupported field.',
                'bundle_field' => 'The collection contains an unsupported top-level field.',
                'page_field' => 'A collection page contains an unsupported field.',
                'page_link' => 'A relative page link does not resolve inside this collection.',
            ];
            if ( $status >= 500 ) {
                error_log( 'markdown-sync plugin: API failed (' . $error . ')' );
            }
            $public_error = $status >= 500 ? 'server_error' : $error;
            $emit_json( $status, [ 'ok' => false, 'error' => $public_error, 'message' => $messages[$error] ?? 'The Markdown collection could not be processed.' ] );
        }
    };

    $plugins->registerRoute( $plugin_key, 'post', '/markdown-sync/v1/plan', static fn() => $handle_api( 'plan' ) );
    $plugins->registerRoute( $plugin_key, 'post', '/markdown-sync/v1/apply', static fn() => $handle_api( 'apply' ) );
};
