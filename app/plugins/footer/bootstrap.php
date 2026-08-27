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
                'summary' => 'Add up to two public footer lines with Markdown, emoji, and classic smileys.',
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

    if ( ! $plugins->isCliRuntime() ) {
        $plugins->registerHelpDocument(
            $plugin_key,
            'plugin-footer',
            [
                'title' => 'Footer',
                'summary' => 'Show configured text in public theme footers.',
                'documentation_url' => 'https://tinymash.joho.se/docs/plugins/footer',
                'documentation_label' => 'Footer',
                'group' => 'Plugins',
                'order' => 124,
                'roles' => [ 'author', 'admin' ],
                'sections' => [
                    [
                        'title' => 'Overview',
                        'markdown' => 'Add up to two lines to the footer slot of supported public themes.',
                    ],
                    [
                        'title' => 'Formatting',
                        'markdown' => 'Footer lines support inline Markdown, emoji shortcodes, and classic smileys. Empty lines are not rendered.',
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
