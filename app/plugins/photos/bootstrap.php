<?php

require_once __DIR__ . '/TinyMashPhotosService.php';

use app\classes\TinyMashMediaService;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashPlugins;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'photos' );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $media_service = $plugins->getService( 'media.service' );
    $markdown_renderer = $plugins->getService( 'markdown.renderer' );
    $photos_service = new TinyMashPhotosService(
        $media_service instanceof TinyMashMediaService ? $media_service : null,
        $markdown_renderer instanceof TinyMashMarkdownRenderer ? $markdown_renderer : null
    );
    \Flight::app()->set( 'plugin.photos.service', $photos_service );

    $plugins->registerPublicAsset( $plugin_key, 'css', '/plugins/photos/photos.css' );
    $plugins->registerPublicAsset( $plugin_key, 'js', '/plugins/photos/photos.js' );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-photos',
        [
            'title' => 'Photos',
            'summary' => 'Add photo-post options and per-post galleries.',
            'group' => 'Plugins',
            'order' => 148,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'Overview',
                    'markdown' => 'Add photo-post options and per-post gallery settings in the editor. Posts can contain multiple ordinary images without becoming galleries.',
                ],
                [
                    'title' => 'Gallery cover',
                    'markdown' => 'The featured image is the gallery cover. There is no separate cover selector.',
                ],
                [
                    'title' => 'Limits',
                    'markdown' => 'Photos renders a gallery grid and local lightbox from selected tinymash media IDs. Ordinary inline-image lightboxes, virtual galleries, importer integration, and maps are not supported.',
                ],
            ],
        ]
    );

    $plugins->registerPublicEntryRenderer(
        $plugin_key,
        static function( array $entry, array $context = [] ) use ( $photos_service ) : array {
            return( $photos_service->renderPublicEntryFragments( $entry, $context ) );
        }
    );

    $plugins->registerEditorTab(
        $plugin_key,
        [
            'key' => 'photos',
            'label' => 'Photos',
            'order' => 75,
            'roles' => [ 'author', 'admin' ],
            'renderer' => static function( array $context = [] ) use ( $photos_service ) : string {
                return( $photos_service->buildEditorTabHtml( $context ) );
            },
        ]
    );
};
