<?php
namespace app\classes;

class TinyMashContentRenderer {
    protected const RENDER_CACHE_VERSION = '2026-05-06-1';

    protected TinyMashMarkdownRenderer $markdown_renderer;
    protected TinyMashDateFormatter $date_formatter;
    protected TinyMashConfig $config;
    protected ?TinyMashUserRepository $user_repository;
    protected ?TinyMashMediaService $media_service;
    protected ?TinyMashPlugins $plugins;
    protected string $render_cache_directory;
    protected array $render_cache_memory = [];
    protected array $author_display_name_cache = [];
    protected array $featured_image_cache = [];
    protected array $profiling_metrics = [];

    public function __construct( TinyMashMarkdownRenderer $markdown_renderer, TinyMashDateFormatter $date_formatter, TinyMashConfig $config, ?TinyMashUserRepository $user_repository = null, ?TinyMashMediaService $media_service = null, string $render_cache_directory = '', ?TinyMashPlugins $plugins = null ) {
        $this->markdown_renderer = $markdown_renderer;
        $this->date_formatter = $date_formatter;
        $this->config = $config;
        $this->user_repository = $user_repository;
        $this->media_service = $media_service;
        $this->plugins = $plugins;
        $this->render_cache_directory = rtrim( $render_cache_directory, DIRECTORY_SEPARATOR );
    }

    public function setPlugins( ?TinyMashPlugins $plugins ) : void {
        $this->plugins = $plugins;
    }

    public function renderEntry( array $entry, TinyMashTheme $theme ) : array {
        $rendered_entry = $entry;
        $classic_smileys_enabled = $this->isClassicSmileysEnabledForEntry( $entry );
        $rendered_entry['title_html'] = $this->renderInlineTitleHtml( (string) ( $entry['title'] ?? '' ), $classic_smileys_enabled );
        $this->decorateEntryMetadata( $rendered_entry, $entry, $theme );
        $content_payload = $this->getRenderedContentPayload( $entry, $classic_smileys_enabled );
        $summary_markdown = $this->buildSummary( $entry, $theme );
        $content_heading_html = (string) ( $content_payload['content_heading_html'] ?? '' );
        $content_body_html = (string) ( $content_payload['content_body_html'] ?? '' );
        $content_body_html = $this->filterPluginContentHtml( $content_body_html, $rendered_entry, $theme );
        $plugin_fragments = $this->renderPluginEntryFragments( $rendered_entry, $theme );
        if ( ! empty( $plugin_fragments['before_content_html'] ) ) {
            $content_body_html = (string) $plugin_fragments['before_content_html'] . $content_body_html;
        }
        if ( ! empty( $plugin_fragments['after_content_html'] ) ) {
            $content_body_html .= (string) $plugin_fragments['after_content_html'];
        }
        $rendered_entry['content_heading_html'] = $content_heading_html;
        $rendered_entry['content_body_html'] = $content_body_html;
        $rendered_entry['content_html'] = $content_heading_html . $content_body_html;
        $rendered_entry['show_featured_image_in_entry'] = ! $this->contentHtmlContainsFeaturedImage( (string) $rendered_entry['content_html'], $rendered_entry['featured_image'] );
        $rendered_entry['summary_html'] = $this->getRenderedSummaryHtml( $entry, $summary_markdown, $classic_smileys_enabled );
        $rendered_entry['content_starts_with_h1'] = ! empty( $content_payload['content_starts_with_h1'] );
        return( $rendered_entry );
    }

    public function renderEntrySummary( array $entry, TinyMashTheme $theme ) : array {
        $rendered_entry = $entry;
        $classic_smileys_enabled = $this->isClassicSmileysEnabledForEntry( $entry );
        $rendered_entry['title_html'] = $this->renderInlineTitleHtml( (string) ( $entry['title'] ?? '' ), $classic_smileys_enabled );
        $decorate_started_at = microtime( true );
        $this->decorateEntryMetadata( $rendered_entry, $entry, $theme );
        $this->recordProfilingMetric( 'decorate_metadata', $decorate_started_at );
        $build_summary_started_at = microtime( true );
        $summary_markdown = $this->buildSummary( $entry, $theme );
        $this->recordProfilingMetric( 'build_summary', $build_summary_started_at );
        $render_summary_started_at = microtime( true );
        $rendered_entry['summary_html'] = $this->getRenderedSummaryHtml( $entry, $summary_markdown, $classic_smileys_enabled );
        $this->recordProfilingMetric( 'render_summary_html', $render_summary_started_at );

        return( $rendered_entry );
    }

    public function renderEntries( array $entries, TinyMashTheme $theme ) : array {
        $rendered_entries = [];
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $rendered_entries[] = $this->renderEntry( $entry, $theme );
        }

        return( $rendered_entries );
    }

    public function renderEntrySummaries( array $entries, TinyMashTheme $theme ) : array {
        $this->profiling_metrics = [];
        $rendered_entries = [];
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $rendered_entries[] = $this->renderEntrySummary( $entry, $theme );
        }

        return( $rendered_entries );
    }

    protected function renderInlineTitleHtml( string $title, bool $classic_smileys_enabled ) : string {
        $title = mb_trim( $title );
        if ( $title === '' ) {
            return( '' );
        }

        return( $this->markdown_renderer->renderInlineCompact( $title, [ 'classic_smileys_enabled' => $classic_smileys_enabled ] ) );
    }

    public function getProfilingMetrics() : array {
        return( $this->profiling_metrics );
    }

    protected function decorateEntryMetadata( array &$rendered_entry, array $entry, TinyMashTheme $theme ) : void {
        $url_started_at = microtime( true );
        $rendered_entry['url'] = $theme->getEntryURL( $entry );
        $this->recordProfilingMetric( 'decorate_url', $url_started_at );
        $featured_image_started_at = microtime( true );
        $rendered_entry['featured_image'] = $this->enrichFeaturedImage( is_array( $entry['featured_image'] ?? null ) ? $entry['featured_image'] : [] );
        $this->recordProfilingMetric( 'decorate_featured_image', $featured_image_started_at );
        $photos_settings = $this->getPhotosEntrySettings( $entry );
        $photos_active = $this->isPluginActive( 'photos' );
        $rendered_entry['is_photo_post'] = $photos_active && ! empty( $photos_settings['photo_post_enabled'] );
        $rendered_entry['has_photo_gallery'] = $photos_active && ! empty( $photos_settings['gallery_enabled'] ) && $this->photosGalleryHasMediaIds( $photos_settings['gallery_media_ids'] ?? [] );
        $rendered_entry['show_entry_timestamps'] = (string) ( $entry['type'] ?? 'post' ) !== 'page' || $this->config->showsPageTimestamps();
        $published_date_started_at = microtime( true );
        $rendered_entry['published_at_display'] = $this->date_formatter->formatUtcDateTime( (string) ( $entry['published_at_utc'] ?? '' ) );
        $this->recordProfilingMetric( 'decorate_published_date', $published_date_started_at );
        $updated_date_started_at = microtime( true );
        $rendered_entry['updated_at_display'] = $this->date_formatter->formatUtcDateTime( (string) ( $entry['updated_at_utc'] ?? '' ) );
        $rendered_entry['updated_at_display_without_timezone'] = $this->date_formatter->formatUtcDateTimeWithoutTimezone( (string) ( $entry['updated_at_utc'] ?? '' ) );
        $this->recordProfilingMetric( 'decorate_updated_date', $updated_date_started_at );
        if ( (string) ( $entry['scope'] ?? '' ) === 'author' && ! empty( $entry['author_slug'] ) && $this->user_repository instanceof TinyMashUserRepository ) {
            $author_label_started_at = microtime( true );
            $author_slug = (string) $entry['author_slug'];
            if ( ! array_key_exists( $author_slug, $this->author_display_name_cache ) ) {
                $this->author_display_name_cache[$author_slug] = $this->user_repository->getDisplayLabelByAuthorSlug( $author_slug );
            }
            $rendered_entry['author_display_name'] = $this->author_display_name_cache[$author_slug];
            $this->recordProfilingMetric( 'decorate_author_display_name', $author_label_started_at );
        } else {
            $rendered_entry['author_display_name'] = (string) ( $entry['author_slug'] ?? '' );
        }
    }

    protected function buildSummary( array $entry, TinyMashTheme $theme ) : string {
        $stored_summary = trim( (string) ( $entry['summary'] ?? '' ) );
        if ( $stored_summary !== '' && ! $this->summaryLooksUnsuitableForListing( $stored_summary ) ) {
            return( $stored_summary );
        }

        $content = trim( (string) ( $entry['content'] ?? '' ) );
        if ( $content === '' ) {
            return( '' );
        }

        if ( (string) ( $entry['type'] ?? 'post' ) !== 'post' ) {
            if ( mb_strlen( $content ) <= 180 ) {
                return( $content );
            }

            return( rtrim( mb_substr( $content, 0, 177 ) ) . '...' );
        }

        $fallback_mode = $theme->getPostSummaryFallbackMode();
        if ( $fallback_mode === 'title' ) {
            return( '' );
        }

        if ( $fallback_mode === 'full' ) {
            return( $content );
        }

        return( $this->buildWordExcerptSummary( $content, $theme->getPostSummaryFallbackWordCount() ) );
    }

    protected function buildWordExcerptSummary( string $content, int $word_count ) : string {
        $plain_content = $this->stripMarkdownForSummary( $content );
        if ( $plain_content === '' ) {
            return( '' );
        }

        $words = preg_split( '/\s+/u', $plain_content, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $words ) || empty( $words ) ) {
            return( '' );
        }
        if ( count( $words ) <= $word_count ) {
            return( $plain_content );
        }

        $paragraphs = preg_split( "/\n{2,}/", $plain_content ) ?: [];
        $excerpt_paragraphs = [];
        $collected_words = 0;

        foreach ( $paragraphs as $paragraph ) {
            $paragraph = mb_trim( (string) $paragraph );
            if ( $paragraph === '' ) {
                continue;
            }

            $paragraph_words = preg_split( '/\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY );
            if ( ! is_array( $paragraph_words ) || empty( $paragraph_words ) ) {
                continue;
            }

            $remaining_words = $word_count - $collected_words;
            if ( $remaining_words <= 0 ) {
                break;
            }

            if ( count( $paragraph_words ) <= $remaining_words ) {
                $excerpt_paragraphs[] = $paragraph;
                $collected_words += count( $paragraph_words );
                continue;
            }

            $excerpt_paragraphs[] = rtrim( implode( ' ', array_slice( $paragraph_words, 0, $remaining_words ) ) ) . '...';
            $collected_words = $word_count;
            break;
        }

        return( implode( "\n\n", $excerpt_paragraphs ) );
    }

    protected function stripMarkdownForSummary( string $content ) : string {
        $content = str_replace( [ "\r\n", "\r" ], "\n", $content );
        $content = preg_replace( '/```.*?```/su', ' ', $content ) ?? $content;
        $content = preg_replace( '/`[^`]*`/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[\![A-Za-z0-9_-]+(?:[| ][^\]]+)?\]/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[\!\[[^\]]*\]\([^)]+\)\]\([^)]+\)/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/!\[[^\]]*\]\([^)]+\)/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[([^\]]+)\]\([^)]+\)/u', '$1', $content ) ?? $content;
        $content = preg_replace( '/\[[a-z][^\]]*\](?:\[\/[a-z][^\]]*\])?/iu', ' ', $content ) ?? $content;
        $content = preg_replace( '/\(?https?:\/\/[^\s)]+\)?/iu', ' ', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}#{1,6}\s+/mu', '', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}>\s?/mu', '', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}(?:[-+*]|\d+\.)\s+/mu', '', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}\[[ xX]\]\s+/mu', '', $content ) ?? $content;
        $content = preg_replace( '/[*_~#>]+/u', ' ', $content ) ?? $content;
        $content = str_replace( '|', ' ', $content );

        $content = strip_tags( $content );
        $lines = preg_split( "/\n/", $content ) ?: [];
        $normalized_lines = [];
        foreach ( $lines as $line ) {
            $normalized_lines[] = trim( preg_replace( '/[^\S\n]+/u', ' ', (string) $line ) ?? (string) $line );
        }

        $content = implode( "\n", $normalized_lines );
        $content = preg_replace( "/\n{3,}/", "\n\n", $content ) ?? $content;
        $content = trim( $content );

        return( $content );
    }

    protected function summaryLooksUnsuitableForListing( string $summary ) : bool {
        return(
            preg_match( '/```/u', $summary ) === 1
            || preg_match( '/^\s{0,3}#{1,6}\s+\S/mu', $summary ) === 1
            || preg_match( '/!\[[^\]]*\]\([^)]+\)/u', $summary ) === 1
            || preg_match( '/\[[a-z][^\]]*\](?:\[\/[a-z][^\]]*\])?/iu', $summary ) === 1
            || preg_match( '/\(\s*https?:\/\/[^\s)]+\s*\)/iu', $summary ) === 1
            || preg_match( '/(?:^|\s)(?:\*\*|__)\s+\S/u', $summary ) === 1
        );
    }

    protected function isClassicSmileysEnabledForEntry( array $entry ) : bool {
        $system_enabled = $this->config->isClassicSmileysEnabled();
        if ( ! is_array( $entry ) || empty( $entry['scope'] ) || (string) $entry['scope'] !== 'author' || empty( $entry['author_slug'] ) || ! $this->user_repository instanceof TinyMashUserRepository ) {
            return( $system_enabled );
        }

        $author = $this->user_repository->getUserByUsername( (string) $entry['author_slug'] );
        if ( ! is_array( $author ) ) {
            return( $system_enabled );
        }

        $mode = strtolower( trim( (string) ( $author['classic_smileys_mode'] ?? 'inherit' ) ) );
        if ( $mode === 'enabled' ) {
            return( true );
        }
        if ( $mode === 'disabled' ) {
            return( false );
        }

        return( $system_enabled );
    }

    protected function getPhotosEntrySettings( array $entry ) : array {
        $plugin_settings = is_array( $entry['plugin_settings'] ?? null ) ? $entry['plugin_settings'] : [];
        $photos_settings = is_array( $plugin_settings['photos'] ?? null ) ? $plugin_settings['photos'] : [];

        return( $photos_settings );
    }

    protected function photosGalleryHasMediaIds( mixed $media_ids ) : bool {
        if ( is_array( $media_ids ) ) {
            foreach ( $media_ids as $media_id ) {
                if ( trim( (string) $media_id ) !== '' ) {
                    return( true );
                }
            }

            return( false );
        }

        return( trim( (string) $media_ids ) !== '' );
    }

    protected function isPluginActive( string $plugin_key ) : bool {
        if ( ! $this->plugins instanceof TinyMashPlugins ) {
            return( false );
        }

        $plugin = $this->plugins->getPlugin( $plugin_key );
        return( is_array( $plugin ) && ! empty( $plugin['active'] ) );
    }

    protected function splitLeadingHeading( string $content_html ) : array {
        if ( preg_match( '/^\s*(<h1\b[^>]*>.*?<\/h1>)(.*)$/is', $content_html, $matches ) !== 1 ) {
            return( [ '', $content_html ] );
        }

        $heading_html = trim( (string) ( $matches[1] ?? '' ) );
        $body_html = ltrim( (string) ( $matches[2] ?? '' ) );

        return( [ $heading_html, $body_html ] );
    }

    protected function renderPluginEntryFragments( array $entry, TinyMashTheme $theme ) : array {
        if ( ! $this->plugins instanceof TinyMashPlugins ) {
            return( [] );
        }

        return( $this->plugins->renderPublicEntryFragments(
            $entry,
            [
                'theme' => $theme,
            ]
        ) );
    }

    protected function filterPluginContentHtml( string $html, array $entry, TinyMashTheme $theme ) : string {
        if ( ! $this->plugins instanceof TinyMashPlugins ) {
            return( $html );
        }

        return( $this->plugins->filterPublicContentHtml(
            $html,
            $entry,
            [
                'theme' => $theme,
            ]
        ) );
    }

    protected function enrichFeaturedImage( array $featured_image ) : array {
        if ( empty( $featured_image['media_id'] ) || ! $this->media_service instanceof TinyMashMediaService ) {
            return( $featured_image );
        }

        $cache_key = trim( (string) ( $featured_image['media_id'] ?? '' ) ) . '|' . trim( (string) ( $featured_image['owner_username'] ?? '' ) );
        if ( $cache_key !== '|' && array_key_exists( $cache_key, $this->featured_image_cache ) ) {
            return( $this->featured_image_cache[$cache_key] );
        }

        $owner_usernames = [];
        if ( ! empty( $featured_image['owner_username'] ) && is_string( $featured_image['owner_username'] ) ) {
            $owner_usernames[] = (string) $featured_image['owner_username'];
        }

        $metadata = $this->media_service->ensureAttachmentThumbnailByMediaId( (string) $featured_image['media_id'], $owner_usernames );
        if ( ! is_array( $metadata ) ) {
            if ( $cache_key !== '|' ) {
                $this->featured_image_cache[$cache_key] = $featured_image;
            }
            return( $featured_image );
        }

        $thumbnail = is_array( $metadata['thumbnail'] ?? null ) ? $metadata['thumbnail'] : [];
        if ( ! empty( $thumbnail['url'] ) && $this->thumbnailLooksSuitableForSummary( $featured_image, $thumbnail ) ) {
            $featured_image['thumbnail_url'] = (string) $thumbnail['url'];
            $featured_image['thumbnail_width'] = max( 0, (int) ( $thumbnail['width'] ?? 0 ) );
            $featured_image['thumbnail_height'] = max( 0, (int) ( $thumbnail['height'] ?? 0 ) );
        }

        if ( $cache_key !== '|' ) {
            $this->featured_image_cache[$cache_key] = $featured_image;
        }

        return( $featured_image );
    }

    protected function contentHtmlContainsFeaturedImage( string $content_html, array $featured_image ) : bool {
        $featured_image_url = trim( (string) ( $featured_image['url'] ?? '' ) );
        if ( $content_html === '' || $featured_image_url === '' ) {
            return( false );
        }

        if ( preg_match( '/<img\b[^>]*\bsrc=["\']' . preg_quote( $featured_image_url, '/' ) . '["\']/i', $content_html ) === 1 ) {
            return( true );
        }
        if ( ! $this->media_service instanceof TinyMashMediaService || empty( $featured_image['media_id'] ) ) {
            return( false );
        }

        $owner_usernames = [];
        if ( ! empty( $featured_image['owner_username'] ) && is_string( $featured_image['owner_username'] ) ) {
            $owner_usernames[] = (string) $featured_image['owner_username'];
        }

        $featured_metadata = $this->media_service->getAttachmentMetadataByMediaId( (string) $featured_image['media_id'], $owner_usernames );
        if ( ! is_array( $featured_metadata ) ) {
            return( false );
        }

        preg_match_all( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $content_html, $matches );
        $image_urls = is_array( $matches[1] ?? null ) ? $matches[1] : [];
        foreach ( $image_urls as $image_url ) {
            $image_url = trim( (string) $image_url );
            if ( $image_url === '' ) {
                continue;
            }

            $body_metadata = $this->media_service->getAttachmentMetadataByUrl( $image_url, $owner_usernames );
            if ( ! is_array( $body_metadata ) ) {
                continue;
            }

            if ( $this->mediaRecordsDescribeSameSource( $featured_metadata, $body_metadata ) ) {
                return( true );
            }
        }

        return( false );
    }

    protected function thumbnailLooksSuitableForSummary( array $featured_image, array $thumbnail ) : bool {
        $source_width = max( 0, (int) ( $featured_image['width'] ?? 0 ) );
        $source_height = max( 0, (int) ( $featured_image['height'] ?? 0 ) );
        $thumb_width = max( 0, (int) ( $thumbnail['width'] ?? 0 ) );
        $thumb_height = max( 0, (int) ( $thumbnail['height'] ?? 0 ) );

        if ( $source_width < 1 || $source_height < 1 || $thumb_width < 1 || $thumb_height < 1 ) {
            return( false );
        }

        $source_ratio = $source_width / max( 1, $source_height );
        $thumb_ratio = $thumb_width / max( 1, $thumb_height );
        return( abs( $source_ratio - $thumb_ratio ) <= 0.20 );
    }

    protected function mediaRecordsDescribeSameSource( array $left, array $right ) : bool {
        $left_source_id = trim( (string) ( $left['source_id'] ?? '' ) );
        $right_source_id = trim( (string) ( $right['source_id'] ?? '' ) );
        if ( $left_source_id !== '' && $right_source_id !== '' && $left_source_id === $right_source_id ) {
            return( true );
        }

        $left_lineage = $this->buildMediaLineageKey( $left );
        $right_lineage = $this->buildMediaLineageKey( $right );
        return( $left_lineage !== '' && $left_lineage === $right_lineage );
    }

    protected function buildMediaLineageKey( array $metadata ) : string {
        $source_url = trim( (string) ( $metadata['source_url'] ?? '' ) );
        if ( $source_url !== '' ) {
            return( $this->normalizeMediaLineageValue( basename( (string) parse_url( $source_url, PHP_URL_PATH ) ) ) );
        }

        $original_filename = trim( (string) ( $metadata['original_filename'] ?? '' ) );
        if ( $original_filename !== '' ) {
            return( $this->normalizeMediaLineageValue( $original_filename ) );
        }

        $filename = trim( (string) ( $metadata['filename'] ?? '' ) );
        if ( $filename !== '' ) {
            return( $this->normalizeMediaLineageValue( $filename ) );
        }

        return( '' );
    }

    protected function normalizeMediaLineageValue( string $filename ) : string {
        $filename = strtolower( trim( pathinfo( $filename, PATHINFO_FILENAME ) ) );
        if ( $filename === '' ) {
            return( '' );
        }

        $filename = preg_replace( '/_[0-9]{8}_[a-f0-9]{8}$/', '', $filename ) ?? $filename;
        $filename = preg_replace( '/-[0-9]+x[0-9]+$/', '', $filename ) ?? $filename;
        return( $filename );
    }

    protected function getRenderedContentPayload( array $entry, bool $classic_smileys_enabled ) : array {
        $cache_key = $this->buildRenderedContentCacheKey( $entry, $classic_smileys_enabled );
        $cache_filename = $this->buildRenderCacheFilename( (string) ( $entry['id'] ?? '' ), 'content' );
        $cached_payload = $this->readRenderCachePayload( $cache_filename, $cache_key );
        if ( is_array( $cached_payload ) ) {
            return( $cached_payload );
        }

        $content_html = $this->markdown_renderer->render( (string) ( $entry['content'] ?? '' ), [ 'classic_smileys_enabled' => $classic_smileys_enabled ] );
        [ $content_heading_html, $content_body_html ] = $this->splitLeadingHeading( $content_html );
        $payload = [
            'content_html' => $content_html,
            'content_heading_html' => $content_heading_html,
            'content_body_html' => $content_body_html,
            'content_starts_with_h1' => preg_match( '/^\s*<h1\b/i', $content_html ) === 1,
        ];
        $this->writeRenderCachePayload( $cache_filename, $cache_key, $payload );
        return( $payload );
    }

    protected function getRenderedSummaryHtml( array $entry, string $summary_markdown, bool $classic_smileys_enabled ) : string {
        $cache_read_started_at = microtime( true );
        $cache_key = $this->buildRenderedSummaryCacheKey( $entry, $summary_markdown, $classic_smileys_enabled );
        $cache_filename = $this->buildRenderCacheFilename( (string) ( $entry['id'] ?? '' ), 'summary' );
        $cached_payload = $this->readRenderCachePayload( $cache_filename, $cache_key );
        $this->recordProfilingMetric( 'summary_cache_read', $cache_read_started_at );
        if ( is_array( $cached_payload ) && array_key_exists( 'summary_html', $cached_payload ) ) {
            $this->recordProfilingMetric( 'summary_cache_hit', $cache_read_started_at );
            return( (string) $cached_payload['summary_html'] );
        }

        if ( ! $this->summaryNeedsMarkdownRender( $summary_markdown, $classic_smileys_enabled ) ) {
            $simple_render_started_at = microtime( true );
            $summary_html = $this->renderPlainTextSummary( $summary_markdown );
            $this->recordProfilingMetric( 'summary_simple_render', $simple_render_started_at );
        } else {
            $markdown_render_started_at = microtime( true );
            $summary_html = $this->markdown_renderer->render( $summary_markdown, [ 'classic_smileys_enabled' => $classic_smileys_enabled ] );
            $this->recordProfilingMetric( 'summary_markdown_render', $markdown_render_started_at );
        }
        $cache_write_started_at = microtime( true );
        $this->writeRenderCachePayload( $cache_filename, $cache_key, [ 'summary_html' => $summary_html ] );
        $this->recordProfilingMetric( 'summary_cache_write', $cache_write_started_at );
        return( $summary_html );
    }

    protected function recordProfilingMetric( string $metric_name, float $started_at ) : void {
        $metric_name = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', trim( $metric_name ) ) ?? '' );
        if ( $metric_name === '' ) {
            return;
        }

        if ( ! isset( $this->profiling_metrics[$metric_name] ) ) {
            $this->profiling_metrics[$metric_name] = 0.0;
        }

        $this->profiling_metrics[$metric_name] += max( 0, ( microtime( true ) - $started_at ) * 1000 );
    }

    protected function summaryNeedsMarkdownRender( string $summary_markdown, bool $classic_smileys_enabled ) : bool {
        $summary_markdown = trim( $summary_markdown );
        if ( $summary_markdown === '' ) {
            return( false );
        }

        return(
            preg_match( '/[`*_#>\[\]!|]/u', $summary_markdown ) === 1
            || preg_match( '/==[^=\r\n][^=\r\n]*==/u', $summary_markdown ) === 1
            || preg_match( '/(?<![A-Za-z0-9]):[a-z0-9_+-]+:(?![A-Za-z0-9])/iu', $summary_markdown ) === 1
            || preg_match( '/\p{Extended_Pictographic}/u', $summary_markdown ) === 1
            || ( $classic_smileys_enabled && preg_match( '/(?<![A-Za-z0-9])(?:<3|[:;8][\-~]?[)(DPpOo\/\\\\|])(?![A-Za-z0-9])/u', $summary_markdown ) === 1 )
            || preg_match( '/https?:\/\//iu', $summary_markdown ) === 1
            || preg_match( '/^\s*(?:[-+*]|\d+\.)\s+/mu', $summary_markdown ) === 1
            || preg_match( '/^\s{0,3}\[[ xX]\]\s+/mu', $summary_markdown ) === 1
        );
    }

    protected function renderPlainTextSummary( string $summary_markdown ) : string {
        $summary_markdown = trim( str_replace( [ "\r\n", "\r" ], "\n", $summary_markdown ) );
        if ( $summary_markdown === '' ) {
            return( '' );
        }

        $paragraphs = preg_split( "/\n{2,}/", $summary_markdown ) ?: [];
        $html_paragraphs = [];
        foreach ( $paragraphs as $paragraph ) {
            $paragraph = trim( (string) $paragraph );
            if ( $paragraph === '' ) {
                continue;
            }

            $html_paragraphs[] = '<p>' . nl2br( htmlspecialchars( $paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ), false ) . '</p>';
        }

        return( implode( '', $html_paragraphs ) );
    }

    protected function buildRenderedContentCacheKey( array $entry, bool $classic_smileys_enabled ) : string {
        return( sha1(
            json_encode(
                [
                    'version' => self::RENDER_CACHE_VERSION,
                    'id' => (string) ( $entry['id'] ?? '' ),
                    'updated_at_utc' => (string) ( $entry['updated_at_utc'] ?? '' ),
                    'content' => (string) ( $entry['content'] ?? '' ),
                    'classic_smileys_enabled' => $classic_smileys_enabled,
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) ?: ''
        ) );
    }

    protected function buildRenderedSummaryCacheKey( array $entry, string $summary_markdown, bool $classic_smileys_enabled ) : string {
        return( sha1(
            json_encode(
                [
                    'version' => self::RENDER_CACHE_VERSION,
                    'id' => (string) ( $entry['id'] ?? '' ),
                    'updated_at_utc' => (string) ( $entry['updated_at_utc'] ?? '' ),
                    'summary' => $summary_markdown,
                    'classic_smileys_enabled' => $classic_smileys_enabled,
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) ?: ''
        ) );
    }

    protected function buildRenderCacheFilename( string $entry_id, string $variant ) : string {
        $entry_id = preg_replace( '/[^a-zA-Z0-9_-]/', '_', trim( $entry_id ) ) ?? '';
        $variant = preg_replace( '/[^a-zA-Z0-9_-]/', '_', trim( $variant ) ) ?? '';
        if ( $entry_id === '' || $variant === '' || $this->render_cache_directory === '' ) {
            return( '' );
        }

        return( $this->render_cache_directory . DIRECTORY_SEPARATOR . $entry_id . '.' . $variant . '.json' );
    }

    protected function readRenderCachePayload( string $cache_filename, string $cache_key ) : ?array {
        if ( $cache_filename === '' || ! $this->config->isRenderedContentCacheEnabled() ) {
            return( null );
        }

        $memory_key = $cache_filename . '|' . $cache_key;
        if ( array_key_exists( $memory_key, $this->render_cache_memory ) ) {
            return( is_array( $this->render_cache_memory[$memory_key] ) ? $this->render_cache_memory[$memory_key] : null );
        }

        if ( ! is_file( $cache_filename ) || ! is_readable( $cache_filename ) ) {
            $this->render_cache_memory[$memory_key] = null;
            return( null );
        }

        $json = file_get_contents( $cache_filename );
        $payload = is_string( $json ) ? json_decode( $json, true ) : null;
        if ( ! is_array( $payload ) || (string) ( $payload['key'] ?? '' ) !== $cache_key || ! is_array( $payload['data'] ?? null ) ) {
            $this->render_cache_memory[$memory_key] = null;
            return( null );
        }

        $this->render_cache_memory[$memory_key] = $payload['data'];
        return( $payload['data'] );
    }

    protected function writeRenderCachePayload( string $cache_filename, string $cache_key, array $data ) : void {
        if ( $cache_filename === '' || ! $this->config->isRenderedContentCacheEnabled() ) {
            return;
        }

        $directory = dirname( $cache_filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            return;
        }

        $payload = json_encode(
            [
                'key' => $cache_key,
                'data' => $data,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ( ! is_string( $payload ) ) {
            return;
        }

        @ file_put_contents( $cache_filename, $payload, LOCK_EX );
        $this->render_cache_memory[$cache_filename . '|' . $cache_key] = $data;
    }

}// TinyMashContentRenderer
