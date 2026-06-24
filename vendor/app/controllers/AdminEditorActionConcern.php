<?php
namespace app\controllers;

use app\classes\TinyMashEditorMediaPickerService;

trait AdminEditorActionConcern {

    public function uploadEditorImage() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->json( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->json( [ 'error' => 'Your session token is invalid. Please reload the page and try again.' ], 403 );
            return;
        }

        $image_upload = $this->getCurrentEditorImageUploadSettings();
        if ( empty( $image_upload['enabled'] ) ) {
            $this->json( [ 'error' => $this->getUserValidationMessage( 'content_images_disabled' ) ], 403 );
            return;
        }

        try {
            if ( empty( $_FILES['image'] ) || ! is_array( $_FILES['image'] ) ) {
                throw new \InvalidArgumentException( 'content_image_file' );
            }

            $media_service = $this->app->has( 'media.service' ) ? $this->app->get( 'media.service' ) : null;
            if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'storeUploadedImage' ) ) {
                throw new \RuntimeException( 'Media service is not available.' );
            }

            $submission = $this->normalizeEditorSubmission( $data, true );
            $owner_username = $submission['scope'] === 'author' ? (string) $submission['author_slug'] : 'root';
            $uploaded_image = $media_service->storeUploadedImage( $_FILES['image'], $owner_username );
            $attachment_context = $this->resolveEditorAttachmentContext( $data, $owner_username );
            if ( method_exists( $media_service, 'assignAttachmentToContent' ) ) {
                $assigned_metadata = $media_service->assignAttachmentToContent(
                    (string) ( $uploaded_image['media_id'] ?? '' ),
                    [ $owner_username ],
                    $attachment_context['entry_id'],
                    $attachment_context['draft_id'],
                    $attachment_context['attachment_session_id']
                );
                if ( is_array( $assigned_metadata ) ) {
                    $uploaded_image['metadata'] = $assigned_metadata;
                }
            }
            $this->json(
                [
                    'uploaded' => true,
                    'media_id' => $uploaded_image['media_id'] ?? '',
                    'url' => $uploaded_image['url'],
                    'markdown' => $uploaded_image['markdown'],
                    'alt_text' => $uploaded_image['alt_text'] ?? '',
                    'filename' => $uploaded_image['filename'],
                    'width' => $uploaded_image['width'],
                    'height' => $uploaded_image['height'],
                    'bytes' => $uploaded_image['bytes'] ?? 0,
                    'mime' => $uploaded_image['mime'],
                    'owner_username' => $uploaded_image['owner_username'] ?? $owner_username,
                    'derivative_key' => $uploaded_image['derivative_key'] ?? 'stored_primary',
                ]
            );
            return;
        } catch ( \InvalidArgumentException $e ) {
            $this->json( [ 'error' => $this->getUserValidationMessage( $e->getMessage() ) ], 422 );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to upload editor image (' . $e->getMessage() . ')' );
            $this->json( [ 'error' => 'Image upload could not be completed right now.' ], 500 );
            return;
        }
    }

    public function loadEditorMediaPicker() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->json( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }

        $data = $this->getRequestDataArray();
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->json( [ 'error' => 'Invalid CSRF token.' ], 403 );
            return;
        }

        $source = strtolower( trim( (string) ( $data['source'] ?? 'attached' ) ) );
        if ( ! in_array( $source, [ 'attached', 'recent', 'library' ], true ) ) {
            $this->json( [ 'error' => 'Choose a valid media source.' ], 422 );
            return;
        }

        $picker_service = $this->getEditorMediaPickerService();
        if ( ! $picker_service instanceof TinyMashEditorMediaPickerService ) {
            $this->json(
                [
                    'source' => $source,
                    'records' => [],
                ]
            );
            return;
        }

        $context = [
            'scope' => (string) ( $data['scope'] ?? 'root' ),
            'author_slug' => (string) ( $data['author_slug'] ?? '' ),
            'loaded_entry_id' => (string) ( $data['loaded_entry_id'] ?? '' ),
            'loaded_draft_id' => (string) ( $data['draft_id'] ?? '' ),
            'attachment_session_id' => (string) ( $data['attachment_session_id'] ?? '' ),
            'media_type' => (string) ( $data['media_type'] ?? 'any' ),
        ];

        $this->json(
            [
                'source' => $source,
                'records' => $picker_service->listSourceRecords( $source, $context ),
            ]
        );
    }

    public function checkEditorSlug() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->json( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }

        $data = $this->getRequestDataArray();
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->json( [ 'error' => 'Invalid CSRF token.' ], 403 );
            return;
        }
        if ( $this->content_repository === null ) {
            $this->json( [ 'error' => 'Content storage is not available.' ], 500 );
            return;
        }

        try {
            $normalized_payload = $this->normalizeEditorSubmission( $data, false );
            $loaded_entry_id = $this->resolveAccessibleLoadedEntryId( (string) ( $data['loaded_entry_id'] ?? '' ) );
            $normalized_slug = $this->content_repository->normalizeEditorSlug(
                (string) ( $normalized_payload['slug'] !== '' ? $normalized_payload['slug'] : ( $data['title'] ?? '' ) )
            );
            $normalized_payload['slug'] = $normalized_slug;
            $duplicate_conflict = $normalized_slug !== '' ? $this->content_repository->hasEditorEntryConflict( $normalized_payload, $loaded_entry_id ) : false;
            $author_space_conflict = $normalized_slug !== '' ? $this->hasRootAuthorSpaceConflict( $normalized_payload ) : false;
            $has_conflict = $duplicate_conflict || $author_space_conflict;
            $this->json(
                [
                    'slug' => $normalized_slug,
                    'exists' => $has_conflict,
                    'available' => ! $has_conflict && $normalized_slug !== '',
                    'message' => $normalized_slug === ''
                        ? 'Slug is required.'
                        : ( $duplicate_conflict
                            ? 'Another content item already uses this slug in this scope.'
                            : ( $author_space_conflict
                                ? 'That root slug is reserved by an existing Username/Author.'
                                : 'Slug is available.' ) ),
                ]
            );
        } catch ( \InvalidArgumentException $e ) {
            $this->json( [ 'error' => $this->getUserValidationMessage( $e->getMessage() ) ], 422 );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to check editor slug (' . $e->getMessage() . ')' );
            $this->json( [ 'error' => 'Unable to validate slug right now.' ], 500 );
        }
    }

    public function searchEditorContent() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->json( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }

        $data = $this->getRequestDataArray();
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->json( [ 'error' => 'Invalid CSRF token.' ], 403 );
            return;
        }

        $query = mb_trim( (string) ( $data['q'] ?? '' ) );
        if ( mb_strlen( $query, 'UTF-8' ) < 2 ) {
            $this->json(
                [
                    'query' => $query,
                    'results' => [],
                    'message' => 'Type at least 2 characters.',
                ]
            );
            return;
        }

        $limit = (int) ( $data['limit'] ?? 12 );
        $limit = max( 1, min( 25, $limit ) );
        $normalized_query = mb_strtolower( $query, 'UTF-8' );
        $results = [];

        foreach ( $this->getEditorEntryOptions() as $entry_option ) {
            $haystack = mb_strtolower(
                trim(
                    (string) ( $entry_option['label'] ?? '' )
                    . ' ' . (string) ( $entry_option['title'] ?? '' )
                    . ' ' . (string) ( $entry_option['path'] ?? '' )
                    . ' ' . (string) ( $entry_option['type'] ?? '' )
                    . ' ' . (string) ( $entry_option['scope'] ?? '' )
                    . ' ' . (string) ( $entry_option['author_slug'] ?? '' )
                    . ' ' . (string) ( $entry_option['status'] ?? '' )
                ),
                'UTF-8'
            );
            if ( ! str_contains( $haystack, $normalized_query ) ) {
                continue;
            }

            $entry_id = (string) ( $entry_option['id'] ?? '' );
            if ( $entry_id === '' ) {
                continue;
            }

            $scope = (string) ( $entry_option['scope'] ?? 'root' );
            $author_slug = (string) ( $entry_option['author_slug'] ?? '' );
            $results[] = [
                'id' => $entry_id,
                'title' => (string) ( $entry_option['title'] ?? 'Untitled content' ),
                'label' => (string) ( $entry_option['label'] ?? ( $entry_option['title'] ?? 'Untitled content' ) ),
                'path' => (string) ( $entry_option['path'] ?? '' ),
                'type' => (string) ( $entry_option['type'] ?? '' ),
                'scope' => $scope,
                'scope_label' => $scope === 'author' && $author_slug !== '' ? $author_slug . ' space' : 'root',
                'author_slug' => $author_slug,
                'status' => (string) ( $entry_option['status'] ?? '' ),
                'url' => $this->app->get( 'admin.url' ) . '/editor?entry=' . rawurlencode( $entry_id ),
            ];

            if ( count( $results ) >= $limit ) {
                break;
            }
        }

        $this->json(
            [
                'query' => $query,
                'results' => $results,
                'message' => empty( $results ) ? 'No matching content found.' : '',
            ]
        );
    }

    public function saveEditorDraft() : void {
        $save_started_at = microtime( true );
        $timings = [];
        if ( ! $this->security->isLoggedIn() ) {
            $this->json( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }

        if ( $this->draft_repository === null ) {
            $this->json( [ 'error' => 'Draft storage is not available.' ], 500 );
            return;
        }

        $timing_started_at = microtime( true );
        $data = $this->getRequestDataArray();
        $timings['request_data'] = ( microtime( true ) - $timing_started_at ) * 1000;
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->json( [ 'error' => 'Invalid CSRF token.' ], 403 );
            return;
        }
        try {
            $timing_started_at = microtime( true );
            $normalized_payload = $this->normalizeEditorSubmission( $data, false );
            $timings['normalize'] = ( microtime( true ) - $timing_started_at ) * 1000;

            $timing_started_at = microtime( true );
            $normalized_payload = $this->applyEditorFeaturedImage( $normalized_payload );
            $normalized_payload = $this->applyEditorSocialImage( $normalized_payload );
            $timings['resolve_images'] = ( microtime( true ) - $timing_started_at ) * 1000;

            $timing_started_at = microtime( true );
            $attachment_context = $this->resolveEditorAttachmentContext(
                $data,
                $normalized_payload['scope'] === 'author' ? (string) $normalized_payload['author_slug'] : 'root'
            );
            $timings['resolve_attachments'] = ( microtime( true ) - $timing_started_at ) * 1000;

            $timing_started_at = microtime( true );
            $saved_draft = $this->draft_repository->saveEditorDraft(
                (string) $this->security->getCurrentUsername(),
                array_merge(
                    $normalized_payload,
                    [
                        'draft_id' => (string) ( $data['draft_id'] ?? '' ),
                        'loaded_entry_id' => $this->resolveAccessibleLoadedEntryIdForDraft( (string) ( $data['loaded_entry_id'] ?? '' ) ),
                    ]
                )
            );
            $timings['save_draft'] = ( microtime( true ) - $timing_started_at ) * 1000;

            $timing_started_at = microtime( true );
            $this->adoptEditorSessionAttachmentsToDraft(
                $normalized_payload['scope'] === 'author' ? (string) $normalized_payload['author_slug'] : 'root',
                $attachment_context,
                $saved_draft
            );
            $timings['adopt_attachments'] = ( microtime( true ) - $timing_started_at ) * 1000;
        } catch ( \InvalidArgumentException $e ) {
            $this->json( [ 'error' => $this->getEditorValidationMessage( $e->getMessage() ) ], 422 );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to save editor draft (' . $e->getMessage() . ')' );
            $this->json( [ 'error' => 'Unable to save draft right now.' ], 500 );
            return;
        }

        $timing_started_at = microtime( true );
        $response_payload = [
            'saved' => true,
            'draft_id' => $saved_draft['draft_id'],
            'updated_at_utc' => $saved_draft['updated_at_utc'],
            'updated_at_display' => $this->formatUtcDateTime( (string) $saved_draft['updated_at_utc'] ),
            'draft' => $this->buildEditorResponseDraft( $saved_draft ),
            'collections' => $this->buildEditorSaveCollections( (string) ( $saved_draft['loaded_entry_id'] ?? '' ) ),
        ];
        $timings['build_response'] = ( microtime( true ) - $timing_started_at ) * 1000;
        $timings['total'] = ( microtime( true ) - $save_started_at ) * 1000;
        $this->emitServerTimingHeader( $timings );
        $this->security->closeSession();
        $this->json( $response_payload );
    }

    public function deleteEditorDraft() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->json( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }

        if ( $this->draft_repository === null ) {
            $this->json( [ 'error' => 'Draft storage is not available.' ], 500 );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->json( [ 'error' => 'Invalid CSRF token.' ], 403 );
            return;
        }

        $username = (string) $this->security->getCurrentUsername();
        $draft_id = trim( (string) ( $data['draft_id'] ?? '' ) );
        if ( $draft_id === '' ) {
            $this->json( [ 'error' => 'Choose a draft to delete.' ], 422 );
            return;
        }

        $draft = $this->draft_repository->getEditorDraftById( $username, $draft_id );
        if ( ! is_array( $draft ) ) {
            $this->json( [ 'error' => 'That draft could not be found.' ], 404 );
            return;
        }

        try {
            $this->draft_repository->deleteEditorDraft( $username, $draft_id );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to delete editor draft (' . $e->getMessage() . ')' );
            $this->json( [ 'error' => 'Unable to delete draft right now.' ], 500 );
            return;
        }

        $remaining_drafts = $this->getEditorDrafts();
        $redirect_url = '';
        $source_entry_id = trim( (string) ( $draft['source_entry_id'] ?? '' ) );
        if ( $source_entry_id !== '' ) {
            $redirect_url = $this->app->get( 'admin.url' ) . '/editor?entry=' . rawurlencode( $source_entry_id ) . '&source=published';
        } elseif ( ! empty( $remaining_drafts[0]['draft_id'] ) ) {
            $redirect_url = $this->app->get( 'admin.url' ) . '/editor?draft=' . rawurlencode( (string) $remaining_drafts[0]['draft_id'] );
        } else {
            $redirect_url = $this->app->get( 'admin.url' ) . '/editor?new=1';
        }

        $this->json(
            [
                'deleted' => true,
                'deleted_draft_id' => $draft_id,
                'message' => 'Draft deleted.',
                'redirect_url' => $redirect_url,
                'collections' => $this->buildEditorCollections( $source_entry_id ),
            ]
        );
    }

    public function publishEditorEntry() : void {
        $save_started_at = microtime( true );
        $timings = [];
        if ( ! $this->security->isLoggedIn() ) {
            $this->json( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }

        if ( $this->content_repository === null ) {
            $this->json( [ 'error' => 'Content storage is not available.' ], 500 );
            return;
        }

        $timing_started_at = microtime( true );
        $data = $this->getRequestDataArray();
        $timings['request_data'] = ( microtime( true ) - $timing_started_at ) * 1000;
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->json( [ 'error' => 'Invalid CSRF token.' ], 403 );
            return;
        }
        try {
            $timing_started_at = microtime( true );
            $normalized_payload = $this->normalizeEditorSubmission( $data, true );
            $timings['normalize'] = ( microtime( true ) - $timing_started_at ) * 1000;

            $timing_started_at = microtime( true );
            $normalized_payload = $this->applyEditorFeaturedImage( $normalized_payload );
            $normalized_payload = $this->applyEditorSocialImage( $normalized_payload );
            $timings['resolve_images'] = ( microtime( true ) - $timing_started_at ) * 1000;
            if ( $this->hasRootAuthorSpaceConflict( $normalized_payload ) ) {
                throw new \InvalidArgumentException( 'reserved_author_slug' );
            }
            $draft_id = trim( (string) ( $data['draft_id'] ?? '' ) );
            $requested_status = strtolower( trim( (string) ( $normalized_payload['status'] ?? 'unpublished' ) ) );
            $save_status = $requested_status === 'published' ? 'published' : 'unpublished';
            if ( $save_status === 'published' && $this->config->isContentModerationRequired() && ! $this->security->isSuperAdmin() ) {
                $save_status = 'pending_review';
            }

            $timing_started_at = microtime( true );
            $loaded_entry_id = $this->resolveAccessibleLoadedEntryId( (string) ( $data['loaded_entry_id'] ?? '' ) );
            $loaded_entry = $loaded_entry_id !== '' ? $this->resolveAccessibleLoadedEntry( $loaded_entry_id ) : null;
            $attachment_context = $this->resolveEditorAttachmentContext(
                $data,
                $normalized_payload['scope'] === 'author' ? (string) $normalized_payload['author_slug'] : 'root'
            );
            $timings['resolve_context'] = ( microtime( true ) - $timing_started_at ) * 1000;

            $timing_started_at = microtime( true );
            $save_entry_timings = [];
            $published_entry = $this->content_repository->saveEditorEntry(
                $normalized_payload,
                $save_status,
                $loaded_entry_id,
                [
                    'published_at_utc' => (string) ( $normalized_payload['published_at_utc'] ?? '' ),
                    'loaded_entry_hint' => is_array( $loaded_entry ) ? $loaded_entry : [],
                ],
                $save_entry_timings
            );
            $timings['save_entry'] = ( microtime( true ) - $timing_started_at ) * 1000;
            foreach ( $save_entry_timings as $metric_name => $duration_ms ) {
                $timings['repo_' . $metric_name] = $duration_ms;
            }

            if ( $save_status === 'pending_review' && is_array( $published_entry ) && ! empty( $published_entry['id'] ) ) {
                $this->createSystemNotification(
                    'moderation_required',
                    'Content requires moderation',
                    'New content is waiting for review: ' . (string) ( $published_entry['title'] ?? 'Untitled content' ) . '.',
                    [
                        'severity' => 'warning',
                        'actor_username' => (string) ( $this->security->getCurrentUsername() ?? '' ),
                        'target_username' => (string) ( $published_entry['author_slug'] ?? '' ),
                        'dedupe_key' => 'moderation-required:' . (string) ( $published_entry['id'] ?? '' ),
                        'dedupe_window_seconds' => 86400,
                        'context' => [
                            'entry_id' => (string) ( $published_entry['id'] ?? '' ),
                            'path' => (string) ( $published_entry['path'] ?? '' ),
                            'title' => (string) ( $published_entry['title'] ?? '' ),
                        ],
                    ]
                );
            }

            $timing_started_at = microtime( true );
            $this->adoptEditorAttachmentsToEntry(
                $normalized_payload['scope'] === 'author' ? (string) $normalized_payload['author_slug'] : 'root',
                $attachment_context,
                $published_entry
            );
            $timings['adopt_attachments'] = ( microtime( true ) - $timing_started_at ) * 1000;

            $timing_started_at = microtime( true );
            $this->queueFediverseEntrySync(
                is_array( $loaded_entry ) ? $loaded_entry : null,
                $published_entry,
                (string) ( $this->security->getCurrentUsername() ?? '' )
            );
            $timings['fediverse_queue'] = ( microtime( true ) - $timing_started_at ) * 1000;
        } catch ( \InvalidArgumentException $e ) {
            $this->json( [ 'error' => $this->getEditorValidationMessage( $e->getMessage() ) ], 422 );
            return;
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to publish editor entry (' . $e->getMessage() . ')' );
            $this->json( [ 'error' => 'Unable to save content right now.' ], 500 );
            return;
        }

        if ( $this->draft_repository !== null && $draft_id !== '' ) {
            try {
                $timing_started_at = microtime( true );
                $this->draft_repository->deleteEditorDraft( (string) $this->security->getCurrentUsername(), $draft_id );
                $timings['delete_draft'] = ( microtime( true ) - $timing_started_at ) * 1000;
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Unable to delete merged editor draft after publish (' . $e->getMessage() . ')' );
            }
        }

        $entry_url = '/';
        if ( $this->theme !== null ) {
            $entry_url = $this->theme->getEntryURL( $published_entry );
        }

        $timing_started_at = microtime( true );
        $response_payload = [
            'published' => $published_entry['status'] === 'published',
            'pending_review' => $published_entry['status'] === 'pending_review',
            'status' => $published_entry['status'],
            'message' => $published_entry['status'] === 'pending_review'
                ? 'Content saved for moderation review.'
                : ( $published_entry['status'] === 'published' ? 'Content saved and published.' : 'Content saved as unpublished.' ),
            'updated_at_utc' => (string) $published_entry['updated_at_utc'],
            'updated_at_display' => $this->formatUtcDateTime( (string) $published_entry['updated_at_utc'] ),
            'url' => $entry_url,
            'draft_id' => '',
            'entry' => $this->buildEditorResponseEntry( $published_entry ),
            'collections' => $this->buildEditorSaveCollections( (string) ( $published_entry['id'] ?? '' ) ),
        ];
        $timings['build_response'] = ( microtime( true ) - $timing_started_at ) * 1000;
        $timings['total'] = ( microtime( true ) - $save_started_at ) * 1000;
        $this->emitServerTimingHeader( $timings );
        $this->security->closeSession();
        $this->json( $response_payload );
    }

    public function previewMarkdown() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->json( [ 'error' => 'Authentication required.' ], 403 );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->json( [ 'error' => 'Invalid CSRF token.' ], 403 );
            return;
        }

        $markdown = (string) ( $data['markdown'] ?? '' );
        $html = '';
        if ( $this->markdown_renderer !== null ) {
            if ( $this->app->has( 'shortcode.registry' ) ) {
                $shortcode_registry = $this->app->get( 'shortcode.registry' );
                if ( $shortcode_registry instanceof \app\classes\TinyMashShortcodeRegistry ) {
                    $shortcode_registry->resetRequestState();
                    $markdown = $shortcode_registry->renderShortcodes(
                        $markdown,
                        [
                            'surface' => 'preview',
                            'current_user' => $this->security->getCurrentUsername(),
                            'is_superadmin' => $this->security->isSuperAdmin(),
                        ]
                    );
                }
            }
            $html = $this->markdown_renderer->render( $markdown, [ 'classic_smileys_enabled' => $this->getCurrentClassicSmileysSettings()['enabled'] ] );
        }

        $this->json(
            [
                'html' => $html,
            ]
        );
    }
}
