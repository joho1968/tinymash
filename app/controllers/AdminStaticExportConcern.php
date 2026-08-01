<?php
namespace app\controllers;

trait AdminStaticExportConcern {

    public function generateProfileStaticExport() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        $data = $this->app->request()->data->getData();
        $data = is_array( $data ) ? $data : [];
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setProfileNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getProfileSectionUrl( 'static-export' ) );
            return;
        }

        $username = strtolower( trim( (string) ( $this->security->getCurrentUsername() ?? '' ) ) );
        $base_url = trim( (string) ( $data['base_url'] ?? '' ) );
        try {
            $service = $this->getStaticExportService();
            if ( $service === null ) {
                throw new \RuntimeException( 'Static export is unavailable.' );
            }
            $artifact = $service->createAuthorArtifact( $username, $username, [ 'base_url' => $base_url ] );
            $this->setProfileNoticeFlash( 'success', 'Static export generated: ' . (string) ( $artifact['download_filename'] ?? 'archive.zip' ) . '.' );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to generate author static export (' . $e->getMessage() . ')' );
            $this->setProfileNoticeFlash( 'danger', 'Your static export could not be generated right now.' );
        }

        $this->app->redirect( $this->getProfileSectionUrl( 'static-export' ) );
    }

    public function generateSystemStaticExport() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }
        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'current_username' => $this->security->getCurrentUsername(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'current_location' => 'forbidden',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        $data = is_array( $data ) ? $data : [];
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'static-export' ) );
            return;
        }

        $username = strtolower( trim( (string) ( $this->security->getCurrentUsername() ?? '' ) ) );
        $excluded_authors = is_array( $data['exclude_authors'] ?? null ) ? $data['exclude_authors'] : [];
        $base_url = trim( (string) ( $data['base_url'] ?? '' ) );
        try {
            $service = $this->getStaticExportService();
            if ( $service === null ) {
                throw new \RuntimeException( 'Static export is unavailable.' );
            }
            $artifact = $service->createSiteArtifact(
                $username,
                [
                    'base_url' => $base_url,
                    'exclude_authors' => $excluded_authors,
                ]
            );
            $this->setSystemNoticeFlash( 'success', 'Static site export generated: ' . (string) ( $artifact['download_filename'] ?? 'archive.zip' ) . '.' );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to generate site static export (' . $e->getMessage() . ')' );
            $this->setSystemNoticeFlash( 'danger', 'The static site export could not be generated right now.' );
        }

        $this->app->redirect( $this->getSystemSectionUrl( 'static-export' ) );
    }

    public function downloadStaticExport( string $artifact_id ) : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        $artifact_service = $this->getStaticExportArtifactService();
        $record = $artifact_service !== null
            ? $artifact_service->getArtifactForDownload( $artifact_id, (string) ( $this->security->getCurrentUsername() ?? '' ), $this->security->isSuperAdmin() )
            : null;
        if ( ! is_array( $record ) ) {
            $this->app->notFound();
            return;
        }

        $archive_path = (string) ( $record['resolved_archive_path'] ?? '' );
        $download_filename = basename( (string) ( $record['download_filename'] ?? 'tinymash-static-export.zip' ) );
        if ( $archive_path === '' || ! is_file( $archive_path ) ) {
            $this->app->notFound();
            return;
        }

        if ( ! headers_sent() ) {
            header( 'Content-Type: application/zip' );
            header( 'Content-Disposition: attachment; filename="' . addcslashes( $download_filename, '"\\' ) . '"' );
            header( 'Content-Length: ' . (string) filesize( $archive_path ) );
            header( 'Cache-Control: private, no-store, max-age=0' );
            header( 'X-Content-Type-Options: nosniff' );
        }
        readfile( $archive_path );
    }

    public function deleteProfileStaticExport() : void {
        $this->deleteStaticExportAndRedirect( $this->getProfileSectionUrl( 'static-export' ), 'profile' );
    }

    public function deleteSystemStaticExport() : void {
        $this->deleteStaticExportAndRedirect( $this->getSystemSectionUrl( 'static-export' ), 'system' );
    }

    protected function deleteStaticExportAndRedirect( string $redirect_url, string $notice_scope ) : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        $data = $this->app->request()->data->getData();
        $data = is_array( $data ) ? $data : [];
        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setStaticExportNoticeFlash( $notice_scope, 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $redirect_url );
            return;
        }

        $artifact_id = trim( (string) ( $data['artifact_id'] ?? '' ) );
        $artifact_service = $this->getStaticExportArtifactService();
        $deleted = $artifact_service !== null
            && $artifact_service->deleteArtifact( $artifact_id, (string) ( $this->security->getCurrentUsername() ?? '' ), $this->security->isSuperAdmin() );
        $this->setStaticExportNoticeFlash(
            $notice_scope,
            $deleted ? 'success' : 'danger',
            $deleted ? 'Static export deleted.' : 'That static export could not be deleted.'
        );
        $this->app->redirect( $redirect_url );
    }

    protected function getProfileStaticExportViewData() : array {
        $artifact_service = $this->getStaticExportArtifactService();
        $username = strtolower( trim( (string) ( $this->security->getCurrentUsername() ?? '' ) ) );
        return(
            [
                'available' => $this->getStaticExportService() !== null && $artifact_service !== null && $artifact_service->isAvailable(),
                'base_url' => (string) $this->config->configGetBaseURL(),
                'generate_url' => $this->app->get( 'admin.url' ) . '/profile/static-export/generate',
                'delete_url' => $this->app->get( 'admin.url' ) . '/profile/static-export/delete',
                'download_url_base' => $this->app->get( 'admin.url' ) . '/static-export',
                'artifacts' => $artifact_service !== null ? $this->formatStaticExportArtifactRows( $artifact_service->listArtifacts( $username, false ) ) : [],
            ]
        );
    }

    protected function getSystemStaticExportViewData() : array {
        $artifact_service = $this->getStaticExportArtifactService();
        $service = $this->getStaticExportService();
        return(
            [
                'available' => $service !== null && $artifact_service !== null && $artifact_service->isAvailable(),
                'base_url' => (string) $this->config->configGetBaseURL(),
                'authors' => $service !== null ? $service->getExportableAuthors() : [],
                'generate_url' => $this->app->get( 'admin.url' ) . '/system/static-export/generate',
                'delete_url' => $this->app->get( 'admin.url' ) . '/system/static-export/delete',
                'download_url_base' => $this->app->get( 'admin.url' ) . '/static-export',
                'artifacts' => $artifact_service !== null ? $this->formatStaticExportArtifactRows( $artifact_service->listArtifacts( null, true ) ) : [],
            ]
        );
    }

    protected function formatStaticExportArtifactRows( array $artifacts ) : array {
        $rows = [];
        foreach ( $artifacts as $artifact ) {
            if ( ! is_array( $artifact ) ) {
                continue;
            }

            $report = is_array( $artifact['report'] ?? null ) ? $artifact['report'] : [];
            $warnings = is_array( $report['warnings'] ?? null ) ? $report['warnings'] : [];
            $rows[] = [
                'id' => (string) ( $artifact['id'] ?? '' ),
                'scope' => (string) ( $artifact['scope'] ?? '' ),
                'owner_username' => (string) ( $artifact['owner_username'] ?? '' ),
                'author_slug' => (string) ( $artifact['author_slug'] ?? '' ),
                'download_filename' => (string) ( $artifact['download_filename'] ?? '' ),
                'size_label' => $this->formatStaticExportBytes( (int) ( $artifact['size_bytes'] ?? 0 ) ),
                'created_at' => (string) ( $artifact['created_at_utc'] ?? '' ),
                'expires_at' => (string) ( $artifact['expires_at_utc'] ?? '' ),
                'route_count' => (int) ( $report['route_count'] ?? 0 ),
                'warning_count' => count( $warnings ),
            ];
        }

        return( $rows );
    }

    protected function formatStaticExportBytes( int $bytes ) : string {
        if ( $bytes >= 1073741824 ) {
            return( number_format( $bytes / 1073741824, 1 ) . ' GB' );
        }
        if ( $bytes >= 1048576 ) {
            return( number_format( $bytes / 1048576, 1 ) . ' MB' );
        }
        if ( $bytes >= 1024 ) {
            return( number_format( $bytes / 1024, 1 ) . ' KB' );
        }

        return( $bytes . ' B' );
    }

    protected function setStaticExportNoticeFlash( string $scope, string $type, string $message ) : void {
        if ( $scope === 'system' ) {
            $this->setSystemNoticeFlash( $type, $message );
            return;
        }

        $this->setProfileNoticeFlash( $type, $message );
    }

    protected function getStaticExportService() : ?\app\classes\TinyMashStaticExportService {
        if ( ! $this->app->has( 'static_export.service' ) ) {
            return( null );
        }

        $service = $this->app->get( 'static_export.service' );
        return( $service instanceof \app\classes\TinyMashStaticExportService ? $service : null );
    }

    protected function getStaticExportArtifactService() : ?\app\classes\TinyMashStaticExportArtifactService {
        if ( ! $this->app->has( 'static_export.artifacts' ) ) {
            return( null );
        }

        $service = $this->app->get( 'static_export.artifacts' );
        return( $service instanceof \app\classes\TinyMashStaticExportArtifactService ? $service : null );
    }
}
