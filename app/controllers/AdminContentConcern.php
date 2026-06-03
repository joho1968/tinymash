<?php
namespace app\controllers;

if ( empty( TINYMASH_RUNNING ) ) {
    die( 'We do not seem to have all the bits configured' );
}

trait AdminContentConcern {
    public function entriesHome() : void {
        $query = $this->app->request()->query->getData();
        if ( ! is_array( $query ) ) {
            $query = [];
        }

        $active_tab = $this->normalizeContentBatchTab( (string) ( $query['tab'] ?? 'entries' ) );
        $content_filters = $this->normalizeContentFilters( $query );
        $this->renderAdmin(
            'entries',
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Content',
                'app_url' => $this->app->get( 'app.url' ),
                'admin_url' => $this->app->get( 'admin.url' ),
                'current_role' => $this->security->getCurrentRole(),
                'current_username' => $this->security->getCurrentUsername(),
                'is_superadmin' => $this->security->isSuperAdmin(),
                'content_moderation_required' => $this->config->isContentModerationRequired(),
                'current_section' => 'content',
                'current_location' => 'content',
                'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
                'entries_url' => $this->app->get( 'admin.url' ) . '/content',
                'content_batch_url' => $this->app->get( 'admin.url' ) . '/content/batch',
                'editor_url' => $this->app->get( 'admin.url' ) . '/editor',
                'entry_management' => $this->getEntriesIndex( $content_filters ),
                'content_active_tab' => $active_tab,
                'content_filters' => $content_filters,
                'content_notice' => $this->getContentNotice(),
                'csrf_token' => $this->security->getCsrfToken(),
                'help_contexts' => [ 'admin-content' ],
            ]
        );
    }

    public function handleContentBatchAction() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setContentNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->buildContentIndexUrl( 'entries', $this->normalizeContentFilters( $data ) ) );
            return;
        }

        $tab = $this->normalizeContentBatchTab( (string) ( $data['batch_tab'] ?? 'entries' ) );
        $content_filters = $this->normalizeContentFilters( $data );
        $action = strtolower( trim( (string) ( $data['batch_action'] ?? '' ) ) );
        $ids = array_values(
            array_filter(
                array_map(
                    static fn( mixed $value ) : string => trim( (string) $value ),
                    is_array( $data['selected_ids'] ?? null ) ? $data['selected_ids'] : []
                ),
                static fn( string $value ) : bool => $value !== ''
            )
        );

        if ( $action === '' || empty( $ids ) ) {
            $this->setContentNoticeFlash( 'danger', 'Select at least one item and choose an action.' );
            $this->app->redirect( $this->buildContentIndexUrl( $tab, $content_filters ) );
            return;
        }

        try {
            $count = 0;
            if ( $tab === 'drafts' ) {
                if ( $action !== 'delete' ) {
                    throw new \InvalidArgumentException( 'content_batch_action' );
                }

                if ( $this->draft_repository === null ) {
                    throw new \RuntimeException( 'Draft repository is not available.' );
                }

                $username = (string) $this->security->getCurrentUsername();
                foreach ( $ids as $draft_id ) {
                    if ( $this->draft_repository->deleteEditorDraft( $username, $draft_id ) ) {
                        $count++;
                    }
                }
            } else {
                if ( $this->content_repository === null ) {
                    throw new \RuntimeException( 'Content repository is not available.' );
                }

                $author_slug = $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug();
                $allow_root = $this->security->isSuperAdmin();
                $before_entries = [];
                if ( $tab === 'trash' ) {
                    foreach ( $ids as $entry_id ) {
                        $entry = $this->content_repository->getAccessibleTrashedEntryById( $entry_id, null, $author_slug, $allow_root );
                        if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
                            $before_entries[(string) $entry['id']] = $entry;
                        }
                    }
                    if ( $action === 'restore' ) {
                        $count = $this->content_repository->restoreTrashedEntriesByIds( $ids, $author_slug, $allow_root );
                    } elseif ( $action === 'permanent_delete' ) {
                        $count = $this->content_repository->permanentlyDeleteTrashedEntriesByIds( $ids, $author_slug, $allow_root );
                    } else {
                        throw new \InvalidArgumentException( 'content_batch_action' );
                    }
                } else {
                    foreach ( $ids as $entry_id ) {
                        $entry = $this->content_repository->getAccessibleEntryById( $entry_id, null, $author_slug, $allow_root );
                        if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
                            $before_entries[(string) $entry['id']] = $entry;
                        }
                    }
                    if ( $action === 'delete' ) {
                        $count = $this->config->getContentTrashRetentionDays() > 0
                            ? $this->content_repository->moveEntriesToTrashByIds( $ids, (string) ( $this->security->getCurrentUsername() ?? '' ), $author_slug, $allow_root )
                            : $this->content_repository->deleteEntriesByIds( $ids, $author_slug, $allow_root );
                        foreach ( $before_entries as $entry_id => $previous_entry ) {
                            $still_exists = $this->content_repository->getAccessibleEntryById( (string) $entry_id, null, $author_slug, $allow_root );
                            if ( ! is_array( $still_exists ) ) {
                                $this->queueFediverseEntryRemoval( $previous_entry, (string) ( $this->security->getCurrentUsername() ?? '' ) );
                            }
                        }
                    } elseif ( in_array( $action, [ 'publish', 'unpublish' ], true ) ) {
                        $count = $this->content_repository->updateEntriesStatusByIds(
                            $ids,
                            $action === 'publish' ? 'published' : 'unpublished',
                            $author_slug,
                            $allow_root
                        );
                        foreach ( $before_entries as $entry_id => $previous_entry ) {
                            $saved_entry = $this->content_repository->getAccessibleEntryById( (string) $entry_id, null, $author_slug, $allow_root );
                            if ( is_array( $saved_entry ) ) {
                                $this->queueFediverseEntrySync(
                                    $previous_entry,
                                    $saved_entry,
                                    (string) ( $this->security->getCurrentUsername() ?? '' )
                                );
                            }
                        }
                    } else {
                        throw new \InvalidArgumentException( 'content_batch_action' );
                    }
                }
            }

            if ( $count < 1 ) {
                $this->setContentNoticeFlash( 'warning', 'No items were changed.' );
            } else {
                $this->setContentNoticeFlash( 'success', $this->getContentBatchSuccessMessage( $tab, $action, $count ) );
            }
        } catch ( \InvalidArgumentException $e ) {
            $message_key = $e->getMessage();
            $message = $message_key === 'content_batch_action'
                ? 'That batch action is not available for the selected items.'
                : $this->getEditorValidationMessage( $message_key );
            $this->setContentNoticeFlash( 'danger', $message );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to run content batch action (' . $e->getMessage() . ')' );
            $this->setContentNoticeFlash( 'danger', 'Content changes could not be saved right now.' );
        }

        $this->app->redirect( $this->buildContentIndexUrl( $tab, $content_filters ) );
    }

    protected function getContentNotice() : array {
        $flash_notice = $this->security->pullFlash( 'content.notice', [] );
        if ( is_array( $flash_notice ) && ! empty( $flash_notice['type'] ) && ! empty( $flash_notice['message'] ) ) {
            return( $flash_notice );
        }

        return( [] );
    }

    protected function setContentNoticeFlash( string $type, string $message ) : void {
        $this->security->setFlash(
            'content.notice',
            [
                'type' => $type,
                'message' => $message,
            ]
        );
    }

    protected function normalizeContentBatchTab( string $tab ) : string {
        $tab = strtolower( trim( $tab ) );
        return( in_array( $tab, [ 'entries', 'drafts', 'moderation', 'trash' ], true ) ? $tab : 'entries' );
    }

    protected function getContentBatchSuccessMessage( string $tab, string $action, int $count ) : string {
        $item_label = $tab === 'drafts' ? 'draft' : 'content item';
        if ( $count !== 1 ) {
            $item_label .= 's';
        }

        if ( $action === 'restore' ) {
            return( 'Restored ' . $count . ' ' . $item_label . ' as unpublished.' );
        }
        if ( $action === 'permanent_delete' ) {
            return( 'Permanently deleted ' . $count . ' ' . $item_label . '.' );
        }
        if ( $action === 'publish' ) {
            return( 'Published ' . $count . ' ' . $item_label . '.' );
        }
        if ( $action === 'unpublish' ) {
            return( 'Unpublished ' . $count . ' ' . $item_label . '.' );
        }

        return( $tab === 'entries' && $this->config->getContentTrashRetentionDays() > 0
            ? 'Moved ' . $count . ' ' . $item_label . ' to Trash.'
            : 'Deleted ' . $count . ' ' . $item_label . '.'
        );
    }

    protected function getEntriesIndex( array $content_filters = [] ) : array {
        $drafts = $this->getEditorDrafts();
        $entries = $this->content_repository !== null
            ? $this->content_repository->getEditorEntries(
                null,
                $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug(),
                $this->security->isSuperAdmin()
            )
            : [];
        $trashed_entries = $this->content_repository !== null
            ? $this->content_repository->getTrashedEditorEntries(
                null,
                $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug(),
                $this->security->isSuperAdmin()
            )
            : [];

        $drafts_by_entry_id = [];
        foreach ( $drafts as $draft ) {
            $source_entry_id = trim( (string) ( $draft['source_entry_id'] ?? '' ) );
            if ( $source_entry_id !== '' ) {
                $drafts_by_entry_id[$source_entry_id] = $draft;
            }
        }

        foreach ( $entries as $index => $entry ) {
            $entries[$index]['updated_at_display'] = $this->formatUtcDateTime( (string) ( $entry['updated_at_utc'] ?? '' ) );
            $entries[$index]['open_url'] = $this->app->get( 'admin.url' ) . '/editor?entry=' . rawurlencode( (string) $entry['id'] );
            $entries[$index]['active_draft'] = $drafts_by_entry_id[(string) $entry['id']] ?? null;
            $entries[$index]['view_url'] = $this->buildEntryPublicUrl( $entry );
        }

        foreach ( $trashed_entries as $index => $entry ) {
            $trashed_entries[$index]['updated_at_display'] = $this->formatUtcDateTime( (string) ( $entry['updated_at_utc'] ?? '' ) );
            $trashed_entries[$index]['trashed_at_display'] = $this->formatUtcDateTime( (string) ( $entry['trashed_at_utc'] ?? '' ) );
        }

        foreach ( $drafts as $index => $draft ) {
            $drafts[$index]['open_url'] = $this->app->get( 'admin.url' ) . '/editor?draft=' . rawurlencode( (string) ( $draft['draft_id'] ?? '' ) );
        }

        $entries_owner_filter = $this->normalizeContentOwnerFilter( (string) ( $content_filters['entries_owner'] ?? '' ) );
        $drafts_owner_filter = $this->normalizeContentOwnerFilter( (string) ( $content_filters['drafts_owner'] ?? '' ) );
        $moderation_owner_filter = $this->normalizeContentOwnerFilter( (string) ( $content_filters['moderation_owner'] ?? '' ) );
        $trash_owner_filter = $this->normalizeContentOwnerFilter( (string) ( $content_filters['trash_owner'] ?? '' ) );
        $entries_title_filter = $this->normalizeContentTitleFilter( (string) ( $content_filters['entries_title'] ?? '' ) );
        $drafts_title_filter = $this->normalizeContentTitleFilter( (string) ( $content_filters['drafts_title'] ?? '' ) );
        $moderation_title_filter = $this->normalizeContentTitleFilter( (string) ( $content_filters['moderation_title'] ?? '' ) );
        $trash_title_filter = $this->normalizeContentTitleFilter( (string) ( $content_filters['trash_title'] ?? '' ) );
        $entries_type_filter = $this->normalizeContentTypeFilter( (string) ( $content_filters['entries_type'] ?? '' ) );
        $drafts_type_filter = $this->normalizeContentTypeFilter( (string) ( $content_filters['drafts_type'] ?? '' ) );
        $moderation_type_filter = $this->normalizeContentTypeFilter( (string) ( $content_filters['moderation_type'] ?? '' ) );
        $trash_type_filter = $this->normalizeContentTypeFilter( (string) ( $content_filters['trash_type'] ?? '' ) );
        $entries_tree_enabled = $this->normalizeContentTreeMode( $content_filters['entries_tree'] ?? '1' );
        $entries_page = $this->normalizeContentPage( $content_filters['entries_page'] ?? 1 );
        $drafts_page = $this->normalizeContentPage( $content_filters['drafts_page'] ?? 1 );
        $moderation_page = $this->normalizeContentPage( $content_filters['moderation_page'] ?? 1 );
        $trash_page = $this->normalizeContentPage( $content_filters['trash_page'] ?? 1 );

        $filtered_drafts = [];
        foreach ( $drafts as $draft ) {
            if ( $this->contentItemMatchesFilters( $draft, $drafts_owner_filter, $drafts_title_filter, $drafts_type_filter, 'entry_type' ) ) {
                $filtered_drafts[] = $draft;
            }
        }

        $content_counts = [
            'drafts' => count( $drafts ),
            'entries' => count( $entries ),
            'published' => 0,
            'unpublished' => 0,
            'pending_review' => 0,
            'trash' => count( $trashed_entries ),
        ];
        $pending_review_entries = [];
        $filtered_entries = [];
        $filtered_pending_review_entries = [];
        $filtered_trashed_entries = [];
        foreach ( $entries as $entry ) {
            $entry_status = (string) ( $entry['status'] ?? '' );
            if ( $entry_status === 'published' ) {
                $content_counts['published']++;
            } elseif ( $entry_status === 'unpublished' ) {
                $content_counts['unpublished']++;
            } elseif ( $entry_status === 'pending_review' ) {
                $content_counts['pending_review']++;
                $pending_review_entries[] = $entry;
                if ( $this->contentItemMatchesFilters( $entry, $moderation_owner_filter, $moderation_title_filter, $moderation_type_filter ) ) {
                    $filtered_pending_review_entries[] = $entry;
                }
            }

            if ( $this->contentItemMatchesFilters( $entry, $entries_owner_filter, $entries_title_filter, $entries_type_filter ) ) {
                $filtered_entries[] = $entry;
            }
        }
        foreach ( $trashed_entries as $entry ) {
            if ( $this->contentItemMatchesFilters( $entry, $trash_owner_filter, $trash_title_filter, $trash_type_filter ) ) {
                $filtered_trashed_entries[] = $entry;
            }
        }

        if ( $entries_tree_enabled ) {
            $filtered_entries = $this->sortContentEntriesForTreeView( $filtered_entries );
        }

        $filtered_drafts = $this->withHighlightedContentTitles( $filtered_drafts, $drafts_title_filter, 'display_title' );
        $filtered_entries = $this->withHighlightedContentTitles( $filtered_entries, $entries_title_filter, 'title' );
        $filtered_pending_review_entries = $this->withHighlightedContentTitles( $filtered_pending_review_entries, $moderation_title_filter, 'title' );
        $filtered_trashed_entries = $this->withHighlightedContentTitles( $filtered_trashed_entries, $trash_title_filter, 'title' );

        $drafts_pagination = $this->paginateContentItems( $filtered_drafts, $drafts_page, 25 );
        $entries_pagination = $this->paginateContentItems( $filtered_entries, $entries_page, 25 );
        $moderation_pagination = $this->paginateContentItems( $filtered_pending_review_entries, $moderation_page, 25 );
        $trash_pagination = $this->paginateContentItems( $filtered_trashed_entries, $trash_page, 25 );
        $revision_counts = [];
        $entries_pagination['items'] = $this->withContentRevisionCounts( $entries_pagination['items'], $revision_counts );
        $moderation_pagination['items'] = $this->withContentRevisionCounts( $moderation_pagination['items'], $revision_counts );
        $trash_pagination['items'] = $this->withContentRevisionCounts( $trash_pagination['items'], $revision_counts );

        return(
            [
                'drafts' => $drafts_pagination['items'],
                'entries' => $entries_pagination['items'],
                'pending_review_entries' => $moderation_pagination['items'],
                'trash_entries' => $trash_pagination['items'],
                'counts' => $content_counts,
                'owner_filter_options' => [
                    'entries' => $this->buildContentOwnerFilterOptions( $entries ),
                    'drafts' => $this->buildContentOwnerFilterOptions( $drafts ),
                    'moderation' => $this->buildContentOwnerFilterOptions( $pending_review_entries ),
                    'trash' => $this->buildContentOwnerFilterOptions( $trashed_entries ),
                ],
                'filtered_counts' => [
                    'drafts' => count( $filtered_drafts ),
                    'entries' => count( $filtered_entries ),
                    'pending_review' => count( $filtered_pending_review_entries ),
                    'trash' => count( $filtered_trashed_entries ),
                ],
                'pagination' => [
                    'drafts' => $drafts_pagination['meta'],
                    'entries' => $entries_pagination['meta'],
                    'moderation' => $moderation_pagination['meta'],
                    'trash' => $trash_pagination['meta'],
                ],
                'entries_tree_enabled' => $entries_tree_enabled,
            ]
        );
    }

    protected function normalizeContentFilters( array $data ) : array {
        return(
            [
                'entries_owner' => $this->normalizeContentOwnerFilter( (string) ( $data['entries_owner'] ?? '' ) ),
                'entries_title' => $this->normalizeContentTitleFilter( (string) ( $data['entries_title'] ?? '' ) ),
                'entries_type' => $this->normalizeContentTypeFilter( (string) ( $data['entries_type'] ?? '' ) ),
                'entries_tree' => $this->normalizeContentTreeMode( $data['entries_tree'] ?? '1' ) ? '1' : '0',
                'entries_page' => $this->normalizeContentPage( $data['entries_page'] ?? 1 ),
                'drafts_owner' => $this->normalizeContentOwnerFilter( (string) ( $data['drafts_owner'] ?? '' ) ),
                'drafts_title' => $this->normalizeContentTitleFilter( (string) ( $data['drafts_title'] ?? '' ) ),
                'drafts_type' => $this->normalizeContentTypeFilter( (string) ( $data['drafts_type'] ?? '' ) ),
                'drafts_page' => $this->normalizeContentPage( $data['drafts_page'] ?? 1 ),
                'moderation_owner' => $this->normalizeContentOwnerFilter( (string) ( $data['moderation_owner'] ?? '' ) ),
                'moderation_title' => $this->normalizeContentTitleFilter( (string) ( $data['moderation_title'] ?? '' ) ),
                'moderation_type' => $this->normalizeContentTypeFilter( (string) ( $data['moderation_type'] ?? '' ) ),
                'moderation_page' => $this->normalizeContentPage( $data['moderation_page'] ?? 1 ),
                'trash_owner' => $this->normalizeContentOwnerFilter( (string) ( $data['trash_owner'] ?? '' ) ),
                'trash_title' => $this->normalizeContentTitleFilter( (string) ( $data['trash_title'] ?? '' ) ),
                'trash_type' => $this->normalizeContentTypeFilter( (string) ( $data['trash_type'] ?? '' ) ),
                'trash_page' => $this->normalizeContentPage( $data['trash_page'] ?? 1 ),
            ]
        );
    }

    protected function normalizeContentTreeMode( mixed $value ) : bool {
        $value = strtolower( trim( (string) $value ) );
        return( ! in_array( $value, [ '0', 'false', 'off', 'no' ], true ) );
    }

    protected function sortContentEntriesForTreeView( array $entries ) : array {
        $page_entries = [];
        $other_entries = [];
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            if ( (string) ( $entry['type'] ?? '' ) === 'page' ) {
                $slug = (string) ( $entry['path'] ?? '' );
                $entry['slug'] = $slug;
                $entry['local_slug'] = $this->getEditorParentPageLocalSlug( $slug );
                $page_entries[] = $entry;
                continue;
            }

            $entry['content_tree_depth'] = 0;
            $entry['content_tree_display_path'] = (string) ( $entry['path'] ?? '' );
            $other_entries[] = $entry;
        }

        $sorted_page_entries = [];
        $sorted_page_ids = [];
        foreach ( $this->sortEditorParentPageOptionsAsTree( $page_entries ) as $page_entry ) {
            if ( ! is_array( $page_entry ) ) {
                continue;
            }

            $page_entry['content_tree_depth'] = max( 0, (int) ( $page_entry['depth'] ?? 0 ) );
            $page_entry['content_tree_display_path'] = (string) ( $page_entry['display_path'] ?? '' );
            $sorted_page_ids[(string) ( $page_entry['id'] ?? '' )] = true;
            $sorted_page_entries[] = $page_entry;
        }

        foreach ( $page_entries as $page_entry ) {
            $page_id = (string) ( $page_entry['id'] ?? '' );
            if ( $page_id !== '' && ! empty( $sorted_page_ids[$page_id] ) ) {
                continue;
            }

            $page_entry['content_tree_depth'] = 0;
            $page_entry['content_tree_display_path'] = '/' . trim( (string) ( $page_entry['path'] ?? '' ), '/' );
            $sorted_page_entries[] = $page_entry;
        }

        return( array_merge( $sorted_page_entries, $other_entries ) );
    }

    protected function normalizeContentOwnerFilter( string $owner ) : string {
        $owner = strtolower( trim( $owner ) );
        if ( $owner === '' || $owner === 'all' ) {
            return( '' );
        }
        if ( $owner === 'root' ) {
            return( 'root' );
        }

        return( preg_replace( '/[^a-z0-9_]/', '', $owner ) ?? '' );
    }

    protected function contentItemMatchesOwnerFilter( array $item, string $owner_filter ) : bool {
        if ( $owner_filter === '' ) {
            return( true );
        }

        $scope = (string) ( $item['scope'] ?? 'root' );
        if ( $owner_filter === 'root' ) {
            return( $scope !== 'author' );
        }

        return( $scope === 'author' && strtolower( trim( (string) ( $item['author_slug'] ?? '' ) ) ) === $owner_filter );
    }

    protected function normalizeContentTitleFilter( string $title ) : string {
        $title = function_exists( 'mb_trim' ) ? mb_trim( $title ) : trim( $title );
        if ( $title === '' ) {
            return( '' );
        }

        return( preg_replace( '/\s+/u', ' ', $title ) ?? $title );
    }

    protected function normalizeContentTypeFilter( string $type ) : string {
        $type = strtolower( trim( $type ) );
        return( in_array( $type, [ 'post', 'page' ], true ) ? $type : '' );
    }

    protected function normalizeContentPage( mixed $page ) : int {
        return( max( 1, (int) $page ) );
    }

    protected function contentItemMatchesTitleFilter( array $item, string $title_filter ) : bool {
        if ( $title_filter === '' ) {
            return( true );
        }

        $candidate = '';
        if ( isset( $item['title'] ) ) {
            $candidate = (string) $item['title'];
        } elseif ( isset( $item['display_title'] ) ) {
            $candidate = (string) $item['display_title'];
        }

        if ( $candidate === '' ) {
            return( false );
        }

        $normalized_candidate = function_exists( 'mb_strtolower' ) ? mb_strtolower( $candidate, 'UTF-8' ) : strtolower( $candidate );
        $normalized_filter = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title_filter, 'UTF-8' ) : strtolower( $title_filter );
        return( str_contains( $normalized_candidate, $normalized_filter ) );
    }

    protected function contentItemMatchesTypeFilter( array $item, string $type_filter, string $field = 'type' ) : bool {
        if ( $type_filter === '' ) {
            return( true );
        }

        return( strtolower( trim( (string) ( $item[$field] ?? '' ) ) ) === $type_filter );
    }

    protected function contentItemMatchesFilters( array $item, string $owner_filter, string $title_filter, string $type_filter, string $type_field = 'type' ) : bool {
        return(
            $this->contentItemMatchesOwnerFilter( $item, $owner_filter ) &&
            $this->contentItemMatchesTitleFilter( $item, $title_filter ) &&
            $this->contentItemMatchesTypeFilter( $item, $type_filter, $type_field )
        );
    }

    protected function withHighlightedContentTitles( array $items, string $title_filter, string $field_key ) : array {
        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $items[$index]['title_highlight_html'] = $this->highlightContentTitle( (string) ( $item[$field_key] ?? '' ), $title_filter );
        }

        return( $items );
    }

    protected function highlightContentTitle( string $title, string $title_filter ) : string {
        $title_filter = $this->normalizeContentTitleFilter( $title_filter );
        if ( $title_filter === '' ) {
            return( htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
        }

        $pattern = '/(' . preg_quote( $title_filter, '/' ) . ')/iu';
        $parts = preg_split( $pattern, $title, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) || empty( $parts ) ) {
            return( htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
        }

        $highlighted = '';
        foreach ( $parts as $part ) {
            if ( $part === '' ) {
                continue;
            }

            if ( preg_match( $pattern, $part ) === 1 ) {
                $highlighted .= '<mark>' . htmlspecialchars( $part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</mark>';
                continue;
            }

            $highlighted .= htmlspecialchars( $part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        }

        return( $highlighted );
    }

    protected function paginateContentItems( array $items, int $page, int $per_page ) : array {
        $total_items = count( $items );
        $per_page = max( 1, $per_page );
        $total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
        $page = min( max( 1, $page ), $total_pages );
        $offset = ( $page - 1 ) * $per_page;

        return(
            [
                'items' => array_slice( $items, $offset, $per_page ),
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $per_page,
                    'total_items' => $total_items,
                    'total_pages' => $total_pages,
                    'has_previous' => $page > 1,
                    'has_next' => $page < $total_pages,
                    'previous_page' => $page > 1 ? $page - 1 : 1,
                    'next_page' => $page < $total_pages ? $page + 1 : $total_pages,
                ],
            ]
        );
    }

    protected function withContentRevisionCounts( array $items, array &$revision_counts ) : array {
        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $entry_id = trim( (string) ( $item['id'] ?? '' ) );
            if ( $entry_id === '' ) {
                $items[$index]['revision_count'] = 0;
                continue;
            }

            if ( ! array_key_exists( $entry_id, $revision_counts ) ) {
                $revision_counts[$entry_id] = count( $this->getEditorRevisions( $entry_id ) );
            }
            $items[$index]['revision_count'] = $revision_counts[$entry_id];
        }

        return( $items );
    }

    protected function buildContentOwnerFilterOptions( array $items ) : array {
        $authors = [];
        $has_root = false;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $scope = (string) ( $item['scope'] ?? 'root' );
            if ( $scope === 'author' ) {
                $author_slug = strtolower( trim( (string) ( $item['author_slug'] ?? '' ) ) );
                if ( $author_slug !== '' ) {
                    $authors[$author_slug] = true;
                }
                continue;
            }

            $has_root = true;
        }

        ksort( $authors );
        $options = [
            [
                'value' => '',
                'label' => 'All',
            ],
        ];
        if ( $has_root ) {
            $options[] = [
                'value' => 'root',
                'label' => 'root',
            ];
        }

        foreach ( array_keys( $authors ) as $author_slug ) {
            $options[] = [
                'value' => $author_slug,
                'label' => $author_slug,
            ];
        }

        return( $options );
    }

    protected function buildContentIndexUrl( string $tab = 'entries', array $content_filters = [] ) : string {
        $query = [];
        $tab = $this->normalizeContentBatchTab( $tab );
        if ( $tab !== 'entries' ) {
            $query['tab'] = $tab;
        }

        foreach ( $this->normalizeContentFilters( $content_filters ) as $query_key => $query_value ) {
            if ( $query_value !== '' ) {
                $query[$query_key] = $query_value;
            }
        }

        $url = $this->app->get( 'admin.url' ) . '/content';
        if ( ! empty( $query ) ) {
            $url .= '?' . http_build_query( $query );
        }

        if ( $tab !== 'entries' ) {
            $url .= '#tm-entry-pane-' . $tab;
        }

        return( $url );
    }

    protected function buildEntryPublicUrl( array $entry ) : string {
        $path = trim( (string) ( $entry['path'] ?? '' ), '/' );
        if ( $path === '' ) {
            return( '' );
        }

        $app_url = rtrim( (string) $this->app->get( 'app.url' ), '/' );
        if ( ( $entry['scope'] ?? 'root' ) === 'author' ) {
            $author_slug = trim( (string) ( $entry['author_slug'] ?? '' ), '/' );
            if ( $author_slug === '' ) {
                return( '' );
            }

            return( $app_url . '/' . rawurlencode( $author_slug ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) ) );
        }

        return( $app_url . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) ) );
    }

    protected function getSystemModerationEntries() : array {
        if ( $this->content_repository === null ) {
            return( [] );
        }

        $entries = $this->content_repository->getPendingReviewEntries( 100, $this->config->getDefaultLanguage() );
        foreach ( $entries as $index => $entry ) {
            $entries[$index]['updated_at_display'] = $this->formatUtcDateTime( (string) ( $entry['updated_at_utc'] ?? '' ) );
            $entries[$index]['open_url'] = $this->app->get( 'admin.url' ) . '/editor?entry=' . rawurlencode( (string) $entry['id'] ) . '&source=published';
            $entries[$index]['revision_count'] = count( $this->getEditorRevisions( (string) ( $entry['id'] ?? '' ) ) );
        }

        return( $entries );
    }

}
