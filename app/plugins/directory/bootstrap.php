<?php

require_once __DIR__ . '/TinyMashDirectoryService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashMediaService;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'directory' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $markdown_renderer = $plugins->getService( 'markdown.renderer' );
    $media_service = $plugins->getService( 'media.service' );
    $theme = $plugins->getService( 'theme' );
    $user_repository = $plugins->getService( 'user.repository' );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $markdown_renderer instanceof TinyMashMarkdownRenderer || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the Links plugin are not available.' );
    }

    $directory_service = new TinyMashDirectoryService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'directory' . DIRECTORY_SEPARATOR . 'directories.json',
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'directory' . DIRECTORY_SEPARATOR . 'items',
        $markdown_renderer,
        $media_service instanceof TinyMashMediaService ? $media_service : null,
        $user_repository
    );
    \Flight::set( 'directory.service', $directory_service );

    if ( $theme instanceof TinyMashTheme ) {
        $directory_service->setTemplateRenderer(
            static function( array $view_model ) use ( $theme ) : string {
                $candidates = [];
                foreach ( is_array( $view_model['template_candidates'] ?? null ) ? $view_model['template_candidates'] : [] as $candidate ) {
                    $candidate = trim( (string) $candidate );
                    if ( $candidate !== '' ) {
                        $candidates[] = 'directory/' . $candidate;
                    }
                }
                if ( empty( $candidates ) ) {
                    return( '' );
                }

                return( $theme->renderFirstExistingThemeTemplate(
                    $candidates,
                    [
                        'directory_view' => $view_model,
                        'directory' => is_array( $view_model['directory'] ?? null ) ? $view_model['directory'] : [],
                        'directory_type' => is_array( $view_model['type'] ?? null ) ? $view_model['type'] : [],
                        'directory_items' => is_array( $view_model['items'] ?? null ) ? $view_model['items'] : [],
                        'directory_groups' => is_array( $view_model['groups'] ?? null ) ? $view_model['groups'] : [],
                        'directory_tree' => is_array( $view_model['tree'] ?? null ) ? $view_model['tree'] : [],
                    ]
                ) );
            }
        );
    }

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $plugins->registerPublicAsset( $plugin_key, 'css', '/plugins/directory/directory.css' );

    $render_links_shortcode = static function( array $shortcode ) use ( $directory_service ) : array {
        $attributes = is_array( $shortcode['attributes'] ?? null ) ? $shortcode['attributes'] : [];
        $wide_content = false;
        $wide_value = strtolower( trim( (string) ( $attributes['wide'] ?? '' ) ) );
        if ( in_array( $wide_value, [ '1', 'true', 'yes', 'on' ], true ) ) {
            $wide_content = true;
        }
        $sidebar_value = strtolower( trim( (string) ( $attributes['sidebar'] ?? '' ) ) );
        if ( in_array( $sidebar_value, [ '0', 'false', 'no', 'off', 'hide', 'hidden', 'disabled' ], true ) ) {
            $wide_content = true;
        }
        return(
            [
                'html' => $directory_service->renderDirectoryHtml( $attributes ),
                'dynamic' => true,
                'page_hints' => $wide_content ? [ 'wide_content' => true ] : [],
            ]
        );
    };

    $plugins->registerShortcode(
        $plugin_key,
        'links',
        $render_links_shortcode,
        [
            'label' => 'Links',
            'description' => 'Shows a Links collection.',
            'example' => '[links id="useful-links" view="boxes"]',
            'dynamic' => true,
        ]
    );

    $plugins->registerMediaUsageContributor(
        $plugin_key,
        static function() use ( $directory_service ) : array {
            return( $directory_service->collectMediaUsageReferences() );
        }
    );

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/links';
    $legacy_plugin_url = $admin_url . '/directory';
    $directory_save_url = $plugin_url . '/directory/save';
    $item_save_url = $plugin_url . '/item/save';
    $item_duplicate_url = $plugin_url . '/item/duplicate';
    $item_delete_url = $plugin_url . '/item/delete';
    $media_picker_url = $plugin_url . '/media/picker';
    $media_upload_url = $plugin_url . '/media/upload';
    $legacy_directory_save_url = $legacy_plugin_url . '/directory/save';
    $legacy_item_save_url = $legacy_plugin_url . '/item/save';
    $legacy_item_duplicate_url = $legacy_plugin_url . '/item/duplicate';
    $legacy_item_delete_url = $legacy_plugin_url . '/item/delete';
    $legacy_media_picker_url = $legacy_plugin_url . '/media/picker';
    $legacy_media_upload_url = $legacy_plugin_url . '/media/upload';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'author',
        [
            'section' => 'directory',
            'label' => 'Links',
            'url' => $plugin_url,
            'icon' => 'bi-link-45deg',
            'order' => 74,
        ]
    );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-directory',
        [
            'title' => 'Links',
            'summary' => 'Create categorized link collections for content shortcodes.',
            'group' => 'Plugins',
            'order' => 138,
            'roles' => [ 'creator', 'admin' ],
            'sections' => [
                [
                    'title' => 'Overview',
                    'markdown' => 'Create categorized link collections and render them with `[links id="alias"]`.',
                ],
                [
                    'title' => 'Display',
                    'markdown' => 'Use `view="list"`, `view="boxes"`, `view="cards"`, or `view="tree"`. Add `expanded="true"` to start tree branches open. Add `wide="true"` or `sidebar="false"` to ask supporting themes for a full-width content area.',
                ],
                [
                    'title' => 'Categories and images',
                    'markdown' => 'Links are grouped by Category. Reuse an existing category from the suggestions when you want items to appear together. Description fields support Markdown; link titles are plain text. Icon/image fields use the image picker instead of pasted media IDs.',
                ],
                [
                    'title' => 'Scopes',
                    'markdown' => 'Admins can manage root and author link collections. Authors can manage their own author-scoped link collections.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };

    $current_username = static function() use ( $security ) : string {
        return( strtolower( trim( (string) $security->getCurrentUsername() ) ) );
    };

    $require_login = static function() use ( $plugins, $security, $login_url ) : bool {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return( false );
        }
        return( true );
    };

    $emit_json = static function( array $payload, int $status = 200 ) : void {
        http_response_code( $status );
        header( 'Content-Type: application/json; charset=UTF-8' );
        echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    };

    $render_select = static function( string $name, string $current, array $options, string $id = '' ) use ( $escape ) : string {
        $html = '<select class="form-select" name="' . $escape( $name ) . '"' . ( $id !== '' ? ' id="' . $escape( $id ) . '"' : '' ) . '>';
        foreach ( $options as $value => $label ) {
            $value = (string) $value;
            $html .= '<option value="' . $escape( $value ) . '"' . ( $value === $current ? ' selected' : '' ) . '>' . $escape( (string) $label ) . '</option>';
        }
        $html .= '</select>';
        return( $html );
    };

    $media_owner_usernames = static function( array $directory ) : array {
        $scope = (string) ( $directory['scope'] ?? 'root' );
        if ( $scope === 'author' ) {
            $author_slug = strtolower( trim( (string) ( $directory['author_slug'] ?? '' ) ) );
            return( $author_slug !== '' ? [ $author_slug ] : [] );
        }
        return( [ 'root' ] );
    };

    $directory_media_upload_settings = static function() use ( $config, $media_service, $security ) : array {
        $mode = strtolower( trim( (string) $config->getContentImagesMode() ) );
        $driver = $media_service instanceof TinyMashMediaService ? $media_service->resolveDriver() : 'none';
        $driver_available = $driver !== 'none';
        $enabled = false;
        if ( $driver_available && $mode === 'authenticated' && $security->isLoggedIn() ) {
            $enabled = true;
        } elseif ( $driver_available && $mode === 'admins_only' && $security->isSuperAdmin() ) {
            $enabled = true;
        }

        $message = match ( $mode ) {
            'admins_only' => $enabled ? 'Upload a new image for this directory.' : 'Image uploads are limited to admins on this site.',
            'authenticated' => $enabled ? 'Upload a new image for this directory.' : 'Log in to upload images.',
            default => 'Image uploads are disabled for this site.',
        };
        if ( ! $driver_available ) {
            $message = 'No graphics driver is available for image uploads.';
        }

        return(
            [
                'enabled' => $enabled,
                'mode' => $mode,
                'driver' => $driver,
                'message' => $message,
            ]
        );
    };

    $category_suggestions_for_items = static function( array $items ) : array {
        $suggestions = [];
        foreach ( $items as $item ) {
            $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : [];
            foreach ( [ 'category' ] as $key ) {
                $value = mb_trim( (string) ( $fields[$key] ?? '' ) );
                if ( $value !== '' ) {
                    $suggestions[mb_strtolower( $value )] = $value;
                }
            }
        }
        natcasesort( $suggestions );
        return( array_values( $suggestions ) );
    };

    $render_field_input = static function( array $field, array $values, string $prefix, array $context = [] ) use ( $escape, $render_select, $directory_service ) : string {
        $key = (string) ( $field['key'] ?? '' );
        if ( $key === '' ) {
            return( '' );
        }
        $field_type = (string) ( $field['type'] ?? 'text' );
        $label = (string) ( $field['label'] ?? $key );
        $value = (string) ( $values[$key] ?? '' );
        $name = 'fields[' . $key . ']';
        $id = 'tm-directory-' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $prefix . '-' . $key );
        $required = ! empty( $field['required'] ) ? ' required' : '';
        $owner_usernames = is_array( $context['media_owner_usernames'] ?? null ) ? $context['media_owner_usernames'] : [];
        $directory_id = (string) ( $context['directory_id'] ?? '' );
        $type_key = (string) ( $context['type_key'] ?? '' );
        $category_suggestions = is_array( $context['category_suggestions'] ?? null ) ? $context['category_suggestions'] : [];

        if ( $type_key === 'links' && $key === 'url' && $field_type === 'url' ) {
            $column_class = 'col-12 col-lg-8';
        } elseif ( $type_key === 'links' && $key === 'category' ) {
            $column_class = 'col-12 col-lg-4';
        } elseif ( $type_key === 'links' && $key === 'description' && $field_type === 'markdown' ) {
            $column_class = 'col-12 col-lg-8';
        } elseif ( $type_key === 'links' && $field_type === 'media' ) {
            $column_class = 'col-12 col-lg-4';
        } else {
            $column_class = match ( $field_type ) {
                'markdown', 'media' => 'col-12',
                default => 'col-12 col-lg-6',
            };
        }

        $html = '<div class="' . $column_class . '"><label class="form-label small mb-1" for="' . $escape( $id ) . '">' . $escape( $label ) . '</label>';
        if ( $field_type === 'markdown' ) {
            $html .= '<textarea class="form-control" id="' . $escape( $id ) . '" name="' . $escape( $name ) . '" rows="3"' . $required . '>' . $escape( $value ) . '</textarea>';
            $html .= '<div class="form-text">Markdown is supported here.</div>';
        } elseif ( $field_type === 'select' ) {
            $options = [];
            foreach ( (array) ( $field['options'] ?? [] ) as $option ) {
                $options[(string) $option] = (string) $option;
            }
            $html .= $render_select( $name, $value, [ '' => '' ] + $options, $id );
        } elseif ( $field_type === 'media' ) {
            $image = $value !== '' ? $directory_service->resolveSelectableImage( $value, $owner_usernames ) : [];
            $label_text = ! empty( $image ) ? (string) ( $image['alt_text'] ?: $image['filename'] ?: $value ) : '';
            $details = ! empty( $image['owner_username'] ) ? ' (' . (string) $image['owner_username'] . ')' : '';
            $status = $label_text !== '' ? 'Selected: ' . $label_text . $details : 'No image selected.';
            $thumbnail = ! empty( $image ) ? (string) ( $image['thumbnail_url'] ?: ( $image['display_url'] ?: $image['url'] ) ) : '';
            $html .= '<div class="d-grid gap-2" data-tm-admin-image-field data-tm-admin-image-context-id="' . $escape( $directory_id ) . '"><input id="' . $escape( $id ) . '" name="' . $escape( $name ) . '" type="hidden" value="' . $escape( $value ) . '" data-tm-admin-image-input' . $required . '>';
            $html .= '<div class="d-flex align-items-center gap-2 border rounded p-2 bg-body-tertiary"><div class="rounded bg-body overflow-hidden flex-shrink-0 d-flex align-items-center justify-content-center" style="width:3rem;height:3rem;">';
            $html .= $thumbnail !== '' ? '<img src="' . $escape( $thumbnail ) . '" alt="" loading="lazy" decoding="async" style="width:3rem;height:3rem;object-fit:cover;" data-tm-admin-image-preview>' : '<span class="bi bi-image text-body-secondary" aria-hidden="true" data-tm-admin-image-preview></span>';
            $html .= '</div><div class="small text-body-secondary text-truncate" data-tm-admin-image-status title="' . $escape( $status ) . '">' . $escape( $status ) . '</div></div>';
            $html .= '<div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-secondary btn-sm" type="button" data-tm-admin-image-open><span class="bi bi-images me-1" aria-hidden="true"></span>Choose image</button>';
            $html .= '<button class="btn btn-outline-secondary btn-sm' . ( $value === '' ? ' d-none' : '' ) . '" type="button" data-tm-admin-image-clear>Clear</button></div></div>';
        } else {
            $input_type = $field_type === 'email' ? 'email' : ( $field_type === 'url' ? 'url' : 'text' );
            $list_id = '';
            if ( $key === 'category' && ! empty( $category_suggestions ) ) {
                $list_id = $id . '-suggestions';
            }
            $html .= '<input class="form-control" id="' . $escape( $id ) . '" name="' . $escape( $name ) . '" type="' . $escape( $input_type ) . '" value="' . $escape( $value ) . '"' . ( $list_id !== '' ? ' list="' . $escape( $list_id ) . '"' : '' ) . $required . '>';
            if ( $list_id !== '' ) {
                $html .= '<datalist id="' . $escape( $list_id ) . '">';
                foreach ( $category_suggestions as $suggestion ) {
                    $html .= '<option value="' . $escape( (string) $suggestion ) . '"></option>';
                }
                $html .= '</datalist><div class="form-text">Reuse an existing category or type a new one.</div>';
            }
        }
        $html .= '</div>';
        return( $html );
    };

    $render_directory_form = static function( array $directory, string $title, string $button_label ) use ( $directory_save_url, $security, $escape, $render_select, $current_username ) : string {
        $id = (string) ( $directory['id'] ?? '' );
        $is_superadmin = $security->isSuperAdmin();
        $scope = (string) ( $directory['scope'] ?? ( $is_superadmin ? 'root' : 'author' ) );
        $author_slug = (string) ( $directory['author_slug'] ?? ( $scope === 'author' ? $current_username() : '' ) );
        $view_options = [ 'list' => 'List', 'boxes' => 'Boxes', 'cards' => 'Cards', 'tree' => 'Tree' ];
        $form_id = 'tm-directory-form-' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $id !== '' ? $id : 'new' );

        $html = '<form method="post" action="' . $escape( $directory_save_url ) . '" class="rounded-3 p-3 bg-body-secondary d-grid gap-3" data-tm-admin-surface-group>';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $html .= '<input type="hidden" name="type_key" value="links">';
        if ( $id !== '' ) {
            $html .= '<input type="hidden" name="id" value="' . $escape( $id ) . '">';
        }
        if ( ! $is_superadmin ) {
            $html .= '<input type="hidden" name="scope" value="author"><input type="hidden" name="author_slug" value="' . $escape( $current_username() ) . '">';
        }
        $html .= '<div class="small text-uppercase text-body-secondary">' . $escape( $title ) . '</div><div class="row g-3 align-items-end">';
        $html .= '<div class="col-12 col-lg-4"><label class="form-label small mb-1" for="' . $escape( $form_id . '-title' ) . '">Title</label><input class="form-control" id="' . $escape( $form_id . '-title' ) . '" name="title" type="text" value="' . $escape( (string) ( $directory['title'] ?? '' ) ) . '" required></div>';
        $html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="' . $escape( $form_id . '-alias' ) . '">Alias</label><input class="form-control font-monospace" id="' . $escape( $form_id . '-alias' ) . '" name="alias" type="text" placeholder="auto" value="' . $escape( (string) ( $directory['alias'] ?? '' ) ) . '"></div>';
        if ( $is_superadmin ) {
            $html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="' . $escape( $form_id . '-scope' ) . '">Scope</label>' . $render_select( 'scope', $scope, [ 'root' => 'Root', 'author' => 'Author' ], $form_id . '-scope' ) . '</div>';
            $html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="' . $escape( $form_id . '-author' ) . '">Author slug</label><input class="form-control font-monospace" id="' . $escape( $form_id . '-author' ) . '" name="author_slug" type="text" value="' . $escape( $author_slug ) . '"></div>';
        }
        $html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="' . $escape( $form_id . '-view' ) . '">Default view</label>' . $render_select( 'default_view', (string) ( $directory['default_view'] ?? 'list' ), $view_options, $form_id . '-view' ) . '</div>';
        $html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="' . $escape( $form_id . '-order' ) . '">Order</label><input class="form-control" id="' . $escape( $form_id . '-order' ) . '" name="sort_order" type="number" min="0" value="' . (int) ( $directory['sort_order'] ?? 0 ) . '"></div>';
        $html .= '<div class="col-12 col-lg-2"><div class="form-check mb-2"><input class="form-check-input" id="tm-directory-enabled-' . $escape( $id ?: 'new' ) . '" name="enabled" type="checkbox" value="1"' . ( ! empty( $directory['enabled'] ) || $id === '' ? ' checked' : '' ) . '><label class="form-check-label" for="tm-directory-enabled-' . $escape( $id ?: 'new' ) . '">Enabled</label></div></div>';
        $html .= '<div class="col-12"><label class="form-label small mb-1" for="' . $escape( $form_id . '-intro' ) . '">Intro Markdown</label><textarea class="form-control" id="' . $escape( $form_id . '-intro' ) . '" name="intro_markdown" rows="2">' . $escape( (string) ( $directory['intro_markdown'] ?? '' ) ) . '</textarea></div>';
        $html .= '<div class="col-12"><button class="btn btn-primary" type="submit">' . $escape( $button_label ) . '</button></div>';
        $html .= '</div></form>';
        return( $html );
    };

    $render_item_form = static function( array $directory, array $item, array $items, string $title, string $button_label ) use ( $item_save_url, $security, $escape, $render_select, $render_field_input, $media_owner_usernames, $category_suggestions_for_items ) : string {
        $type = is_array( $directory['type'] ?? null ) ? $directory['type'] : [];
        $item_id = (string) ( $item['id'] ?? '' );
        $parent_options = [ '' => 'Top level' ];
        foreach ( $items as $candidate ) {
            $candidate_id = (string) ( $candidate['id'] ?? '' );
            if ( $candidate_id === '' || $candidate_id === $item_id ) {
                continue;
            }
            $parent_options[$candidate_id] = (string) ( $candidate['title'] ?? $candidate_id );
        }
        $prefix = 'item_' . ( $item_id !== '' ? $item_id : 'new_' . (string) ( $directory['id'] ?? '' ) );
        $form_id = 'tm-directory-' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $prefix );
        $field_context = [
            'media_owner_usernames' => $media_owner_usernames( $directory ),
            'category_suggestions' => $category_suggestions_for_items( $items ),
            'directory_id' => (string) ( $directory['id'] ?? '' ),
            'type_key' => (string) ( $type['key'] ?? '' ),
        ];
        $html = '<form method="post" action="' . $escape( $item_save_url ) . '" class="rounded-3 p-3 bg-body-secondary d-grid gap-3" data-tm-links-item-form data-tm-admin-surface-group>';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $html .= '<input type="hidden" name="directory_id" value="' . $escape( (string) ( $directory['id'] ?? '' ) ) . '">';
        if ( $item_id !== '' ) {
            $html .= '<input type="hidden" name="id" value="' . $escape( $item_id ) . '">';
        }
        $html .= '<input type="hidden" name="slug" value="' . $escape( (string) ( $item['slug'] ?? '' ) ) . '">';
        $html .= '<div class="small text-uppercase text-body-secondary">' . $escape( $title ) . '</div><div class="row g-3 align-items-start">';
        $html .= '<div class="col-12 col-lg-7"><label class="form-label small mb-1" for="' . $escape( $form_id . '-title' ) . '">Title</label><input class="form-control" id="' . $escape( $form_id . '-title' ) . '" name="title" type="text" value="' . $escape( (string) ( $item['title'] ?? '' ) ) . '" required><div class="form-text">Plain text; use Description for Markdown.</div></div>';
        $html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="' . $escape( $form_id . '-parent' ) . '">Parent</label>' . $render_select( 'parent_id', (string) ( $item['parent_id'] ?? '' ), $parent_options, $form_id . '-parent' ) . '</div>';
        $html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1" for="' . $escape( $form_id . '-order' ) . '">Order</label><input class="form-control" id="' . $escape( $form_id . '-order' ) . '" name="sort_order" type="number" min="0" value="' . (int) ( $item['sort_order'] ?? 0 ) . '"></div>';
        $html .= '<div class="col-12"><div class="form-check mb-0"><input class="form-check-input" id="tm-directory-item-enabled-' . $escape( $prefix ) . '" name="enabled" type="checkbox" value="1"' . ( ! empty( $item['enabled'] ) || $item_id === '' ? ' checked' : '' ) . '><label class="form-check-label" for="tm-directory-item-enabled-' . $escape( $prefix ) . '">Enabled</label></div></div>';
        $fields = array_values( array_filter( (array) ( $type['fields'] ?? [] ), static fn( mixed $field ) : bool => is_array( $field ) ) );
        $type_key = (string) ( $type['key'] ?? '' );
        $preferred_field_order = $type_key === 'links' ? [ 'url', 'category', 'description', 'icon_media_id' ] : [];
        if ( ! empty( $preferred_field_order ) ) {
            $fields_by_key = [];
            foreach ( $fields as $field ) {
                $field_key = (string) ( $field['key'] ?? '' );
                if ( $field_key !== '' && ! isset( $fields_by_key[$field_key] ) ) {
                    $fields_by_key[$field_key] = $field;
                }
            }
            $ordered_fields = [];
            foreach ( $preferred_field_order as $field_key ) {
                if ( isset( $fields_by_key[$field_key] ) ) {
                    $ordered_fields[] = $fields_by_key[$field_key];
                    unset( $fields_by_key[$field_key] );
                }
            }
            if ( ! empty( $ordered_fields ) ) {
                $fields = [ ...$ordered_fields, ...array_values( $fields_by_key ) ];
            }
        }
        foreach ( $fields as $field ) {
            $html .= $render_field_input( is_array( $field ) ? $field : [], is_array( $item['fields'] ?? null ) ? $item['fields'] : [], $prefix, $field_context );
        }
        $html .= '<div class="col-12"><button class="btn btn-primary btn-sm" type="submit">' . $escape( $button_label ) . '</button></div>';
        $html .= '</div></form>';
        return( $html );
    };

    $build_admin_url = static function( array $query = [] ) use ( $plugin_url ) : string {
        $clean = [];
        foreach ( $query as $key => $value ) {
            $value = trim( (string) $value );
            if ( $value !== '' ) {
                $clean[(string) $key] = $value;
            }
        }
        if ( empty( $clean ) ) {
            return( $plugin_url );
        }
        return( $plugin_url . '?' . http_build_query( $clean, '', '&', PHP_QUERY_RFC3986 ) );
    };

    $summarize_item = static function( array $directory, array $item ) : string {
        $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : [];
        $type = is_array( $directory['type'] ?? null ) ? $directory['type'] : [];
        $labels = [];
        foreach ( (array) ( $type['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key = (string) ( $field['key'] ?? '' );
            $field_type = (string) ( $field['type'] ?? 'text' );
            if ( $key === '' || $field_type === 'media' ) {
                continue;
            }
            $value = trim( preg_replace( '/\s+/', ' ', strip_tags( (string) ( $fields[$key] ?? '' ) ) ) );
            if ( $value === '' ) {
                continue;
            }
            if ( mb_strlen( $value ) > 72 ) {
                $value = mb_substr( $value, 0, 69 ) . '...';
            }
            $labels[] = (string) ( $field['label'] ?? $key ) . ': ' . $value;
            if ( count( $labels ) >= 2 ) {
                break;
            }
        }
        if ( ! empty( $labels ) ) {
            return( implode( ' / ', $labels ) );
        }
        $parent_id = trim( (string) ( $item['parent_id'] ?? '' ) );
        return( $parent_id !== '' ? 'Child item' : 'Top-level item' );
    };

    $render_admin_page = static function() use ( $plugins, $security, $directory_service, $plugin_url, $item_duplicate_url, $item_delete_url, $media_picker_url, $media_upload_url, $escape, $render_directory_form, $render_item_form, $current_username, $build_admin_url, $summarize_item, $directory_media_upload_settings ) : void {
        $is_superadmin = $security->isSuperAdmin();
        $directories = $directory_service->listAccessibleDirectories( $is_superadmin, $current_username() );
        $notice = $security->pullFlash( 'directory.notice' );
        $query = $plugins->getRequestQueryData();
        $requested_directory = trim( (string) ( $query['directory'] ?? '' ) );
        $active_tab = trim( (string) ( $query['tab'] ?? 'items' ) );
        $allowed_tabs = [ 'items', 'settings', 'display' ];
        if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
            $active_tab = 'items';
        }
        $new_directory_requested = $requested_directory === 'new';
        $selected_directory = null;
        if ( ! $new_directory_requested ) {
            foreach ( $directories as $directory ) {
                $directory_id = (string) ( $directory['id'] ?? '' );
                $directory_alias = (string) ( $directory['alias'] ?? '' );
                if ( $requested_directory !== '' && ( $directory_id === $requested_directory || $directory_alias === $requested_directory ) ) {
                    $selected_directory = $directory;
                    break;
                }
            }
            if ( $selected_directory === null && $requested_directory === '' && ! empty( $directories ) ) {
                $selected_directory = $directories[0];
            }
        }
        if ( $new_directory_requested ) {
            $active_tab = 'settings';
        }

        $body_html = '<div class="d-grid gap-4">';
        if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
            $body_html .= '<div class="alert alert-' . $escape( (string) ( $notice['type'] ?? 'info' ) ) . '">' . $escape( (string) $notice['message'] ) . '</div>';
        }

        $body_html .= '<section class="row g-4 align-items-start">';
        $body_html .= '<aside class="col-12 col-lg-3"><div class="d-grid gap-3">';
        $body_html .= '<div class="d-flex align-items-center justify-content-between gap-2"><div class="small text-uppercase text-body-secondary">Link collections</div><a class="btn btn-primary btn-sm" href="' . $escape( $build_admin_url( [ 'directory' => 'new', 'tab' => 'settings' ] ) ) . '"><span class="bi bi-plus-lg me-1" aria-hidden="true"></span>New</a></div>';
        if ( empty( $directories ) ) {
            $body_html .= '<div class="text-body-secondary small">No link collections yet.</div>';
        } else {
            $collection_navigation_html = '';
            foreach ( $directories as $directory ) {
                $directory_id = (string) ( $directory['id'] ?? '' );
                $items = $directory_service->listItems( $directory_id );
                $is_active = is_array( $selected_directory ) && (string) ( $selected_directory['id'] ?? '' ) === $directory_id;
                $collection_navigation_html .= '<a class="text-decoration-none py-1 ' . ( $is_active ? 'border-start border-primary border-3 ps-2 fw-semibold' : 'ps-3' ) . '" href="' . $escape( $build_admin_url( [ 'directory' => $directory_id, 'tab' => 'items' ] ) ) . '"' . ( $is_active ? ' aria-current="page"' : '' ) . '><span class="d-flex justify-content-between align-items-start gap-2"><span class="text-break">' . $escape( (string) ( $directory['title'] ?? 'Untitled links' ) ) . '</span><span class="small text-body-secondary">' . (int) count( $items ) . '</span></span></a>';
            }
            $body_html .= '<nav class="d-none d-lg-grid gap-2" aria-label="Link collections" data-tm-links-navigation-desktop>' . $collection_navigation_html . '</nav><details class="d-lg-none" data-tm-links-navigation-mobile><summary class="fw-semibold py-2">Browse link collections</summary><nav class="d-grid gap-2 pt-2" aria-label="Link collections">' . $collection_navigation_html . '</nav></details>';
        }
        $body_html .= '</div></aside>';

        $body_html .= '<div class="col-12 col-lg-9" data-tm-links-current-workspace>';
        if ( $new_directory_requested || $selected_directory === null ) {
            $body_html .= '<section class="d-grid gap-3">';
            $body_html .= '<div><div class="small text-uppercase text-body-secondary mb-1">New links</div><h2 class="h4 mb-1">Create a link collection</h2><div class="small text-body-secondary">Add a title, optional intro, and then fill it with categorized links.</div></div>';
            $body_html .= $render_directory_form( [], 'Links details', 'Create links' );
            $body_html .= '</section>';
        } else {
            $selected_id = (string) ( $selected_directory['id'] ?? '' );
            $selected_alias = (string) ( $selected_directory['alias'] ?? '' );
            $selected_type = is_array( $selected_directory['type'] ?? null ) ? $selected_directory['type'] : [];
            $selected_items = $directory_service->listItems( $selected_id );
            $item_label = mb_trim( (string) ( $selected_type['item_label'] ?? 'item' ) ) ?: 'item';
            $item_label_action = mb_strtolower( mb_substr( $item_label, 0, 1 ) ) . mb_substr( $item_label, 1 );
            $edit_item_id = trim( (string) ( $query['edit_item'] ?? '' ) );
            $new_item_requested = (string) ( $query['new_item'] ?? '' ) === '1';
            $edit_item = null;
            if ( $edit_item_id !== '' ) {
                foreach ( $selected_items as $item ) {
                    if ( (string) ( $item['id'] ?? '' ) === $edit_item_id ) {
                        $edit_item = $item;
                        break;
                    }
                }
            }

            $body_html .= '<section class="d-grid gap-4">';
            $body_html .= '<div class="d-flex flex-wrap align-items-start justify-content-between gap-3"><div><div class="small text-uppercase text-body-secondary mb-1">Links</div><h2 class="h4 mb-1">' . $escape( (string) ( $selected_directory['title'] ?? 'Untitled links' ) ) . '</h2><div class="small text-body-secondary"><code>[links id="' . $escape( $selected_alias ) . '"]</code></div></div><a class="btn btn-primary btn-sm" href="' . $escape( $build_admin_url( [ 'directory' => $selected_id, 'tab' => 'items', 'new_item' => '1' ] ) ) . '"><span class="bi bi-plus-lg me-1" aria-hidden="true"></span>Add ' . $escape( $item_label_action ) . '</a></div>';
            $body_html .= '<nav class="nav nav-tabs" aria-label="Links sections">';
            foreach ( [ 'items' => 'Items', 'settings' => 'Settings', 'display' => 'Display' ] as $tab => $label ) {
                $body_html .= '<a class="nav-link' . ( $active_tab === $tab ? ' active' : '' ) . '" href="' . $escape( $build_admin_url( [ 'directory' => $selected_id, 'tab' => $tab ] ) ) . '">' . $escape( $label ) . '</a>';
            }
            $body_html .= '</nav>';

            if ( $active_tab === 'settings' ) {
                $body_html .= $render_directory_form( $selected_directory, 'Links settings', 'Save links' );
            } elseif ( $active_tab === 'display' ) {
                $shortcode = '[links id="' . $selected_alias . '"]';
                $body_html .= '<div class="d-grid gap-3"><div><div class="small text-uppercase text-body-secondary mb-1">Shortcode</div><div class="input-group"><input class="form-control font-monospace" readonly value="' . $escape( $shortcode ) . '"><a class="btn btn-outline-primary" href="' . $escape( $build_admin_url( [ 'directory' => $selected_id, 'tab' => 'items' ] ) ) . '">Manage items</a></div></div>';
                $body_html .= '<div class="row g-4"><div class="col-12 col-lg-6"><section><div class="fw-semibold mb-1">Views</div><div class="small text-body-secondary d-grid gap-1"><code>[links id="' . $escape( $selected_alias ) . '" view="list"]</code><code>[links id="' . $escape( $selected_alias ) . '" view="boxes"]</code><code>[links id="' . $escape( $selected_alias ) . '" view="cards"]</code><code>[links id="' . $escape( $selected_alias ) . '" view="tree"]</code></div></section></div><div class="col-12 col-lg-6"><section><div class="fw-semibold mb-1">Options</div><div class="small text-body-secondary d-grid gap-1"><code>expanded="true"</code><code>depth="2"</code><code>wide="true"</code><code>sidebar="false"</code><span>Default view: ' . $escape( ucfirst( (string) ( $selected_directory['default_view'] ?? 'list' ) ) ) . '</span></div></section></div></div></div>';
            } else {
                $body_html .= '<div class="d-grid gap-4">';
                $show_item_form = is_array( $edit_item ) || $new_item_requested || empty( $selected_items );
                if ( $show_item_form ) {
                    $body_html .= $render_item_form( $selected_directory, is_array( $edit_item ) ? $edit_item : [], $selected_items, ( is_array( $edit_item ) ? 'Edit ' : 'Add ' ) . $item_label_action, is_array( $edit_item ) ? 'Save ' . $item_label_action : 'Add ' . $item_label_action );
                }
                if ( ( is_array( $edit_item ) || $new_item_requested ) && ! empty( $selected_items ) ) {
                    $body_html .= '<div><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $build_admin_url( [ 'directory' => $selected_id, 'tab' => 'items' ] ) ) . '">' . ( is_array( $edit_item ) ? 'Cancel editing' : 'Cancel adding' ) . '</a></div>';
                }
                if ( empty( $selected_items ) ) {
                    $body_html .= '<p class="text-body-secondary mb-0">No links have been added to this collection.</p>';
                } else {
                    $body_html .= '<div class="table-responsive d-none d-lg-block"><table class="table table-hover align-middle mb-0"><thead><tr><th scope="col">Item</th><th scope="col">Summary</th><th scope="col">Order</th><th scope="col">Status</th><th class="text-end" scope="col">Actions</th></tr></thead><tbody>';
                    foreach ( $selected_items as $item ) {
                        $item_id = (string) ( $item['id'] ?? '' );
                        $edit_url = $build_admin_url( [ 'directory' => $selected_id, 'tab' => 'items', 'edit_item' => $item_id ] );
                        $body_html .= '<tr' . ( $edit_item_id === $item_id ? ' class="table-active"' : '' ) . '><td><div class="fw-semibold">' . $escape( (string) ( $item['title'] ?? 'Untitled item' ) ) . '</div></td><td class="small text-body-secondary">' . $escape( $summarize_item( $selected_directory, $item ) ) . '</td><td>' . (int) ( $item['sort_order'] ?? 0 ) . '</td><td><span class="' . ( ! empty( $item['enabled'] ) ? 'text-success-emphasis' : 'text-body-secondary' ) . '">' . ( ! empty( $item['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</span></td><td><div class="d-flex flex-wrap gap-1 justify-content-end"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $edit_url ) . '" title="Edit ' . $escape( $item_label_action ) . '"><span class="bi bi-pencil" aria-hidden="true"></span></a><form method="post" action="' . $escape( $item_duplicate_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="directory_id" value="' . $escape( $selected_id ) . '"><input type="hidden" name="id" value="' . $escape( $item_id ) . '"><button class="btn btn-outline-secondary btn-sm" type="submit" title="Duplicate ' . $escape( $item_label_action ) . '"><span class="bi bi-files" aria-hidden="true"></span></button></form><form method="post" action="' . $escape( $item_delete_url ) . '" onsubmit="return confirm(\'Delete this item and any child items?\');"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="directory_id" value="' . $escape( $selected_id ) . '"><input type="hidden" name="id" value="' . $escape( $item_id ) . '"><button class="btn btn-outline-danger btn-sm" type="submit" title="Delete ' . $escape( $item_label_action ) . '"><span class="bi bi-trash" aria-hidden="true"></span></button></form></div></td></tr>';
                    }
                    $body_html .= '</tbody></table></div><div class="d-grid d-lg-none">';
                    foreach ( $selected_items as $item ) {
                        $item_id = (string) ( $item['id'] ?? '' );
                        $edit_url = $build_admin_url( [ 'directory' => $selected_id, 'tab' => 'items', 'edit_item' => $item_id ] );
                        $body_html .= '<article class="d-grid gap-2 py-3 border-top"><div class="d-flex align-items-start justify-content-between gap-2"><div><div class="fw-semibold">' . $escape( (string) ( $item['title'] ?? 'Untitled item' ) ) . '</div><div class="small text-body-secondary">' . $escape( $summarize_item( $selected_directory, $item ) ) . '</div></div><span class="' . ( ! empty( $item['enabled'] ) ? 'text-success-emphasis' : 'text-body-secondary' ) . '">' . ( ! empty( $item['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</span></div><div class="d-flex flex-wrap gap-1"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $edit_url ) . '"><span class="bi bi-pencil me-1" aria-hidden="true"></span>Edit</a><form method="post" action="' . $escape( $item_duplicate_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="directory_id" value="' . $escape( $selected_id ) . '"><input type="hidden" name="id" value="' . $escape( $item_id ) . '"><button class="btn btn-outline-secondary btn-sm" type="submit"><span class="bi bi-files me-1" aria-hidden="true"></span>Duplicate</button></form><form method="post" action="' . $escape( $item_delete_url ) . '" onsubmit="return confirm(\'Delete this item and any child items?\');"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="directory_id" value="' . $escape( $selected_id ) . '"><input type="hidden" name="id" value="' . $escape( $item_id ) . '"><button class="btn btn-outline-danger btn-sm" type="submit"><span class="bi bi-trash me-1" aria-hidden="true"></span>Delete</button></form></div></article>';
                    }
                    $body_html .= '</div>';
                }
                $body_html .= '</div>';
            }
            $body_html .= '</section>';
        }
        $body_html .= '</div></section>';
        $media_upload_settings = $directory_media_upload_settings();
        $upload_disabled = empty( $media_upload_settings['enabled'] ) ? ' disabled data-disabled-by-policy="1"' : '';
        $body_html .= '<div class="modal fade" id="tm-directory-media-picker-modal" tabindex="-1" aria-labelledby="tm-directory-media-picker-title" aria-hidden="true" data-tm-admin-image-picker data-context-name="directory_id" data-picker-url="' . $escape( $media_picker_url ) . '" data-upload-url="' . $escape( $media_upload_url ) . '" data-upload-field-name="image" data-csrf-token="' . $escape( $security->getCsrfToken() ) . '"><div class="modal-dialog modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><div><div class="small text-uppercase text-body-secondary mb-1">Links media</div><h2 class="modal-title fs-5" id="tm-directory-media-picker-title">Choose image</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body d-grid gap-3">';
        $body_html .= '<div class="border rounded p-3 d-grid gap-2 bg-body-tertiary"><label class="form-label small mb-0" for="tm-directory-media-upload">Upload image</label><div class="input-group"><input class="form-control" id="tm-directory-media-upload" type="file" accept="image/png,image/jpeg,image/webp,image/gif" data-tm-admin-image-upload' . $upload_disabled . '><button class="btn btn-outline-primary" type="button" data-tm-admin-image-upload-button' . $upload_disabled . '><span class="bi bi-upload me-1" aria-hidden="true"></span>Upload</button></div><div class="small text-body-secondary" data-tm-admin-image-upload-status>' . $escape( (string) ( $media_upload_settings['message'] ?? '' ) ) . '</div></div>';
        $body_html .= '<div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-secondary btn-sm active" type="button" data-tm-admin-image-source="recent">Suggested</button><button class="btn btn-outline-secondary btn-sm" type="button" data-tm-admin-image-source="library">Library</button></div><input class="form-control" type="search" placeholder="Search filename, label, or media ID" data-tm-admin-image-filter><div class="row g-2" data-tm-admin-image-grid><div class="col-12 small text-body-secondary">Choose image to load media.</div></div></div></div></div></div>';
        $body_html .= '<script src="/js/tinymash-admin-image-picker.js"></script>';
        $body_html .= '</div>';

        $plugins->renderAdminPage(
            [
                'title' => 'Links',
                'active_menu' => 'directory',
                'plugin_page_title' => 'Links',
                'plugin_page_summary' => 'Create categorized link collections and render them through shortcodes.',
                'plugin_page_body_html' => $body_html,
                'help_contexts' => [ 'plugin-directory' ],
            ]
        );
    };

    $handle_exception = static function( \Throwable $e, string $fallback ) use ( $security ) : void {
        $messages = [
            'permission' => 'You do not have permission to manage that Links record.',
            'type' => 'Links only supports link collections.',
            'label' => 'Enter a type label.',
            'key' => 'Enter a usable type key.',
            'key_conflict' => 'Another Links type already uses that key.',
            'title' => 'Enter a title.',
            'alias' => 'Enter a usable alias.',
            'alias_conflict' => 'Another link collection already uses that alias.',
            'author' => 'Choose a valid author.',
            'directory' => 'Choose a link collection you can manage.',
            'slug' => 'Enter a usable item slug.',
            'slug_conflict' => 'Another item already uses that slug under the same parent.',
            'parent' => 'Choose a valid parent item.',
            'item' => 'Choose an item that still exists.',
            'field_url' => 'Enter a valid URL.',
        ];
        $message = $e instanceof \InvalidArgumentException ? ( $messages[$e->getMessage()] ?? 'The Links record could not be saved.' ) : $fallback;
        if ( ! $e instanceof \InvalidArgumentException ) {
            error_log( 'directory plugin: ' . $fallback . ' (' . $e->getMessage() . ')' );
        }
        $security->setFlash( 'directory.notice', [ 'type' => 'danger', 'message' => $message ] );
    };

    $render_admin_route = static function() use ( $require_login, $render_admin_page ) : void {
        if ( ! $require_login() ) {
            return;
        }
        $render_admin_page();
    };
    $plugins->registerRoute( $plugin_key, 'get', $plugin_url, $render_admin_route );
    $plugins->registerRoute(
        $plugin_key,
        'get',
        $legacy_plugin_url,
        static function() use ( $plugins, $build_admin_url ) : void {
            $plugins->redirect( $build_admin_url( $plugins->getRequestQueryData() ) );
        }
    );

    $media_picker_route = static function() use ( $plugins, $security, $directory_service, $current_username, $media_owner_usernames, $emit_json ) : void {
            if ( ! $security->isLoggedIn() ) {
                $emit_json( [ 'error' => 'Authentication required.' ], 403 );
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $emit_json( [ 'error' => 'Invalid CSRF token.' ], 403 );
                return;
            }
            $directory = $directory_service->getDirectoryByIdOrAlias( (string) ( $data['directory_id'] ?? '' ) );
            if ( empty( $directory ) || ! $directory_service->canManageDirectory( $directory, $security->isSuperAdmin(), $current_username() ) ) {
                $emit_json( [ 'error' => 'Choose a link collection you can manage.' ], 403 );
                return;
            }
            $source = strtolower( trim( (string) ( $data['source'] ?? 'recent' ) ) ) === 'library' ? 'library' : 'recent';
            $preferred_media_ids = $source === 'recent' ? $directory_service->listDirectoryMediaFieldIds( (string) ( $directory['id'] ?? '' ) ) : [];
            $emit_json(
                [
                    'source' => $source,
                    'records' => $directory_service->listSelectableImages( $media_owner_usernames( $directory ), $source === 'library' ? 500 : 48, $preferred_media_ids ),
                ]
            );
    };
    foreach ( [ $media_picker_url, $legacy_media_picker_url ] as $route_url ) {
        $plugins->registerRoute( $plugin_key, 'post', $route_url, $media_picker_route );
    }

    $media_upload_route = static function() use ( $plugins, $security, $directory_service, $media_service, $current_username, $media_owner_usernames, $directory_media_upload_settings, $emit_json ) : void {
            if ( ! $security->isLoggedIn() ) {
                $emit_json( [ 'error' => 'Authentication required.' ], 403 );
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $emit_json( [ 'error' => 'Invalid CSRF token.' ], 403 );
                return;
            }

            $settings = $directory_media_upload_settings();
            if ( empty( $settings['enabled'] ) ) {
                $emit_json( [ 'error' => (string) ( $settings['message'] ?? 'Image uploads are disabled for this site.' ) ], 403 );
                return;
            }
            if ( ! $media_service instanceof TinyMashMediaService ) {
                $emit_json( [ 'error' => 'Media service is not available.' ], 500 );
                return;
            }

            $directory = $directory_service->getDirectoryByIdOrAlias( (string) ( $data['directory_id'] ?? '' ) );
            if ( empty( $directory ) || ! $directory_service->canManageDirectory( $directory, $security->isSuperAdmin(), $current_username() ) ) {
                $emit_json( [ 'error' => 'Choose a link collection you can manage.' ], 403 );
                return;
            }

            try {
                if ( empty( $_FILES['image'] ) || ! is_array( $_FILES['image'] ) ) {
                    throw new \InvalidArgumentException( 'content_image_file' );
                }

                $owner_usernames = $media_owner_usernames( $directory );
                $owner_username = (string) ( $owner_usernames[0] ?? '' );
                if ( $owner_username === '' ) {
                    throw new \InvalidArgumentException( 'content_image_owner' );
                }

                $uploaded_image = $media_service->storeUploadedImage(
                    $_FILES['image'],
                    $owner_username,
                    [
                        'allowed_mimes' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ],
                    ]
                );
                $record = $directory_service->buildSelectableImageRecord( $uploaded_image );
                if ( empty( $record ) ) {
                    throw new \RuntimeException( 'Uploaded media did not produce a selectable image record.' );
                }

                $emit_json( [ 'uploaded' => true, 'record' => $record ] );
            } catch ( \InvalidArgumentException $e ) {
                $messages = [
                    'content_image_file' => 'Choose an image file to upload.',
                    'content_image_upload' => 'That image upload did not complete successfully.',
                    'content_image_owner' => 'That image upload could not be assigned to this link collection.',
                    'content_image_too_large' => 'That image exceeds the allowed maximum upload size for this site.',
                    'content_image_type' => 'Choose a JPEG, PNG, WebP, or GIF image.',
                ];
                $emit_json( [ 'error' => $messages[$e->getMessage()] ?? 'Image upload could not be completed.' ], 422 );
            } catch ( \Throwable $e ) {
                error_log( 'directory plugin: image upload failed (' . $e->getMessage() . ')' );
                $emit_json( [ 'error' => 'Image upload could not be completed right now.' ], 500 );
            }
    };
    foreach ( [ $media_upload_url, $legacy_media_upload_url ] as $route_url ) {
        $plugins->registerRoute( $plugin_key, 'post', $route_url, $media_upload_route );
    }

    $directory_save_route = static function() use ( $plugins, $security, $directory_service, $plugin_url, $require_login, $current_username, $handle_exception, $build_admin_url ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'directory.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            $redirect_query = [ 'directory' => (string) ( $data['id'] ?? '' ), 'tab' => 'settings' ];
            try {
                $was_new = trim( (string) ( $data['id'] ?? '' ) ) === '';
                $saved_directory = $directory_service->saveDirectory( $data, $security->isSuperAdmin(), $current_username() );
                $redirect_query = [
                    'directory' => (string) ( $saved_directory['id'] ?? $data['id'] ?? '' ),
                    'tab' => $was_new ? 'items' : 'settings',
                ];
                $security->setFlash( 'directory.notice', [ 'type' => 'success', 'message' => 'Links saved.' ] );
            } catch ( \Throwable $e ) {
                $redirect_query = [
                    'directory' => trim( (string) ( $data['id'] ?? '' ) ) !== '' ? (string) $data['id'] : 'new',
                    'tab' => 'settings',
                ];
                $handle_exception( $e, 'Links save failed.' );
            }
            $plugins->redirect( $build_admin_url( $redirect_query ) );
    };
    foreach ( [ $directory_save_url, $legacy_directory_save_url ] as $route_url ) {
        $plugins->registerRoute( $plugin_key, 'post', $route_url, $directory_save_route );
    }

    $item_save_route = static function() use ( $plugins, $security, $directory_service, $plugin_url, $require_login, $current_username, $handle_exception, $build_admin_url ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'directory.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            $redirect_query = [ 'directory' => (string) ( $data['directory_id'] ?? '' ), 'tab' => 'items' ];
            try {
                $directory_service->saveItem( $data, $security->isSuperAdmin(), $current_username() );
                $security->setFlash( 'directory.notice', [ 'type' => 'success', 'message' => 'Link saved.' ] );
            } catch ( \Throwable $e ) {
                if ( trim( (string) ( $data['id'] ?? '' ) ) !== '' ) {
                    $redirect_query['edit_item'] = (string) $data['id'];
                } else {
                    $redirect_query['new_item'] = '1';
                }
                $handle_exception( $e, 'Item save failed.' );
            }
            $plugins->redirect( $build_admin_url( $redirect_query ) );
    };
    foreach ( [ $item_save_url, $legacy_item_save_url ] as $route_url ) {
        $plugins->registerRoute( $plugin_key, 'post', $route_url, $item_save_route );
    }

    $item_duplicate_route = static function() use ( $plugins, $security, $directory_service, $plugin_url, $require_login, $current_username, $handle_exception, $build_admin_url ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'directory.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            $redirect_query = [ 'directory' => (string) ( $data['directory_id'] ?? '' ), 'tab' => 'items' ];
            try {
                $copy = $directory_service->duplicateItem( (string) ( $data['directory_id'] ?? '' ), (string) ( $data['id'] ?? '' ), $security->isSuperAdmin(), $current_username() );
                $redirect_query['edit_item'] = (string) ( $copy['id'] ?? '' );
                $directory = $directory_service->getDirectoryByIdOrAlias( (string) ( $data['directory_id'] ?? '' ) );
                $type = is_array( $directory['type'] ?? null ) ? $directory['type'] : [];
                $item_label = mb_trim( (string) ( $type['item_label'] ?? 'Item' ) ) ?: 'Item';
                $security->setFlash( 'directory.notice', [ 'type' => 'success', 'message' => $item_label . ' duplicated.' ] );
            } catch ( \Throwable $e ) {
                $handle_exception( $e, 'Item duplicate failed.' );
            }
            $plugins->redirect( $build_admin_url( $redirect_query ) );
    };
    foreach ( [ $item_duplicate_url, $legacy_item_duplicate_url ] as $route_url ) {
        $plugins->registerRoute( $plugin_key, 'post', $route_url, $item_duplicate_route );
    }

    $item_delete_route = static function() use ( $plugins, $security, $directory_service, $plugin_url, $require_login, $current_username, $build_admin_url ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'directory.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            $deleted = $directory_service->deleteItem( (string) ( $data['directory_id'] ?? '' ), (string) ( $data['id'] ?? '' ), $security->isSuperAdmin(), $current_username() );
            $security->setFlash(
                'directory.notice',
                $deleted
                    ? [ 'type' => 'success', 'message' => 'Link deleted.' ]
                : [ 'type' => 'warning', 'message' => 'That item could not be found.' ]
            );
            $plugins->redirect( $build_admin_url( [ 'directory' => (string) ( $data['directory_id'] ?? '' ), 'tab' => 'items' ] ) );
    };
    foreach ( [ $item_delete_url, $legacy_item_delete_url ] as $route_url ) {
        $plugins->registerRoute( $plugin_key, 'post', $route_url, $item_delete_route );
    }
};
