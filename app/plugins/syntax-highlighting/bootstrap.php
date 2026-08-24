<?php
declare(strict_types=1);

use app\classes\TinyMashPlugins;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $plugin_key = (string) ( $plugin['key'] ?? 'syntax-highlighting' );
    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-syntax-highlighting',
        [
            'title' => 'Syntax highlighting',
            'summary' => 'Highlight fenced code blocks in supported public themes.',
            'group' => 'Plugins',
            'order' => 126,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'Overview',
                    'markdown' => 'Highlight fenced Markdown code blocks with local highlight.js assets when the active theme enables syntax highlighting.',
                ],
                [
                    'title' => 'Language names',
                    'markdown' => 'Add a language name after the opening fence, such as `php` or `javascript`. Unlabeled and unsupported blocks remain readable without language-specific colors.',
                ],
                [
                    'title' => 'Privacy',
                    'markdown' => 'All scripts and styles are local. Public pages do not contact a CDN or highlighting service.',
                ],
            ],
        ]
    );
};
