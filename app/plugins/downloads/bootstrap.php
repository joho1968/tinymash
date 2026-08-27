<?php

require_once __DIR__ . '/TinyMashDownloadsService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'downloads' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $user_repository = $plugins->getService( 'user.repository' );
    $date_formatter = $plugins->getService( 'date.formatter' );
    $theme = $plugins->getService( 'theme' );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $user_repository instanceof TinyMashUserRepository || ! $date_formatter instanceof TinyMashDateFormatter || ! $theme instanceof TinyMashTheme ) {
        throw new \RuntimeException( 'Required services for the Downloads plugin are not available.' );
    }

    $downloads_service = new TinyMashDownloadsService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'downloads' . DIRECTORY_SEPARATOR . 'downloads.json',
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'downloads' . DIRECTORY_SEPARATOR . 'files',
        $user_repository,
        $config->getDefaultLanguage()
    );
    \Flight::set( 'downloads.service', $downloads_service );

    $plugins->registerSystemSettingsSection(
        $plugin_key,
        [
            'title' => 'Downloads',
            'summary' => 'Choose whether visitors can browse the root Downloads index.',
            'fields' => [
                [
                    'key' => 'root_index_browseable',
                    'type' => 'checkbox',
                    'label' => 'Allow browsing the root Downloads index',
                    'help_html' => 'When disabled, bare <code>/downloads</code> returns 404 while direct folder, file, and shortcode-contained Downloads links keep working.',
                    'default' => true,
                ],
            ],
        ]
    );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $downloads_admin_url = $admin_url . '/downloads';
    $catalogue_save_url = $downloads_admin_url . '/catalogue/save';
    $file_upload_url = $downloads_admin_url . '/file/upload';
    $file_save_url = $downloads_admin_url . '/file/save';
    $file_save_json_url = $downloads_admin_url . '/file/save-json';
    $file_delete_url = $downloads_admin_url . '/file/delete';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'author',
        [
            'section' => 'downloads',
            'label' => 'Downloads',
            'url' => $downloads_admin_url,
            'icon' => 'bi-download',
            'order' => 72,
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-downloads',
        [
            'title' => 'Downloads',
            'summary' => 'Create managed download folders and routed file downloads.',
            'documentation_url' => 'https://tinymash.joho.se/docs/plugins/downloads',
            'documentation_label' => 'Downloads',
            'group' => 'Plugins',
            'order' => 135,
            'roles' => [ 'creator', 'admin' ],
            'sections' => [
                [
                    'title' => 'Overview',
                    'markdown' => 'Create public folder pages and routed file downloads. Root folders use `/downloads`; author folders use `/<author>/downloads`.',
                ],
                [
                    'title' => 'Scopes',
                    'markdown' => 'Author folders stay in their author space and do not automatically appear in the root downloads area. Admins can manage root and author folders; authors manage their own author folders.',
                ],
                [
                    'title' => 'Files',
                    'markdown' => 'Files stay outside public asset paths and are served through routed URLs. Upload one or more files into a folder; download counts and recent activity remain visible only in management.',
                ],
                [
                    'title' => 'Linking from content',
                    'markdown' => 'The editor internal-link picker includes enabled download folders and files. Inserted links are normal Markdown links to routed Downloads URLs. To embed a contained folder listing in content, use `[[downloads path="/downloads/folder-slug"]]` or `[[downloads path="/author/downloads/folder-slug"]]`.',
                ],
                [
                    'title' => 'Browsing',
                    'markdown' => 'Public folder pages show `..` when visitors can move up within the current browsing root. A shortcode keeps browsing inside its starting folder. Disabling the bare root index does not disable direct folder or file URLs.',
                ],
                [
                    'title' => 'Accepted formats',
                    'markdown' => 'Accepted files are downloads only. Images and videos are not processed as managed media.',
                ],
                [
                    'title' => 'File dates',
                    'markdown' => 'Browser uploads do not provide a reliable original file modified or created time. Downloads initializes the file date to the upload time and lets admins/authors edit that date after upload.',
                ],
                [
                    'title' => 'Import limits',
                    'markdown' => 'FILES.BBS import is not supported.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };

    $emit_json = static function( array $payload, int $status_code = 200 ) use ( $plugins ) : void {
        $plugins->setResponseStatus( $status_code );
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=UTF-8' );
            header( 'X-Content-Type-Options: nosniff' );
        }

        echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT );
    };

    $root_index_browseable = static function() use ( $plugins, $plugin_key ) : bool {
        $plugin_settings = $plugins->getPluginSystemSettings( $plugin_key );
        return( ! array_key_exists( 'root_index_browseable', $plugin_settings ) || ! empty( $plugin_settings['root_index_browseable'] ) );
    };

    $parse_size_to_bytes = static function( string $value ) : int {
        $value = trim( $value );
        if ( $value === '' ) {
            return( 0 );
        }

        $unit = strtolower( substr( $value, -1 ) );
        $number = (float) $value;
        if ( $number <= 0 ) {
            return( 0 );
        }

        if ( $unit === 'g' ) {
            $number *= 1024 * 1024 * 1024;
        } elseif ( $unit === 'm' ) {
            $number *= 1024 * 1024;
        } elseif ( $unit === 'k' ) {
            $number *= 1024;
        }

        return( (int) max( 0, round( $number ) ) );
    };

    $request_exceeds_post_limit = static function() use ( $parse_size_to_bytes ) : bool {
        $content_length = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
        $post_max_bytes = $parse_size_to_bytes( (string) ini_get( 'post_max_size' ) );
        return( $content_length > 0 && $post_max_bytes > 0 && $content_length > $post_max_bytes );
    };

    $require_login = static function() use ( $plugins, $security, $login_url ) : bool {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return( false );
        }

        return( true );
    };

    $current_username = static function() use ( $security ) : string {
        return( strtolower( trim( (string) $security->getCurrentUsername() ) ) );
    };

    $build_admin_selection_url = static function( string $folder_id = '', string $scope = '', string $author_slug = '' ) use ( $downloads_admin_url ) : string {
        $folder_id = trim( $folder_id );
        if ( $folder_id !== '' ) {
            return( $downloads_admin_url . '?folder=' . rawurlencode( $folder_id ) );
        }

        $scope = strtolower( trim( $scope ) ) === 'author' ? 'author' : 'root';
        $parameters = [ 'scope' => $scope ];
        if ( $scope === 'author' && trim( $author_slug ) !== '' ) {
            $parameters['author'] = strtolower( trim( $author_slug ) );
        }
        return( $downloads_admin_url . '?' . http_build_query( $parameters, '', '&', PHP_QUERY_RFC3986 ) );
    };

    $build_catalogue_options = static function( string $current_id, string $current_parent_id, string $scope, string $author_slug ) use ( $downloads_service, $escape ) : string {
        $html = '<option value="">Top level</option>';
        $catalogues = $downloads_service->listCatalogues( $scope, $author_slug );
        $catalogues_by_id = [];
        foreach ( $catalogues as $catalogue ) {
            $catalogue_id = (string) ( $catalogue['id'] ?? '' );
            if ( $catalogue_id !== '' ) {
                $catalogues_by_id[$catalogue_id] = $catalogue;
            }
        }

        foreach ( $catalogues as $catalogue ) {
            $id = (string) ( $catalogue['id'] ?? '' );
            if ( $id === '' || $id === $current_id ) {
                continue;
            }

            $ancestor_id = (string) ( $catalogue['parent_id'] ?? '' );
            $guard = 0;
            while ( $current_id !== '' && $ancestor_id !== '' && $guard < count( $catalogues_by_id ) ) {
                if ( $ancestor_id === $current_id ) {
                    continue 2;
                }
                $ancestor_id = (string) ( $catalogues_by_id[$ancestor_id]['parent_id'] ?? '' );
                $guard++;
            }

            $label = (string) ( $catalogue['path'] ?? $catalogue['title'] ?? $id );
            $html .= '<option value="' . $escape( $id ) . '"' . ( $id === $current_parent_id ? ' selected' : '' ) . '>' . $escape( $label ) . '</option>';
        }

        return( $html );
    };

    $build_catalogue_form = static function( array $catalogue, string $title, string $button_label, string $return_folder_id = '', string $return_scope = '', string $return_author_slug = '' ) use ( $catalogue_save_url, $security, $downloads_service, $escape, $build_catalogue_options, $current_username ) : string {
        $is_superadmin = $security->isSuperAdmin();
        $id = (string) ( $catalogue['id'] ?? '' );
        $scope = (string) ( $catalogue['scope'] ?? ( $is_superadmin ? 'root' : 'author' ) );
        $author_slug = (string) ( $catalogue['author_slug'] ?? ( $scope === 'author' ? $current_username() : '' ) );
        $enabled = ! array_key_exists( 'enabled', $catalogue ) || ! empty( $catalogue['enabled'] );
        $form_id = 'tm-downloads-catalogue-' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $id !== '' ? $id : 'new' );

        $html = '<form method="post" action="' . $escape( $catalogue_save_url ) . '" class="d-grid gap-3">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $html .= '<input type="hidden" name="return_folder_id" value="' . $escape( $return_folder_id ) . '"><input type="hidden" name="return_scope" value="' . $escape( $return_scope ) . '"><input type="hidden" name="return_author_slug" value="' . $escape( $return_author_slug ) . '">';
        if ( $id !== '' ) {
            $html .= '<input type="hidden" name="id" value="' . $escape( $id ) . '">';
        }
        if ( ! $is_superadmin ) {
            $html .= '<input type="hidden" name="scope" value="author">';
            $html .= '<input type="hidden" name="author_slug" value="' . $escape( $current_username() ) . '">';
        }

        $html .= '<div class="small text-uppercase text-body-secondary">' . $escape( $title ) . '</div>';
        $html .= '<div class="row g-3 align-items-end">';
        if ( $is_superadmin ) {
            $html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1" for="' . $escape( $form_id . '-scope' ) . '">Scope</label><select class="form-select" id="' . $escape( $form_id . '-scope' ) . '" name="scope"><option value="root"' . ( $scope === 'root' ? ' selected' : '' ) . '>Root</option><option value="author"' . ( $scope === 'author' ? ' selected' : '' ) . '>Author</option></select></div>';
            $html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1" for="' . $escape( $form_id . '-author' ) . '">Author slug</label><input class="form-control font-monospace" id="' . $escape( $form_id . '-author' ) . '" name="author_slug" type="text" value="' . $escape( $author_slug ) . '"></div>';
        }
        $html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="' . $escape( $form_id . '-title' ) . '">Title</label><input class="form-control" id="' . $escape( $form_id . '-title' ) . '" name="title" type="text" value="' . $escape( (string) ( $catalogue['title'] ?? '' ) ) . '" required></div>';
        $html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1" for="' . $escape( $form_id . '-slug' ) . '">Slug</label><input class="form-control font-monospace" id="' . $escape( $form_id . '-slug' ) . '" name="slug" type="text" placeholder="auto" value="' . $escape( (string) ( $catalogue['slug'] ?? '' ) ) . '"></div>';
        $html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="' . $escape( $form_id . '-parent' ) . '">Parent</label><select class="form-select" id="' . $escape( $form_id . '-parent' ) . '" name="parent_id">' . $build_catalogue_options( $id, (string) ( $catalogue['parent_id'] ?? '' ), $scope, $author_slug ) . '</select></div>';
        $html .= '<div class="col-12"><label class="form-label small mb-1" for="' . $escape( $form_id . '-intro' ) . '">Intro text</label><textarea class="form-control" id="' . $escape( $form_id . '-intro' ) . '" name="intro" rows="3">' . $escape( (string) ( $catalogue['intro'] ?? '' ) ) . '</textarea></div>';
        $html .= '</div>';
        $html .= '<div class="row g-3 align-items-end" data-tm-downloads-catalogue-actions>';
        $html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="' . $escape( $form_id . '-order' ) . '">Order</label><input class="form-control" id="' . $escape( $form_id . '-order' ) . '" name="sort_order" type="number" value="' . (int) ( $catalogue['sort_order'] ?? 0 ) . '"></div>';
        $html .= '<div class="col-6 col-lg-auto"><div class="form-check mb-2"><input class="form-check-input" id="tm-downloads-enabled-' . $escape( $id !== '' ? $id : 'new' ) . '" name="enabled" type="checkbox" value="1"' . ( $enabled ? ' checked' : '' ) . '><label class="form-check-label" for="tm-downloads-enabled-' . $escape( $id !== '' ? $id : 'new' ) . '">Enabled</label></div></div>';
        $html .= '<div class="col-12 col-sm-auto ms-sm-auto d-flex justify-content-end"><button class="btn btn-primary text-nowrap" type="submit">' . $escape( $button_label ) . '</button></div>';
        $html .= '</div>';
        $html .= '<div class="form-text">Root folders use /downloads. Author folders use /&lt;author&gt;/downloads and stay in that author space.</div>';
        $html .= '</form>';

        return( $html );
    };

    $build_upload_form = static function( array $catalogue ) use ( $file_upload_url, $security, $escape ) : string {
        $id = (string) ( $catalogue['id'] ?? '' );
        if ( $id === '' ) {
            return( '' );
        }
        $form_id = 'tm-downloads-upload-' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $id );

        $html = '<form method="post" action="' . $escape( $file_upload_url ) . '" enctype="multipart/form-data" data-tm-downloads-upload-form data-tm-downloads-max-file-bytes="209715200">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $html .= '<input type="hidden" name="catalogue_id" value="' . $escape( $id ) . '">';
        $html .= '<input type="hidden" name="return_folder_id" value="' . $escape( $id ) . '">';
        $html .= '<div class="row g-3 align-items-end" data-tm-downloads-upload-grid>';
        $html .= '<div class="col-12 col-xl-5"><label class="form-label small mb-1" for="' . $escape( $form_id . '-files' ) . '">Files</label><input class="form-control" id="' . $escape( $form_id . '-files' ) . '" name="download_file[]" type="file" multiple required></div>';
        $html .= '<div class="col-12 col-xl-7"><label class="form-label small mb-1" for="' . $escape( $form_id . '-title' ) . '">Title</label><input class="form-control" id="' . $escape( $form_id . '-title' ) . '" name="title" type="text" placeholder="Use filename"></div>';
        $html .= '<div class="col-12 col-xl-8"><label class="form-label small mb-1" for="' . $escape( $form_id . '-description' ) . '">Description</label><input class="form-control" id="' . $escape( $form_id . '-description' ) . '" name="description" type="text"></div>';
        $html .= '<div class="col-6 col-xl-2"><div class="form-check mb-2"><input class="form-check-input" id="tm-downloads-file-enabled-' . $escape( $id ) . '" name="enabled" type="checkbox" value="1" checked><label class="form-check-label" for="tm-downloads-file-enabled-' . $escape( $id ) . '">Enabled</label></div></div>';
        $html .= '<div class="col-6 col-xl-2"><button class="btn btn-outline-primary w-100" type="submit" data-tm-downloads-upload-submit>Upload file</button></div>';
        $html .= '</div>';
        $html .= '<div class="form-text">Allowed formats include archives, documents, images, video files, and plain data files. Maximum size: 200 MB per file. When several files are uploaded together, each file uses its filename as the title.</div>';
        $html .= '<div class="alert alert-danger d-none mt-3 mb-0" role="alert" data-tm-downloads-upload-error></div>';
        $html .= '</form>';
        return( $html );
    };

    $build_files_html = static function( array $catalogue ) use ( $downloads_service, $security, $file_save_url, $file_save_json_url, $file_delete_url, $date_formatter, $escape ) : string {
        $files = $downloads_service->listFilesForCatalogue( (string) ( $catalogue['id'] ?? '' ) );
        if ( empty( $files ) ) {
            return( '<div class="small text-body-secondary mt-3">No files have been uploaded to this folder yet.</div>' );
        }

        $html = '<div class="mt-3" data-tm-downloads-file-list>';
        foreach ( $files as $file ) {
            $last_downloaded = trim( (string) ( $file['last_downloaded_at_utc'] ?? '' ) ) !== ''
                ? $date_formatter->formatUtcDateTime( (string) $file['last_downloaded_at_utc'] )
                : '';
            $file_date = trim( (string) ( $file['file_date_utc'] ?? '' ) ) !== ''
                ? $date_formatter->formatUtcDateTime( (string) $file['file_date_utc'] )
                : '';
            $file_id = (string) ( $file['id'] ?? '' );
            $form_id = 'tm-downloads-file-' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $file_id );
            $public_url = (string) ( $file['public_url'] ?? '' );
            $download_count = (int) ( $file['download_count'] ?? 0 );
            $file_title = trim( (string) ( $file['title'] ?? '' ) );
            if ( $file_title === '' ) {
                $file_title = (string) ( $file['original_filename'] ?? 'Untitled file' );
            }
            $html .= '<details class="border-top py-2" data-tm-downloads-file-disclosure>';
            $html .= '<summary class="py-1"><span class="fw-semibold">' . $escape( $file_title ) . '</span> <span class="small text-body-secondary">' . $escape( (string) ( $file['size_label'] ?? '' ) ) . ' · ' . $download_count . ' downloads · ' . ( ! empty( $file['enabled'] ) ? 'enabled' : 'disabled' ) . '</span></summary>';
            $html .= '<form method="post" action="' . $escape( $file_save_url ) . '" data-tm-downloads-file-form="1" data-tm-downloads-save-url="' . $escape( $file_save_json_url ) . '" class="row g-2 align-items-start">';
            $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="id" value="' . $escape( $file_id ) . '"><input type="hidden" name="return_folder_id" value="' . $escape( (string) ( $catalogue['id'] ?? '' ) ) . '">';
            $html .= '<div class="col-12 col-xl-5"><label class="form-label small mb-1" for="' . $escape( $form_id . '-title' ) . '">Title</label><input class="form-control form-control-sm" id="' . $escape( $form_id . '-title' ) . '" name="title" type="text" value="' . $escape( (string) ( $file['title'] ?? '' ) ) . '"><label class="form-label small mt-2 mb-1" for="' . $escape( $form_id . '-description' ) . '">Description</label><textarea class="form-control form-control-sm" id="' . $escape( $form_id . '-description' ) . '" name="description" rows="2">' . $escape( (string) ( $file['description'] ?? '' ) ) . '</textarea></div>';
            $html .= '<div class="col-12 col-xl-4"><div class="small text-body-secondary mb-2 text-truncate" title="' . $escape( (string) ( $file['original_filename'] ?? '' ) ) . '">' . $escape( (string) ( $file['original_filename'] ?? '' ) ) . '</div><div class="small text-body-secondary d-flex flex-wrap gap-2 mb-2"><span>' . $escape( (string) ( $file['size_label'] ?? '' ) ) . '</span>' . ( $file_date !== '' ? '<span class="text-nowrap">' . $escape( $file_date ) . '</span>' : '' ) . '<span class="text-nowrap">' . $download_count . ' downloads</span>' . ( $last_downloaded !== '' ? '<span class="text-nowrap" title="' . $escape( $last_downloaded ) . '">last ' . $escape( $last_downloaded ) . '</span>' : '' ) . '</div><label class="form-label small mb-1" for="' . $escape( $form_id . '-date' ) . '">File date</label><input class="form-control form-control-sm" id="' . $escape( $form_id . '-date' ) . '" name="file_date" type="datetime-local" value="' . $escape( (string) ( $file['file_date_input'] ?? '' ) ) . '"><div class="form-check mt-2"><input class="form-check-input" id="tm-download-file-enabled-' . $escape( $file_id ) . '" name="enabled" type="checkbox" value="1"' . ( ! empty( $file['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label small" for="tm-download-file-enabled-' . $escape( $file_id ) . '">Enabled</label></div></div>';
            $html .= '<div class="col-12 col-xl-3 text-xl-end"><div class="d-flex flex-wrap gap-2 justify-content-xl-end"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $public_url ) . '" title="' . $escape( $public_url ) . '" aria-label="Open public file URL"><span class="bi bi-question-circle" aria-hidden="true"></span></a><button class="btn btn-outline-primary btn-sm" type="submit" data-tm-downloads-ajax-save="1">Save</button><button class="btn btn-outline-danger btn-sm" type="submit" formaction="' . $escape( $file_delete_url ) . '">Delete</button></div><div class="small text-body-secondary mt-2" data-tm-downloads-save-status aria-live="polite"></div></div>';
            $html .= '</form>';
            $html .= '</details>';
        }
        $html .= '</div>';
        return( $html );
    };

    $build_admin_script = static function() : string {
        return( <<<'HTML'
<script>
(function() {
    const forms = document.querySelectorAll('[data-tm-downloads-file-form]');
    const uploadForms = document.querySelectorAll('[data-tm-downloads-upload-form]');

    function showUploadModal(fileCount, message) {
        if (!window.bootstrap || !window.bootstrap.Modal) {
            return null;
        }
        let modalElement = document.getElementById('tm-downloads-upload-progress-modal');
        if (!modalElement) {
            modalElement = document.createElement('div');
            modalElement.id = 'tm-downloads-upload-progress-modal';
            modalElement.className = 'modal fade';
            modalElement.tabIndex = -1;
            modalElement.setAttribute('data-bs-backdrop', 'static');
            modalElement.setAttribute('data-bs-keyboard', 'false');
            modalElement.setAttribute('aria-hidden', 'true');
            modalElement.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-body p-4"><div class="d-flex align-items-center gap-3 mb-3"><div class="spinner-border text-primary flex-shrink-0" role="status"><span class="visually-hidden">Uploading files</span></div><div><div class="fw-semibold">Uploading files</div><div class="small text-body-secondary" data-tm-downloads-upload-message></div></div></div><div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%;"></div></div><div class="small text-body-secondary mt-2 text-end" data-tm-downloads-upload-count></div></div></div></div>';
            document.body.appendChild(modalElement);
        }
        const messageElement = modalElement.querySelector('[data-tm-downloads-upload-message]');
        const countElement = modalElement.querySelector('[data-tm-downloads-upload-count]');
        if (messageElement) {
            messageElement.textContent = message || 'Uploading and processing files...';
        }
        if (countElement) {
            countElement.textContent = String(fileCount || 1) + ' selected';
        }
        const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();
        return modal;
    }

    uploadForms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const fileInput = form.querySelector('input[type="file"][name="download_file[]"]');
            const files = fileInput && fileInput.files ? Array.from(fileInput.files) : [];
            const maxBytes = Number.parseInt(form.getAttribute('data-tm-downloads-max-file-bytes') || '0', 10) || 0;
            const tooLarge = maxBytes > 0 ? files.find(function(file) { return file && file.size > maxBytes; }) : null;
            const uploadError = form.querySelector('[data-tm-downloads-upload-error]');
            if (uploadError) {
                uploadError.classList.add('d-none');
                uploadError.textContent = '';
            }
            if (tooLarge) {
                event.preventDefault();
                if (uploadError) {
                    uploadError.textContent = '"' + (tooLarge.name || 'Selected file') + '" is larger than the 200 MB download file limit.';
                    uploadError.classList.remove('d-none');
                }
                return;
            }
            if (files.length > 0) {
                const submitter = event.submitter || form.querySelector('[data-tm-downloads-upload-submit]');
                if (submitter) {
                    submitter.disabled = true;
                }
                showUploadModal(files.length, files.length === 1 ? 'Uploading one file...' : ('Uploading ' + files.length + ' files...'));
            }
        });
    });

    if (!forms.length || !window.fetch || !window.FormData) {
        return;
    }

    forms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const submitter = event.submitter || document.activeElement;
            if (!submitter || !submitter.matches('[data-tm-downloads-ajax-save]')) {
                return;
            }

            event.preventDefault();

            const status = form.querySelector('[data-tm-downloads-save-status]');
            const saveUrl = form.getAttribute('data-tm-downloads-save-url') || form.getAttribute('action');
            const originalText = submitter.textContent;

            if (status) {
                status.className = 'small text-body-secondary mt-2';
                status.textContent = 'Saving...';
            }
            submitter.disabled = true;
            submitter.textContent = 'Saving';

            fetch(saveUrl, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(response) {
                return response.json().catch(function() {
                    return {};
                }).then(function(payload) {
                    if (!response.ok) {
                        throw payload;
                    }
                    return payload;
                });
            }).then(function(payload) {
                if (status) {
                    status.className = 'small text-success mt-2';
                    status.textContent = payload.message || 'Saved.';
                }
            }).catch(function(payload) {
                if (status) {
                    status.className = 'small text-danger mt-2';
                    status.textContent = payload && payload.error ? payload.error : 'The file could not be saved.';
                }
            }).finally(function() {
                submitter.disabled = false;
                submitter.textContent = originalText;
            });
        });
    });
})();
</script>
HTML );
    };

    $build_folder_tree_items = null;
    $build_folder_tree_items = static function( array $children_by_parent, string $parent_id, string $current_folder_id, array $file_counts, array $trail = [] ) use ( &$build_folder_tree_items, $build_admin_selection_url, $escape ) : string {
        $children = is_array( $children_by_parent[$parent_id] ?? null ) ? $children_by_parent[$parent_id] : [];
        if ( empty( $children ) ) {
            return( '' );
        }

        $html = '<ul class="list-unstyled mb-0' . ( $parent_id !== '' ? ' ms-3 ps-2 border-start' : '' ) . '">';
        foreach ( $children as $catalogue ) {
            $id = (string) ( $catalogue['id'] ?? '' );
            if ( $id === '' || isset( $trail[$id] ) ) {
                continue;
            }
            $next_trail = $trail;
            $next_trail[$id] = true;
            $is_current = $id === $current_folder_id;
            $file_count = (int) ( $file_counts[$id] ?? 0 );
            $html .= '<li data-tm-downloads-tree-item="' . $escape( $id ) . '"><div class="d-flex align-items-baseline gap-2 py-1">';
            $html .= '<a class="flex-grow-1 text-decoration-none ' . ( $is_current ? 'fw-semibold text-body' : 'text-body-secondary' ) . '" href="' . $escape( $build_admin_selection_url( $id ) ) . '" data-tm-downloads-tree-node="' . $escape( $id ) . '"' . ( $is_current ? ' aria-current="page"' : '' ) . '>' . $escape( (string) ( $catalogue['title'] ?? '' ) ) . '</a>';
            $html .= '<span class="small text-body-secondary" title="' . $file_count . ' ' . ( $file_count === 1 ? 'file' : 'files' ) . '">' . $file_count . '</span></div>';
            $html .= $build_folder_tree_items( $children_by_parent, $id, $current_folder_id, $file_counts, $next_trail );
            $html .= '</li>';
        }
        $html .= '</ul>';
        return( $html );
    };

    $build_folder_navigation = static function( array $groups, string $current_folder_id, string $current_scope, string $current_author_slug, array $file_counts, string $label, string $marker ) use ( $build_folder_tree_items, $build_admin_selection_url, $escape ) : string {
        $html = '<nav aria-label="' . $escape( $label ) . '" ' . $marker . '>';
        foreach ( $groups as $group ) {
            $scope = (string) ( $group['scope'] ?? 'root' );
            $author_slug = (string) ( $group['author_slug'] ?? '' );
            $is_current_root = $current_folder_id === '' && $scope === $current_scope && $author_slug === $current_author_slug;
            $html .= '<div class="mb-3" data-tm-downloads-tree-group="' . $escape( $scope . ( $author_slug !== '' ? ':' . $author_slug : '' ) ) . '">';
            $html .= '<div class="d-flex align-items-baseline gap-2 mb-1"><a class="flex-grow-1 text-decoration-none small text-uppercase ' . ( $is_current_root ? 'fw-semibold text-body' : 'text-body-secondary' ) . '" href="' . $escape( $build_admin_selection_url( '', $scope, $author_slug ) ) . '"' . ( $is_current_root ? ' aria-current="page"' : '' ) . '>' . $escape( (string) ( $group['label'] ?? 'Downloads' ) ) . '</a><span class="small text-body-secondary">' . count( (array) ( $group['catalogues'] ?? [] ) ) . '</span></div>';
            $html .= $build_folder_tree_items( (array) ( $group['children'] ?? [] ), '', $current_folder_id, $file_counts );
            $html .= '</div>';
        }
        $html .= '</nav>';
        return( $html );
    };

    $render_admin_page = static function( array $notice = [] ) use ( $plugins, $security, $downloads_service, $user_repository, $build_admin_selection_url, $build_folder_navigation, $build_catalogue_form, $build_upload_form, $build_files_html, $build_admin_script, $escape, $current_username ) : void {
        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'downloads.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }

        $is_superadmin = $security->isSuperAdmin();
        $username = $current_username();
        $catalogues = $downloads_service->listAccessibleCatalogues( $is_superadmin, $username );

        $catalogues_by_id = [];
        $file_counts = [];
        $groups = [];
        if ( $is_superadmin ) {
            $groups['root:'] = [ 'scope' => 'root', 'author_slug' => '', 'label' => 'Site downloads', 'catalogues' => [], 'children' => [] ];
        } else {
            $author_label = $user_repository->getDisplayLabelByAuthorSlug( $username );
            $groups['author:' . $username] = [ 'scope' => 'author', 'author_slug' => $username, 'label' => ( $author_label !== '' ? $author_label : $username ) . ' downloads', 'catalogues' => [], 'children' => [] ];
        }

        foreach ( $catalogues as $catalogue ) {
            $id = (string) ( $catalogue['id'] ?? '' );
            if ( $id === '' ) {
                continue;
            }
            $catalogues_by_id[$id] = $catalogue;
            $file_counts[$id] = count( $downloads_service->listFilesForCatalogue( $id ) );
            $scope = (string) ( $catalogue['scope'] ?? 'root' );
            $author_slug = $scope === 'author' ? (string) ( $catalogue['author_slug'] ?? '' ) : '';
            $group_key = $scope . ':' . $author_slug;
            if ( ! isset( $groups[$group_key] ) ) {
                $author_label = $author_slug !== '' ? $user_repository->getDisplayLabelByAuthorSlug( $author_slug ) : '';
                $groups[$group_key] = [
                    'scope' => $scope,
                    'author_slug' => $author_slug,
                    'label' => $scope === 'author' ? ( $author_label !== '' ? $author_label : $author_slug ) . ' downloads' : 'Site downloads',
                    'catalogues' => [],
                    'children' => [],
                ];
            }
            $groups[$group_key]['catalogues'][] = $catalogue;
        }

        uksort(
            $groups,
            static function( string $left, string $right ) : int {
                if ( $left === 'root:' ) {
                    return( -1 );
                }
                if ( $right === 'root:' ) {
                    return( 1 );
                }
                return( strcmp( $left, $right ) );
            }
        );

        foreach ( $groups as $group_key => $group ) {
            $group_ids = [];
            foreach ( (array) $group['catalogues'] as $catalogue ) {
                $group_ids[(string) ( $catalogue['id'] ?? '' )] = true;
            }
            foreach ( (array) $group['catalogues'] as $catalogue ) {
                $parent_id = (string) ( $catalogue['parent_id'] ?? '' );
                if ( $parent_id !== '' && ! isset( $group_ids[$parent_id] ) ) {
                    $parent_id = '';
                }
                $groups[$group_key]['children'][$parent_id][] = $catalogue;
            }
        }

        $query = $plugins->getRequestQueryData();
        $requested_folder_id = trim( (string) ( $query['folder'] ?? '' ) );
        $selected_catalogue = $requested_folder_id !== '' && isset( $catalogues_by_id[$requested_folder_id] ) ? $catalogues_by_id[$requested_folder_id] : [];
        $current_folder_id = (string) ( $selected_catalogue['id'] ?? '' );
        $current_scope = $is_superadmin ? 'root' : 'author';
        $current_author_slug = $is_superadmin ? '' : $username;
        if ( $current_folder_id !== '' ) {
            $current_scope = (string) ( $selected_catalogue['scope'] ?? 'root' );
            $current_author_slug = $current_scope === 'author' ? (string) ( $selected_catalogue['author_slug'] ?? '' ) : '';
        } elseif ( strtolower( trim( (string) ( $query['scope'] ?? '' ) ) ) === 'author' ) {
            $requested_author = strtolower( trim( (string) ( $query['author'] ?? '' ) ) );
            $requested_group_key = 'author:' . $requested_author;
            if ( isset( $groups[$requested_group_key] ) && ( $is_superadmin || $requested_author === $username ) ) {
                $current_scope = 'author';
                $current_author_slug = $requested_author;
            }
        }

        $current_group_key = $current_scope . ':' . $current_author_slug;
        if ( ! isset( $groups[$current_group_key] ) ) {
            $current_group_key = array_key_first( $groups ) ?? '';
            $current_scope = (string) ( $groups[$current_group_key]['scope'] ?? ( $is_superadmin ? 'root' : 'author' ) );
            $current_author_slug = (string) ( $groups[$current_group_key]['author_slug'] ?? ( $is_superadmin ? '' : $username ) );
            $selected_catalogue = [];
            $current_folder_id = '';
        }

        $current_group = is_array( $groups[$current_group_key] ?? null ) ? $groups[$current_group_key] : [];
        $current_title = $current_folder_id !== '' ? (string) ( $selected_catalogue['title'] ?? 'Download folder' ) : (string) ( $current_group['label'] ?? 'Downloads' );
        $current_children = is_array( $current_group['children'][$current_folder_id] ?? null ) ? $current_group['children'][$current_folder_id] : [];
        $return_scope = $current_folder_id === '' ? $current_scope : '';
        $return_author_slug = $current_folder_id === '' ? $current_author_slug : '';
        $new_catalogue = [
            'enabled' => true,
            'scope' => $current_scope,
            'author_slug' => $current_author_slug,
            'parent_id' => $current_folder_id,
        ];

        $desktop_navigation = $build_folder_navigation( $groups, $current_folder_id, $current_scope, $current_author_slug, $file_counts, 'Download folder tree', 'data-tm-downloads-tree-desktop' );
        $mobile_navigation = $build_folder_navigation( $groups, $current_folder_id, $current_scope, $current_author_slug, $file_counts, 'Download folders', 'data-tm-downloads-tree-mobile' );

        $body_html = '<div class="row g-4 align-items-start" data-tm-downloads-workspace>';
        $body_html .= '<div class="col-12 d-lg-none"><details class="border-bottom pb-3" data-tm-downloads-mobile-navigation><summary class="py-2 fw-semibold">Browse folders <span class="small fw-normal text-body-secondary">' . $escape( $current_title ) . '</span></summary><div class="pt-3">' . $mobile_navigation . '</div></details></div>';
        $body_html .= '<aside class="col-lg-3 d-none d-lg-block" aria-label="Download folder navigation"><div class="pe-lg-3 border-end position-sticky" style="top:5rem;">' . $desktop_navigation . '</div></aside>';
        $body_html .= '<div class="col-12 col-lg-9"><div class="d-grid gap-3" data-tm-downloads-current-workspace' . ( $current_folder_id !== '' ? ' data-folder-id="' . $escape( $current_folder_id ) . '"' : ' data-scope="' . $escape( $current_scope ) . '" data-author="' . $escape( $current_author_slug ) . '"' ) . '>';
        $body_html .= '<header class="d-flex flex-wrap align-items-start justify-content-between gap-2"><div><div class="small text-uppercase text-body-secondary mb-1">' . ( $current_folder_id !== '' ? 'Current folder' : 'Download root' ) . '</div><h2 class="h5 mb-1">' . $escape( $current_title ) . '</h2>';
        if ( $current_folder_id !== '' ) {
            $body_html .= '<div class="small text-body-secondary font-monospace">' . $escape( (string) ( $selected_catalogue['public_url'] ?? '' ) ) . '</div>';
        }
        $body_html .= '</div>';
        if ( $current_folder_id !== '' ) {
            $public_url = (string) ( $selected_catalogue['public_url'] ?? '' );
            $body_html .= '<a class="btn btn-outline-secondary btn-sm" href="' . $escape( $public_url ) . '">Open public folder</a>';
        }
        $body_html .= '</header>';

        $body_html .= '<details class="py-2" data-tm-downloads-new-folder><summary class="py-1 fw-semibold">New folder</summary><div class="pt-2"><div class="rounded-3 p-3 bg-body-secondary" data-tm-downloads-form-surface="new" data-tm-admin-surface-group>';
        $body_html .= $build_catalogue_form( $new_catalogue, $current_folder_id !== '' ? 'New subfolder' : 'New top-level folder', 'Create folder', $current_folder_id, $return_scope, $return_author_slug );
        $body_html .= '</div></div></details>';

        if ( $current_folder_id !== '' ) {
            $body_html .= '<details class="py-2" data-tm-downloads-folder-settings data-tm-downloads-folder-disclosure><summary class="py-1 fw-semibold">Folder settings <span class="small fw-normal text-body-secondary">' . ( ! empty( $selected_catalogue['enabled'] ) ? 'enabled' : 'disabled' ) . ' · ' . (int) ( $file_counts[$current_folder_id] ?? 0 ) . ' ' . ( (int) ( $file_counts[$current_folder_id] ?? 0 ) === 1 ? 'file' : 'files' ) . '</span></summary><div class="pt-2"><div class="rounded-3 p-3 bg-body-secondary" data-tm-downloads-form-surface="settings" data-tm-admin-surface-group>';
            $body_html .= $build_catalogue_form( $selected_catalogue, 'Edit folder', 'Save folder', $current_folder_id );
            $body_html .= '</div></div></details>';
            $body_html .= '<details class="py-2" data-tm-downloads-upload-disclosure><summary class="py-1 fw-semibold">Upload files</summary><div class="pt-2"><div class="rounded-3 p-3 bg-body-secondary" data-tm-downloads-form-surface="upload" data-tm-admin-surface-group>' . $build_upload_form( $selected_catalogue ) . '</div></div></details>';
        }

        $body_html .= '<div class="rounded-3 p-3 bg-body-secondary d-grid gap-3" data-tm-admin-surface-group data-tm-downloads-folder-contents>';
        $body_html .= '<section data-tm-downloads-child-folders><div class="small text-uppercase text-body-secondary mb-2">Folders</div>';
        if ( empty( $current_children ) ) {
            $body_html .= '<div class="small text-body-secondary">No subfolders.</div>';
        } else {
            $body_html .= '<div class="table-responsive" tabindex="0" aria-label="Subfolders"><table class="table table-sm align-middle mb-0"><thead><tr><th>Folder</th><th class="d-none d-xl-table-cell">Path</th><th class="text-end d-none d-md-table-cell">Files</th><th class="d-none d-md-table-cell">Status</th><th class="text-end d-md-none">Details</th></tr></thead><tbody>';
            foreach ( $current_children as $child ) {
                $child_id = (string) ( $child['id'] ?? '' );
                $child_path = (string) ( $child['public_url'] ?? '' );
                $child_file_count = (int) ( $file_counts[$child_id] ?? 0 );
                $child_status = ! empty( $child['enabled'] ) ? 'Enabled' : 'Disabled';
                $body_html .= '<tr data-tm-downloads-child-folder="' . $escape( $child_id ) . '"><td><a class="fw-semibold text-decoration-none" href="' . $escape( $build_admin_selection_url( $child_id ) ) . '">' . $escape( (string) ( $child['title'] ?? '' ) ) . '</a><div class="small text-body-secondary font-monospace d-xl-none">' . $escape( $child_path ) . '</div></td><td class="d-none d-xl-table-cell font-monospace small text-body-secondary">' . $escape( $child_path ) . '</td><td class="text-end d-none d-md-table-cell">' . $child_file_count . '</td><td class="d-none d-md-table-cell">' . $child_status . '</td><td class="text-end text-nowrap d-md-none"><div>' . $child_file_count . ' ' . ( $child_file_count === 1 ? 'file' : 'files' ) . '</div><div class="small text-body-secondary">' . $child_status . '</div></td></tr>';
            }
            $body_html .= '</tbody></table></div>';
        }
        $body_html .= '</section>';

        if ( $current_folder_id !== '' ) {
            $body_html .= '<section data-tm-downloads-selected-files><div class="small text-uppercase text-body-secondary mb-2">Files</div>' . $build_files_html( $selected_catalogue ) . '</section>';
        } elseif ( empty( $catalogues ) ) {
            $body_html .= '<div class="small text-body-secondary">No download folders have been created yet.</div>';
        }

        $body_html .= '</div></div></div></div>';
        $body_html .= $build_admin_script();

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Downloads',
                'current_section' => 'downloads',
                'current_location' => 'downloads',
                'help_contexts' => [ 'plugin-downloads' ],
                'plugin_page_kicker' => 'Downloads',
                'plugin_page_title' => 'Download folders',
                'plugin_page_summary' => 'Create managed download folders with routed file downloads.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $sanitize_return_url = static function( string $return_url ) : string {
        $return_url = trim( $return_url );
        if ( $return_url === '' || str_starts_with( $return_url, '//' ) ) {
            return( '' );
        }

        $parts = parse_url( $return_url );
        if ( $parts === false ) {
            return( '' );
        }
        if ( isset( $parts['scheme'] ) || isset( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return( '' );
        }

        $path = trim( (string) ( $parts['path'] ?? '' ) );
        if ( $path === '' ) {
            return( '' );
        }
        if ( ! str_starts_with( $path, '/' ) ) {
            $path = '/' . ltrim( $path, '/' );
        }
        if ( str_contains( $path, "\0" ) || str_contains( $path, "\r" ) || str_contains( $path, "\n" ) ) {
            return( '' );
        }

        $query = trim( (string) ( $parts['query'] ?? '' ) );
        if ( str_contains( $query, "\0" ) || str_contains( $query, "\r" ) || str_contains( $query, "\n" ) ) {
            return( '' );
        }

        return( $path . ( $query !== '' ? '?' . $query : '' ) );
    };

    $append_downloads_query = static function( string $url, string $return_url = '', string $root_url = '' ) : string {
        $url = trim( $url );
        if ( $url === '' ) {
            return( $url );
        }

        $parameters = [];
        if ( $return_url !== '' ) {
            $parameters['return'] = $return_url;
        }
        if ( $root_url !== '' ) {
            $parameters['root'] = $root_url;
        }
        if ( empty( $parameters ) ) {
            return( $url );
        }

        $separator = str_contains( $url, '?' ) ? '&' : '?';
        return( $url . $separator . http_build_query( $parameters, '', '&', PHP_QUERY_RFC3986 ) );
    };

    $sanitize_downloads_root_url = static function( string $root_url ) use ( $sanitize_return_url, $downloads_service ) : string {
        $root_url = $sanitize_return_url( $root_url );
        if ( $root_url === '' ) {
            return( '' );
        }

        $tree = $downloads_service->getPublicTreeByUrl( $root_url );
        return( empty( $tree ) ? '' : (string) ( $tree['catalogue']['public_url'] ?? '' ) );
    };

    $format_public_files = static function( array $files ) use ( $date_formatter ) : array {
        foreach ( $files as $index => $file ) {
            if ( ! is_array( $file ) ) {
                continue;
            }
            $files[$index]['file_date_display'] = trim( (string) ( $file['file_date_utc'] ?? '' ) ) !== ''
                ? $date_formatter->formatUtcDateTime( (string) $file['file_date_utc'] )
                : '';
            $files[$index]['file_date_title'] = $files[$index]['file_date_display'];
            $files[$index]['file_date_display'] = $files[$index]['file_date_display'] !== ''
                ? preg_replace( '/\\s*\\([^)]*\\)\\s*$/', '', $files[$index]['file_date_display'] )
                : '';
        }

        return( $files );
    };

    $resolve_public_navigation = static function( array $tree, string $return_url, string $root_url ) use ( $downloads_service, $append_downloads_query, $root_index_browseable ) : array {
        $catalogue = is_array( $tree['catalogue'] ?? null ) ? $tree['catalogue'] : [];
        $children = is_array( $tree['children'] ?? null ) ? $tree['children'] : [];
        $breadcrumbs = is_array( $tree['breadcrumbs'] ?? null ) ? $tree['breadcrumbs'] : [];
        $parent = is_array( $tree['parent'] ?? null ) ? $tree['parent'] : [];
        $current_url = (string) ( $catalogue['public_url'] ?? '' );
        $contained_root_active = $root_url !== '' && $downloads_service->isTreeWithinRootUrl( $tree, $root_url );
        $root_index_hidden = ! $contained_root_active && (string) ( $catalogue['scope'] ?? '' ) === 'root' && ! $root_index_browseable();
        $display_root_url = $root_index_hidden ? $current_url : ( $contained_root_active ? $root_url : $downloads_service->getTreeRootUrl( $tree ) );
        $navigation_root_url = $contained_root_active || $root_index_hidden ? $display_root_url : '';
        $display_root_tree = $downloads_service->getPublicTreeByUrl( $display_root_url );
        $display_root_label = (string) ( $display_root_tree['catalogue']['title'] ?? ( $catalogue['title'] ?? 'Downloads' ) );
        if ( $display_root_label === '' ) {
            $display_root_label = 'Downloads';
        }

        $up_url = '';
        if ( $current_url !== '' && $current_url !== $display_root_url ) {
            if ( ! empty( $parent ) && ! empty( $parent['public_url'] ) ) {
                $up_url = (string) $parent['public_url'];
            } else {
                $up_url = $downloads_service->getTreeRootUrl( $tree );
            }
        }

        if ( $contained_root_active ) {
            $breadcrumbs = $downloads_service->trimBreadcrumbsToRootUrl( $breadcrumbs, $display_root_url );
            if ( ! empty( $breadcrumbs ) && (string) ( $breadcrumbs[0]['url'] ?? '' ) === $display_root_url ) {
                array_shift( $breadcrumbs );
            }
        }
        if ( $root_index_hidden ) {
            $breadcrumbs = [];
        }

        foreach ( $children as $index => $child ) {
            if ( is_array( $child ) && isset( $child['public_url'] ) ) {
                $children[$index]['public_url'] = $append_downloads_query( (string) $child['public_url'], $return_url, $navigation_root_url );
            }
        }
        foreach ( $breadcrumbs as $index => $breadcrumb ) {
            if ( is_array( $breadcrumb ) && isset( $breadcrumb['url'] ) ) {
                $breadcrumbs[$index]['url'] = $append_downloads_query( (string) $breadcrumb['url'], $return_url, $navigation_root_url );
            }
        }

        return(
            [
                'children' => $children,
                'breadcrumbs' => $breadcrumbs,
                'root_url' => $root_index_hidden ? '' : $append_downloads_query( $display_root_url, $return_url, $contained_root_active ? $display_root_url : '' ),
                'root_label' => $display_root_label,
                'up_url' => $append_downloads_query( $up_url, $return_url, $navigation_root_url ),
            ]
        );
    };

    $render_public_page = static function( array $tree ) use ( $plugins, $date_formatter, $theme, $sanitize_return_url, $sanitize_downloads_root_url, $format_public_files, $resolve_public_navigation ) : void {
        $catalogue = is_array( $tree['catalogue'] ?? null ) ? $tree['catalogue'] : [];
        $files = is_array( $tree['files'] ?? null ) ? $tree['files'] : [];
        $title = (string) ( $catalogue['title'] ?? 'Downloads' );
        $query = $plugins->getRequestQueryData();
        $return_url = $sanitize_return_url( (string) ( $query['return'] ?? '' ) );
        $root_url = $sanitize_downloads_root_url( (string) ( $query['root'] ?? '' ) );
        $navigation = $resolve_public_navigation( $tree, $return_url, $root_url );
        $files = $format_public_files( $files );
        $view_data = $theme->getBaseViewData(
            $title,
            null,
            (string) ( $catalogue['scope'] ?? '' ) === 'author' ? (string) ( $catalogue['author_slug'] ?? '' ) : null
        );
        $view_data['theme_is_downloads_area'] = true;
        $view_data['theme_navigation_menu'] = [];
        $view_data['theme_sidebar_menu'] = [];
        $view_data['theme_footer_menu'] = [];
        $view_data['theme_page_structure'] = [];
        $view_data['theme_sidebar_enabled'] = false;
        $view_data['theme_public_sidebar_fragments'] = [];
        $view_data['theme_public_footer_fragments'] = [];
        $view_data['theme_body_classes'] = trim( (string) ( $view_data['theme_body_classes'] ?? '' ) . ' tm-downloads-area-view' );

        \Flight::view()->render(
            'public/downloads.latte',
            array_merge(
                $view_data,
                [
                    'downloads_catalogue' => $catalogue,
                    'downloads_children' => $navigation['children'],
                    'downloads_files' => $files,
                    'downloads_breadcrumbs' => $navigation['breadcrumbs'],
                    'downloads_root_url' => $navigation['root_url'],
                    'downloads_root_label' => $navigation['root_label'],
                    'downloads_up_url' => $navigation['up_url'],
                    'downloads_return_url' => $return_url,
                    'downloads_timezone_label' => $date_formatter->getTimezoneAbbreviation(),
                ]
            )
        );
    };

    $parse_shortcode_attributes = static function( string $shortcode ) : array {
        $shortcode = html_entity_decode( trim( $shortcode ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( preg_match( '/^\[\[downloads\b(.*)\]\]$/isu', $shortcode, $matches ) !== 1 ) {
            return( [] );
        }

        $attribute_source = trim( (string) ( $matches[1] ?? '' ) );
        if ( $attribute_source === '' ) {
            return( [] );
        }

        $attributes = [];
        preg_match_all(
            '/([a-z][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))/iu',
            $attribute_source,
            $matches,
            PREG_SET_ORDER
        );
        foreach ( $matches as $match ) {
            $key = strtolower( str_replace( '-', '_', (string) ( $match[1] ?? '' ) ) );
            $value = (string) ( $match[2] ?? $match[3] ?? $match[4] ?? '' );
            if ( $key !== '' ) {
                $attributes[$key] = trim( $value );
            }
        }

        return( $attributes );
    };

    $render_downloads_embed = static function( string $shortcode, array $entry ) use ( $downloads_service, $date_formatter, $escape, $sanitize_return_url, $sanitize_downloads_root_url, $append_downloads_query, $format_public_files, $parse_shortcode_attributes ) : string {
        $attributes = $parse_shortcode_attributes( $shortcode );
        $path = (string) ( $attributes['path'] ?? $attributes['folder'] ?? $attributes['root'] ?? '' );
        $root_url = $sanitize_downloads_root_url( $path );
        if ( $root_url === '' ) {
            return( $shortcode );
        }

        $tree = $downloads_service->getPublicTreeByUrl( $root_url );
        if ( empty( $tree ) ) {
            return( '' );
        }

        $catalogue = is_array( $tree['catalogue'] ?? null ) ? $tree['catalogue'] : [];
        $children = is_array( $tree['children'] ?? null ) ? $tree['children'] : [];
        $files = $format_public_files( is_array( $tree['files'] ?? null ) ? $tree['files'] : [] );
        $return_url = $sanitize_return_url( (string) ( $entry['url'] ?? '' ) );
        $title = trim( (string) ( $attributes['title'] ?? '' ) );
        if ( $title === '' ) {
            $title = (string) ( $catalogue['title'] ?? 'Downloads' );
        }

        foreach ( $children as $index => $child ) {
            if ( is_array( $child ) && isset( $child['public_url'] ) ) {
                $children[$index]['public_url'] = $append_downloads_query( (string) $child['public_url'], $return_url, $root_url );
            }
        }

        $timezone_label = $date_formatter->getTimezoneAbbreviation();
        $html = '<div class="tm-downloads-page tm-downloads-embed" data-tm-heading-scope="component">';
        $html .= '<div class="tm-downloads-header"><h2 class="tm-downloads-title">' . $escape( $title ) . '</h2>';
        if ( trim( (string) ( $catalogue['intro'] ?? '' ) ) !== '' ) {
            $html .= '<div class="tm-downloads-description">' . nl2br( $escape( (string) $catalogue['intro'] ) ) . '</div>';
        }
        $html .= '</div>';

        if ( ! empty( $children ) ) {
            $html .= '<div class="tm-downloads-catalogues"><h3 class="tm-downloads-section-title">Folders</h3><div class="tm-downloads-catalogue-list">';
            foreach ( $children as $child ) {
                if ( ! is_array( $child ) ) {
                    continue;
                }
                $html .= '<a class="tm-downloads-catalogue-link" href="' . $escape( (string) ( $child['public_url'] ?? '' ) ) . '"><span class="tm-downloads-folder-icon" aria-hidden="true"></span><span>' . $escape( (string) ( $child['title'] ?? '' ) ) . '</span></a>';
            }
            $html .= '</div></div>';
        }

        if ( ! empty( $files ) ) {
            $html .= '<div class="tm-downloads-files"><h3 class="tm-downloads-section-title">Files</h3><div class="tm-downloads-file-list" role="table" aria-label="Download files">';
            $html .= '<div class="tm-downloads-file-row tm-downloads-file-head" role="row"><div class="tm-downloads-file-cell tm-downloads-file-name" role="columnheader">Filename</div><div class="tm-downloads-file-cell tm-downloads-file-size" role="columnheader">Size</div><div class="tm-downloads-file-cell tm-downloads-file-date" role="columnheader">Date' . ( $timezone_label !== '' ? ' <span class="tm-downloads-file-date-zone">(' . $escape( $timezone_label ) . ')</span>' : '' ) . '</div><div class="tm-downloads-file-cell tm-downloads-file-description" role="columnheader">Description</div></div>';
            foreach ( $files as $file ) {
                if ( ! is_array( $file ) ) {
                    continue;
                }
                $description = trim( (string) ( $file['description'] ?? '' ) );
                $filename = (string) ( $file['original_filename'] ?? $file['title'] ?? '' );
                $html .= '<div class="tm-downloads-file-row" role="row">';
                $html .= '<div class="tm-downloads-file-cell tm-downloads-file-name" role="cell"><a class="tm-downloads-file-link" href="' . $escape( (string) ( $file['public_url'] ?? '' ) ) . '" title="' . $escape( $filename ) . '">' . $escape( $filename ) . '</a></div>';
                $html .= '<div class="tm-downloads-file-cell tm-downloads-file-size" role="cell">' . $escape( (string) ( $file['size_label'] ?? '' ) ) . '</div>';
                $html .= '<div class="tm-downloads-file-cell tm-downloads-file-date" role="cell" title="' . $escape( (string) ( $file['file_date_title'] ?? $file['file_date_display'] ?? '' ) ) . '" data-timezone="' . $escape( $timezone_label ) . '">' . $escape( (string) ( $file['file_date_display'] ?? '' ) ) . '</div>';
                $html .= '<div class="tm-downloads-file-cell tm-downloads-file-description" role="cell">' . ( $description !== '' ? nl2br( $escape( $description ) ) : '<span class="tm-downloads-empty-value">-</span>' ) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }

        if ( empty( $children ) && empty( $files ) ) {
            $html .= '<div class="tm-downloads-empty">No downloads are available here yet.</div>';
        }

        $html .= '</div>';
        return( $html );
    };

    $plugins->registerPublicContentFilter(
        $plugin_key,
        static function( string $html, array $entry = [] ) use ( $render_downloads_embed ) : string {
            if ( ! str_contains( $html, '[[downloads' ) ) {
                return( $html );
            }

            $html = preg_replace_callback(
                '/<p\b[^>]*>\s*(\[\[downloads\b[^\]]*\]\])\s*<\/p>/isu',
                static function( array $matches ) use ( $render_downloads_embed, $entry ) : string {
                    return( $render_downloads_embed( (string) ( $matches[1] ?? '' ), $entry ) );
                },
                $html
            ) ?? $html;

            return( preg_replace_callback(
                '/\[\[downloads\b[^\]]*\]\]/isu',
                static function( array $matches ) use ( $render_downloads_embed, $entry ) : string {
                    return( $render_downloads_embed( (string) ( $matches[0] ?? '' ), $entry ) );
                },
                $html
            ) ?? $html );
        }
    );

    $send_download = static function( array $download ) : void {
        $file = is_array( $download['file'] ?? null ) ? $download['file'] : [];
        $storage_path = (string) ( $file['storage_path'] ?? '' );
        if ( $storage_path === '' || ! is_file( $storage_path ) || ! is_readable( $storage_path ) ) {
            \Flight::notFound();
            return;
        }

        $download_name = basename( (string) ( $file['original_filename'] ?? 'download' ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . addcslashes( $download_name, "\\\"" ) . '"' );
        header( 'Content-Length: ' . (string) filesize( $storage_path ) );
        header( 'Cache-Control: private, no-store' );
        readfile( $storage_path );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $downloads_admin_url,
        static function() use ( $require_login, $render_admin_page ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $render_admin_page();
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $catalogue_save_url,
        static function() use ( $plugins, $security, $downloads_service, $build_admin_selection_url, $require_login, $current_username ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            $redirect_url = $build_admin_selection_url(
                (string) ( $data['return_folder_id'] ?? '' ),
                (string) ( $data['return_scope'] ?? '' ),
                (string) ( $data['return_author_slug'] ?? '' )
            );
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $redirect_url );
                return;
            }

            try {
                $saved_catalogue = $downloads_service->saveCatalogue( $data, $security->isSuperAdmin(), $current_username() );
                $redirect_url = $build_admin_selection_url( (string) ( $saved_catalogue['id'] ?? '' ) );
                $security->setFlash( 'downloads.notice', [ 'type' => 'success', 'message' => 'Download folder saved.' ] );
            } catch ( \InvalidArgumentException $e ) {
                $messages = [
                    'author' => 'Choose a valid author for author-scoped downloads.',
                    'title' => 'Enter a folder title.',
                    'slug' => 'Enter a usable slug.',
                    'slug_conflict' => 'Another folder already uses that slug under the same parent.',
                    'parent' => 'Choose a parent folder in the same scope.',
                    'permission' => 'You do not have permission to edit that folder.',
                ];
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => $messages[$e->getMessage()] ?? 'The folder could not be saved.' ] );
            } catch ( \Throwable $e ) {
                error_log( 'downloads plugin: catalogue save failed (' . $e->getMessage() . ')' );
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'The folder could not be saved.' ] );
            }

            $plugins->redirect( $redirect_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $file_upload_url,
        static function() use ( $plugins, $security, $downloads_service, $downloads_admin_url, $build_admin_selection_url, $require_login, $current_username, $request_exceeds_post_limit ) : void {
            if ( ! $require_login() ) {
                return;
            }
            if ( $request_exceeds_post_limit() ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'The upload is larger than the server request limit. Upload fewer or smaller files.' ] );
                $plugins->redirect( $downloads_admin_url );
                return;
            }
            $data = $plugins->getRequestData();
            $redirect_url = $build_admin_selection_url( (string) ( $data['return_folder_id'] ?? $data['catalogue_id'] ?? '' ) );
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $redirect_url );
                return;
            }

            try {
                $uploaded_files = $downloads_service->uploadFiles( (string) ( $data['catalogue_id'] ?? '' ), (array) ( $_FILES['download_file'] ?? [] ), $data, $security->isSuperAdmin(), $current_username() );
                $uploaded_count = count( $uploaded_files );
                $security->setFlash( 'downloads.notice', [ 'type' => 'success', 'message' => $uploaded_count === 1 ? 'Download file uploaded.' : $uploaded_count . ' download files uploaded.' ] );
            } catch ( \InvalidArgumentException $e ) {
                $messages = [
                    'catalogue' => 'Choose a folder you can manage.',
                    'upload' => 'Choose a file to upload.',
                    'size' => 'The file is empty or larger than the 200 MB limit.',
                    'type' => 'That file type is not allowed for downloads.',
                ];
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => $messages[$e->getMessage()] ?? 'The file could not be uploaded.' ] );
            } catch ( \Throwable $e ) {
                error_log( 'downloads plugin: file upload failed (' . $e->getMessage() . ')' );
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'The file could not be uploaded.' ] );
            }

            $plugins->redirect( $redirect_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $file_save_url,
        static function() use ( $plugins, $security, $downloads_service, $build_admin_selection_url, $require_login, $current_username ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            $redirect_url = $build_admin_selection_url( (string) ( $data['return_folder_id'] ?? '' ) );
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $redirect_url );
                return;
            }

            try {
                $downloads_service->saveFileMetadata( $data, $security->isSuperAdmin(), $current_username() );
                $security->setFlash( 'downloads.notice', [ 'type' => 'success', 'message' => 'Download file saved.' ] );
            } catch ( \InvalidArgumentException $e ) {
                $messages = [
                    'file' => 'Choose a download file to edit.',
                    'file_date' => 'Enter a valid file date and time.',
                    'permission' => 'You do not have permission to edit that file.',
                ];
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => $messages[$e->getMessage()] ?? 'The file could not be saved.' ] );
            } catch ( \Throwable $e ) {
                error_log( 'downloads plugin: file metadata save failed (' . $e->getMessage() . ')' );
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'The file could not be saved.' ] );
            }

            $plugins->redirect( $redirect_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $file_save_json_url,
        static function() use ( $plugins, $security, $downloads_service, $current_username, $emit_json ) : void {
            if ( ! $security->isLoggedIn() ) {
                $emit_json( [ 'ok' => false, 'error' => 'Authentication required.' ], 403 );
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $emit_json( [ 'ok' => false, 'error' => 'Your session token is invalid. Please reload the page and try again.' ], 403 );
                return;
            }

            try {
                $file = $downloads_service->saveFileMetadata( $data, $security->isSuperAdmin(), $current_username() );
                $emit_json(
                    [
                        'ok' => true,
                        'message' => 'Saved.',
                        'file' => [
                            'id' => (string) ( $file['id'] ?? '' ),
                            'title' => (string) ( $file['title'] ?? '' ),
                            'description' => (string) ( $file['description'] ?? '' ),
                            'enabled' => ! empty( $file['enabled'] ),
                            'file_date_input' => (string) ( $file['file_date_input'] ?? '' ),
                        ],
                    ]
                );
            } catch ( \InvalidArgumentException $e ) {
                $messages = [
                    'file' => 'Choose a download file to edit.',
                    'file_date' => 'Enter a valid file date and time.',
                    'permission' => 'You do not have permission to edit that file.',
                ];
                $emit_json( [ 'ok' => false, 'error' => $messages[$e->getMessage()] ?? 'The file could not be saved.' ], 422 );
            } catch ( \Throwable $e ) {
                error_log( 'downloads plugin: file metadata JSON save failed (' . $e->getMessage() . ')' );
                $emit_json( [ 'ok' => false, 'error' => 'The file could not be saved.' ], 500 );
            }
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $file_delete_url,
        static function() use ( $plugins, $security, $downloads_service, $build_admin_selection_url, $require_login, $current_username ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            $redirect_url = $build_admin_selection_url( (string) ( $data['return_folder_id'] ?? '' ) );
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $redirect_url );
                return;
            }

            $deleted = $downloads_service->deleteFile( (string) ( $data['id'] ?? '' ), $security->isSuperAdmin(), $current_username() );
            $security->setFlash(
                'downloads.notice',
                $deleted
                    ? [ 'type' => 'success', 'message' => 'Download file deleted.' ]
                    : [ 'type' => 'warning', 'message' => 'That file could not be found.' ]
            );
            $plugins->redirect( $redirect_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/downloads/_file/@file_id/@filename',
        static function( string $file_id, string $filename ) use ( $downloads_service, $send_download ) : void {
            $download = $downloads_service->resolveDownload( $file_id, 'root', '' );
            if ( empty( $download ) ) {
                \Flight::notFound();
                return;
            }
            $send_download( $download );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/@author_slug/downloads/_file/@file_id/@filename',
        static function( string $author_slug, string $file_id, string $filename ) use ( $downloads_service, $send_download ) : void {
            $download = $downloads_service->resolveDownload( $file_id, 'author', $author_slug );
            if ( empty( $download ) ) {
                \Flight::notFound();
                return;
            }
            $send_download( $download );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/downloads',
        static function() use ( $downloads_service, $render_public_page, $root_index_browseable ) : void {
            if ( ! $root_index_browseable() ) {
                \Flight::notFound();
                return;
            }
            $render_public_page( $downloads_service->getPublicRootTree( 'root', '' ) );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/downloads/@path:.*',
        static function( string $path ) use ( $downloads_service, $render_public_page, $root_index_browseable ) : void {
            $path = trim( $path, '/' );
            if ( $path === '' ) {
                if ( ! $root_index_browseable() ) {
                    \Flight::notFound();
                    return;
                }
                $render_public_page( $downloads_service->getPublicRootTree( 'root', '' ) );
                return;
            }
            $tree = $downloads_service->getPublicCatalogueTree( 'root', '', $path );
            if ( empty( $tree ) ) {
                \Flight::notFound();
                return;
            }
            $render_public_page( $tree );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/@author_slug/downloads',
        static function( string $author_slug ) use ( $downloads_service, $user_repository, $render_public_page ) : void {
            if ( ! $user_repository->isAuthorContentPublic( $author_slug ) ) {
                \Flight::notFound();
                return;
            }
            $render_public_page( $downloads_service->getPublicRootTree( 'author', $author_slug ) );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/@author_slug/downloads/@path:.*',
        static function( string $author_slug, string $path ) use ( $downloads_service, $user_repository, $render_public_page ) : void {
            if ( ! $user_repository->isAuthorContentPublic( $author_slug ) ) {
                \Flight::notFound();
                return;
            }
            $path = trim( $path, '/' );
            if ( $path === '' ) {
                $render_public_page( $downloads_service->getPublicRootTree( 'author', $author_slug ) );
                return;
            }
            $tree = $downloads_service->getPublicCatalogueTree( 'author', $author_slug, $path );
            if ( empty( $tree ) ) {
                \Flight::notFound();
                return;
            }
            $render_public_page( $tree );
        }
    );
};
