<?php
namespace app\controllers;

use app\classes\TinyMashContentTargetPickerService;
use app\classes\TinyMashEditorMediaPickerService;
use app\classes\TinyMashMarkdownEditorComponent;

trait AdminEditorStateConcern {

    public function editor() : void {
        $data = $this->app->request()->query->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        $requested_entry_id = ! empty( $data['entry'] ) ? trim( (string) $data['entry'] ) : '';
        $requested_draft_id = ! empty( $data['draft'] ) ? trim( (string) $data['draft'] ) : '';
        $requested_revision_id = ! empty( $data['revision'] ) ? trim( (string) $data['revision'] ) : '';
        $requested_source = ! empty( $data['source'] ) ? trim( (string) $data['source'] ) : '';
        $editor_state = $this->getEditorState( $requested_entry_id, $requested_draft_id, ! empty( $data['new'] ), $requested_source, $requested_revision_id );
        $editor_attachment_session_id = $this->buildEditorAttachmentSessionId( $editor_state );
        $editor_image_upload = $this->getCurrentEditorImageUploadSettings();
        $editor_shortcodes = $this->getEditorShortcodes();
        if ( $requested_entry_id !== '' && ! empty( $editor_state['requested_entry_denied'] ) ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'current_username' => $this->security->getCurrentUsername(),
                    'is_superadmin' => $this->security->isSuperAdmin(),
                    'current_section' => 'composer',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
                    'help_contexts' => [ 'admin-editor' ],
                ]
            );
            return;
        }

        $this->renderAdmin(
            'editor',
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Editor',
                'app_url' => $this->app->get( 'app.url' ),
                'admin_url' => $this->app->get( 'admin.url' ),
                'current_role' => $this->security->getCurrentRole(),
                'current_username' => $this->security->getCurrentUsername(),
                'is_superadmin' => $this->security->isSuperAdmin(),
                'content_moderation_required' => $this->config->isContentModerationRequired(),
                'current_section' => 'composer',
                'current_location' => 'composer',
                'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
                'entries_url' => $this->app->get( 'admin.url' ) . '/content',
                'preview_url' => $this->app->get( 'admin.url' ) . '/preview/markdown',
                'draft_url' => $this->app->get( 'admin.url' ) . '/editor/draft',
                'draft_delete_url' => $this->app->get( 'admin.url' ) . '/editor/draft/delete',
                'publish_url' => $this->app->get( 'admin.url' ) . '/editor/publish',
                'editor_url' => $this->app->get( 'admin.url' ) . '/editor',
                'editor_cancel_url' => $this->security->isSuperAdmin()
                    ? $this->app->get( 'admin.url' ) . '/content'
                    : $this->app->get( 'admin.url' ) . '/author',
                'csrf_token' => $this->security->getCsrfToken(),
                'editor_draft' => $editor_state['draft'],
                'editor_has_saved_draft' => $editor_state['has_saved_draft'],
                'editor_loaded_entry_id' => $editor_state['loaded_entry_id'],
                'editor_loaded_draft_id' => $editor_state['loaded_draft_id'],
                'editor_loaded_revision_id' => $editor_state['loaded_revision_id'],
                'editor_attachment_session_id' => $editor_attachment_session_id,
                'editor_loaded_source' => $editor_state['source'],
                'editor_notice' => $editor_state['notice'],
                'editor_slug_check_url' => $this->app->get( 'admin.url' ) . '/editor/slug-check',
                'editor_content_search_url' => $this->app->get( 'admin.url' ) . '/editor/content-search',
                'editor_media_picker_url' => $this->app->get( 'admin.url' ) . '/editor/media-picker',
                'editor_new_url' => $this->app->get( 'admin.url' ) . '/editor?new=1',
                'editor_entry_options' => $this->getEditorEntryOptions(),
                'editor_linkable_entries' => $this->getEditorLinkableEntries(),
                'editor_tag_suggestions' => $this->getEditorTagSuggestions(),
                'editor_current_featured_image' => $this->resolveCurrentEditorMediaPickerRecord(
                    $editor_state['draft'],
                    'featured_image_media_id',
                    'featured_image'
                ),
                'editor_current_social_image' => $this->resolveCurrentEditorMediaPickerRecord(
                    $editor_state['draft'],
                    'seo_social_image_media_id',
                    'seo_social_image'
                ),
                'editor_recent_entries' => $this->getRecentEditorEntries(),
                'editor_drafts' => $this->getEditorDrafts(),
                'editor_revisions' => $this->getEditorRevisions( $editor_state['loaded_entry_id'] ),
                'editor_parent_page_options' => $this->getEditorParentPageOptions(),
                'current_author_slug' => $this->getCurrentAuthorSlug(),
                'editor_author_options' => $this->getEditorAuthorOptions(),
                'editor_autosave' => $this->getCurrentEditorAutosaveSettings(),
                'editor_image_upload' => $editor_image_upload,
                'editor_markdown_component_html' => TinyMashMarkdownEditorComponent::render(
                    [
                        'field_id' => 'tm-editor-markdown',
                        'content' => (string) ( $editor_state['draft']['content'] ?? '' ),
                        'rows' => 22,
                        'shortcodes' => $editor_shortcodes,
                        'external_links' => true,
                        'internal_links' => true,
                        'images' => ! empty( $editor_image_upload['enabled'] ),
                        'emoji' => true,
                        'emoji_autocomplete' => true,
                    ]
                ),
                'editor_featured_image' => $this->getCurrentEditorFeaturedImageSettings(),
                'editor_tags_enabled' => $this->config->areTagsEnabled(),
                'editor_seo_enabled' => $this->app->has( 'public.seo.service' ),
                'editor_plugin_tabs' => $this->getEditorPluginTabs( $editor_state ),
                'editor_shortcodes' => $editor_shortcodes,
                'help_contexts' => [ 'admin-editor' ],
            ]
        );
    }

    protected function getCurrentEditorImageUploadSettings() : array {
        $mode = $this->config->getContentImagesMode();
        $selected_driver = $this->getCurrentMediaRuntimeSelectedDriver();
        $driver_available = $selected_driver !== 'none';
        $enabled = false;
        if ( $driver_available && $mode === 'authenticated' && $this->security->isLoggedIn() ) {
            $enabled = true;
        } elseif ( $driver_available && $mode === 'admins_only' && $this->security->isSuperAdmin() ) {
            $enabled = true;
        }

        $capability_details = [];
        if ( $this->app->has( 'media.capability_registry' ) ) {
            $media_capability_registry = $this->app->get( 'media.capability_registry' );
            if ( is_object( $media_capability_registry ) && method_exists( $media_capability_registry, 'resolveCapabilities' ) ) {
                $capability_details = $media_capability_registry->resolveCapabilities(
                    [
                        'editor.image_picker' => $enabled,
                        'editor.paste_images' => $enabled,
                        'editor.drag_drop_images' => $enabled,
                    ]
                );
            }
        }

        if ( ! $driver_available ) {
            $message = 'No graphics driver selected. Content images are disabled in the composer.';
        } else {
            $message = match ( $mode ) {
                'admins_only' => $enabled
                    ? 'Content images are enabled here for admins through upload, paste, and drag-and-drop.'
                    : 'Content images are currently limited to admins on this site.',
                'authenticated' => $enabled
                    ? 'Content images are enabled here through upload, paste, and drag-and-drop.'
                    : 'Log in to use content images in the composer.',
                default => 'Content images are disabled for this site.',
            };
        }

        return(
            [
                'enabled' => $enabled,
                'mode' => $mode,
                'mode_label' => $this->describeContentImageMode( $mode ),
                'selected_driver' => $selected_driver,
                'driver_available' => $driver_available,
                'message' => $message,
                'upload_url' => $this->app->get( 'admin.url' ) . '/editor/image-upload',
                'capability_details' => $capability_details,
            ]
        );
    }

    protected function getCurrentEditorFeaturedImageSettings() : array {
        $selected_driver = $this->getCurrentMediaRuntimeSelectedDriver();
        if ( $selected_driver === 'none' ) {
            return(
                [
                    'enabled' => false,
                    'message' => 'No graphics driver selected, featured image is disabled.',
                ]
            );
        }

        return(
            [
                'enabled' => true,
                'message' => 'Optional. Themes can use the selected image in lists and entry views.',
            ]
        );
    }

    protected function getCurrentMediaRuntimeSelectedDriver() : string {
        if ( ! $this->app->has( 'media.service' ) ) {
            return( 'none' );
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'getRuntimeInfo' ) ) {
            return( 'none' );
        }

        $runtime_info = $media_service->getRuntimeInfo();
        return( strtolower( trim( (string) ( $runtime_info['selected_driver'] ?? 'none' ) ) ) ?: 'none' );
    }

    protected function getEditorDraft( string $draft_id = '' ) : array {
        $fallback_draft = $this->buildDefaultEditorDraft();

        if ( $this->draft_repository === null || ! $this->security->isLoggedIn() ) {
            return( $fallback_draft );
        }

        $draft = $this->draft_repository->getEditorDraft( (string) $this->security->getCurrentUsername(), $draft_id );
        if ( $draft['content'] === '' ) {
            $draft['content'] = $fallback_draft['content'];
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $draft['scope'] = 'author';
            $draft['author_slug'] = $this->getCurrentAuthorSlug();
        }
        $draft['draft_id'] = trim( (string) ( $draft['draft_id'] ?? '' ) );
        $draft['source_entry_id'] = trim( (string) ( $draft['source_entry_id'] ?? ( $draft['loaded_entry_id'] ?? '' ) ) );
        $draft['loaded_entry_id'] = trim( (string) ( $draft['loaded_entry_id'] ?? '' ) );
        $draft['published_at_local'] = $this->formatUtcDateTimeForEditorInput( (string) ( $draft['published_at_utc'] ?? '' ) );
        $draft['updated_at_display'] = $this->formatUtcDateTime( (string) ( $draft['updated_at_utc'] ?? '' ) );

        return( $draft );
    }

    protected function buildDefaultEditorDraft() : array {
        $publishing_defaults = $this->getCurrentPublishingDefaults();
        $default_scope = $this->security->isSuperAdmin() ? 'root' : 'author';
        $default_author_slug = $this->security->isSuperAdmin() ? '' : $this->getCurrentAuthorSlug();

        return(
            [
                'draft_id' => '',
                'source_entry_id' => '',
                'entry_type' => (string) ( $publishing_defaults['default_entry_type'] ?? 'post' ),
                'scope' => $default_scope,
                'author_slug' => $default_author_slug,
                'loaded_entry_id' => '',
                'status' => (string) ( $publishing_defaults['default_status'] ?? 'unpublished' ),
                'published_at_utc' => '',
                'published_at_local' => '',
                'slug' => '',
                'title' => '',
                'summary' => '',
                'tags' => [],
                'plugin_settings' => [],
                'seo_title' => '',
                'seo_description' => '',
                'seo_social_title' => '',
                'seo_social_description' => '',
                'seo_social_image_media_id' => '',
                'seo_social_image' => [],
                'seo_canonical_url' => '',
                'seo_robots' => '',
                'seo_exclude_from_sitemap' => false,
                'content' => '',
                'parent_slug' => '',
                'sort_order' => 0,
                'aggregate_to_root' => ! empty( $publishing_defaults['default_aggregate_to_root'] ),
                'sticky' => false,
                'featured' => false,
                'show_in_navigation' => false,
                'featured_image_media_id' => '',
                'featured_image' => [],
                'fallback_cover_color' => '',
                'updated_at_utc' => '',
                'updated_at_display' => '',
            ]
        );
    }

    protected function resetEditorDraft() : void {
        return;
    }

    protected function hasSavedEditorDraft() : bool {
        if ( $this->draft_repository === null || ! $this->security->isLoggedIn() ) {
            return( false );
        }

        return( $this->draft_repository->hasEditorDraft( (string) $this->security->getCurrentUsername() ) );
    }

    protected function getEditorState( string $requested_entry_id = '', string $requested_draft_id = '', bool $force_new = false, string $requested_source = '', string $requested_revision_id = '' ) : array {
        $has_saved_draft = $this->hasSavedEditorDraft();
        $draft = ( ! $force_new && $has_saved_draft ) ? $this->getEditorDraft() : $this->buildDefaultEditorDraft();
        $requested_draft_id = trim( $requested_draft_id );
        $requested_revision_id = trim( $requested_revision_id );
        $requested_source = strtolower( trim( $requested_source ) );
        $source = ( ! $force_new && $has_saved_draft ) ? 'draft' : 'new';
        $loaded_entry_id = trim( (string) ( $draft['loaded_entry_id'] ?? '' ) );
        $loaded_draft_id = trim( (string) ( $draft['draft_id'] ?? '' ) );
        $loaded_revision_id = '';
        $requested_entry_denied = false;
        $requested_entry_invalid = false;
        $requested_draft_invalid = false;
        $requested_revision_invalid = false;
        $active_draft_notice = null;
        $requested_entry_id = trim( $requested_entry_id );
        $author_filter = $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug();
        $allow_root = $this->security->isSuperAdmin();

        if ( ! $force_new && $requested_draft_id !== '' ) {
            $requested_draft = $this->getEditorDraft( $requested_draft_id );
            if ( ! empty( $requested_draft['draft_id'] ) ) {
                $draft = $requested_draft;
                $loaded_entry_id = trim( (string) ( $draft['loaded_entry_id'] ?? '' ) );
                $loaded_draft_id = trim( (string) ( $draft['draft_id'] ?? '' ) );
                $source = 'draft';
            } else {
                $requested_draft_invalid = true;
            }
        } elseif ( ! $force_new && $requested_entry_id !== '' && $requested_revision_id !== '' && $this->content_repository !== null ) {
            $revision_entry = $this->content_repository->getEntryRevisionSnapshot( $requested_entry_id, $requested_revision_id, $author_filter, $allow_root );
            if ( is_array( $revision_entry ) ) {
                $draft = $this->buildEditorDraftFromEntry( $revision_entry );
                $loaded_entry_id = $revision_entry['id'];
                $loaded_draft_id = '';
                $loaded_revision_id = (string) ( $revision_entry['revision_id'] ?? '' );
                $source = 'revision';
                $active_draft_notice = [
                    'type' => 'info',
                    'message' => 'You are viewing a revision snapshot. Save to restore it as the current content state, or duplicate it into new content.',
                    'actions' => [
                        [
                            'label' => 'Open current saved content',
                            'url' => $this->app->get( 'admin.url' ) . '/editor?entry=' . rawurlencode( $revision_entry['id'] ) . '&source=published',
                            'class' => 'btn btn-sm btn-outline-secondary',
                        ],
                    ],
                ];
            } else {
                $requested_revision_invalid = true;
            }
        } elseif ( ! $force_new && $requested_entry_id !== '' && $this->content_repository !== null ) {
            $published_entry = $this->content_repository->getAccessibleEntryById( $requested_entry_id, null, $author_filter, $allow_root );
            if ( is_array( $published_entry ) ) {
                $active_draft = $this->draft_repository !== null
                    ? $this->draft_repository->getEditorDraftBySourceEntryId( (string) $this->security->getCurrentUsername(), $published_entry['id'] )
                    : null;
                if ( is_array( $active_draft ) ) {
                    if ( $requested_source === 'published' ) {
                        $draft = $this->buildEditorDraftFromEntry( $published_entry );
                        $loaded_entry_id = $published_entry['id'];
                        $loaded_draft_id = '';
                        $source = 'published';
                        $active_draft_notice = [
                            'type' => 'info',
                            'message' => 'An active draft already exists for this content item. You are currently editing the saved content.',
                            'actions' => [
                                [
                                    'label' => 'Open draft',
                                    'url' => $this->app->get( 'admin.url' ) . '/editor?draft=' . rawurlencode( (string) $active_draft['draft_id'] ),
                                    'class' => 'btn btn-sm btn-primary',
                                ],
                            ],
                        ];
                    } else {
                        $draft = $this->getEditorDraft( (string) $active_draft['draft_id'] );
                        $loaded_entry_id = trim( (string) ( $draft['loaded_entry_id'] ?? '' ) );
                        $loaded_draft_id = trim( (string) ( $draft['draft_id'] ?? '' ) );
                        $source = 'draft';
                        $active_draft_notice = [
                            'type' => 'info',
                            'message' => 'An active draft already exists for this content item. You are currently editing the draft.',
                            'actions' => [
                                [
                                    'label' => 'Continue with saved content',
                                    'url' => $this->app->get( 'admin.url' ) . '/editor?entry=' . rawurlencode( $published_entry['id'] ) . '&source=published',
                                    'class' => 'btn btn-sm btn-outline-secondary',
                                ],
                            ],
                        ];
                    }
                } else {
                    $draft = $this->buildEditorDraftFromEntry( $published_entry );
                    $loaded_entry_id = $published_entry['id'];
                    $loaded_draft_id = '';
                    $source = 'published';
                }
            } elseif ( $this->content_repository->getAccessibleEntryById( $requested_entry_id ) !== null ) {
                $requested_entry_denied = true;
            } else {
                $requested_entry_invalid = true;
            }
        } elseif ( ! $force_new && $has_saved_draft ) {
            $draft = $this->getEditorDraft();
            $loaded_entry_id = trim( (string) ( $draft['loaded_entry_id'] ?? '' ) );
            $loaded_draft_id = trim( (string) ( $draft['draft_id'] ?? '' ) );
            $source = 'draft';
        }

        if ( $loaded_entry_id !== '' && $this->content_repository !== null ) {
            $published_entry = $this->content_repository->getAccessibleEntryById( $loaded_entry_id, null, $author_filter, $allow_root );
            if ( ! is_array( $published_entry ) ) {
                $draft['source_entry_id'] = '';
                $draft['loaded_entry_id'] = '';
                $loaded_entry_id = '';
            }
        }

        $notice = null;
        if ( $requested_entry_invalid ) {
            $notice = [
                'type' => 'warning',
                'message' => 'That content item could not be found. The composer stayed on your current draft.',
            ];
        } elseif ( $requested_revision_invalid ) {
            $notice = [
                'type' => 'warning',
                'message' => 'That revision could not be found. The composer stayed on the current state.',
            ];
        } elseif ( $requested_draft_invalid ) {
            $notice = [
                'type' => 'warning',
                'message' => 'That draft could not be found. The composer stayed on the current state.',
            ];
        } elseif ( is_array( $active_draft_notice ) ) {
            $notice = $active_draft_notice;
        }

        return(
            [
                'draft' => $draft,
                'has_saved_draft' => $has_saved_draft,
                'loaded_entry_id' => $loaded_entry_id,
                'loaded_draft_id' => $loaded_draft_id,
                'loaded_revision_id' => $loaded_revision_id,
                'source' => $source,
                'requested_entry_denied' => $requested_entry_denied,
                'notice' => $notice,
            ]
        );
    }

    protected function getEditorEntryOptions() : array {
        if ( $this->content_repository === null ) {
            return( [] );
        }

        return(
            $this->content_repository->getEditorEntryOptions(
                null,
                $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug(),
                $this->security->isSuperAdmin()
            )
        );
    }

    protected function getRecentEditorEntries() : array {
        if ( $this->content_repository === null ) {
            return( [] );
        }

        return(
            $this->content_repository->getRecentEditorEntries(
                8,
                null,
                $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug(),
                $this->security->isSuperAdmin()
            )
        );
    }

    protected function getEditorParentPageOptions() : array {
        $page_options = [];
        foreach ( $this->getEditorEntryOptions() as $entry_option ) {
            if ( ! is_array( $entry_option ) || ( $entry_option['type'] ?? '' ) !== 'page' ) {
                continue;
            }

            $slug = (string) ( $entry_option['slug'] ?? '' );
            $path = (string) ( $entry_option['path'] ?? $slug );
            $page_options[] = [
                'id' => (string) ( $entry_option['id'] ?? '' ),
                'scope' => (string) ( $entry_option['scope'] ?? 'root' ),
                'author_slug' => (string) ( $entry_option['author_slug'] ?? '' ),
                'slug' => $slug,
                'path' => $path,
                'parent_slug' => (string) ( $entry_option['parent_slug'] ?? '' ),
                'sort_order' => (int) ( $entry_option['sort_order'] ?? 0 ),
                'title' => (string) ( $entry_option['title'] ?? '' ),
                'status' => (string) ( $entry_option['status'] ?? 'unpublished' ),
                'local_slug' => $slug,
            ];
        }

        return( $this->sortEditorParentPageOptionsAsTree( $page_options ) );
    }

    protected function getEditorParentPageLocalSlug( string $slug ) : string {
        $segments = preg_split( '@/+@', trim( $slug, '/' ) ) ?: [];
        $segments = array_values(
            array_filter(
                array_map( static fn( mixed $segment ) : string => trim( (string) $segment ), $segments ),
                static fn( string $segment ) : bool => $segment !== ''
            )
        );

        return( ! empty( $segments ) ? (string) end( $segments ) : trim( $slug, '/' ) );
    }

    protected function sortEditorParentPageOptionsAsTree( array $page_options ) : array {
        $grouped_options = [];
        foreach ( $page_options as $page_option ) {
            if ( ! is_array( $page_option ) ) {
                continue;
            }

            $scope = (string) ( $page_option['scope'] ?? 'root' );
            $author_slug = (string) ( $page_option['author_slug'] ?? '' );
            $parent_slug = (string) ( $page_option['parent_slug'] ?? '' );
            $group_key = $scope . "\n" . $author_slug . "\n" . $parent_slug;
            $grouped_options[$group_key][] = $page_option;
        }

        foreach ( $grouped_options as $group_key => $options ) {
            usort(
                $options,
                static function( array $left, array $right ) : int {
                    $left_sort_order = (int) ( $left['sort_order'] ?? 0 );
                    $right_sort_order = (int) ( $right['sort_order'] ?? 0 );
                    if ( $left_sort_order !== $right_sort_order ) {
                        return( $left_sort_order <=> $right_sort_order );
                    }

                    return( strcasecmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) ) );
                }
            );
            $grouped_options[$group_key] = $options;
        }

        $sorted_options = [];
        $root_keys = array_keys( $grouped_options );
        sort( $root_keys, SORT_STRING );
        foreach ( $root_keys as $group_key ) {
            $parts = explode( "\n", (string) $group_key );
            if ( (string) ( $parts[2] ?? '' ) !== '' ) {
                continue;
            }

            foreach ( $grouped_options[$group_key] as $page_option ) {
                $this->appendEditorParentPageOptionBranch( $sorted_options, $grouped_options, $page_option, 0 );
            }
        }

        return( $sorted_options );
    }

    protected function appendEditorParentPageOptionBranch( array &$sorted_options, array $grouped_options, array $page_option, int $depth ) : void {
        $page_option['depth'] = max( 0, $depth );
        $page_option['display_path'] = (int) $page_option['depth'] > 0
            ? '../' . (string) ( $page_option['local_slug'] ?? $this->getEditorParentPageLocalSlug( (string) ( $page_option['slug'] ?? '' ) ) )
            : '/' . trim( (string) ( $page_option['path'] ?? $page_option['slug'] ?? '' ), '/' );
        $sorted_options[] = $page_option;

        $child_group_key = (string) ( $page_option['scope'] ?? 'root' )
            . "\n"
            . (string) ( $page_option['author_slug'] ?? '' )
            . "\n"
            . (string) ( $page_option['slug'] ?? '' );
        foreach ( $grouped_options[$child_group_key] ?? [] as $child_option ) {
            $this->appendEditorParentPageOptionBranch( $sorted_options, $grouped_options, $child_option, $depth + 1 );
        }
    }

    protected function getEditorLinkableEntries() : array {
        $content_target_picker = $this->getContentTargetPickerService();
        if ( ! $content_target_picker instanceof TinyMashContentTargetPickerService ) {
            return( [] );
        }

        $targets = $content_target_picker->listTargets(
            null,
            $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug(),
            $this->security->isSuperAdmin(),
            [
                'published_only' => true,
                'types' => [ 'post', 'page' ],
            ]
        );

        if ( $this->app->has( 'downloads.service' ) ) {
            $downloads_service = $this->app->get( 'downloads.service' );
            if ( is_object( $downloads_service ) && method_exists( $downloads_service, 'listLinkTargets' ) ) {
                $downloads_targets = $downloads_service->listLinkTargets( $this->security->isSuperAdmin(), $this->getCurrentAuthorSlug() );
                if ( is_array( $downloads_targets ) && ! empty( $downloads_targets ) ) {
                    $targets = array_merge( $targets, $downloads_targets );
                }
            }
        }

        return( $targets );
    }

    protected function getEditorTagSuggestions() : array {
        if ( $this->content_repository === null || ! $this->config->areTagsEnabled() ) {
            return( [] );
        }

        $current_author_slug = $this->getCurrentAuthorSlug();
        return(
            $this->content_repository->getEditorTagSuggestions(
                $current_author_slug,
                $this->security->isSuperAdmin()
            )
        );
    }

    protected function getContentTargetPickerService() : ?TinyMashContentTargetPickerService {
        if ( $this->app->has( 'content.target_picker' ) ) {
            $content_target_picker = $this->app->get( 'content.target_picker' );
            if ( $content_target_picker instanceof TinyMashContentTargetPickerService ) {
                return( $content_target_picker );
            }
        }

        if ( $this->content_repository === null ) {
            return( null );
        }

        return( new TinyMashContentTargetPickerService( $this->content_repository ) );
    }

    protected function getCurrentEditorDraftInfo() : array {
        if ( $this->draft_repository === null ) {
            return( [] );
        }

        $drafts = $this->getEditorDrafts();
        if ( empty( $drafts[0] ) ) {
            return( [] );
        }

        $draft = $drafts[0];
        return(
            [
                'has_saved_draft' => true,
                'draft_count' => count( $drafts ),
                'draft_id' => (string) ( $draft['draft_id'] ?? '' ),
                'title' => (string) ( $draft['title'] ?? '' ),
                'updated_at_utc' => (string) ( $draft['updated_at_utc'] ?? '' ),
                'updated_at_display' => $this->formatUtcDateTime( (string) ( $draft['updated_at_utc'] ?? '' ) ),
                'loaded_entry_id' => (string) ( $draft['loaded_entry_id'] ?? '' ),
            ]
        );
    }

    protected function getEditorDrafts() : array {
        if ( $this->draft_repository === null || ! $this->security->isLoggedIn() ) {
            return( [] );
        }

        $drafts = $this->draft_repository->listEditorDrafts( (string) $this->security->getCurrentUsername() );
        foreach ( $drafts as $index => $draft ) {
            $drafts[$index]['updated_at_display'] = $this->formatUtcDateTime( (string) ( $draft['updated_at_utc'] ?? '' ) );
            $draft_title = mb_trim( (string) ( $draft['title'] ?? '' ) );
            $drafts[$index]['display_title'] = $draft_title !== '' ? $draft_title : 'Untitled draft';
        }

        return( $drafts );
    }

    protected function buildEditorCollections( string $loaded_entry_id = '' ) : array {
        return(
            [
                'entry_options' => $this->getEditorEntryOptions(),
                'recent_entries' => $this->getRecentEditorEntries(),
                'drafts' => $this->getEditorDrafts(),
                'revisions' => $this->getEditorRevisions( $loaded_entry_id ),
            ]
        );
    }

    protected function buildEditorSaveCollections( string $loaded_entry_id = '' ) : array {
        return(
            [
                'drafts' => $this->getEditorDrafts(),
                'recent_entries' => $this->getRecentEditorEntries(),
                'revisions' => $this->getEditorRevisions( $loaded_entry_id ),
            ]
        );
    }

    protected function buildEditorResponseEntry( array $entry ) : array {
        $translations = is_array( $entry['translations'] ?? null ) ? $entry['translations'] : [];
        $default_language = strtolower( trim( (string) ( $entry['default_language'] ?? 'en' ) ) );
        if ( $default_language === '' ) {
            $default_language = 'en';
        }
        $default_translation = is_array( $translations[$default_language] ?? null ) ? $translations[$default_language] : [];
        $fallback_translation = is_array( $default_translation ) && ! empty( $default_translation ) ? $default_translation : ( is_array( reset( $translations ) ) ? reset( $translations ) : [] );

        return(
            [
                'id' => (string) ( $entry['id'] ?? '' ),
                'title' => trim( (string) ( $entry['title'] ?? ( $fallback_translation['title'] ?? '' ) ) ),
                'slug' => (string) ( $entry['slug'] ?? '' ),
                'author_slug' => (string) ( $entry['author_slug'] ?? '' ),
                'parent_slug' => (string) ( $entry['parent_slug'] ?? '' ),
                'status' => (string) ( $entry['status'] ?? 'unpublished' ),
                'published_at_utc' => (string) ( $entry['published_at_utc'] ?? '' ),
                'published_at_local' => $this->formatUtcDateTimeForEditorInput( (string) ( $entry['published_at_utc'] ?? '' ) ),
                'seo_title' => (string) ( $entry['seo_title'] ?? '' ),
                'seo_description' => (string) ( $entry['seo_description'] ?? '' ),
                'seo_social_title' => (string) ( $entry['seo_social_title'] ?? '' ),
                'seo_social_description' => (string) ( $entry['seo_social_description'] ?? '' ),
                'seo_social_image_media_id' => (string) ( $entry['seo_social_image_media_id'] ?? '' ),
                'seo_social_image' => is_array( $entry['seo_social_image'] ?? null ) ? $entry['seo_social_image'] : [],
                'seo_canonical_url' => (string) ( $entry['seo_canonical_url'] ?? '' ),
                'seo_robots' => (string) ( $entry['seo_robots'] ?? '' ),
                'seo_exclude_from_sitemap' => ! empty( $entry['seo_exclude_from_sitemap'] ),
                'featured_image_media_id' => (string) ( $entry['featured_image_media_id'] ?? '' ),
                'featured_image' => is_array( $entry['featured_image'] ?? null ) ? $entry['featured_image'] : [],
                'fallback_cover_color' => (string) ( $entry['fallback_cover_color'] ?? '' ),
                'tags' => is_array( $entry['tags'] ?? null ) ? array_values( $entry['tags'] ) : [],
                'plugin_settings' => $this->normalizePluginSettingsMap( $entry['plugin_settings'] ?? [] ),
            ]
        );
    }

    protected function buildEditorResponseDraft( array $draft ) : array {
        return(
            [
                'draft_id' => (string) ( $draft['draft_id'] ?? '' ),
                'loaded_entry_id' => (string) ( $draft['loaded_entry_id'] ?? '' ),
                'published_at_utc' => (string) ( $draft['published_at_utc'] ?? '' ),
                'published_at_local' => $this->formatUtcDateTimeForEditorInput( (string) ( $draft['published_at_utc'] ?? '' ) ),
                'featured_image_media_id' => (string) ( $draft['featured_image_media_id'] ?? '' ),
                'featured_image' => is_array( $draft['featured_image'] ?? null ) ? $draft['featured_image'] : [],
                'fallback_cover_color' => (string) ( $draft['fallback_cover_color'] ?? '' ),
                'seo_social_image_media_id' => (string) ( $draft['seo_social_image_media_id'] ?? '' ),
                'seo_social_image' => is_array( $draft['seo_social_image'] ?? null ) ? $draft['seo_social_image'] : [],
                'tags' => is_array( $draft['tags'] ?? null ) ? array_values( $draft['tags'] ) : [],
                'plugin_settings' => $this->normalizePluginSettingsMap( $draft['plugin_settings'] ?? [] ),
            ]
        );
    }

    protected function formatUtcDateTimeForEditorInput( string $utc_datetime ) : string {
        $utc_datetime = trim( $utc_datetime );
        if ( $utc_datetime === '' ) {
            return( '' );
        }

        try {
            $timezone = $this->getEditorDateTimezone();
            $date_time = new \DateTimeImmutable( $utc_datetime, new \DateTimeZone( 'UTC' ) );
            return( $date_time->setTimezone( $timezone )->format( 'Y-m-d\TH:i' ) );
        } catch ( \Throwable ) {
            return( '' );
        }
    }

    protected function normalizeEditorDateTimeLocalToUtc( string $value ) : string {
        $value = trim( $value );
        if ( $value === '' ) {
            return( '' );
        }

        try {
            $timezone = $this->getEditorDateTimezone();
            $date_time = new \DateTimeImmutable( $value, $timezone );
            return( $date_time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) );
        } catch ( \Throwable ) {
            throw new \InvalidArgumentException( 'published_at_local' );
        }
    }

    protected function getEditorDateTimezone() : \DateTimeZone {
        $formatter = $this->app->has( 'formatter.date' ) ? $this->app->get( 'formatter.date' ) : null;
        if ( is_object( $formatter ) && method_exists( $formatter, 'getTimezoneName' ) ) {
            try {
                return( new \DateTimeZone( (string) $formatter->getTimezoneName() ) );
            } catch ( \Throwable ) {
            }
        }

        return( new \DateTimeZone( 'UTC' ) );
    }

    protected function getCurrentEditorAutosaveSettings() : array {
        $system_enabled = $this->config->isEditorAutosaveEnabled();
        $system_interval_seconds = $this->config->getEditorAutosaveIntervalSeconds();
        $effective_enabled = $system_enabled;
        $effective_interval_seconds = $system_interval_seconds;
        $user_mode = 'inherit';
        $user_interval_seconds = null;

        $user = $this->getCurrentUserRecord();
        if ( is_array( $user ) ) {
            $candidate_mode = strtolower( trim( (string) ( $user['autosave_mode'] ?? 'inherit' ) ) );
            if ( in_array( $candidate_mode, [ 'inherit', 'enabled', 'disabled' ], true ) ) {
                $user_mode = $candidate_mode;
            }
            $candidate_interval = $user['autosave_interval_seconds'] ?? null;
            if ( $candidate_interval !== null && $candidate_interval !== '' ) {
                $candidate_interval = (int) $candidate_interval;
                if ( $candidate_interval >= 30 && $candidate_interval <= 3600 ) {
                    $user_interval_seconds = $candidate_interval;
                }
            }
        }

        if ( ! $system_enabled ) {
            $effective_enabled = false;
        } elseif ( $user_mode === 'disabled' ) {
            $effective_enabled = false;
        } else {
            $effective_enabled = true;
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
    }

    protected function getCurrentClassicSmileysSettings() : array {
        $system_enabled = $this->config->isClassicSmileysEnabled();
        $effective_enabled = $system_enabled;
        $user_mode = 'inherit';

        $user = $this->getCurrentUserRecord();
        if ( is_array( $user ) ) {
            $candidate_mode = strtolower( trim( (string) ( $user['classic_smileys_mode'] ?? 'inherit' ) ) );
            if ( in_array( $candidate_mode, [ 'inherit', 'enabled', 'disabled' ], true ) ) {
                $user_mode = $candidate_mode;
            }
        }

        if ( $user_mode === 'enabled' ) {
            $effective_enabled = true;
        } elseif ( $user_mode === 'disabled' ) {
            $effective_enabled = false;
        }

        return(
            [
                'system_enabled' => $system_enabled,
                'user_mode' => $user_mode,
                'enabled' => $effective_enabled,
            ]
        );
    }

    protected function getCurrentAuthorSlug() : string {
        $user = $this->getCurrentUserRecord();
        if ( is_array( $user ) && ! empty( $user['author_slug'] ) ) {
            return( (string) $user['author_slug'] );
        }

        $username = $this->security->getCurrentUsername();
        return( is_string( $username ) ? trim( $username ) : '' );
    }

    protected function getEditorMediaPickerService() : ?TinyMashEditorMediaPickerService {
        if ( $this->editor_media_picker_service instanceof TinyMashEditorMediaPickerService ) {
            return( $this->editor_media_picker_service );
        }

        if ( ! $this->app->has( 'media.service' ) ) {
            return( null );
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) ) {
            return( null );
        }

        $this->editor_media_picker_service = new TinyMashEditorMediaPickerService(
            $media_service,
            $this->content_repository,
            $this->security->isSuperAdmin(),
            $this->getCurrentAuthorSlug()
        );

        return( $this->editor_media_picker_service );
    }

    protected function buildEditorMediaPickerRecord( array $image ) : array {
        $picker_service = $this->getEditorMediaPickerService();
        if ( ! $picker_service instanceof TinyMashEditorMediaPickerService ) {
            return( [] );
        }

        return( $picker_service->buildRecord( $image ) );
    }

    protected function resolveCurrentEditorMediaPickerRecord( array $draft, string $media_id_key, string $image_key ) : array {
        $current_image = is_array( $draft[$image_key] ?? null ) ? $draft[$image_key] : [];
        $record = $this->buildEditorMediaPickerRecord( $current_image );
        if ( ! empty( $record ) ) {
            return( $record );
        }

        $media_id = trim( (string) ( $draft[$media_id_key] ?? '' ) );
        if ( $media_id === '' ) {
            return( [] );
        }

        $picker_service = $this->getEditorMediaPickerService();
        if ( ! $picker_service instanceof TinyMashEditorMediaPickerService ) {
            return( [] );
        }

        return(
            $picker_service->resolveRecordByMediaId(
                $media_id,
                (string) ( $draft['scope'] ?? 'root' ),
                (string) ( $draft['author_slug'] ?? '' )
            )
        );
    }

    protected function getEditorAuthorOptions() : array {
        if ( ! $this->security->isSuperAdmin() ) {
            return( [] );
        }

        $user_repository = $this->getUserRepository();
        if ( $user_repository === null ) {
            return( [] );
        }

        $options = [];
        foreach ( $user_repository->getAllUsers() as $user ) {
            if ( ! is_array( $user ) || empty( $user['author_slug'] ) ) {
                continue;
            }
            $options[] = [
                'username' => (string) $user['username'],
                'author_slug' => (string) $user['author_slug'],
                'active' => ! empty( $user['active'] ),
                'content_active' => ! empty( $user['content_active'] ),
            ];
        }

        return( $options );
    }

    protected function getEditorRevisions( string $entry_id = '' ) : array {
        $entry_id = trim( $entry_id );
        if ( $entry_id === '' || $this->content_repository === null ) {
            return( [] );
        }

        $revisions = $this->content_repository->getEntryRevisionHistory(
            $entry_id,
            8,
            $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug(),
            $this->security->isSuperAdmin()
        );
        foreach ( $revisions as $index => $revision ) {
            $revisions[$index]['saved_at_display'] = $this->formatUtcDateTime( (string) ( $revision['saved_at_utc'] ?? '' ) );
            $revisions[$index]['open_url'] = $this->app->get( 'admin.url' ) . '/editor?entry=' . rawurlencode( (string) $entry_id ) . '&revision=' . rawurlencode( (string) ( $revision['revision_id'] ?? '' ) );
        }

        return( $revisions );
    }

    protected function normalizeEditorSubmission( array $data, bool $require_resolved_author ) : array {
        $current_author_slug = $this->getCurrentAuthorSlug();
        $scope = ! empty( $data['scope'] ) && (string) $data['scope'] === 'author' ? 'author' : 'root';
        $author_slug = trim( (string) ( $data['author_slug'] ?? '' ) );

        if ( ! $this->security->isSuperAdmin() ) {
            $scope = 'author';
            $author_slug = $current_author_slug;
        } elseif ( $scope === 'author' && $author_slug === '' && $current_author_slug !== '' ) {
            $author_slug = $current_author_slug;
        }

        if ( $this->security->isSuperAdmin() && $scope === 'author' && $require_resolved_author ) {
            $user_repository = $this->getUserRepository();
            if ( $user_repository === null || ! is_array( $user_repository->getUserByAuthorSlug( $author_slug ) ) ) {
                throw new \InvalidArgumentException( 'editor_author_slug' );
            }
        }

        $tags = (string) ( $data['tags'] ?? '' );
        if ( ! $this->config->areTagsEnabled() ) {
            $tags = '';
            $draft_id = trim( (string) ( $data['draft_id'] ?? '' ) );
            if ( $draft_id !== '' && $this->draft_repository !== null ) {
                $existing_draft = $this->draft_repository->getEditorDraftById( (string) $this->security->getCurrentUsername(), $draft_id );
                if ( is_array( $existing_draft ) && is_array( $existing_draft['tags'] ?? null ) ) {
                    $tags = implode( ', ', array_values( $existing_draft['tags'] ) );
                }
            } elseif ( ! empty( $data['loaded_entry_id'] ) ) {
                $loaded_entry = $this->resolveAccessibleLoadedEntry( (string) $data['loaded_entry_id'] );
                if ( is_array( $loaded_entry ) && is_array( $loaded_entry['tags'] ?? null ) ) {
                    $tags = implode( ', ', array_values( $loaded_entry['tags'] ) );
                }
            }
        }

        return(
            [
                'entry_type' => (string) ( $data['entry_type'] ?? 'post' ),
                'scope' => $scope,
                'author_slug' => $scope === 'author' ? $author_slug : '',
                'status' => (string) ( $data['status'] ?? 'unpublished' ),
                'published_at_utc' => $this->normalizeEditorDateTimeLocalToUtc( (string) ( $data['published_at_local'] ?? '' ) ),
                'slug' => (string) ( $data['slug'] ?? '' ),
                'title' => (string) ( $data['title'] ?? '' ),
                'summary' => (string) ( $data['summary'] ?? '' ),
                'tags' => $tags,
                'plugin_settings' => $this->normalizePluginSettingsMap( $data['plugin_settings'] ?? [] ),
                'seo_title' => (string) ( $data['seo_title'] ?? '' ),
                'seo_description' => (string) ( $data['seo_description'] ?? '' ),
                'seo_social_title' => (string) ( $data['seo_social_title'] ?? '' ),
                'seo_social_description' => (string) ( $data['seo_social_description'] ?? '' ),
                'seo_social_image_media_id' => (string) ( $data['seo_social_image_media_id'] ?? '' ),
                'seo_canonical_url' => (string) ( $data['seo_canonical_url'] ?? '' ),
                'seo_robots' => (string) ( $data['seo_robots'] ?? '' ),
                'seo_exclude_from_sitemap' => (string) ( $data['seo_exclude_from_sitemap'] ?? '0' ),
                'content' => (string) ( $data['content'] ?? '' ),
                'parent_slug' => (string) ( $data['parent_slug'] ?? '' ),
                'sort_order' => (string) ( $data['sort_order'] ?? '0' ),
                'aggregate_to_root' => (string) ( $data['aggregate_to_root'] ?? '0' ),
                'sticky' => (string) ( $data['sticky'] ?? '0' ),
                'featured' => (string) ( $data['featured'] ?? '0' ),
                'show_in_navigation' => (string) ( $data['show_in_navigation'] ?? '0' ),
                'featured_image_media_id' => (string) ( $data['featured_image_media_id'] ?? '' ),
                'fallback_cover_color' => (string) ( $data['fallback_cover_color'] ?? '' ),
            ]
        );
    }

    protected function resolveAccessibleLoadedEntryId( string $loaded_entry_id ) : string {
        $loaded_entry_id = trim( $loaded_entry_id );
        if ( $loaded_entry_id === '' || $this->content_repository === null ) {
            return( '' );
        }

        $author_filter = $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug();
        $allow_root = $this->security->isSuperAdmin();
        $accessible_entry = $this->content_repository->getAccessibleEntryById( $loaded_entry_id, null, $author_filter, $allow_root );
        if ( is_array( $accessible_entry ) ) {
            return( (string) $accessible_entry['id'] );
        }
        if ( $this->content_repository->getAccessibleEntryById( $loaded_entry_id ) !== null ) {
            throw new \InvalidArgumentException( 'editor_entry_access' );
        }

        throw new \InvalidArgumentException( 'editor_entry_access' );
    }

    protected function resolveAccessibleLoadedEntry( string $loaded_entry_id ) : ?array {
        $loaded_entry_id = trim( $loaded_entry_id );
        if ( $loaded_entry_id === '' || $this->content_repository === null ) {
            return( null );
        }

        $author_filter = $this->security->isSuperAdmin() ? null : $this->getCurrentAuthorSlug();
        $allow_root = $this->security->isSuperAdmin();
        $accessible_entry = $this->content_repository->getAccessibleEntryById( $loaded_entry_id, null, $author_filter, $allow_root );
        return( is_array( $accessible_entry ) ? $accessible_entry : null );
    }

    protected function resolveAccessibleLoadedEntryIdForDraft( string $loaded_entry_id ) : string {
        $loaded_entry_id = trim( $loaded_entry_id );
        if ( $loaded_entry_id === '' ) {
            return( '' );
        }

        try {
            return( $this->resolveAccessibleLoadedEntryId( $loaded_entry_id ) );
        } catch ( \InvalidArgumentException $e ) {
            return( '' );
        }
    }

    protected function buildEditorAttachmentSessionId( array $editor_state ) : string {
        return( 'session_' . bin2hex( random_bytes( 8 ) ) );
    }

    protected function normalizeEditorAttachmentSessionId( string $attachment_session_id ) : string {
        return( preg_replace( '/[^a-zA-Z0-9_-]/', '', trim( $attachment_session_id ) ) ?? '' );
    }

    protected function resolveEditorAttachmentContext( array $data, string $owner_username ) : array {
        $attachment_session_id = $this->normalizeEditorAttachmentSessionId( (string) ( $data['attachment_session_id'] ?? '' ) );
        $entry_id = $this->resolveAccessibleLoadedEntryIdForDraft( (string) ( $data['loaded_entry_id'] ?? '' ) );
        $draft_id = '';
        $requested_draft_id = trim( (string) ( $data['draft_id'] ?? '' ) );
        if ( $requested_draft_id !== '' && $this->draft_repository !== null ) {
            $draft = $this->draft_repository->getEditorDraftById( (string) $this->security->getCurrentUsername(), $requested_draft_id );
            if ( is_array( $draft ) ) {
                $draft_id = trim( (string) ( $draft['draft_id'] ?? '' ) );
            }
        }

        return(
            [
                'owner_username' => $owner_username,
                'entry_id' => $entry_id,
                'draft_id' => $draft_id,
                'attachment_session_id' => $attachment_session_id,
            ]
        );
    }

    protected function adoptEditorSessionAttachmentsToDraft( string $owner_username, array $attachment_context, array $saved_draft ) : void {
        if ( ! $this->app->has( 'media.service' ) ) {
            return;
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'adoptSessionAttachmentsToDraft' ) ) {
            return;
        }

        $attachment_session_id = trim( (string) ( $attachment_context['attachment_session_id'] ?? '' ) );
        $draft_id = trim( (string) ( $saved_draft['draft_id'] ?? '' ) );
        if ( $attachment_session_id === '' || $draft_id === '' ) {
            return;
        }

        $media_service->adoptSessionAttachmentsToDraft(
            [ $owner_username ],
            $attachment_session_id,
            $draft_id,
            trim( (string) ( $saved_draft['loaded_entry_id'] ?? '' ) )
        );
    }

    protected function adoptEditorAttachmentsToEntry( string $owner_username, array $attachment_context, array $published_entry ) : void {
        if ( ! $this->app->has( 'media.service' ) ) {
            return;
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'adoptAttachmentsToEntry' ) ) {
            return;
        }

        $entry_id = trim( (string) ( $published_entry['id'] ?? '' ) );
        if ( $entry_id === '' ) {
            return;
        }

        $media_service->adoptAttachmentsToEntry(
            [ $owner_username ],
            $entry_id,
            trim( (string) ( $attachment_context['entry_id'] ?? '' ) ),
            trim( (string) ( $attachment_context['draft_id'] ?? '' ) ),
            trim( (string) ( $attachment_context['attachment_session_id'] ?? '' ) )
        );
    }

    protected function getEditorValidationMessage( string $field ) : string {
        return(
            match ( $field ) {
                'duplicate_slug' => 'Another content item already uses this slug in this scope.',
                'reserved_author_slug' => 'That root slug is reserved by an existing Username/Author.',
                'editor_author_slug' => 'Choose an existing non-deleted Username/Author for author-scoped publishing.',
                'published_at_local' => 'Publish date/time is not valid.',
                'editor_entry_access' => 'You cannot edit that content item in this composer.',
                'trash_restore_slug_conflict' => 'That trashed item cannot be restored because active content already uses its slug in the same scope.',
                'trash_restore_parent_unavailable' => 'That trashed page cannot be restored because its parent page is missing or still in Trash.',
                'featured_image_media_id' => 'Choose a valid featured image.',
                'seo_social_image_media_id' => 'Choose a valid social image.',
                'SEO canonical URL must be an absolute http(s) URL.' => 'SEO canonical URL must be an absolute http(s) URL.',
                default => $field,
            }
        );
    }

    protected function buildEditorDraftFromEntry( array $entry ) : array {
        return(
            [
                'draft_id' => '',
                'source_entry_id' => (string) ( $entry['id'] ?? '' ),
                'entry_type' => (string) ( $entry['type'] ?? 'post' ),
                'scope' => (string) ( $entry['scope'] ?? 'root' ),
                'author_slug' => (string) ( $entry['author_slug'] ?? '' ),
                'loaded_entry_id' => (string) ( $entry['id'] ?? '' ),
                'status' => (string) ( $entry['status'] ?? 'unpublished' ),
                'published_at_utc' => (string) ( $entry['published_at_utc'] ?? '' ),
                'published_at_local' => $this->formatUtcDateTimeForEditorInput( (string) ( $entry['published_at_utc'] ?? '' ) ),
                'slug' => (string) ( $entry['slug'] ?? '' ),
                'title' => (string) ( $entry['title'] ?? '' ),
                'summary' => (string) ( $entry['summary'] ?? '' ),
                'tags' => is_array( $entry['tags'] ?? null ) ? array_values( $entry['tags'] ) : [],
                'plugin_settings' => $this->normalizePluginSettingsMap( $entry['plugin_settings'] ?? [] ),
                'seo_title' => (string) ( $entry['seo_title'] ?? '' ),
                'seo_description' => (string) ( $entry['seo_description'] ?? '' ),
                'seo_social_title' => (string) ( $entry['seo_social_title'] ?? '' ),
                'seo_social_description' => (string) ( $entry['seo_social_description'] ?? '' ),
                'seo_social_image_media_id' => trim( (string) ( $entry['seo_social_image_media_id'] ?? '' ) ),
                'seo_social_image' => is_array( $entry['seo_social_image'] ?? null ) ? $entry['seo_social_image'] : [],
                'seo_canonical_url' => (string) ( $entry['seo_canonical_url'] ?? '' ),
                'seo_robots' => (string) ( $entry['seo_robots'] ?? '' ),
                'seo_exclude_from_sitemap' => ! empty( $entry['seo_exclude_from_sitemap'] ),
                'content' => (string) ( $entry['content'] ?? '' ),
                'parent_slug' => (string) ( $entry['parent_slug'] ?? '' ),
                'sort_order' => (int) ( $entry['sort_order'] ?? 0 ),
                'aggregate_to_root' => ! empty( $entry['aggregate_to_root'] ),
                'sticky' => ! empty( $entry['sticky'] ),
                'featured' => ! empty( $entry['featured'] ),
                'show_in_navigation' => ! empty( $entry['show_in_navigation'] ),
                'featured_image_media_id' => trim( (string) ( $entry['featured_image_media_id'] ?? '' ) ),
                'featured_image' => is_array( $entry['featured_image'] ?? null ) ? $entry['featured_image'] : [],
                'fallback_cover_color' => (string) ( $entry['fallback_cover_color'] ?? '' ),
                'updated_at_utc' => (string) ( $entry['updated_at_utc'] ?? '' ),
                'updated_at_display' => $this->formatUtcDateTime( (string) ( $entry['updated_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function applyEditorFeaturedImage( array $normalized_payload ) : array {
        return( $this->applyEditorMediaImage( $normalized_payload, 'featured_image_media_id', 'featured_image' ) );
    }

    protected function applyEditorSocialImage( array $normalized_payload ) : array {
        return( $this->applyEditorMediaImage( $normalized_payload, 'seo_social_image_media_id', 'seo_social_image' ) );
    }

    protected function applyEditorMediaImage( array $normalized_payload, string $media_id_key, string $image_key ) : array {
        $media_id = trim( (string) ( $normalized_payload[$media_id_key] ?? '' ) );
        if ( $media_id === '' ) {
            $normalized_payload[$media_id_key] = '';
            $normalized_payload[$image_key] = [];
            return( $normalized_payload );
        }

        if ( ! $this->app->has( 'media.service' ) ) {
            throw new \InvalidArgumentException( $media_id_key );
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'getAttachmentMetadataByMediaId' ) ) {
            throw new \InvalidArgumentException( $media_id_key );
        }

        $picker_service = $this->getEditorMediaPickerService();
        $record = $picker_service instanceof TinyMashEditorMediaPickerService
            ? $picker_service->resolveRecordByMediaId(
                $media_id,
                (string) ( $normalized_payload['scope'] ?? 'root' ),
                (string) ( $normalized_payload['author_slug'] ?? '' )
            )
            : [];
        if ( empty( $record ) ) {
            throw new \InvalidArgumentException( $media_id_key );
        }

        $normalized_payload[$media_id_key] = $media_id;
        $normalized_payload[$image_key] = [
            'media_id' => $media_id,
            'owner_username' => strtolower( trim( (string) ( $record['owner_username'] ?? '' ) ) ),
            'filename' => basename( trim( (string) ( $record['filename'] ?? '' ) ) ),
            'url' => trim( (string) ( $record['url'] ?? '' ) ),
            'alt_text' => mb_trim( (string) ( $record['alt_text'] ?? '' ) ),
            'mime' => trim( (string) ( $record['mime'] ?? '' ) ),
            'width' => max( 0, (int) ( $record['width'] ?? 0 ) ),
            'height' => max( 0, (int) ( $record['height'] ?? 0 ) ),
            'bytes' => max( 0, (int) ( $record['bytes'] ?? 0 ) ),
            'derivative_key' => trim( (string) ( $record['derivative_key'] ?? 'stored_primary' ) ),
        ];

        return( $normalized_payload );
    }

    protected function hasRootAuthorSpaceConflict( array $normalized_payload ) : bool {
        if ( (string) ( $normalized_payload['scope'] ?? 'root' ) !== 'root' ) {
            return( false );
        }

        $slug = $this->content_repository !== null
            ? $this->content_repository->normalizeEditorSlug( (string) ( $normalized_payload['slug'] ?? '' ) )
            : strtolower( trim( (string) ( $normalized_payload['slug'] ?? '' ) ) );
        if ( $slug === '' ) {
            return( false );
        }

        $user_repository = $this->getUserRepository();
        if ( $user_repository === null ) {
            return( false );
        }

        return( is_array( $user_repository->getUserByAuthorSlug( $slug ) ) );
    }

    protected function getEditorPluginTabs( array $editor_state ) : array {
        $plugins = $this->getPluginsService();
        if ( $plugins === null || ! method_exists( $plugins, 'getEditorTabs' ) ) {
            return( [] );
        }

        $current_user = $this->getCurrentUserRecord();
        return(
            $plugins->getEditorTabs(
                [
                    'role' => $this->security->isSuperAdmin() ? 'admin' : 'author',
                    'draft' => is_array( $editor_state['draft'] ?? null ) ? $editor_state['draft'] : [],
                    'loaded_entry_id' => (string) ( $editor_state['loaded_entry_id'] ?? '' ),
                    'loaded_draft_id' => (string) ( $editor_state['loaded_draft_id'] ?? '' ),
                    'loaded_source' => (string) ( $editor_state['source'] ?? '' ),
                    'current_user' => is_array( $current_user ) ? $current_user : [],
                    'current_author_slug' => $this->getCurrentAuthorSlug(),
                    'is_superadmin' => $this->security->isSuperAdmin(),
                    'editor_image_upload' => $this->getCurrentEditorImageUploadSettings(),
                ]
            )
        );
    }

    protected function getEditorShortcodes() : array {
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
}
