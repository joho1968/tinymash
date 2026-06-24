<?php

namespace app\classes;

class TinyMashMediaUsageReporter {
    public function __construct(
        protected TinyMashContentRepository $content_repository,
        protected TinyMashDraftRepository $draft_repository,
        protected TinyMashUserRepository $user_repository,
        protected TinyMashConfig $config,
        protected TinyMashMediaService $media_service,
        protected ?TinyMashPlugins $plugins = null
    ) {}

    public function buildReport( array $media_records, string $owner_filter = '' ) : array {
        $owner_filter = strtolower( trim( $owner_filter ) );
        if ( $owner_filter === 'all' ) {
            $owner_filter = '';
        }

        $records_by_id = [];
        foreach ( $media_records as $metadata ) {
            if ( ! is_array( $metadata ) ) {
                continue;
            }
            $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
            if ( $media_id === '' ) {
                continue;
            }
            $records_by_id[$media_id] = [
                'media_id' => $media_id,
                'owner_username' => strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) ),
                'filename' => basename( trim( (string) ( $metadata['filename'] ?? ( $metadata['original_filename'] ?? '' ) ) ) ),
                'direct_markers' => $this->buildDirectMarkerLabel( $metadata ),
                'attached_entry_id' => trim( (string) ( $metadata['attached_entry_id'] ?? '' ) ),
                'attached_draft_id' => trim( (string) ( $metadata['attached_draft_id'] ?? '' ) ),
                'attachment_session_id' => trim( (string) ( $metadata['attachment_session_id'] ?? '' ) ),
                'references' => [],
            ];
        }

        $missing_references = [];
        $this->collectReferences( $records_by_id, $missing_references, $owner_filter );

        $rows = [];
        $summary = [
            'checked' => count( $records_by_id ),
            'used' => 0,
            'unreferenced' => 0,
            'direct_marker_only' => 0,
            'missing_references' => count( $missing_references ),
        ];

        foreach ( $records_by_id as $record ) {
            $references = is_array( $record['references'] ?? null ) ? $record['references'] : [];
            $reference_count = count( $references );
            $direct_markers = (string) ( $record['direct_markers'] ?? '-' );
            if ( $reference_count > 0 ) {
                $status = 'used';
                $summary['used']++;
            } elseif ( $direct_markers !== '-' ) {
                $status = 'direct_marker_only';
                $summary['direct_marker_only']++;
            } else {
                $status = 'unreferenced';
                $summary['unreferenced']++;
            }

            $categories = [];
            foreach ( $references as $reference ) {
                if ( ! is_array( $reference ) ) {
                    continue;
                }
                $category = trim( (string) ( $reference['category'] ?? 'reference' ) );
                if ( $category === '' ) {
                    $category = 'reference';
                }
                $categories[$category] = ( $categories[$category] ?? 0 ) + 1;
            }
            ksort( $categories, SORT_STRING );

            $category_labels = [];
            foreach ( $categories as $category => $count ) {
                $category_labels[] = $category . ':' . $count;
            }

            $record['status'] = $status;
            $record['status_label'] = $this->getStatusLabel( $status );
            $record['reference_count'] = $reference_count;
            $record['reference_categories'] = ! empty( $category_labels ) ? implode( ',', $category_labels ) : '-';
            $record['references'] = array_values( $references );
            $rows[] = $record;
        }

        usort(
            $rows,
            static function( array $left, array $right ) : int {
                $status_order = [ 'unreferenced' => 0, 'direct_marker_only' => 1, 'used' => 2 ];
                $left_status = $status_order[(string) ( $left['status'] ?? '' )] ?? 9;
                $right_status = $status_order[(string) ( $right['status'] ?? '' )] ?? 9;
                if ( $left_status !== $right_status ) {
                    return( $left_status <=> $right_status );
                }
                return( strcmp( (string) ( $left['media_id'] ?? '' ), (string) ( $right['media_id'] ?? '' ) ) );
            }
        );

        return(
            [
                'summary' => $summary,
                'records' => $rows,
                'records_by_id' => array_column( $rows, null, 'media_id' ),
                'missing_references' => $missing_references,
            ]
        );
    }

    protected function collectReferences( array &$records_by_id, array &$missing_references, string $owner_filter ) : void {
        $known_entry_ids = [];
        $known_draft_ids = [];

        $content_author_filter = $owner_filter !== '' && $owner_filter !== 'root' ? $owner_filter : null;
        foreach ( $this->content_repository->getEntriesForAudit( null, $content_author_filter, true, false ) as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $source = 'entry:' . (string) ( $entry['id'] ?? '' );
            if ( trim( (string) ( $entry['id'] ?? '' ) ) !== '' ) {
                $known_entry_ids[(string) $entry['id']] = true;
            }
            $label = (string) ( $entry['path'] ?? ( $entry['slug'] ?? $source ) );
            $status = (string) ( $entry['status'] ?? '' );
            $category = $status === 'published' ? 'published_content' : 'unpublished_content';
            $this->addIdReference( $records_by_id, $missing_references, (string) ( $entry['featured_image_media_id'] ?? '' ), $category, $source, 'Featured image: ' . $label, $owner_filter === '' );
            $this->addIdReference( $records_by_id, $missing_references, (string) ( $entry['seo_social_image_media_id'] ?? '' ), $category, $source, 'Social image: ' . $label, $owner_filter === '' );
            $this->collectPluginSettingsReferences( $entry['plugin_settings'] ?? [], $records_by_id, $missing_references, $category, $source, 'content ' . $label, $owner_filter === '' );
            foreach ( $this->collectEntryContents( $entry ) as $content ) {
                $this->collectUrlsFromContent( $content, $records_by_id, $missing_references, $category, $source, 'Content body image: ' . $label, $owner_filter );
            }
        }

        foreach ( $this->user_repository->getAllUsers() as $user ) {
            if ( ! is_array( $user ) ) {
                continue;
            }
            $username = strtolower( trim( (string) ( $user['username'] ?? '' ) ) );
            if ( $username === '' ) {
                continue;
            }
            foreach ( $this->draft_repository->listEditorDrafts( $username ) as $draft ) {
                if ( ! is_array( $draft ) ) {
                    continue;
                }
                $source = 'draft:' . $username . ':' . (string) ( $draft['draft_id'] ?? '' );
                if ( trim( (string) ( $draft['draft_id'] ?? '' ) ) !== '' ) {
                    $known_draft_ids[(string) $draft['draft_id']] = true;
                }
                $label = $username . '/' . (string) ( $draft['draft_id'] ?? '' );
                $this->addIdReference( $records_by_id, $missing_references, (string) ( $draft['featured_image_media_id'] ?? '' ), 'draft', $source, 'Draft featured image: ' . $label, $owner_filter === '' );
                $this->addIdReference( $records_by_id, $missing_references, (string) ( $draft['seo_social_image_media_id'] ?? '' ), 'draft', $source, 'Draft social image: ' . $label, $owner_filter === '' );
                $this->collectPluginSettingsReferences( $draft['plugin_settings'] ?? [], $records_by_id, $missing_references, 'draft', $source, 'draft ' . $label, $owner_filter === '' );
                $this->collectUrlsFromContent( (string) ( $draft['content'] ?? '' ), $records_by_id, $missing_references, 'draft', $source, 'Draft body image: ' . $label, $owner_filter );
            }
        }

        $site_images = [
            'site.banner' => [ 'image' => $this->config->getSiteBannerImage(), 'label' => 'Site banner' ],
            'site.favicon_png' => [ 'image' => $this->config->getSiteFaviconPngImage(), 'label' => 'Site favicon (PNG)' ],
            'site.favicon_ico' => [ 'image' => $this->config->getSiteFaviconIcoImage(), 'label' => 'Site favicon (ICO)' ],
            'site.og' => [ 'image' => $this->config->getSiteOgImage(), 'label' => 'Site social image' ],
            'site.background' => [ 'image' => $this->config->getSiteBackgroundImage(), 'label' => 'Site background image' ],
        ];
        foreach ( $site_images as $source => $site_image ) {
            $image = is_array( $site_image['image'] ?? null ) ? $site_image['image'] : [];
            if ( is_array( $image ) ) {
                $this->addIdReference( $records_by_id, $missing_references, (string) ( $image['media_id'] ?? '' ), 'site', $source, (string) ( $site_image['label'] ?? 'Site image' ), $owner_filter === '' );
            }
        }
        foreach ( $this->config->getPluginStates() as $plugin_key => $active ) {
            if ( ! is_string( $plugin_key ) ) {
                continue;
            }
            $this->collectIdsFromMixed( $this->config->getPluginSettings( $plugin_key ), $records_by_id, $missing_references, 'system_plugin_settings', 'system-plugin:' . $plugin_key, 'Plugin: ' . $this->formatPluginName( $plugin_key ) . ' (site settings)', $owner_filter === '' );
        }

        foreach ( $this->user_repository->getAllUsers() as $user ) {
            if ( ! is_array( $user ) ) {
                continue;
            }
            $username = strtolower( trim( (string) ( $user['username'] ?? '' ) ) );
            $source = 'user:' . ( $username !== '' ? $username : 'unknown' );
            $this->addIdReference( $records_by_id, $missing_references, (string) ( $user['public_banner_media_id'] ?? '' ), 'profile', $source, 'Profile banner: ' . $username, $owner_filter === '' );
            $this->addIdReference( $records_by_id, $missing_references, (string) ( $user['public_background_media_id'] ?? '' ), 'profile', $source, 'Profile background: ' . $username, $owner_filter === '' );
            $this->collectPluginSettingsReferences( $user['plugin_settings'] ?? [], $records_by_id, $missing_references, 'profile_plugin_settings', $source . ':plugin-settings', 'profile ' . $username, $owner_filter === '' );
        }

        if ( $this->plugins instanceof TinyMashPlugins ) {
            foreach ( $this->plugins->collectMediaUsageReferences() as $reference ) {
                if ( ! is_array( $reference ) ) {
                    continue;
                }
                $this->addIdReference(
                    $records_by_id,
                    $missing_references,
                    (string) ( $reference['media_id'] ?? '' ),
                    (string) ( $reference['category'] ?? 'system_plugin_settings' ),
                    (string) ( $reference['source'] ?? 'plugin-reference' ),
                    (string) ( $reference['label'] ?? 'Plugin media' ),
                    $owner_filter === ''
                );
            }
        }

        $this->collectDirectMarkerReferences( $records_by_id, $known_entry_ids, $known_draft_ids );
    }

    protected function collectDirectMarkerReferences( array &$records_by_id, array $known_entry_ids, array $known_draft_ids ) : void {
        $ignored_missing_references = [];
        foreach ( $records_by_id as $media_id => $record ) {
            $entry_id = trim( (string) ( $record['attached_entry_id'] ?? '' ) );
            if ( $entry_id !== '' && isset( $known_entry_ids[$entry_id] ) ) {
                $this->addIdReference( $records_by_id, $ignored_missing_references, (string) $media_id, 'direct_attachment', 'entry:' . $entry_id, 'Upload association: entry ' . $entry_id, false );
            }
            $draft_id = trim( (string) ( $record['attached_draft_id'] ?? '' ) );
            if ( $draft_id !== '' && isset( $known_draft_ids[$draft_id] ) ) {
                $this->addIdReference( $records_by_id, $ignored_missing_references, (string) $media_id, 'direct_attachment', 'Upload association: draft ' . $draft_id, false );
            }
        }
    }

    protected function collectEntryContents( array $entry ) : array {
        $contents = [];
        if ( isset( $entry['content'] ) ) {
            $contents[] = (string) $entry['content'];
        }
        if ( is_array( $entry['translations'] ?? null ) ) {
            foreach ( $entry['translations'] as $translation ) {
                if ( is_array( $translation ) && array_key_exists( 'content', $translation ) ) {
                    $contents[] = (string) $translation['content'];
                }
            }
        }

        return( array_values( array_unique( array_filter( $contents, static fn( string $content ) : bool => $content !== '' ) ) ) );
    }

    protected function collectUrlsFromContent( string $content, array &$records_by_id, array &$missing_references, string $category, string $source, string $label, string $owner_filter ) : void {
        if ( $content === '' || ! str_contains( $content, '/media/' ) ) {
            return;
        }
        if ( ! preg_match_all( '#(?:src|href)\s*=\s*["\'](?P<html>/media/[^"\']+)["\']|\((?P<markdown>/media/[^)\s]+)\)#i', $content, $matches, PREG_SET_ORDER ) ) {
            return;
        }
        foreach ( $matches as $match ) {
            $url = trim( (string) ( ( $match['html'] ?? '' ) !== '' ? $match['html'] : ( $match['markdown'] ?? '' ) ) );
            $url = $this->normalizeUrl( $url );
            if ( $url === '' ) {
                continue;
            }
            $metadata = $this->media_service->getAttachmentMetadataByUrl( $url, $owner_filter !== '' ? [ $owner_filter ] : [] );
            if ( is_array( $metadata ) ) {
                $this->addIdReference( $records_by_id, $missing_references, (string) ( $metadata['media_id'] ?? '' ), $category, $source, $label, $owner_filter === '' );
            } elseif ( $owner_filter === '' ) {
                $missing_references[] = [ 'source' => $source, 'value' => $url ];
            }
        }
    }

    protected function collectPluginSettingsReferences( mixed $settings, array &$records_by_id, array &$missing_references, string $category, string $source, string $context, bool $record_missing ) : void {
        if ( ! is_array( $settings ) ) {
            return;
        }
        foreach ( $settings as $plugin_key => $plugin_settings ) {
            $plugin_name = is_string( $plugin_key ) ? $this->formatPluginName( $plugin_key ) : 'Plugin';
            $this->collectIdsFromMixed( $plugin_settings, $records_by_id, $missing_references, $category, $source, 'Plugin: ' . $plugin_name . ' in ' . $context, $record_missing );
        }
    }

    protected function collectIdsFromMixed( mixed $value, array &$records_by_id, array &$missing_references, string $category, string $source, string $label, bool $record_missing ) : void {
        if ( is_array( $value ) ) {
            foreach ( $value as $child_key => $child_value ) {
                $child_label = is_string( $child_key ) || is_int( $child_key ) ? $label . '.' . (string) $child_key : $label;
                $this->collectIdsFromMixed( $child_value, $records_by_id, $missing_references, $category, $source, $child_label, $record_missing );
            }
            return;
        }
        if ( ! is_scalar( $value ) ) {
            return;
        }
        $text = trim( (string) $value );
        if ( $text === '' || ! str_contains( $text, 'media_' ) ) {
            return;
        }
        if ( ! preg_match_all( '/media_[A-Za-z0-9_-]+/', $text, $matches ) ) {
            return;
        }
        foreach ( array_unique( $matches[0] ) as $media_id ) {
            $this->addIdReference( $records_by_id, $missing_references, $media_id, $category, $source, $label, $record_missing );
        }
    }

    protected function addIdReference( array &$records_by_id, array &$missing_references, string $media_id, string $category, string $source, string $label, bool $record_missing = true ) : void {
        $media_id = trim( $media_id );
        if ( $media_id === '' ) {
            return;
        }
        if ( ! isset( $records_by_id[$media_id] ) || ! is_array( $records_by_id[$media_id] ) ) {
            if ( ! $record_missing ) {
                return;
            }
            $missing_references[] = [ 'source' => $source, 'value' => $media_id ];
            return;
        }
        $reference_key = $category . '|' . $source . '|' . $label;
        $records_by_id[$media_id]['references'][$reference_key] = [
            'category' => $category,
            'source' => $source,
            'label' => $label,
        ];
    }

    protected function buildDirectMarkerLabel( array $metadata ) : string {
        $markers = [];
        foreach ( [ 'attached_entry_id' => 'entry', 'attached_draft_id' => 'draft', 'attachment_session_id' => 'session' ] as $key => $label ) {
            $value = trim( (string) ( $metadata[$key] ?? '' ) );
            if ( $value !== '' ) {
                $markers[] = $label . ':' . $value;
            }
        }

        return( ! empty( $markers ) ? implode( ',', $markers ) : '-' );
    }

    protected function normalizeUrl( string $url ) : string {
        $url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( $url === '' || ! str_starts_with( $url, '/media/' ) ) {
            return( '' );
        }
        $path = parse_url( $url, PHP_URL_PATH );
        return( is_string( $path ) && str_starts_with( $path, '/media/' ) ? $path : '' );
    }

    protected function formatPluginName( string $plugin_key ) : string {
        $plugin_key = trim( str_replace( [ '-', '_' ], ' ', $plugin_key ) );
        return( $plugin_key !== '' ? ucwords( $plugin_key ) : 'Plugin' );
    }

    protected function getStatusLabel( string $status ) : string {
        return(
            match ( $status ) {
                'used' => 'In use',
                'direct_marker_only' => 'Upload association only',
                'unreferenced' => 'Possibly unused',
                default => 'Unknown',
            }
        );
    }
}
