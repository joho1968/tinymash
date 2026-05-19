<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashPlugins;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'footer' );
    $config = $plugins->getService( 'config' );
    $markdown_renderer = $plugins->getService( 'markdown.renderer' );

    if ( $config instanceof TinyMashConfig && $markdown_renderer instanceof TinyMashMarkdownRenderer ) {
        $plugins->registerSystemSettingsSection(
            $plugin_key,
            [
                'title' => 'Footer',
                'summary' => 'Two small public footer lines. Markdown, emoji, and classic smileys are supported.',
                'fields' => [
                    [
                        'key' => 'footer_line_one',
                        'type' => 'text',
                        'label' => 'Footer line 1',
                        'help' => 'Rendered as Markdown in public theme footer slots.',
                        'default' => '',
                    ],
                    [
                        'key' => 'footer_line_two',
                        'type' => 'text',
                        'label' => 'Footer line 2',
                        'help' => 'Rendered as Markdown in public theme footer slots.',
                        'default' => '',
                    ],
                ],
            ]
        );
    }

    $plugins->registerPublicSlotRenderer(
        $plugin_key,
        'footer',
        static function() use ( $plugins, $plugin_key, $config, $markdown_renderer ) : array|string {
            if ( ! $config instanceof TinyMashConfig || ! $markdown_renderer instanceof TinyMashMarkdownRenderer ) {
                return( '' );
            }

            $settings = $plugins->getPluginSystemSettings( $plugin_key );
            $lines = [];
            foreach ( [ 'footer_line_one', 'footer_line_two' ] as $setting_key ) {
                $line = trim( (string) ( $settings[$setting_key] ?? '' ) );
                if ( $line !== '' ) {
                    $lines[] = $markdown_renderer->renderInlineCompact(
                        $line,
                        [ 'classic_smileys_enabled' => $config->isClassicSmileysEnabled() ]
                    );
                }
            }

            if ( empty( $lines ) ) {
                return( '' );
            }

            return(
                [
                    'lines' => array_values( $lines ),
                ]
            );
        }
    );
};
