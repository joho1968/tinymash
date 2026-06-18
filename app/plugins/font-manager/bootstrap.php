<?php

require_once __DIR__ . '/TinyMashFontManagerService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'font-manager' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity ) {
        throw new \RuntimeException( 'Required services for the Font manager plugin are not available.' );
    }

    $service = new TinyMashFontManagerService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'font-manager' . DIRECTORY_SEPARATOR . 'settings.json',
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'font-manager' . DIRECTORY_SEPARATOR . 'files'
    );
    \Flight::set( 'plugin.font_manager.service', $service );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    if ( $service->getGeneratedCss() !== '' ) {
        $plugins->registerPublicAsset( $plugin_key, 'css', '/plugin-font-manager/fonts.css' );
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/font-manager';
    $assignments_url = $plugin_url . '/assignments/save';
    $upload_url = $plugin_url . '/font/upload';
    $delete_family_url = $plugin_url . '/family/delete';
    $save_file_url = $plugin_url . '/file/save';
    $delete_file_url = $plugin_url . '/file/delete';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'font-manager',
            'label' => 'Font manager',
            'url' => $plugin_url,
            'icon' => 'bi-type',
            'order' => 83,
        ]
    );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-font-manager',
        [
            'title' => 'Font manager',
            'summary' => 'Manage local font files and assign them to public theme roles.',
            'group' => 'Plugins',
            'order' => 146,
            'roles' => [ 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'Font manager stores WOFF2/WOFF files locally, emits safe `@font-face` CSS, and assigns families to supported public theme roles.',
                ],
                [
                    'title' => 'Font roles',
                    'markdown' => '`Publication titles` control the site/author identity title treatment in supported themes. `Headings` control content headings and story/listing headings. Body text, UI/navigation, and monospace/code are separate roles so themes can keep readable fallbacks and their own spacing/scale.',
                ],
                [
                    'title' => 'Display',
                    'markdown' => 'The Display setting is the CSS `font-display` value. `swap` shows fallback text immediately and swaps to the custom font when ready. `auto` lets the browser decide. `block` can briefly hide text. `fallback` gives the custom font a short chance before keeping fallback text. `optional` uses the custom font only if it loads very quickly. This setting is not stored in the font file.',
                ],
                [
                    'title' => 'Boundaries',
                    'markdown' => 'It does not bundle fonts, fetch remote font CSS, load CDN assets, or provide arbitrary CSS editing. Only upload fonts you have the right to use and serve.',
                ],
            ],
        ]
    );

    $escape = static fn( string $value ) : string => htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    $clear_cache = static function() use ( $project_root ) : void {
        ( new TinyMashPublicPageCache( $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'public-page-cache' ) )->clear();
    };
    $require_admin = static function() use ( $plugins, $security, $login_url ) : bool {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return( false );
        }
        if ( ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            $plugins->renderAdminPage(
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'current_section' => 'font-manager',
                    'plugin_page_kicker' => 'Font manager',
                    'plugin_page_title' => 'Forbidden',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You do not have permission to manage fonts.' ],
                    'plugin_page_body_html' => '',
                ]
            );
            return( false );
        }
        return( true );
    };
    $flash_notice = static function( array $notice ) use ( $security ) : void {
        $security->setFlash( 'font-manager.notice', $notice );
    };
    $validate_post = static function( array $data ) use ( $security ) : bool {
        return( $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) );
    };
    $format_size = static function( int $bytes ) : string {
        if ( $bytes >= 1048576 ) {
            return( number_format( $bytes / 1048576, 1 ) . ' MB' );
        }
        if ( $bytes >= 1024 ) {
            return( number_format( $bytes / 1024, 1 ) . ' KB' );
        }
        return( (string) $bytes . ' B' );
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

    $render_page = static function( array $notice = [] ) use ( $plugins, $security, $service, $plugin_url, $assignments_url, $upload_url, $delete_family_url, $save_file_url, $delete_file_url, $escape, $format_size, $render_select ) : void {
        if ( empty( $notice ) ) {
            $notice = $security->pullFlash( 'font-manager.notice', [] );
            if ( ! is_array( $notice ) ) {
                $notice = [];
            }
        }
        $settings = $service->getSettings();
        $families = is_array( $settings['families'] ?? null ) ? $settings['families'] : [];
        $assignments = is_array( $settings['assignments'] ?? null ) ? $settings['assignments'] : [];

        $family_options = [ '' => 'Theme default' ];
        $existing_family_options = [ '' => 'New family' ];
        foreach ( $families as $family ) {
            if ( empty( $family['files'] ) ) {
                continue;
            }
            $family_options[(string) $family['id']] = (string) $family['name'];
            $existing_family_options[(string) $family['id']] = (string) $family['name'];
        }

        $font_face_css = $service->getFontFaceCss();
        $body_html = $font_face_css !== '' ? '<style>' . $font_face_css . '</style>' : '';
        $body_html .= '<div class="d-grid gap-4">';
        $body_html .= '<section class="rounded-3 p-3 bg-body-secondary">';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Font roles</div>';
        $body_html .= '<form method="post" action="' . $escape( $assignments_url ) . '">';
        $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $body_html .= '<div class="row g-3 align-items-end">';
        foreach ( $service->getRoles() as $role => $label ) {
            $body_html .= '<div class="col-12 col-lg-6 col-xxl-4"><label class="form-label small mb-1" for="tm-font-role-' . $escape( $role ) . '">' . $escape( (string) $label ) . '</label>';
            $body_html .= $render_select( 'assignments[' . $role . ']', (string) ( $assignments[$role] ?? '' ), $family_options, 'tm-font-role-' . $role );
            $body_html .= '</div>';
        }
        $body_html .= '<div class="col-12 col-lg-6 col-xxl-4"><button class="btn btn-primary" type="submit">Save font roles</button></div>';
        $body_html .= '</div>';
        $body_html .= '</form></section>';

        $body_html .= '<section class="rounded-3 p-3 bg-body-secondary">';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Add local font</div>';
        $body_html .= '<form method="post" action="' . $escape( $upload_url ) . '" enctype="multipart/form-data" class="d-grid gap-3">';
        $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $body_html .= '<div class="row g-3 align-items-end">';
        $body_html .= '<div class="col-12 col-lg-4"><label class="form-label small mb-1" for="tm-font-family-id">Existing family</label>' . $render_select( 'family_id', '', $existing_family_options, 'tm-font-family-id' ) . '</div>';
        $body_html .= '<div class="col-12 col-lg-4"><label class="form-label small mb-1" for="tm-font-family-name">New family name</label><input class="form-control" id="tm-font-family-name" name="family_name" type="text" maxlength="80" placeholder="Example Sans"></div>';
        $body_html .= '<div class="col-12 col-lg-4"><label class="form-label small mb-1" for="tm-font-file">Font file</label><input class="form-control" id="tm-font-file" name="font_file" type="file" accept=".woff2,.woff,font/woff2,font/woff"></div>';
        $body_html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="tm-font-weight">Weight</label><input class="form-control" id="tm-font-weight" name="weight" type="number" min="100" max="900" step="100" placeholder="Auto"></div>';
        $body_html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="tm-font-style">Style</label>' . $render_select( 'style', '', [ '' => 'Auto', 'normal' => 'Normal', 'italic' => 'Italic' ], 'tm-font-style' ) . '</div>';
        $body_html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="tm-font-display">Display</label>' . $render_select( 'display', 'swap', [ 'swap' => 'swap', 'auto' => 'auto', 'block' => 'block', 'fallback' => 'fallback', 'optional' => 'optional' ], 'tm-font-display' ) . '</div>';
        $body_html .= '<div class="col-12 col-lg-5"><button class="btn btn-primary" type="submit">Upload font file</button></div>';
        $body_html .= '</div>';
        $body_html .= '<div class="form-text">Only WOFF2 and WOFF are accepted. Weight/style are inferred when possible; use the fields above to override. Display is a CSS loading choice and cannot be read from the font file.</div>';
        $body_html .= '</form></section>';

        $body_html .= '<section class="pt-3 border-top">';
        $body_html .= '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><div class="small text-uppercase text-body-secondary mb-2">Installed families</div><h2 class="h5 mb-0">Font families</h2></div><span class="badge text-bg-secondary">' . count( $families ) . '</span></div>';
        if ( empty( $families ) ) {
            $body_html .= '<p class="text-body-secondary mb-0">No local fonts have been uploaded yet.</p>';
        } else {
            $body_html .= '<div class="d-grid gap-4">';
            foreach ( $families as $family ) {
                $family_id = (string) ( $family['id'] ?? '' );
                $body_html .= '<article class="pt-3 border-top">';
                $body_html .= '<div class="d-flex justify-content-between align-items-start gap-2 mb-2"><div><h3 class="h6 mb-1">' . $escape( (string) ( $family['name'] ?? '' ) ) . '</h3><div class="small text-body-secondary font-monospace">' . $escape( $family_id ) . '</div></div>';
                $body_html .= '<form method="post" action="' . $escape( $delete_family_url ) . '" data-tm-font-manager-confirm data-tm-font-manager-confirm-title="Delete font family?" data-tm-font-manager-confirm-message="Delete this font family and its files?" data-tm-font-manager-confirm-label="Delete family" data-tm-font-manager-confirm-class="btn-danger"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="family_id" value="' . $escape( $family_id ) . '"><button class="btn btn-sm btn-outline-danger" type="submit">Delete family</button></form></div>';
                $body_html .= '<div class="mb-3 p-2 bg-body-secondary rounded" style="font-family:&quot;' . $escape( $service->getCssFamilyName( $family_id ) ) . '&quot;, var(--bs-body-font-family);">The quick brown fox jumps over the lazy dog.</div>';
                $desktop_rows = '';
                $mobile_rows = '';
                $file_modals = '';
                foreach ( is_array( $family['files'] ?? null ) ? $family['files'] : [] as $font_file ) {
                    $font_file_id = (string) ( $font_file['id'] ?? '' );
                    $modal_id = 'tm-font-manager-file-edit-' . $family_id . '-' . $service->getCssFamilyName( $font_file_id );
                    $font_filename = (string) ( $font_file['original_filename'] ?? '' );
                    $font_format_size = $escape( strtoupper( (string) ( $font_file['format'] ?? '' ) ) ) . ' · ' . $escape( $format_size( (int) ( $font_file['size'] ?? 0 ) ) );
                    $file_actions_html = '<div class="d-inline-flex gap-2 align-items-center"><button class="btn btn-sm btn-outline-secondary text-nowrap" type="button" data-bs-toggle="modal" data-bs-target="#' . $escape( $modal_id ) . '">Edit</button><form class="m-0" method="post" action="' . $escape( $delete_file_url ) . '" data-tm-font-manager-confirm data-tm-font-manager-confirm-title="Delete font file?" data-tm-font-manager-confirm-message="Delete this font file?" data-tm-font-manager-confirm-label="Delete" data-tm-font-manager-confirm-class="btn-danger"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="family_id" value="' . $escape( $family_id ) . '"><input type="hidden" name="file_id" value="' . $escape( $font_file_id ) . '"><button class="btn btn-sm btn-outline-danger text-nowrap" type="submit">Delete</button></form></div>';
                    $desktop_rows .= '<tr><td class="w-50" style="max-width: 16rem;"><div class="fw-semibold text-truncate" title="' . $escape( $font_filename ) . '">' . $escape( $font_filename ) . '</div><div class="small text-body-secondary">' . $font_format_size . '</div></td>';
                    $desktop_rows .= '<td>' . (int) ( $font_file['weight'] ?? 400 ) . '</td><td>' . $escape( (string) ( $font_file['style'] ?? 'normal' ) ) . '</td><td>' . $escape( (string) ( $font_file['display'] ?? 'swap' ) ) . '</td>';
                    $desktop_rows .= '<td class="text-end text-nowrap">' . $file_actions_html . '</td></tr>';
                    $mobile_rows .= '<div class="list-group-item px-0 bg-transparent"><div class="d-grid gap-2"><div class="overflow-hidden"><div class="fw-semibold text-truncate" title="' . $escape( $font_filename ) . '">' . $escape( $font_filename ) . '</div><div class="small text-body-secondary">' . $font_format_size . '</div></div><div class="d-flex flex-wrap gap-2 small text-body-secondary"><span>Weight <span class="text-body">' . (int) ( $font_file['weight'] ?? 400 ) . '</span></span><span>Style <span class="text-body">' . $escape( (string) ( $font_file['style'] ?? 'normal' ) ) . '</span></span><span>Display <span class="text-body">' . $escape( (string) ( $font_file['display'] ?? 'swap' ) ) . '</span></span></div><div>' . $file_actions_html . '</div></div></div>';
                    $file_modals .= '<div class="modal fade" id="' . $escape( $modal_id ) . '" tabindex="-1" aria-labelledby="' . $escape( $modal_id ) . '-title" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="' . $escape( $modal_id ) . '-title">Edit font file</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><form method="post" action="' . $escape( $save_file_url ) . '" class="d-grid gap-3"><input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '"><input type="hidden" name="family_id" value="' . $escape( $family_id ) . '"><input type="hidden" name="file_id" value="' . $escape( $font_file_id ) . '"><div><div class="small text-body-secondary mb-1">File</div><div class="fw-semibold text-break">' . $escape( (string) ( $font_file['original_filename'] ?? '' ) ) . '</div></div><div><label class="form-label small mb-1" for="' . $escape( $modal_id ) . '-weight">Weight</label><input class="form-control" id="' . $escape( $modal_id ) . '-weight" name="weight" type="number" min="100" max="900" step="100" value="' . (int) ( $font_file['weight'] ?? 400 ) . '"></div><div><label class="form-label small mb-1" for="' . $escape( $modal_id ) . '-style">Style</label>' . $render_select( 'style', (string) ( $font_file['style'] ?? 'normal' ), [ 'normal' => 'Normal', 'italic' => 'Italic' ], $modal_id . '-style' ) . '</div><div><label class="form-label small mb-1" for="' . $escape( $modal_id ) . '-display">Display</label>' . $render_select( 'display', (string) ( $font_file['display'] ?? 'swap' ), [ 'swap' => 'swap', 'auto' => 'auto', 'block' => 'block', 'fallback' => 'fallback', 'optional' => 'optional' ], $modal_id . '-display' ) . '<div class="form-text">Display controls browser font-loading behavior and is not stored in the font file.</div></div><div class="d-flex justify-content-end gap-2"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save font file</button></div></form></div></div></div></div>';
                }
                $body_html .= '<div class="table-responsive d-none d-md-block"><table class="table table-sm align-middle mb-0"><thead><tr><th>File</th><th>Weight</th><th>Style</th><th>Display</th><th></th></tr></thead><tbody>' . $desktop_rows . '</tbody></table></div>';
                $body_html .= '<div class="list-group list-group-flush d-md-none border-top">' . $mobile_rows . '</div>';
                $body_html .= $file_modals;
                $body_html .= '</article>';
            }
            $body_html .= '</div>';
        }
        $body_html .= '</section></div>';
        $body_html .= '<div class="modal fade" id="tm-font-manager-confirm-modal" tabindex="-1" aria-labelledby="tm-font-manager-confirm-modal-title" aria-describedby="tm-font-manager-confirm-modal-message" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="tm-font-manager-confirm-modal-title">Confirm action</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="mb-0" id="tm-font-manager-confirm-modal-message">Confirm this action.</p></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="button" data-tm-font-manager-confirm-submit>Confirm</button></div></div></div></div>';
        $body_html .= <<<'HTML'
<script>
document.addEventListener("DOMContentLoaded", function () {
    var modalElement = document.getElementById("tm-font-manager-confirm-modal");
    var submitButton = modalElement ? modalElement.querySelector("[data-tm-font-manager-confirm-submit]") : null;
    var titleElement = modalElement ? modalElement.querySelector("#tm-font-manager-confirm-modal-title") : null;
    var messageElement = modalElement ? modalElement.querySelector("#tm-font-manager-confirm-modal-message") : null;
    var activeForm = null;

    document.querySelectorAll("[data-tm-font-manager-confirm]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            if (form.getAttribute("data-tm-font-manager-confirmed") === "1") {
                return;
            }
            event.preventDefault();
            if (!modalElement || !submitButton || !window.bootstrap || !window.bootstrap.Modal) {
                form.setAttribute("data-tm-font-manager-confirmed", "1");
                form.submit();
                return;
            }
            activeForm = form;
            if (titleElement) {
                titleElement.textContent = form.getAttribute("data-tm-font-manager-confirm-title") || "Confirm action";
            }
            if (messageElement) {
                messageElement.textContent = form.getAttribute("data-tm-font-manager-confirm-message") || "Confirm this action.";
            }
            submitButton.textContent = form.getAttribute("data-tm-font-manager-confirm-label") || "Confirm";
            submitButton.className = "btn " + (form.getAttribute("data-tm-font-manager-confirm-class") || "btn-danger");
            window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
        });
    });

    if (submitButton) {
        submitButton.addEventListener("click", function () {
            if (!activeForm) {
                return;
            }
            activeForm.setAttribute("data-tm-font-manager-confirmed", "1");
            activeForm.submit();
        });
    }
});
</script>
HTML;

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Font manager',
                'current_section' => 'font-manager',
                'current_location' => 'font-manager',
                'plugin_page_kicker' => 'Font manager',
                'plugin_page_title' => 'Font manager',
                'plugin_page_summary' => 'Local font files and public theme typography roles.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
                'help_contexts' => [ 'plugin-font-manager' ],
            ]
        );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/plugin-font-manager/fonts.css',
        static function() use ( $service ) : void {
            $css = $service->getGeneratedCss();
            if ( $css === '' ) {
                \Flight::app()->notFound();
                return;
            }
            $etag = '"' . sha1( $css ) . '"';
            if ( trim( (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) ) === $etag ) {
                \Flight::app()->response()->status( 304 );
                header( 'ETag: ' . $etag );
                header( 'Cache-Control: no-store' );
                return;
            }
            header( 'Content-Type: text/css; charset=utf-8' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Cache-Control: no-store' );
            header( 'ETag: ' . $etag );
            echo $css;
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/plugin-font-manager/font/@file_id',
        static function( string $file_id ) use ( $service ) : void {
            $font_file = $service->findFontFile( $file_id );
            $path = (string) ( $font_file['path'] ?? '' );
            if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
                \Flight::app()->notFound();
                return;
            }
            $format = strtolower( (string) ( $font_file['format'] ?? '' ) );
            header( 'Content-Type: ' . ( $format === 'woff2' ? 'font/woff2' : 'font/woff' ) );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Cache-Control: public, max-age=31536000' );
            header( 'Content-Length: ' . (string) filesize( $path ) );
            readfile( $path );
        }
    );

    $plugins->registerRoute( $plugin_key, 'get', $plugin_url, static function() use ( $require_admin, $render_page ) : void {
        if ( ! $require_admin() ) {
            return;
        }
        $render_page();
    } );

    $plugins->registerRoute( $plugin_key, 'post', $assignments_url, static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $flash_notice, $clear_cache ) : void {
        if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            return;
        }
        $data = $plugins->getRequestData();
        if ( ! $validate_post( $data ) ) {
            $flash_notice( [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            $plugins->redirect( $plugin_url );
            return;
        }
        $service->saveAssignments( $data );
        $clear_cache();
        $flash_notice( [ 'type' => 'success', 'message' => 'Font roles saved.' ] );
        $plugins->redirect( $plugin_url );
    } );

    $plugins->registerRoute( $plugin_key, 'post', $upload_url, static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $flash_notice, $clear_cache ) : void {
        if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            return;
        }
        $data = $plugins->getRequestData();
        if ( ! $validate_post( $data ) ) {
            $flash_notice( [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            $plugins->redirect( $plugin_url );
            return;
        }
        $result = $service->addUploadedFont( is_array( $_FILES['font_file'] ?? null ) ? $_FILES['font_file'] : [], $data );
        $clear_cache();
        $flash_notice( [ 'type' => ! empty( $result['ok'] ) ? 'success' : 'danger', 'message' => (string) ( $result['message'] ?? 'Font upload finished.' ) ] );
        $plugins->redirect( $plugin_url );
    } );

    $plugins->registerRoute( $plugin_key, 'post', $save_file_url, static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $flash_notice, $clear_cache ) : void {
        if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            return;
        }
        $data = $plugins->getRequestData();
        if ( ! $validate_post( $data ) ) {
            $flash_notice( [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            $plugins->redirect( $plugin_url );
            return;
        }
        $result = $service->updateFontFileMetadata( (string) ( $data['family_id'] ?? '' ), (string) ( $data['file_id'] ?? '' ), $data );
        $clear_cache();
        $flash_notice( [ 'type' => ! empty( $result['ok'] ) ? 'success' : 'danger', 'message' => (string) ( $result['message'] ?? 'Font file save finished.' ) ] );
        $plugins->redirect( $plugin_url );
    } );

    $plugins->registerRoute( $plugin_key, 'post', $delete_family_url, static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $flash_notice, $clear_cache ) : void {
        if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            return;
        }
        $data = $plugins->getRequestData();
        if ( ! $validate_post( $data ) ) {
            $flash_notice( [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            $plugins->redirect( $plugin_url );
            return;
        }
        $result = $service->deleteFamily( (string) ( $data['family_id'] ?? '' ) );
        $clear_cache();
        $flash_notice( [ 'type' => ! empty( $result['ok'] ) ? 'success' : 'danger', 'message' => (string) ( $result['message'] ?? 'Font family delete finished.' ) ] );
        $plugins->redirect( $plugin_url );
    } );

    $plugins->registerRoute( $plugin_key, 'post', $delete_file_url, static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $flash_notice, $clear_cache ) : void {
        if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            return;
        }
        $data = $plugins->getRequestData();
        if ( ! $validate_post( $data ) ) {
            $flash_notice( [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            $plugins->redirect( $plugin_url );
            return;
        }
        $result = $service->deleteFontFile( (string) ( $data['family_id'] ?? '' ), (string) ( $data['file_id'] ?? '' ) );
        $clear_cache();
        $flash_notice( [ 'type' => ! empty( $result['ok'] ) ? 'success' : 'danger', 'message' => (string) ( $result['message'] ?? 'Font file delete finished.' ) ] );
        $plugins->redirect( $plugin_url );
    } );
};
