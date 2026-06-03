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
            'summary' => 'Controls public Downloads browsing behavior.',
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
            'summary' => 'Create managed download folders with routed file downloads.',
            'group' => 'Plugins',
            'order' => 135,
            'roles' => [ 'creator', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'Downloads creates public folder pages and routed file downloads. Root folders are published under `/downloads`; author folders are published under `/<author>/downloads`.',
                ],
                [
                    'title' => 'Scopes',
                    'markdown' => 'Author folders stay in their author space and do not automatically appear in the root downloads area. Admins can manage root and author folders; authors manage their own author folders.',
                ],
                [
                    'title' => 'Files',
                    'markdown' => 'Files are stored outside public asset paths and served through a download route that records a count and the most recent download time. One or more files can be uploaded into a folder at once. File metadata rows can be saved individually without reloading the whole Downloads page. Counts are visible to admins/authors in the Downloads management page, not on the public listing.',
                ],
                [
                    'title' => 'Linking from content',
                    'markdown' => 'The editor internal-link picker includes enabled download folders and files. Inserted links are normal Markdown links to routed Downloads URLs. To embed a contained folder listing in content, use `[[downloads path="/downloads/folder-slug"]]` or `[[downloads path="/author/downloads/folder-slug"]]`.',
                ],
                [
                    'title' => 'Browsing',
                    'markdown' => 'Public folder pages show a `..` folder when visitors can move up within the active browsing root. Shortcode links keep that root contained to the embedded starting folder. Admins can disable browsing the bare `/downloads` root index without disabling direct folder or file URLs.',
                ],
                [
                    'title' => 'Accepted formats',
                    'markdown' => 'Downloads accepts common archives, office documents, plain data files, images, and video files. These are treated as downloadable files only; the plugin does not process image/video metadata or render them as managed media.',
                ],
                [
                    'title' => 'File dates',
                    'markdown' => 'Browser uploads do not provide a reliable original file modified or created time. Downloads initializes the file date to the upload time and lets admins/authors edit that date after upload.',
                ],
                [
                    'title' => 'Future import',
                    'markdown' => 'FILES.BBS-style import is planned as a future importer path. The current plugin creates managed folders directly.',
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

    $build_catalogue_options = static function( string $current_id, string $current_parent_id, string $scope, string $author_slug ) use ( $downloads_service, $escape ) : string {
        $html = '<option value="">Top level</option>';
        foreach ( $downloads_service->listCatalogues( $scope, $author_slug ) as $catalogue ) {
            $id = (string) ( $catalogue['id'] ?? '' );
            if ( $id === '' || $id === $current_id ) {
                continue;
            }
            $label = (string) ( $catalogue['path'] ?? $catalogue['title'] ?? $id );
            $html .= '<option value="' . $escape( $id ) . '"' . ( $id === $current_parent_id ? ' selected' : '' ) . '>' . $escape( $label ) . '</option>';
        }

        return( $html );
    };

    $build_catalogue_form = static function( array $catalogue, string $title, string $button_label ) use ( $catalogue_save_url, $security, $downloads_service, $escape, $build_catalogue_options, $current_username ) : string {
        $is_superadmin = $security->isSuperAdmin();
        $id = (string) ( $catalogue['id'] ?? '' );
        $scope = (string) ( $catalogue['scope'] ?? ( $is_superadmin ? 'root' : 'author' ) );
        $author_slug = (string) ( $catalogue['author_slug'] ?? ( $scope === 'author' ? $current_username() : '' ) );
        $enabled = ! array_key_exists( 'enabled', $catalogue ) || ! empty( $catalogue['enabled'] );

        $html = '<form method="post" action="' . $escape( $catalogue_save_url ) . '" class="d-grid gap-3">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
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
            $html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1">Scope</label><select class="form-select" name="scope"><option value="root"' . ( $scope === 'root' ? ' selected' : '' ) . '>Root</option><option value="author"' . ( $scope === 'author' ? ' selected' : '' ) . '>Author</option></select></div>';
            $html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1">Author slug</label><input class="form-control font-monospace" name="author_slug" type="text" value="' . $escape( $author_slug ) . '"></div>';
        }
        $html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1">Title</label><input class="form-control" name="title" type="text" value="' . $escape( (string) ( $catalogue['title'] ?? '' ) ) . '" required></div>';
        $html .= '<div class="col-12 col-lg-2"><label class="form-label small mb-1">Slug</label><input class="form-control font-monospace" name="slug" type="text" placeholder="auto" value="' . $escape( (string) ( $catalogue['slug'] ?? '' ) ) . '"></div>';
        $html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1">Parent</label><select class="form-select" name="parent_id">' . $build_catalogue_options( $id, (string) ( $catalogue['parent_id'] ?? '' ), $scope, $author_slug ) . '</select></div>';
        $html .= '<div class="col-12"><label class="form-label small mb-1">Intro text</label><textarea class="form-control" name="intro" rows="3">' . $escape( (string) ( $catalogue['intro'] ?? '' ) ) . '</textarea></div>';
        $html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1">Order</label><input class="form-control" name="sort_order" type="number" value="' . (int) ( $catalogue['sort_order'] ?? 0 ) . '"></div>';
        $html .= '<div class="col-6 col-lg-2"><div class="form-check mb-2"><input class="form-check-input" id="tm-downloads-enabled-' . $escape( $id !== '' ? $id : 'new' ) . '" name="enabled" type="checkbox" value="1"' . ( $enabled ? ' checked' : '' ) . '><label class="form-check-label" for="tm-downloads-enabled-' . $escape( $id !== '' ? $id : 'new' ) . '">Enabled</label></div></div>';
        $html .= '<div class="col-12 col-lg-2"><button class="btn btn-primary w-100" type="submit">' . $escape( $button_label ) . '</button></div>';
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

        $html = '<form method="post" action="' . $escape( $file_upload_url ) . '" enctype="multipart/form-data" class="mt-3 p-3 rounded-3 bg-body-tertiary" data-tm-downloads-upload-form data-tm-downloads-max-file-bytes="209715200">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $html .= '<input type="hidden" name="catalogue_id" value="' . $escape( $id ) . '">';
        $html .= '<div class="row g-3 align-items-end">';
        $html .= '<div class="col-12 col-xl-3"><label class="form-label small mb-1">Files</label><input class="form-control" name="download_file[]" type="file" multiple required></div>';
        $html .= '<div class="col-12 col-xl-3"><label class="form-label small mb-1">Title</label><input class="form-control" name="title" type="text" placeholder="Use filename"></div>';
        $html .= '<div class="col-12 col-xl-3"><label class="form-label small mb-1">Description</label><input class="form-control" name="description" type="text"></div>';
        $html .= '<div class="col-6 col-xl-1"><div class="form-check mb-2"><input class="form-check-input" id="tm-downloads-file-enabled-' . $escape( $id ) . '" name="enabled" type="checkbox" value="1" checked><label class="form-check-label" for="tm-downloads-file-enabled-' . $escape( $id ) . '">Enabled</label></div></div>';
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

        $html = '<div class="d-grid gap-2 mt-3">';
        foreach ( $files as $file ) {
            $last_downloaded = trim( (string) ( $file['last_downloaded_at_utc'] ?? '' ) ) !== ''
                ? $date_formatter->formatUtcDateTime( (string) $file['last_downloaded_at_utc'] )
                : '';
            $file_date = trim( (string) ( $file['file_date_utc'] ?? '' ) ) !== ''
                ? $date_formatter->formatUtcDateTime( (string) $file['file_date_utc'] )
                : '';
            $file_id = (string) ( $file['id'] ?? '' );
            $public_url = (string) ( $file['public_url'] ?? '' );
            $download_count = (int) ( $file['download_count'] ?? 0 );
            $html .= '<article class="p-2 rounded-3 bg-body-tertiary">';
            $html .= '<form method="post" action="' . $escape( $file_save_url ) . '" data-tm-downloads-file-form="1" data-tm-downloads-save-url="' . $escape( $file_save_json_url ) . '" class="row g-2 align-items-start">';
            $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="id" value="' . $escape( $file_id ) . '">';
            $html .= '<div class="col-12 col-xl-5"><label class="form-label small mb-1">Title</label><input class="form-control form-control-sm" name="title" type="text" value="' . $escape( (string) ( $file['title'] ?? '' ) ) . '"><label class="form-label small mt-2 mb-1">Description</label><textarea class="form-control form-control-sm" name="description" rows="2">' . $escape( (string) ( $file['description'] ?? '' ) ) . '</textarea></div>';
            $html .= '<div class="col-12 col-xl-4"><div class="small text-body-secondary mb-2 text-truncate" title="' . $escape( (string) ( $file['original_filename'] ?? '' ) ) . '">' . $escape( (string) ( $file['original_filename'] ?? '' ) ) . '</div><div class="small text-body-secondary d-flex flex-wrap gap-2 mb-2"><span>' . $escape( (string) ( $file['size_label'] ?? '' ) ) . '</span>' . ( $file_date !== '' ? '<span class="text-nowrap">' . $escape( $file_date ) . '</span>' : '' ) . '<span class="text-nowrap">' . $download_count . ' downloads</span>' . ( $last_downloaded !== '' ? '<span class="text-nowrap" title="' . $escape( $last_downloaded ) . '">last ' . $escape( $last_downloaded ) . '</span>' : '' ) . '</div><label class="form-label small mb-1">File date</label><input class="form-control form-control-sm" name="file_date" type="datetime-local" value="' . $escape( (string) ( $file['file_date_input'] ?? '' ) ) . '"><div class="form-check mt-2"><input class="form-check-input" id="tm-download-file-enabled-' . $escape( $file_id ) . '" name="enabled" type="checkbox" value="1"' . ( ! empty( $file['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label small" for="tm-download-file-enabled-' . $escape( $file_id ) . '">Enabled</label></div></div>';
            $html .= '<div class="col-12 col-xl-3 text-xl-end"><div class="d-flex flex-wrap gap-2 justify-content-xl-end"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $public_url ) . '" title="' . $escape( $public_url ) . '" aria-label="Open public file URL"><span class="bi bi-question-circle" aria-hidden="true"></span></a><button class="btn btn-outline-primary btn-sm" type="submit" data-tm-downloads-ajax-save="1">Save</button><button class="btn btn-outline-danger btn-sm" type="submit" formaction="' . $escape( $file_delete_url ) . '">Delete</button></div><div class="small text-body-secondary mt-2" data-tm-downloads-save-status aria-live="polite"></div></div>';
            $html .= '</form>';
            $html .= '</article>';
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

    $render_admin_page = static function( array $notice = [] ) use ( $plugins, $security, $downloads_service, $downloads_admin_url, $build_catalogue_form, $build_upload_form, $build_files_html, $build_admin_script, $escape, $current_username ) : void {
        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'downloads.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }

        $is_superadmin = $security->isSuperAdmin();
        $username = $current_username();
        $catalogues = $downloads_service->listAccessibleCatalogues( $is_superadmin, $username );

        $body_html = '<div class="d-grid gap-4">';
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
        $body_html .= $build_catalogue_form( [ 'enabled' => true ], 'New download folder', 'Create folder' );
        $body_html .= '</section>';
        $body_html .= '<section class="d-grid gap-3">';
        $body_html .= '<div><div class="small text-uppercase text-body-secondary mb-1">Download folders</div><div class="text-body-secondary small">Use folder URLs in posts/pages directly; file URLs are routed and counted.</div></div>';

        if ( empty( $catalogues ) ) {
            $body_html .= '<div class="p-3 rounded-3 bg-body-secondary text-body-secondary">No download folders have been created yet.</div>';
        }

        foreach ( $catalogues as $catalogue ) {
            $body_html .= '<article class="p-3 bg-body-secondary rounded-3">';
            $body_html .= '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">';
            $body_html .= '<div><div class="fw-semibold">' . $escape( (string) ( $catalogue['title'] ?? '' ) ) . '</div><div class="small text-body-secondary"><code>' . $escape( (string) ( $catalogue['public_url'] ?? '' ) ) . '</code></div></div>';
            $body_html .= '<div class="d-flex flex-wrap gap-2 align-items-center"><span class="badge text-bg-' . ( ! empty( $catalogue['enabled'] ) ? 'success' : 'secondary' ) . '">' . ( ! empty( $catalogue['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</span><span class="badge text-bg-secondary">' . $escape( (string) ( $catalogue['scope'] ?? 'root' ) ) . '</span></div>';
            $body_html .= '</div>';
            $body_html .= $build_catalogue_form( $catalogue, 'Edit folder', 'Save folder' );
            $body_html .= $build_upload_form( $catalogue );
            $body_html .= $build_files_html( $catalogue );
            $body_html .= '</article>';
        }
        $body_html .= '</section></div>';
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
        $html = '<div class="tm-downloads-page tm-downloads-embed">';
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
        static function() use ( $plugins, $security, $downloads_service, $downloads_admin_url, $require_login, $current_username ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $downloads_admin_url );
                return;
            }

            try {
                $downloads_service->saveCatalogue( $data, $security->isSuperAdmin(), $current_username() );
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

            $plugins->redirect( $downloads_admin_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $file_upload_url,
        static function() use ( $plugins, $security, $downloads_service, $downloads_admin_url, $require_login, $current_username, $request_exceeds_post_limit ) : void {
            if ( ! $require_login() ) {
                return;
            }
            if ( $request_exceeds_post_limit() ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'The upload is larger than the server request limit. Upload fewer or smaller files.' ] );
                $plugins->redirect( $downloads_admin_url );
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $downloads_admin_url );
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

            $plugins->redirect( $downloads_admin_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $file_save_url,
        static function() use ( $plugins, $security, $downloads_service, $downloads_admin_url, $require_login, $current_username ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $downloads_admin_url );
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

            $plugins->redirect( $downloads_admin_url );
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
        static function() use ( $plugins, $security, $downloads_service, $downloads_admin_url, $require_login, $current_username ) : void {
            if ( ! $require_login() ) {
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'downloads.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $downloads_admin_url );
                return;
            }

            $deleted = $downloads_service->deleteFile( (string) ( $data['id'] ?? '' ), $security->isSuperAdmin(), $current_username() );
            $security->setFlash(
                'downloads.notice',
                $deleted
                    ? [ 'type' => 'success', 'message' => 'Download file deleted.' ]
                    : [ 'type' => 'warning', 'message' => 'That file could not be found.' ]
            );
            $plugins->redirect( $downloads_admin_url );
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
