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
            'summary' => 'Photo-post settings and explicit per-post gallery planning.',
            'group' => 'Plugins',
            'order' => 148,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'Photos adds photo-post intent and explicit per-post gallery settings to the editor. A normal post can still contain many photos without becoming a gallery.',
                ],
                [
                    'title' => 'Gallery cover',
                    'markdown' => 'The entry featured image is the gallery cover image. Photos does not add a separate cover selector.',
                ],
                [
                    'title' => 'Current limits',
                    'markdown' => 'Photos renders an explicit gallery grid from selected tinymash media IDs. Lightbox output, virtual galleries, importer integration, EXIF/privacy policy, and map integration are planned follow-ups.',
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
