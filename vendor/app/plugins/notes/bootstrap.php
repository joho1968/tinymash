<?php

require_once __DIR__ . '/TinyMashNotesRepository.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'notes' );
    $config = $plugins->getService( 'config' );
    $project_root = dirname( __DIR__, 3 );
    $notes_repository = new TinyMashNotesRepository(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'notes'
    );

    if ( ! $config instanceof TinyMashConfig ) {
        throw new \RuntimeException( 'Required services for the notes plugin are not available.' );
    }

    $plugins->registerHousekeepingTask(
        $plugin_key,
        static function() use ( $config, $notes_repository ) : string {
            $revision_limit = $config->getContentRevisionRetentionLimit();
            $result = $notes_repository->pruneAllRevisions( $revision_limit );
            return(
                'removed '
                . (int) ( $result['removed'] ?? 0 )
                . ' stale note revision(s) across '
                . (int) ( $result['users'] ?? 0 )
                . ' note owner(s)'
            );
        },
        'revisions',
        'Prune note revisions'
    );

    $plugins->registerExportContributor(
        $plugin_key,
        static function( array $context ) use ( $notes_repository ) : string {
            $scope = strtolower( trim( (string) ( $context['scope'] ?? '' ) ) );
            $plugins_directory = rtrim( (string) ( $context['plugins_directory'] ?? '' ), DIRECTORY_SEPARATOR );
            if ( $plugins_directory === '' ) {
                return( 'No plugin export directory was provided.' );
            }

            $notes_root = $plugins_directory . DIRECTORY_SEPARATOR . 'notes';
            if ( $scope === 'author' ) {
                $username = trim( (string) ( $context['author_username'] ?? '' ) );
                if ( $username === '' ) {
                    return( 'No author username was provided for the notes export.' );
                }

                $result = $notes_repository->exportUserData( $username, $notes_root . DIRECTORY_SEPARATOR . $username );
                return(
                    ! empty( $result['exported'] )
                    ? 'Exported notes data for "' . $username . '".'
                    : 'No notes data existed for "' . $username . '".'
                );
            }

            $result = $notes_repository->exportAllUsersData( $notes_root );
            return(
                'Exported notes data for '
                . (int) ( $result['exported'] ?? 0 )
                . ' user(s).'
            );
        },
        'both',
        'Notes data'
    );

    $plugins->registerImportContributor(
        $plugin_key,
        static function( array $context ) use ( $notes_repository ) : string {
            $scope = strtolower( trim( (string) ( $context['scope'] ?? '' ) ) );
            $plugins_directory = rtrim( (string) ( $context['plugins_directory'] ?? '' ), DIRECTORY_SEPARATOR );
            if ( $plugins_directory === '' ) {
                return( 'No plugin import directory was provided.' );
            }

            $notes_root = $plugins_directory . DIRECTORY_SEPARATOR . 'notes';
            if ( $scope === 'author' ) {
                $username = trim( (string) ( $context['author_username'] ?? '' ) );
                if ( $username === '' ) {
                    return( 'No author username was provided for the notes import.' );
                }

                $result = $notes_repository->importUserData(
                    $username,
                    $notes_root . DIRECTORY_SEPARATOR . $username,
                    ! empty( $context['replace_existing'] )
                );
                return(
                    ! empty( $result['imported'] )
                    ? 'Imported notes data for "' . $username . '".'
                    : 'No notes data existed for "' . $username . '" in the import bundle.'
                );
            }

            $result = $notes_repository->importAllUsersData( $notes_root, ! empty( $context['replace_existing'] ) );
            return(
                'Imported notes data for '
                . (int) ( $result['imported'] ?? 0 )
                . ' user(s).'
            );
        },
        'both',
        'Notes data'
    );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $security = $plugins->getService( 'security' );
    $markdown_renderer = $plugins->getService( 'markdown.renderer' );
    $date_formatter = $plugins->getService( 'date.formatter' );
    $user_repository = $plugins->getService( 'user.repository' );

    if ( ! $security instanceof TinyMashSecurity || ! $markdown_renderer instanceof TinyMashMarkdownRenderer || ! $date_formatter instanceof TinyMashDateFormatter || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the notes plugin are not available.' );
    }
    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $notes_url = $admin_url . '/notes';
    $notes_save_url = $notes_url . '/save';
    $notes_draft_url = $notes_url . '/draft';
    $notes_draft_delete_url = $notes_url . '/draft/delete';
    $notes_exit_url = $admin_url . '/author';
    $preview_url = $admin_url . '/preview/markdown';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'author',
        [
            'section' => 'notes',
            'label' => 'Notes',
            'url' => $notes_url,
            'icon' => 'bi-journal-richtext',
            'order' => 70,
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-notes',
        [
            'title' => 'Notes',
            'summary' => 'A private per-user Markdown note inside admin.',
            'group' => 'Plugins',
            'order' => 100,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it is',
                    'markdown' => 'Notes gives each logged-in user one private note. It is only available in admin, never public, and never included in feeds.',
                ],
                [
                    'title' => 'Write, preview, and save',
                    'markdown' => "- **Write** edits the Markdown source.\n- **Preview** renders through the same Markdown pipeline used elsewhere in tinymash.\n- **Save note** writes the live note and creates a revision when the content changed.",
                ],
                [
                    'title' => 'Drafts and autosave',
                    'markdown' => "- Autosave writes to a private note draft, not directly to the saved note.\n- **Cancel** lets you keep the draft, discard it, or stay on the page.\n- `Ctrl`/`Cmd` + `Enter` saves the note, and `Esc` opens the leave flow when no modal is already open.",
                ],
                [
                    'title' => 'Task lists',
                    'markdown' => "Use normal Markdown indentation for nested task lists:\n\n```md\n- [ ] Parent\n  - [ ] Child\n```\n\nDo not write nested items as `- - [ ] Child`, because that produces awkward wrapper list markup.",
                ],
                [
                    'title' => 'Emoji',
                    'markdown' => "Type `:` to autocomplete emoji shortcodes such as `:blush:` or use the emoji picker button in the toolbar.",
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };

    $emit_json = static function( TinyMashPlugins $plugins, array $payload, int $status_code = 200 ) : void {
        $plugins->setResponseStatus( $status_code );
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=UTF-8' );
        }
        echo json_encode( $payload, JSON_UNESCAPED_SLASHES );
    };

    $emit_server_timing = static function( array $timings ) : void {
        if ( headers_sent() ) {
            return;
        }

        $parts = [];
        foreach ( $timings as $metric_name => $duration_ms ) {
            $metric_name = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', trim( (string) $metric_name ) ) ?? '' );
            if ( $metric_name === '' || ! is_numeric( $duration_ms ) ) {
                continue;
            }

            $parts[] = $metric_name . ';dur=' . number_format( max( 0, (float) $duration_ms ), 2, '.', '' );
        }

        if ( ! empty( $parts ) ) {
            header( 'Server-Timing: ' . implode( ', ', $parts ) );
        }
    };

    $is_json_request = static function() : bool {
        $requested_with = strtolower( trim( (string) ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) ) );
        if ( $requested_with === 'xmlhttprequest' ) {
            return( true );
        }

        $accept = strtolower( trim( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) ) );
        return( str_contains( $accept, 'application/json' ) );
    };

    $resolve_autosave_settings = static function( string $username ) use ( $config, $user_repository ) : array {
        $system_enabled = $config->isEditorAutosaveEnabled();
        $system_interval_seconds = $config->getEditorAutosaveIntervalSeconds();
        $effective_enabled = $system_enabled;
        $effective_interval_seconds = $system_interval_seconds;
        $user_mode = 'inherit';
        $user_interval_seconds = null;

        $user = $user_repository->getUserByUsername( $username );
        if ( is_array( $user ) ) {
            $candidate_mode = strtolower( trim( (string) ( $user['autosave_mode'] ?? 'inherit' ) ) );
            if ( in_array( $candidate_mode, [ 'inherit', 'enabled', 'disabled' ], true ) ) {
                $user_mode = $candidate_mode;
            }

            $candidate_interval = $user['autosave_interval_seconds'] ?? null;
            if ( $candidate_interval !== null && $candidate_interval !== '' ) {
                $candidate_interval = (int) $candidate_interval;
                if ( $candidate_interval >= 30 && $candidate_interval <= 180 ) {
                    $user_interval_seconds = $candidate_interval;
                }
            }
        }

        if ( ! $system_enabled || $user_mode === 'disabled' ) {
            $effective_enabled = false;
        }

        if ( $user_interval_seconds !== null ) {
            $effective_interval_seconds = $user_interval_seconds;
        }

        return(
            [
                'system_enabled' => $system_enabled,
                'system_interval_seconds' => $system_interval_seconds,
                'user_mode' => $user_mode,
                'user_interval_seconds' => $user_interval_seconds,
                'enabled' => $effective_enabled,
                'interval_seconds' => $effective_interval_seconds,
            ]
        );
    };

    $render_notes_page = static function( array $notice = [] ) use ( $plugins, $plugin_key, $security, $markdown_renderer, $date_formatter, $notes_repository, $notes_url, $notes_save_url, $notes_draft_url, $notes_draft_delete_url, $notes_exit_url, $preview_url, $login_url, $resolve_autosave_settings, $escape ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }

        $username = trim( (string) $security->getCurrentUsername() );
        if ( $username === '' ) {
            $plugins->setResponseStatus( 403 );
            $plugins->renderAdminPage(
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Notes',
                    'current_section' => 'notes',
                    'current_location' => 'notes',
                    'plugin_page_kicker' => 'Notes',
                    'plugin_page_title' => 'Private note',
                    'plugin_page_summary' => 'This note is only available in admin and is never public.',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You must be logged in to access notes.' ],
                ]
            );
            return;
        }

        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'notes.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }

        $query = $plugins->getRequestQueryData();
        $requested_revision_id = trim( (string) ( $query['revision'] ?? '' ) );
        $current_note = $notes_repository->getNote( $username );
        $current_draft = $notes_repository->getDraft( $username );
        $loaded_revision = null;
        $editor_content = ! empty( $current_draft['has_draft'] )
            ? (string) ( $current_draft['content'] ?? '' )
            : (string) ( $current_note['content'] ?? '' );

        if ( $requested_revision_id !== '' ) {
            $loaded_revision = $notes_repository->getRevision( $username, $requested_revision_id );
            if ( is_array( $loaded_revision ) ) {
                $editor_content = (string) ( $loaded_revision['content'] ?? '' );
                if ( empty( $notice ) ) {
                    $notice = [
                        'type' => 'info',
                        'message' => 'Loaded revision ' . $requested_revision_id . ' into the editor. Save to make it current again.',
                    ];
                }
            } elseif ( empty( $notice ) ) {
                $notice = [
                    'type' => 'warning',
                    'message' => 'That note revision could not be found.',
                ];
            }
        }

        $rendered_note = $editor_content !== '' ? $markdown_renderer->render( $editor_content, [ 'classic_smileys_enabled' => true ] ) : '';
        $note_updated_display = trim( (string) ( $current_note['updated_at_utc'] ?? '' ) ) !== ''
            ? $date_formatter->formatUtcDateTime( (string) $current_note['updated_at_utc'] )
            : '';
        $draft_updated_display = trim( (string) ( $current_draft['updated_at_utc'] ?? '' ) ) !== ''
            ? $date_formatter->formatUtcDateTime( (string) $current_draft['updated_at_utc'] )
            : '';
        $default_mode = trim( $editor_content ) !== '' ? 'preview' : 'write';
        $autosave = $resolve_autosave_settings( $username );

        $revisions_html = '';
        $revisions = $notes_repository->listRevisions( $username, 12 );
        if ( empty( $revisions ) ) {
            $revisions_html = '<div class="small text-body-secondary">No saved revisions yet.</div>';
        } else {
            $revisions_html .= '<div class="border rounded-3 bg-body overflow-auto" style="max-height: 18rem;">';
            $revisions_html .= '<div class="list-group list-group-flush">';
            foreach ( $revisions as $revision ) {
                if ( ! is_array( $revision ) ) {
                    continue;
                }
                $revision_id = trim( (string) ( $revision['id'] ?? '' ) );
                if ( $revision_id === '' ) {
                    continue;
                }
                $revision_time = trim( (string) ( $revision['created_at_utc'] ?? '' ) ) !== ''
                    ? $date_formatter->formatUtcDateTime( (string) $revision['created_at_utc'] )
                    : 'Unknown time';
                $revision_bytes = max( 0, (int) ( $revision['bytes'] ?? 0 ) );
                $load_url = $notes_url . '?revision=' . rawurlencode( $revision_id );

                $revisions_html .= '<a class="list-group-item list-group-item-action d-flex justify-content-between align-items-start gap-3" href="' . $escape( $load_url ) . '">';
                $revisions_html .= '<span><span class="d-block fw-semibold font-monospace">' . $escape( $revision_id ) . '</span><span class="d-block small text-body-secondary">' . $escape( $revision_time ) . '</span></span>';
                $revisions_html .= '<span class="small text-body-secondary">' . $escape( (string) $revision_bytes ) . ' bytes</span>';
                $revisions_html .= '</a>';
            }
            $revisions_html .= '</div>';
            $revisions_html .= '</div>';
        }

        $actions_html = '';
        if ( $loaded_revision !== null ) {
            $actions_html = '<a class="btn btn-outline-secondary btn-sm" href="' . $escape( $notes_url ) . '">Back to current note</a>';
        }

        ob_start();
        ?>
        <form method="post" action="<?= $escape( $notes_save_url ) ?>" class="d-grid gap-3" id="tm-notes-root" data-preview-url="<?= $escape( $preview_url ) ?>" data-save-url="<?= $escape( $notes_save_url ) ?>" data-draft-url="<?= $escape( $notes_draft_url ) ?>" data-draft-delete-url="<?= $escape( $notes_draft_delete_url ) ?>" data-cancel-url="<?= $escape( $notes_exit_url ) ?>" data-csrf-token="<?= $escape( $security->getCsrfToken() ) ?>" data-default-mode="<?= $escape( $default_mode ) ?>" data-loaded-revision-id="<?= $escape( $requested_revision_id ) ?>" data-has-draft="<?= ! empty( $current_draft['has_draft'] ) ? '1' : '0' ?>" data-autosave-enabled="<?= ! empty( $autosave['enabled'] ) ? '1' : '0' ?>" data-autosave-interval-seconds="<?= (int) ( $autosave['interval_seconds'] ?? 120 ) ?>" data-emoji-data-url="/ext/emoji-picker-data/en/github/data">
            <input type="hidden" name="tinymash_csrf" value="<?= $escape( $security->getCsrfToken() ) ?>">

            <section class="rounded-3 p-3 bg-body-secondary">
                <div class="small text-uppercase text-body-secondary mb-2">Status</div>
                <div class="row g-3 align-items-start">
                    <div class="col-12 col-md-4">
                        <div class="small text-body-secondary mb-1">Owner</div>
                        <div class="fw-semibold font-monospace"><?= $escape( $username ) ?></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="small text-body-secondary mb-1">Updated</div>
                        <div class="fw-semibold" id="tm-notes-status-updated"><?= $escape( $note_updated_display !== '' ? $note_updated_display : 'Never saved' ) ?></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="small text-body-secondary mb-1">Revisions</div>
                        <div class="fw-semibold" id="tm-notes-status-revisions"><?= $escape( (string) count( $revisions ) ) ?></div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button class="btn btn-primary" id="tm-notes-save" type="submit" title="Save note (Ctrl/Cmd+Enter)">Save note</button>
                    <button class="btn btn-outline-secondary" id="tm-notes-cancel" type="button">Cancel</button>
                    <?php if ( $loaded_revision !== null ) { ?>
                        <a class="btn btn-outline-secondary" href="<?= $escape( $notes_url ) ?>">Discard loaded revision</a>
                    <?php } ?>
                    <span id="tm-notes-save-status" class="small text-body-secondary align-self-center" aria-live="polite">Ready</span>
                </div>
                <div class="small text-body-secondary mt-3" id="tm-notes-state-summary">
                    <?php if ( ! empty( $current_draft['has_draft'] ) && $draft_updated_display !== '' ) { ?>
                        Draft last updated <?= $escape( $draft_updated_display ) ?>.
                    <?php } elseif ( $note_updated_display !== '' ) { ?>
                        Editing saved note last updated <?= $escape( $note_updated_display ) ?>.
                    <?php } else { ?>
                        No saved draft yet for this note.
                    <?php } ?>
                    <?php if ( ! empty( $autosave['enabled'] ) ) { ?>
                        Autosave is on every <?= (int) ( $autosave['interval_seconds'] ?? 120 ) ?> seconds.
                    <?php } elseif ( ! empty( $autosave['system_enabled'] ) ) { ?>
                        Autosave is disabled.
                    <?php } else { ?>
                        Autosave is disabled sitewide.
                    <?php } ?>
                </div>
            </section>

            <section class="border rounded-3 p-3 bg-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link<?= $default_mode === 'write' ? ' active' : '' ?>" id="tm-notes-tab-write" type="button" role="tab" aria-controls="tm-notes-write-pane" aria-selected="<?= $default_mode === 'write' ? 'true' : 'false' ?>" data-mode="write">Write</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link<?= $default_mode === 'preview' ? ' active' : '' ?>" id="tm-notes-tab-preview" type="button" role="tab" aria-controls="tm-notes-preview-pane" aria-selected="<?= $default_mode === 'preview' ? 'true' : 'false' ?>" data-mode="preview">Preview</button>
                    </li>
                </ul>

                <div id="tm-notes-write-pane" role="tabpanel" aria-labelledby="tm-notes-tab-write" aria-hidden="<?= $default_mode === 'write' ? 'false' : 'true' ?>" class="<?= $default_mode === 'write' ? '' : 'd-none' ?>" tabindex="0">
                    <label class="form-label small mb-1" for="tm-notes-content">Note</label>
                    <div class="btn-toolbar gap-2 mb-3" role="toolbar" aria-label="Markdown formatting tools">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Text styles">
                            <button class="btn btn-outline-secondary tm-notes-insert" type="button" data-prefix="**" data-suffix="**" data-placeholder="bold text" title="Bold" aria-label="Bold"><span class="bi bi-type-bold" aria-hidden="true"></span></button>
                            <button class="btn btn-outline-secondary tm-notes-insert" type="button" data-prefix="*" data-suffix="*" data-placeholder="italic text" title="Italic" aria-label="Italic"><span class="bi bi-type-italic" aria-hidden="true"></span></button>
                            <button class="btn btn-outline-secondary tm-notes-insert" type="button" data-prefix="~~" data-suffix="~~" data-placeholder="struck text" title="Strikethrough" aria-label="Strikethrough"><span class="bi bi-type-strikethrough" aria-hidden="true"></span></button>
                            <button class="btn btn-outline-secondary tm-notes-insert" type="button" data-prefix="`" data-suffix="`" data-placeholder="code" title="Inline code" aria-label="Inline code"><span class="bi bi-code-slash" aria-hidden="true"></span></button>
                            <button class="btn btn-outline-secondary tm-notes-insert" type="button" data-prefix="==" data-suffix="==" data-placeholder="highlighted text" title="Mark / highlight" aria-label="Mark / highlight"><span class="bi bi-marker-tip" aria-hidden="true"></span></button>
                        </div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Blocks">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Heading levels">
                                <button class="btn btn-outline-secondary" id="tm-notes-heading-apply" type="button" title="Apply heading level" aria-label="Apply heading level">
                                    <span class="bi bi-type-h1 me-1" aria-hidden="true"></span><span id="tm-notes-heading-label">H1</span>
                                </button>
                                <button class="btn btn-outline-secondary px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Choose heading level" aria-label="Choose heading level">
                                    <span class="bi bi-three-dots-vertical" aria-hidden="true"></span>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><button class="dropdown-item tm-notes-heading-level font-monospace" type="button" data-heading-level="1" title="Heading 1">H1</button></li>
                                    <li><button class="dropdown-item tm-notes-heading-level font-monospace" type="button" data-heading-level="2" title="Heading 2">H2</button></li>
                                    <li><button class="dropdown-item tm-notes-heading-level font-monospace" type="button" data-heading-level="3" title="Heading 3">H3</button></li>
                                    <li><button class="dropdown-item tm-notes-heading-level font-monospace" type="button" data-heading-level="4" title="Heading 4">H4</button></li>
                                    <li><button class="dropdown-item tm-notes-heading-level font-monospace" type="button" data-heading-level="5" title="Heading 5">H5</button></li>
                                    <li><button class="dropdown-item tm-notes-heading-level font-monospace" type="button" data-heading-level="6" title="Heading 6">H6</button></li>
                                </ul>
                            </div>
                            <button class="btn btn-outline-secondary tm-notes-prefix-lines" type="button" data-prefix="> " data-placeholder="Blockquote" title="Blockquote" aria-label="Blockquote"><span class="bi bi-blockquote-left" aria-hidden="true"></span></button>
                            <button class="btn btn-outline-secondary tm-notes-prefix-lines" type="button" data-prefix="- " data-placeholder="List item" title="Bullet list" aria-label="Bullet list"><span class="bi bi-list-ul" aria-hidden="true"></span></button>
                            <button class="btn btn-outline-secondary tm-notes-prefix-lines" type="button" data-prefix="- [ ] " data-placeholder="Task item" title="Task list" aria-label="Task list"><span class="bi bi-list-check" aria-hidden="true"></span></button>
                            <button class="btn btn-outline-secondary tm-notes-wrap-block" type="button" data-prefix="```text&#10;" data-suffix="&#10;```" data-placeholder="code" title="Code block" aria-label="Code block"><span class="bi bi-braces-asterisk" aria-hidden="true"></span></button>
                        </div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Links">
                            <button class="btn btn-outline-secondary tm-notes-insert" type="button" data-prefix="[" data-suffix="](https://example.com)" data-placeholder="link text" title="Link" aria-label="Link"><span class="bi bi-link-45deg" aria-hidden="true"></span></button>
                        </div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Emoji">
                            <button class="btn btn-outline-secondary" id="tm-notes-open-emoji-picker" type="button" title="Open emoji picker" aria-label="Open emoji picker"><span class="bi bi-emoji-smile" aria-hidden="true"></span></button>
                        </div>
                    </div>
                    <div class="position-relative">
                        <textarea class="form-control font-monospace" id="tm-notes-content" name="notes_content" rows="22" spellcheck="true"><?= $escape( $editor_content ) ?></textarea>
                        <div class="list-group shadow-sm d-none tm-editor-emoji-autocomplete" id="tm-notes-emoji-autocomplete" role="listbox" aria-label="Emoji shortcode suggestions"></div>
                    </div>
                </div>

                <div id="tm-notes-preview-pane" role="tabpanel" aria-labelledby="tm-notes-tab-preview" aria-live="polite" aria-hidden="<?= $default_mode === 'preview' ? 'false' : 'true' ?>" class="<?= $default_mode === 'preview' ? '' : 'd-none' ?>" tabindex="0">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="small text-body-secondary">Preview</div>
                        <div id="tm-notes-preview-status" class="small text-body-secondary">Ready</div>
                    </div>
                    <div id="tm-notes-preview" class="border rounded-3 bg-body-tertiary p-3" style="min-height: 24rem;">
                        <?php if ( $rendered_note !== '' ) { ?>
                            <div class="tm-baseline-content"><?= $rendered_note ?></div>
                        <?php } else { ?>
                            <div class="small text-body-secondary">Nothing to preview yet.</div>
                        <?php } ?>
                    </div>
                </div>
            </section>

            <section class="border rounded-3 p-3 bg-body-tertiary">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <div class="small text-uppercase text-body-secondary mb-1">Revisions</div>
                        <div class="small text-body-secondary">Save the note to create a revision snapshot when the content changes.</div>
                    </div>
                    <?php if ( $actions_html !== '' ) { ?>
                        <div><?= $actions_html ?></div>
                    <?php } ?>
                </div>
                <?= $revisions_html ?>
            </section>
        </form>
        <div class="modal fade" id="tm-notes-cancel-modal" tabindex="-1" aria-labelledby="tm-notes-cancel-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="tm-notes-cancel-modal-label">Leave note?</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2" id="tm-notes-cancel-modal-summary">You have work in progress in the note.</p>
                        <p class="mb-0 text-body-secondary" id="tm-notes-cancel-modal-detail">Choose whether to keep the draft, discard it, or stay here.</p>
                    </div>
                    <div class="modal-footer d-flex flex-wrap gap-2 justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Stay in note</button>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-danger" id="tm-notes-cancel-discard">Discard draft and leave</button>
                            <button type="button" class="btn btn-primary" id="tm-notes-cancel-keep">Keep draft and leave</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="tm-notes-emoji-modal" tabindex="-1" aria-labelledby="tm-notes-emoji-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="tm-notes-emoji-modal-label">Emoji picker</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <emoji-picker id="tm-notes-emoji-picker" class="tm-editor-emoji-picker w-100" data-source="/ext/emoji-picker-data/en/github/data"></emoji-picker>
                    </div>
                </div>
            </div>
        </div>
        <script type="module" src="/ext/emoji-picker-element/index.js"></script>
        <script>
            (function() {
                const root = document.getElementById('tm-notes-root');
                if (!root) {
                    return;
                }

                const previewUrl = root.dataset.previewUrl || '';
                const saveUrl = root.dataset.saveUrl || '';
                const draftUrl = root.dataset.draftUrl || '';
                const draftDeleteUrl = root.dataset.draftDeleteUrl || '';
                const cancelUrl = root.dataset.cancelUrl || '/admin/author';
                const csrfToken = root.dataset.csrfToken || '';
                const emojiDataUrl = root.dataset.emojiDataUrl || '/ext/emoji-picker-data/en/github/data';
                const autosaveEnabled = root.dataset.autosaveEnabled === '1';
                const autosaveIntervalSeconds = Math.min(180, Math.max(30, Number.parseInt(root.dataset.autosaveIntervalSeconds || '120', 10) || 120));
                const textarea = document.getElementById('tm-notes-content');
                const previewPane = document.getElementById('tm-notes-preview-pane');
                const writePane = document.getElementById('tm-notes-write-pane');
                const previewTarget = document.getElementById('tm-notes-preview');
                const previewStatus = document.getElementById('tm-notes-preview-status');
                const saveButton = document.getElementById('tm-notes-save');
                const cancelButton = document.getElementById('tm-notes-cancel');
                const saveStatus = document.getElementById('tm-notes-save-status');
                const stateSummary = document.getElementById('tm-notes-state-summary');
                const statusUpdated = document.getElementById('tm-notes-status-updated');
                const statusRevisions = document.getElementById('tm-notes-status-revisions');
                const cancelModalElement = document.getElementById('tm-notes-cancel-modal');
                const cancelModalSummary = document.getElementById('tm-notes-cancel-modal-summary');
                const cancelModalDetail = document.getElementById('tm-notes-cancel-modal-detail');
                const cancelKeepButton = document.getElementById('tm-notes-cancel-keep');
                const cancelDiscardButton = document.getElementById('tm-notes-cancel-discard');
                const headingApplyButton = document.getElementById('tm-notes-heading-apply');
                const headingLabel = document.getElementById('tm-notes-heading-label');
                const headingLevelButtons = Array.from(root.querySelectorAll('.tm-notes-heading-level'));
                const emojiPickerButton = document.getElementById('tm-notes-open-emoji-picker');
                const emojiAutocomplete = document.getElementById('tm-notes-emoji-autocomplete');
                const emojiPickerModalElement = document.getElementById('tm-notes-emoji-modal');
                const emojiPickerElement = document.getElementById('tm-notes-emoji-picker');
                const tabButtons = Array.from(root.querySelectorAll('[data-mode]'));
                const headingStorageKey = 'tinymash.editor.headingLevel';
                let lastPreviewSource = null;
                let autosaveTimer = null;
                let hasUnsavedChanges = false;
                let draftSaveInFlight = false;
                let noteSaveInFlight = false;
                let hasDraft = root.dataset.hasDraft === '1';
                let loadedRevisionId = root.dataset.loadedRevisionId || '';
                let currentHeadingLevel = '1';
                let emojiAutocompleteEntries = [];
                let emojiAutocompletePromise = null;
                let emojiAutocompleteMatches = [];
                let emojiAutocompleteIndex = -1;
                let emojiAutocompleteTokenStart = -1;
                let emojiAutocompleteTokenEnd = -1;

                if (!textarea || !previewPane || !writePane || !previewTarget || !previewStatus || !previewUrl || !csrfToken) {
                    return;
                }

                function getBootstrapModal(modalElement) {
                    if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) {
                        return null;
                    }

                    return window.bootstrap.Modal.getOrCreateInstance(modalElement);
                }

                function setTransientStatus(text, tone) {
                    if (!saveStatus) {
                        return;
                    }

                    saveStatus.textContent = text;
                    saveStatus.classList.remove('text-body-secondary', 'text-success', 'text-danger', 'text-warning');
                    saveStatus.classList.add(tone || 'text-body-secondary');
                }

                function updateStateSummary(text) {
                    if (stateSummary) {
                        stateSummary.textContent = text;
                    }
                }

                function clearAutosaveTimer() {
                    if (autosaveTimer) {
                        window.clearTimeout(autosaveTimer);
                        autosaveTimer = null;
                    }
                }

                function scheduleAutosave() {
                    clearAutosaveTimer();
                    if (!autosaveEnabled || !hasUnsavedChanges) {
                        return;
                    }

                    autosaveTimer = window.setTimeout(() => {
                        saveDraft(true);
                    }, autosaveIntervalSeconds * 1000);
                }

                function markDirty() {
                    hasUnsavedChanges = true;
                    setTransientStatus('Unsaved changes', 'text-body-secondary');
                    lastPreviewSource = null;
                    scheduleAutosave();
                }

                function buildPayload() {
                    return new URLSearchParams({
                        tinymash_csrf: csrfToken,
                        notes_content: textarea.value || ''
                    });
                }

                function escapeHtml(value) {
                    return String(value || '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                function replaceSelection(prefix, suffix, placeholder) {
                    const start = textarea.selectionStart || 0;
                    const end = textarea.selectionEnd || 0;
                    const selected = textarea.value.slice(start, end);
                    const content = selected || placeholder;
                    textarea.setRangeText(prefix + content + suffix, start, end, 'select');
                    textarea.focus();
                    markDirty();
                }

                function prefixLines(prefix, placeholder) {
                    const start = textarea.selectionStart || 0;
                    const end = textarea.selectionEnd || 0;
                    const selected = textarea.value.slice(start, end);
                    const source = selected || placeholder;
                    const nextBlock = source.split('\n').map((line) => prefix + line).join('\n');
                    textarea.setRangeText(nextBlock, start, end, 'select');
                    textarea.focus();
                    markDirty();
                }

                function wrapSelectionBlock(prefix, suffix, placeholder) {
                    const start = textarea.selectionStart || 0;
                    const end = textarea.selectionEnd || 0;
                    const selected = textarea.value.slice(start, end) || placeholder;
                    textarea.setRangeText(prefix + selected + suffix, start, end, 'select');
                    textarea.focus();
                    markDirty();
                }

                function insertText(text) {
                    const start = textarea.selectionStart || 0;
                    const end = textarea.selectionEnd || 0;
                    textarea.setRangeText(text, start, end, 'end');
                    textarea.focus();
                    markDirty();
                }

                function setHeadingLevel(level) {
                    const normalizedLevel = String(level || '1').replace(/[^1-6]/g, '') || '1';
                    currentHeadingLevel = normalizedLevel;
                    if (headingLabel) {
                        headingLabel.textContent = 'H' + normalizedLevel;
                    }
                    try {
                        window.localStorage.setItem(headingStorageKey, normalizedLevel);
                    } catch (error) {
                        console.debug('[tinymash] heading level could not be persisted', error);
                    }
                }

                function loadHeadingLevel() {
                    let storedLevel = '1';
                    try {
                        storedLevel = window.localStorage.getItem(headingStorageKey) || '1';
                    } catch (error) {
                        storedLevel = '1';
                    }
                    setHeadingLevel(storedLevel);
                }

                function applyHeadingLevel() {
                    prefixLines('#'.repeat(Number(currentHeadingLevel || '1')) + ' ', 'Heading');
                }

                async function ensureEmojiAutocompleteEntries() {
                    if (emojiAutocompleteEntries.length > 0) {
                        return emojiAutocompleteEntries;
                    }
                    if (emojiAutocompletePromise) {
                        return emojiAutocompletePromise;
                    }

                    emojiAutocompletePromise = fetch(emojiDataUrl)
                        .then((response) => {
                            if (!response.ok) {
                                throw new Error('Emoji data could not be loaded.');
                            }
                            return response.json();
                        })
                        .then((payload) => {
                            const entries = [];
                            const seen = new Set();
                            if (Array.isArray(payload)) {
                                payload.forEach((emojiRecord) => {
                                    if (!emojiRecord || typeof emojiRecord !== 'object' || typeof emojiRecord.emoji !== 'string') {
                                        return;
                                    }

                                    const annotation = typeof emojiRecord.annotation === 'string' ? emojiRecord.annotation : '';
                                    const tags = Array.isArray(emojiRecord.tags) ? emojiRecord.tags.filter((tag) => typeof tag === 'string') : [];
                                    const shortcodes = Array.isArray(emojiRecord.shortcodes) ? emojiRecord.shortcodes.filter((shortcode) => typeof shortcode === 'string' && shortcode !== '') : [];
                                    shortcodes.forEach((shortcode, index) => {
                                        const normalizedShortcode = shortcode.toLowerCase();
                                        if (seen.has(normalizedShortcode)) {
                                            return;
                                        }

                                        seen.add(normalizedShortcode);
                                        entries.push({
                                            emoji: emojiRecord.emoji,
                                            shortcode: normalizedShortcode,
                                            annotation: annotation,
                                            searchText: [ normalizedShortcode, annotation ].concat(tags).join(' ').toLowerCase(),
                                            primary: index === 0
                                        });
                                    });
                                });
                            }

                            emojiAutocompleteEntries = entries.sort((left, right) => {
                                if (left.primary !== right.primary) {
                                    return left.primary ? -1 : 1;
                                }
                                return left.shortcode.localeCompare(right.shortcode);
                            });

                            return emojiAutocompleteEntries;
                        })
                        .catch((error) => {
                            emojiAutocompletePromise = null;
                            throw error;
                        });

                    return emojiAutocompletePromise;
                }

                function hideEmojiAutocomplete() {
                    emojiAutocompleteMatches = [];
                    emojiAutocompleteIndex = -1;
                    emojiAutocompleteTokenStart = -1;
                    emojiAutocompleteTokenEnd = -1;
                    if (emojiAutocomplete) {
                        emojiAutocomplete.classList.add('d-none');
                        emojiAutocomplete.innerHTML = '';
                    }
                }

                function syncEmojiPickerTheme() {
                    if (!emojiPickerElement) {
                        return;
                    }

                    const theme = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
                    emojiPickerElement.classList.toggle('dark', theme === 'dark');
                    emojiPickerElement.classList.toggle('light', theme !== 'dark');
                }

                function renderEmojiAutocomplete() {
                    if (!emojiAutocomplete) {
                        return;
                    }
                    if (emojiAutocompleteMatches.length === 0) {
                        hideEmojiAutocomplete();
                        return;
                    }

                    emojiAutocomplete.innerHTML = emojiAutocompleteMatches.map((entry, index) => {
                        const active = index === emojiAutocompleteIndex;
                        return ''
                            + '<button class="list-group-item list-group-item-action d-flex align-items-center gap-2'
                            + (active ? ' active' : '')
                            + '" type="button" data-emoji-index="' + index + '" role="option" aria-selected="' + (active ? 'true' : 'false') + '">'
                            + '<span class="tm-emoji fs-5" aria-hidden="true">' + escapeHtml(entry.emoji) + '</span>'
                            + '<span class="d-flex flex-column align-items-start text-start">'
                            + '<span class="font-monospace">:' + escapeHtml(entry.shortcode) + ':</span>'
                            + '<span class="small opacity-75">' + escapeHtml(entry.annotation || '') + '</span>'
                            + '</span>'
                            + '</button>';
                    }).join('');
                    emojiAutocomplete.classList.remove('d-none');
                }

                function getEmojiShortcodeQuery() {
                    const selectionStart = textarea.selectionStart;
                    const selectionEnd = textarea.selectionEnd;
                    if (selectionStart !== selectionEnd) {
                        return null;
                    }

                    const textBeforeCursor = textarea.value.slice(0, selectionStart);
                    const shortcodePattern = new RegExp('(^|[\\s([>{])(:[a-z0-9_+-]{1,40})$', 'i');
                    const match = textBeforeCursor.match(shortcodePattern);
                    if (!match) {
                        return null;
                    }

                    return {
                        query: match[2].slice(1).toLowerCase(),
                        start: selectionStart - match[2].length,
                        end: selectionStart
                    };
                }

                function applyEmojiShortcode(entry) {
                    if (emojiAutocompleteTokenStart < 0 || emojiAutocompleteTokenEnd < emojiAutocompleteTokenStart) {
                        return;
                    }

                    const nextCharacter = textarea.value.charAt(emojiAutocompleteTokenEnd);
                    const suffix = nextCharacter === '' || /\s/.test(nextCharacter) ? ' ' : '';
                    textarea.setRangeText(':' + entry.shortcode + ':' + suffix, emojiAutocompleteTokenStart, emojiAutocompleteTokenEnd, 'end');
                    textarea.focus();
                    hideEmojiAutocomplete();
                    markDirty();
                }

                async function updateEmojiAutocomplete() {
                    const shortcodeQuery = getEmojiShortcodeQuery();
                    if (!shortcodeQuery) {
                        hideEmojiAutocomplete();
                        return;
                    }

                    try {
                        const entries = await ensureEmojiAutocompleteEntries();
                        emojiAutocompleteTokenStart = shortcodeQuery.start;
                        emojiAutocompleteTokenEnd = shortcodeQuery.end;
                        emojiAutocompleteMatches = entries
                            .filter((entry) => entry.shortcode.startsWith(shortcodeQuery.query))
                            .slice(0, 8);
                        emojiAutocompleteIndex = emojiAutocompleteMatches.length > 0 ? 0 : -1;
                        renderEmojiAutocomplete();
                    } catch (error) {
                        hideEmojiAutocomplete();
                        console.error('[tinymash] emoji autocomplete error', error);
                    }
                }

                async function saveDraft(isAutosave = false) {
                    if (!draftUrl) {
                        return null;
                    }
                    if (isAutosave && !hasUnsavedChanges) {
                        return null;
                    }
                    if (draftSaveInFlight || noteSaveInFlight) {
                        if (isAutosave) {
                            scheduleAutosave();
                        }
                        return null;
                    }

                    draftSaveInFlight = true;
                    clearAutosaveTimer();
                    setTransientStatus(isAutosave ? 'Autosaving...' : 'Saving draft...', 'text-warning');

                    try {
                        const response = await fetch(draftUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: buildPayload()
                        });
                        const payload = await response.json();
                        if (!response.ok) {
                            throw new Error(payload.error || ('Draft save failed with status ' + response.status));
                        }

                        hasDraft = !!payload.has_draft;
                        hasUnsavedChanges = false;
                        if (payload.updated_at_display) {
                            updateStateSummary(
                                'Draft last updated '
                                + payload.updated_at_display
                                + '.'
                                + (autosaveEnabled ? (' Autosave is on every ' + autosaveIntervalSeconds + ' seconds.') : '')
                            );
                            setTransientStatus((isAutosave ? 'Autosaved ' : 'Saved ') + payload.updated_at_display, 'text-success');
                        } else {
                            setTransientStatus(isAutosave ? 'Autosaved' : 'Draft saved', 'text-success');
                        }
                        return payload;
                    } catch (error) {
                        setTransientStatus(isAutosave ? 'Autosave failed' : 'Draft save failed', 'text-danger');
                        console.error('[tinymash] notes draft save error', error);
                        return null;
                    } finally {
                        draftSaveInFlight = false;
                        if (hasUnsavedChanges) {
                            scheduleAutosave();
                        }
                    }
                }

                async function saveNote() {
                    if (!saveUrl || noteSaveInFlight) {
                        return;
                    }

                    noteSaveInFlight = true;
                    clearAutosaveTimer();
                    if (saveButton) {
                        saveButton.disabled = true;
                    }
                    setTransientStatus('Saving note...', 'text-warning');

                    try {
                        const response = await fetch(saveUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: buildPayload()
                        });
                        const payload = await response.json();
                        if (!response.ok) {
                            throw new Error(payload.error || ('Note save failed with status ' + response.status));
                        }

                        hasDraft = false;
                        hasUnsavedChanges = false;
                        loadedRevisionId = '';
                        if (statusUpdated && payload.updated_at_display) {
                            statusUpdated.textContent = payload.updated_at_display;
                        }
                        if (statusRevisions && typeof payload.revision_count !== 'undefined') {
                            statusRevisions.textContent = String(payload.revision_count);
                        }
                        if (payload.updated_at_display) {
                            updateStateSummary('Editing saved note last updated ' + payload.updated_at_display + '.');
                            setTransientStatus('Saved ' + payload.updated_at_display, 'text-success');
                        } else {
                            setTransientStatus(payload.message || 'Note saved', 'text-success');
                        }
                    } catch (error) {
                        setTransientStatus(error.message || 'Note save failed', 'text-danger');
                        console.error('[tinymash] notes save error', error);
                    } finally {
                        noteSaveInFlight = false;
                        if (saveButton) {
                            saveButton.disabled = false;
                        }
                    }
                }

                async function renderPreview() {
                    const markdown = textarea.value || '';
                    if (markdown.trim() === '') {
                        previewTarget.innerHTML = '<div class="small text-body-secondary">Nothing to preview yet.</div>';
                        previewStatus.textContent = 'Ready';
                        lastPreviewSource = '';
                        return;
                    }
                    if (markdown === lastPreviewSource) {
                        return;
                    }

                    previewStatus.textContent = 'Rendering...';
                    try {
                        const response = await fetch(previewUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: new URLSearchParams({ markdown, tinymash_csrf: csrfToken })
                        });
                        if (!response.ok) {
                            throw new Error('Preview request failed with status ' + response.status);
                        }
                        const payload = await response.json();
                        previewTarget.innerHTML = payload.html
                            ? '<div class="tm-baseline-content">' + payload.html + '</div>'
                            : '<div class="small text-body-secondary">Nothing to preview yet.</div>';
                        previewStatus.textContent = 'Up to date';
                        lastPreviewSource = markdown;
                    } catch (error) {
                        previewTarget.innerHTML = '<div class="alert alert-danger mb-0" role="alert">Preview could not be rendered right now.</div>';
                        previewStatus.textContent = 'Preview failed';
                    }
                }

                function setMode(mode) {
                    const isPreview = mode === 'preview';
                    writePane.classList.toggle('d-none', isPreview);
                    writePane.setAttribute('aria-hidden', isPreview ? 'true' : 'false');
                    previewPane.classList.toggle('d-none', !isPreview);
                    previewPane.setAttribute('aria-hidden', isPreview ? 'false' : 'true');
                    tabButtons.forEach((button) => {
                        const active = button.dataset.mode === mode;
                        button.classList.toggle('active', active);
                        button.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    if (isPreview) {
                        renderPreview();
                    }
                }

                function getNoteExitUrl() {
                    return cancelUrl || '/admin/author';
                }

                function openCancelModal() {
                    if (!hasDraft && !hasUnsavedChanges) {
                        window.location.href = getNoteExitUrl();
                        return;
                    }

                    if (cancelModalSummary) {
                        cancelModalSummary.textContent = hasDraft
                            ? 'A draft exists for this note.'
                            : 'You have unsaved changes in this note.';
                    }
                    if (cancelModalDetail) {
                        cancelModalDetail.textContent = hasDraft
                            ? 'Choose whether to keep the draft for later, discard it now, or stay in the note.'
                            : 'Choose whether to save a draft before leaving, leave without saving, or stay in the note.';
                    }
                    if (cancelKeepButton) {
                        cancelKeepButton.textContent = hasDraft ? 'Keep draft and leave' : 'Save draft and leave';
                    }
                    if (cancelDiscardButton) {
                        cancelDiscardButton.textContent = hasDraft ? 'Discard draft and leave' : 'Leave without saving';
                    }

                    const cancelModal = getBootstrapModal(cancelModalElement);
                    if (cancelModal) {
                        cancelModal.show();
                    }
                }

                function setCancelActionState(disabled) {
                    if (cancelKeepButton) {
                        cancelKeepButton.disabled = disabled;
                    }
                    if (cancelDiscardButton) {
                        cancelDiscardButton.disabled = disabled;
                    }
                }

                async function keepDraftAndLeave() {
                    setCancelActionState(true);
                    try {
                        if (!hasDraft && hasUnsavedChanges) {
                            const payload = await saveDraft(false);
                            if (!payload || !payload.has_draft) {
                                return;
                            }
                        }

                        window.location.href = getNoteExitUrl();
                    } finally {
                        setCancelActionState(false);
                    }
                }

                async function discardDraftAndLeave() {
                    setCancelActionState(true);
                    try {
                        if (hasDraft && draftDeleteUrl) {
                            const response = await fetch(draftDeleteUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: new URLSearchParams({ tinymash_csrf: csrfToken })
                            });
                            const payload = await response.json();
                            if (!response.ok) {
                                throw new Error(payload.error || ('Draft delete failed with status ' + response.status));
                            }
                            hasDraft = false;
                        }

                        window.location.href = getNoteExitUrl();
                    } catch (error) {
                        setTransientStatus(error.message || 'Unable to leave the note right now', 'text-danger');
                        console.error('[tinymash] notes cancel/discard error', error);
                    } finally {
                        setCancelActionState(false);
                    }
                }

                root.querySelectorAll('.tm-notes-insert').forEach((button) => {
                    button.addEventListener('click', () => {
                        replaceSelection(
                            button.dataset.prefix || '',
                            button.dataset.suffix || '',
                            button.dataset.placeholder || 'text'
                        );
                    });
                });

                root.querySelectorAll('.tm-notes-prefix-lines').forEach((button) => {
                    button.addEventListener('click', () => {
                        prefixLines(
                            button.dataset.prefix || '',
                            button.dataset.placeholder || 'Item'
                        );
                    });
                });

                root.querySelectorAll('.tm-notes-wrap-block').forEach((button) => {
                    button.addEventListener('click', () => {
                        wrapSelectionBlock(
                            (button.dataset.prefix || '').replaceAll('&#10;', '\n'),
                            (button.dataset.suffix || '').replaceAll('&#10;', '\n'),
                            button.dataset.placeholder || 'code'
                        );
                    });
                });

                if (headingApplyButton) {
                    headingApplyButton.addEventListener('click', applyHeadingLevel);
                }

                headingLevelButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        setHeadingLevel(button.getAttribute('data-heading-level') || '1');
                        applyHeadingLevel();
                    });
                });

                tabButtons.forEach((button) => {
                    button.addEventListener('click', () => setMode(button.dataset.mode || 'write'));
                });

                textarea.addEventListener('input', () => {
                    markDirty();
                });
                textarea.addEventListener('input', updateEmojiAutocomplete);
                textarea.addEventListener('click', updateEmojiAutocomplete);
                textarea.addEventListener('blur', () => {
                    window.setTimeout(hideEmojiAutocomplete, 150);
                });
                textarea.addEventListener('keydown', (event) => {
                    if (emojiAutocompleteMatches.length === 0) {
                        return;
                    }

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        emojiAutocompleteIndex = emojiAutocompleteIndex >= emojiAutocompleteMatches.length - 1 ? 0 : emojiAutocompleteIndex + 1;
                        renderEmojiAutocomplete();
                        return;
                    }
                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        emojiAutocompleteIndex = emojiAutocompleteIndex <= 0 ? emojiAutocompleteMatches.length - 1 : emojiAutocompleteIndex - 1;
                        renderEmojiAutocomplete();
                        return;
                    }
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        event.stopPropagation();
                        hideEmojiAutocomplete();
                        return;
                    }
                    if (event.key === 'Enter' || event.key === 'Tab') {
                        const activeEntry = emojiAutocompleteMatches[emojiAutocompleteIndex];
                        if (!activeEntry) {
                            return;
                        }
                        event.preventDefault();
                        applyEmojiShortcode(activeEntry);
                    }
                });

                if (emojiAutocomplete) {
                    emojiAutocomplete.addEventListener('mousedown', (event) => {
                        event.preventDefault();
                    });
                    emojiAutocomplete.addEventListener('click', (event) => {
                        const button = event.target.closest('[data-emoji-index]');
                        if (!button) {
                            return;
                        }

                        const selectedIndex = Number(button.getAttribute('data-emoji-index'));
                        if (!Number.isInteger(selectedIndex) || !emojiAutocompleteMatches[selectedIndex]) {
                            return;
                        }

                        applyEmojiShortcode(emojiAutocompleteMatches[selectedIndex]);
                    });
                }

                if (root) {
                    root.addEventListener('submit', (event) => {
                        event.preventDefault();
                        saveNote();
                    });
                }

                if (cancelButton) {
                    cancelButton.addEventListener('click', openCancelModal);
                }
                if (cancelKeepButton) {
                    cancelKeepButton.addEventListener('click', keepDraftAndLeave);
                }
                if (cancelDiscardButton) {
                    cancelDiscardButton.addEventListener('click', discardDraftAndLeave);
                }
                if (emojiPickerButton) {
                    emojiPickerButton.addEventListener('click', () => {
                        syncEmojiPickerTheme();
                        const emojiPickerModal = getBootstrapModal(emojiPickerModalElement);
                        if (emojiPickerModal) {
                            emojiPickerModal.show();
                        }
                    });
                }
                if (emojiPickerElement) {
                    emojiPickerElement.setAttribute('data-source', emojiDataUrl);
                    emojiPickerElement.addEventListener('emoji-click', (event) => {
                        const unicode = event && event.detail ? (event.detail.unicode || (event.detail.emoji && event.detail.emoji.unicode) || '') : '';
                        if (unicode !== '') {
                            insertText(unicode);
                        }
                        const emojiPickerModal = getBootstrapModal(emojiPickerModalElement);
                        if (emojiPickerModal) {
                            emojiPickerModal.hide();
                        }
                    });
                }

                document.addEventListener('keydown', (event) => {
                    if (event.defaultPrevented) {
                        return;
                    }

                    if (event.key === 'Escape') {
                        if (!root || !document.body.contains(root)) {
                            return;
                        }
                        const openModal = document.querySelector('.modal.show');
                        if (openModal) {
                            return;
                        }
                        const activeElement = document.activeElement;
                        if (activeElement && !root.contains(activeElement)) {
                            return;
                        }
                        event.preventDefault();
                        openCancelModal();
                        return;
                    }

                    if (!(event.ctrlKey || event.metaKey) || event.key !== 'Enter') {
                        return;
                    }
                    if (!root || !document.body.contains(root)) {
                        return;
                    }
                    const openModal = document.querySelector('.modal.show');
                    if (openModal) {
                        return;
                    }
                    const activeElement = document.activeElement;
                    if (activeElement && !root.contains(activeElement)) {
                        return;
                    }
                    event.preventDefault();
                    saveNote();
                });

                loadHeadingLevel();
                syncEmojiPickerTheme();
                setMode(root.dataset.defaultMode === 'preview' ? 'preview' : 'write');
            })();
        </script>
        <?php
        $body_html = (string) ob_get_clean();

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Notes',
                'current_section' => 'notes',
                'current_location' => 'notes',
                'help_contexts' => [ 'plugin-notes' ],
                'plugin_page_kicker' => 'Notes',
                'plugin_page_title' => 'Private note',
                'plugin_page_summary' => 'One private note per user. Stored in admin only, never public, never included in feeds.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $notes_url,
        static function() use ( $security, $plugins, $login_url, $render_notes_page ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }

            $render_notes_page();
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $notes_draft_url,
        static function() use ( $plugins, $config, $security, $notes_repository, $date_formatter, $emit_json, $emit_server_timing ) : void {
            if ( ! $security->isLoggedIn() ) {
                $emit_json( $plugins, [ 'error' => 'Authentication required.' ], 403 );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $emit_json( $plugins, [ 'error' => 'Your session token is invalid. Please reload the page and try again.' ], 403 );
                return;
            }

            $username = trim( (string) $security->getCurrentUsername() );
            if ( $username === '' ) {
                $emit_json( $plugins, [ 'error' => 'Authentication required.' ], 403 );
                return;
            }

            try {
                $save_started_at = microtime( true );
                $timing_started_at = microtime( true );
                $draft = $notes_repository->saveDraft(
                    $username,
                    (string) ( $data['notes_content'] ?? '' )
                );
                $timings = [
                    'save_draft' => ( microtime( true ) - $timing_started_at ) * 1000,
                ];
                $timing_started_at = microtime( true );
                $updated_at_display = trim( (string) ( $draft['updated_at_utc'] ?? '' ) ) !== ''
                    ? $date_formatter->formatUtcDateTime( (string) $draft['updated_at_utc'] )
                    : '';
                $timings['build_response'] = ( microtime( true ) - $timing_started_at ) * 1000;
                $timings['total'] = ( microtime( true ) - $save_started_at ) * 1000;
                $emit_server_timing( $timings );
                $emit_json(
                    $plugins,
                    [
                        'saved' => true,
                        'has_draft' => ! empty( $draft['has_draft'] ),
                        'updated_at_display' => $updated_at_display,
                        'message' => 'Draft saved.',
                    ]
                );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Unable to save note draft (' . $e->getMessage() . ')' );
                $emit_json( $plugins, [ 'error' => 'Note draft could not be saved right now.' ], 500 );
            }
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $notes_draft_delete_url,
        static function() use ( $plugins, $security, $notes_repository, $emit_json ) : void {
            if ( ! $security->isLoggedIn() ) {
                $emit_json( $plugins, [ 'error' => 'Authentication required.' ], 403 );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $emit_json( $plugins, [ 'error' => 'Your session token is invalid. Please reload the page and try again.' ], 403 );
                return;
            }

            $username = trim( (string) $security->getCurrentUsername() );
            if ( $username === '' ) {
                $emit_json( $plugins, [ 'error' => 'Authentication required.' ], 403 );
                return;
            }

            $notes_repository->deleteDraft( $username );
            $emit_json(
                $plugins,
                [
                    'deleted' => true,
                    'has_draft' => false,
                    'message' => 'Draft discarded.',
                ]
            );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $notes_save_url,
        static function() use ( $plugins, $config, $security, $notes_repository, $notes_url, $date_formatter, $emit_json, $emit_server_timing, $is_json_request ) : void {
            if ( ! $security->isLoggedIn() ) {
                if ( $is_json_request() ) {
                    $emit_json( $plugins, [ 'error' => 'Authentication required.' ], 403 );
                    return;
                }
                $plugins->redirect( (string) ( $config->configGetLoginURL() ?: '/login' ) );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                if ( $is_json_request() ) {
                    $emit_json( $plugins, [ 'error' => 'Your session token is invalid. Please reload the page and try again.' ], 403 );
                    return;
                }
                $security->setFlash(
                    'notes.notice',
                    [
                        'type' => 'danger',
                        'message' => 'Your session token is invalid. Please reload the page and try again.',
                    ]
                );
                $plugins->redirect( $notes_url );
                return;
            }

            $username = trim( (string) $security->getCurrentUsername() );
            if ( $username === '' ) {
                if ( $is_json_request() ) {
                    $emit_json( $plugins, [ 'error' => 'Authentication required.' ], 403 );
                    return;
                }
                $security->setFlash(
                    'notes.notice',
                    [
                        'type' => 'danger',
                        'message' => 'You must be logged in to save notes.',
                    ]
                );
                $plugins->redirect( $notes_url );
                return;
            }

            try {
                $save_started_at = microtime( true );
                $timing_started_at = microtime( true );
                $note = $notes_repository->saveNote(
                    $username,
                    (string) ( $data['notes_content'] ?? '' ),
                    $config->getContentRevisionRetentionLimit()
                );
                $timings = [
                    'save_note' => ( microtime( true ) - $timing_started_at ) * 1000,
                ];
                if ( $is_json_request() ) {
                    $timing_started_at = microtime( true );
                    $updated_at_display = trim( (string) ( $note['updated_at_utc'] ?? '' ) ) !== ''
                        ? $date_formatter->formatUtcDateTime( (string) $note['updated_at_utc'] )
                        : '';
                    $revision_count = count( $notes_repository->listRevisions( $username, 1000 ) );
                    $timings['build_response'] = ( microtime( true ) - $timing_started_at ) * 1000;
                    $timings['total'] = ( microtime( true ) - $save_started_at ) * 1000;
                    $emit_server_timing( $timings );
                    $emit_json(
                        $plugins,
                        [
                            'saved' => true,
                            'has_draft' => false,
                            'message' => 'Note saved.',
                            'updated_at_display' => $updated_at_display,
                            'revision_count' => $revision_count,
                        ]
                    );
                    return;
                }
                $security->setFlash(
                    'notes.notice',
                    [
                        'type' => 'success',
                        'message' => 'Note saved.',
                    ]
                );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Unable to save notes (' . $e->getMessage() . ')' );
                if ( $is_json_request() ) {
                    $emit_json( $plugins, [ 'error' => 'Notes could not be saved right now.' ], 500 );
                    return;
                }
                $security->setFlash(
                    'notes.notice',
                    [
                        'type' => 'danger',
                        'message' => 'Notes could not be saved right now.',
                    ]
                );
            }

            $plugins->redirect( $notes_url );
        }
    );
};
