<?php

class TinyMashCommentsAdminRenderer {

    public function render( array $context ) : string {
        $scope = (string) ( $context['scope'] ?? 'root' );
        $status = (string) ( $context['status'] ?? 'pending' );
        $workspace = is_array( $context['workspace'] ?? null ) ? $context['workspace'] : [];
        $settings = is_array( $context['settings'] ?? null ) ? $context['settings'] : [];
        $retention_settings = is_array( $context['retention_settings'] ?? null ) ? $context['retention_settings'] : [];
        $retention_preview = is_array( $context['retention_preview'] ?? null ) ? $context['retention_preview'] : [];
        $users = is_array( $context['users'] ?? null ) ? $context['users'] : [];
        $entries = is_array( $context['entries'] ?? null ) ? $context['entries'] : [];
        $plugin_url = (string) ( $context['plugin_url'] ?? '' );
        $csrf_token = (string) ( $context['csrf_token'] ?? '' );
        $is_admin = ! empty( $context['is_admin'] );
        $scope_label = (string) ( $context['scope_label'] ?? 'Comments' );
        $format_date = is_callable( $context['format_date'] ?? null ) ? $context['format_date'] : static fn( string $value ) : string => $value;

        $html = '<div class="d-grid gap-4">';
        if ( $is_admin ) {
            $html .= $this->renderScopeSelector( $scope, $users, $plugin_url );
        }
        $html .= $this->renderSettings( $scope, $status, $settings, $plugin_url, $csrf_token );
        if ( $is_admin && $scope === 'root' ) {
            $html .= $this->renderRetention( $status, $retention_settings, $retention_preview, $plugin_url, $csrf_token );
        }
        $html .= $this->renderModeration( $scope, $scope_label, $status, $workspace, $entries, $plugin_url, $csrf_token, $format_date );
        $html .= '</div>';
        return( $html );
    }

    protected function renderRetention( string $status, array $settings, array $preview, string $plugin_url, string $csrf_token ) : string {
        $html = '<section class="bg-body-secondary rounded-3 p-3"><h2 class="h5">Comment retention</h2><p class="text-body-secondary">Site-wide cleanup rules. Approved comments are never removed automatically; thread parents are anonymized while replies remain.</p>';
        $html .= '<form method="post" action="' . $this->escape( $plugin_url . '/retention-settings' ) . '" class="row g-3">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $this->escape( $csrf_token ) . '"><input type="hidden" name="status" value="' . $this->escape( $status ) . '">';
        $html .= $this->renderRetentionSelect( 'tm-comments-retention-unverified', 'unverified_retention_days', 'Unverified comments', (int) ( $settings['unverified_retention_days'] ?? 2 ), [ 2 => '2 days', 7 => '7 days', 30 => '30 days', 90 => '90 days', 0 => 'Indefinitely' ] );
        $html .= $this->renderRetentionSelect( 'tm-comments-retention-pending', 'pending_retention_days', 'Pending comments', (int) ( $settings['pending_retention_days'] ?? 0 ), [ 0 => 'Do not expire', 30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '365 days' ] );
        $moderated_options = [ 7 => '7 days', 30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '365 days' ];
        $html .= $this->renderRetentionSelect( 'tm-comments-retention-spam', 'spam_retention_days', 'Spam', (int) ( $settings['spam_retention_days'] ?? 30 ), $moderated_options );
        $html .= $this->renderRetentionSelect( 'tm-comments-retention-trash', 'trash_retention_days', 'Trash', (int) ( $settings['trash_retention_days'] ?? 30 ), $moderated_options );
        $html .= '<div class="col-12"><button class="btn btn-primary" type="submit">Save retention settings</button></div></form>';

        $removed = is_array( $preview['removed'] ?? null ) ? $preview['removed'] : [];
        $anonymized = is_array( $preview['anonymized'] ?? null ) ? $preview['anonymized'] : [];
        $labels = [ 'awaiting_verification' => 'Unverified', 'pending' => 'Pending', 'spam' => 'Spam', 'trash' => 'Trash' ];
        $html .= '<div class="border-top mt-3 pt-3"><h3 class="h6">Cleanup preview</h3><div class="d-flex flex-wrap gap-2">';
        foreach ( $labels as $value => $label ) {
            $html .= '<span class="badge text-bg-secondary">' . $label . ': ' . (int) ( $removed[$value] ?? 0 ) . ' remove, ' . (int) ( $anonymized[$value] ?? 0 ) . ' anonymize</span>';
        }
        $html .= '</div><p class="small text-body-secondary mt-2 mb-2">The preview is recalculated when this page loads. Cleanup recalculates it before changing records.</p>';
        $html .= '<form method="post" action="' . $this->escape( $plugin_url . '/retention-cleanup' ) . '" class="d-flex flex-wrap gap-3 align-items-center">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $this->escape( $csrf_token ) . '"><input type="hidden" name="status" value="' . $this->escape( $status ) . '">';
        $html .= '<div class="form-check"><input class="form-check-input" id="tm-comments-retention-confirm" name="confirm_cleanup" type="checkbox" value="1" required><label class="form-check-label" for="tm-comments-retention-confirm">I understand that eligible comments will be removed or anonymized</label></div>';
        $html .= '<button class="btn btn-outline-danger" type="submit">Run cleanup now</button></form></div></section>';
        return( $html );
    }

    protected function renderRetentionSelect( string $id, string $name, string $label, int $selected, array $options ) : string {
        $html = '<div class="col-12 col-md-6"><label class="form-label" for="' . $id . '">' . $label . '</label><select class="form-select" id="' . $id . '" name="' . $name . '">';
        foreach ( $options as $value => $option_label ) {
            $html .= '<option value="' . (int) $value . '"' . ( $selected === (int) $value ? ' selected' : '' ) . '>' . $option_label . '</option>';
        }
        return( $html . '</select></div>' );
    }

    protected function renderScopeSelector( string $scope, array $users, string $plugin_url ) : string {
        $html = '<div><form method="get" action="' . $this->escape( $plugin_url ) . '" class="d-flex flex-wrap gap-2 align-items-end">';
        $html .= '<div><label class="form-label" for="tm-comments-scope">Space</label><select class="form-select" id="tm-comments-scope" name="scope">';
        $html .= '<option value="root"' . ( $scope === 'root' ? ' selected' : '' ) . '>Root</option>';
        foreach ( $users as $user ) {
            if ( ! is_array( $user ) ) {
                continue;
            }
            $author_slug = mb_strtolower( mb_trim( (string) ( $user['author_slug'] ?? $user['username'] ?? '' ) ) );
            if ( $author_slug === '' || str_starts_with( $author_slug, '_deleted_' ) ) {
                continue;
            }
            $author_scope = 'author:' . $author_slug;
            $label = mb_trim( (string) ( $user['display_name'] ?? '' ) );
            if ( $label === '' ) {
                $label = mb_trim( (string) ( $user['username'] ?? '' ) );
            }
            if ( $label === '' ) {
                $label = $author_slug;
            }
            $html .= '<option value="' . $this->escape( $author_scope ) . '"' . ( $scope === $author_scope ? ' selected' : '' ) . '>' . $this->escape( $label ) . '</option>';
        }
        $html .= '</select></div><button class="btn btn-outline-secondary" type="submit">Open</button></form></div>';
        return( $html );
    }

    protected function renderSettings( string $scope, string $status, array $settings, string $plugin_url, string $csrf_token ) : string {
        $heading = $scope === 'root' ? 'Root settings' : 'Space settings';
        $html = '<section class="bg-body-secondary rounded-3 p-3"><h2 class="h5">' . $heading . '</h2>';
        $html .= '<form method="post" action="' . $this->escape( $plugin_url . '/settings' ) . '" class="d-grid gap-3">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $this->escape( $csrf_token ) . '"><input type="hidden" name="scope" value="' . $this->escape( $scope ) . '"><input type="hidden" name="status" value="' . $this->escape( $status ) . '">';
        $html .= $this->renderCheckbox( 'tm-comments-posts', 'posts_enabled', 'Allow comments on posts by default', ! empty( $settings['posts_enabled'] ) );
        $html .= $this->renderCheckbox( 'tm-comments-pages', 'pages_enabled', 'Allow comments on pages by default', ! empty( $settings['pages_enabled'] ) );
        $html .= '<div><label class="form-label" for="tm-comments-access">Who can comment</label><select class="form-select" id="tm-comments-access" name="access_policy">';
        foreach ( [ 'anyone' => 'Anyone who can view', 'secret_or_signed_in' => 'Signed-in users and secret-link visitors', 'signed_in' => 'Signed-in users only' ] as $value => $label ) {
            $html .= '<option value="' . $value . '"' . ( (string) ( $settings['access_policy'] ?? '' ) === $value ? ' selected' : '' ) . '>' . $label . '</option>';
        }
        $html .= '</select></div><div><button class="btn btn-primary" type="submit">Save settings</button></div></form></section>';
        return( $html );
    }

    protected function renderModeration(
        string $scope,
        string $scope_label,
        string $status,
        array $workspace,
        array $entries,
        string $plugin_url,
        string $csrf_token,
        callable $format_date
    ) : string {
        $counts = is_array( $workspace['counts'] ?? null ) ? $workspace['counts'] : [];
        $comments = is_array( $workspace['comments'] ?? null ) ? $workspace['comments'] : [];
        $labels = [ 'pending' => 'Pending', 'approved' => 'Approved', 'awaiting_verification' => 'Unverified', 'spam' => 'Spam', 'trash' => 'Trash' ];
        $html = '<section><div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2"><h2 class="h5 mb-0">Moderation</h2><span class="small text-body-secondary">' . $this->escape( $scope_label ) . '</span></div>';
        $html .= '<nav class="nav nav-underline mt-2" aria-label="Comment status">';
        foreach ( $labels as $value => $label ) {
            $url = $plugin_url . '?scope=' . rawurlencode( $scope ) . '&status=' . rawurlencode( $value );
            $html .= '<a class="nav-link' . ( $status === $value ? ' active' : '' ) . '" href="' . $this->escape( $url ) . '"' . ( $status === $value ? ' aria-current="page"' : '' ) . '>' . $label . ' <span class="badge text-bg-secondary">' . (int) ( $counts[$value] ?? 0 ) . '</span></a>';
        }
        $html .= '</nav>';
        if ( $status === 'spam' ) {
            $html .= '<p class="small text-body-secondary mt-2 mb-0">Spam is assigned manually. tinymash does not classify comments automatically.</p>';
        }
        if ( $comments === [] ) {
            $html .= '<p class="text-body-secondary mt-3 mb-0">No ' . mb_strtolower( $labels[$status] ?? 'matching' ) . ' comments.</p></section>';
            return( $html );
        }

        $groups = [];
        foreach ( $comments as $comment ) {
            if ( ! is_array( $comment ) ) {
                continue;
            }
            $entry_id = (string) ( $comment['entry_id'] ?? '' );
            $groups[$entry_id][] = $comment;
        }

        $html .= '<div class="mt-3 d-grid gap-4">';
        foreach ( $groups as $entry_id => $entry_comments ) {
            $entry = is_array( $entries[$entry_id] ?? null ) ? $entries[$entry_id] : [];
            $entry_title = mb_trim( (string) ( $entry['title'] ?? '' ) );
            $entry_title = $entry_title !== '' ? $entry_title : $entry_id;
            $entry_url = (string) ( $entry['url'] ?? '' );
            $html .= '<section class="tm-comments-admin-entry"><div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2"><h3 class="h6 mb-0">';
            if ( $entry_url !== '' ) {
                $html .= '<a href="' . $this->escape( $entry_url ) . '">' . $this->escape( $entry_title ) . '</a>';
            } else {
                $html .= $this->escape( $entry_title );
            }
            $html .= '</h3><span class="small text-body-secondary">' . count( $entry_comments ) . ' ' . ( count( $entry_comments ) === 1 ? 'comment' : 'comments' ) . '</span></div><div class="mt-2">';
            foreach ( $entry_comments as $comment ) {
                $html .= '<article class="border-top py-3">';
                $html .= '<div class="d-flex flex-wrap justify-content-between gap-2"><div><strong>' . $this->escape( (string) ( $comment['author_name'] ?? '' ) ) . '</strong>';
                if ( (string) ( $comment['email'] ?? '' ) !== '' ) {
                    $html .= '<div class="small text-body-secondary">' . $this->escape( (string) $comment['email'] ) . '</div>';
                }
                $html .= '</div><div class="small text-body-secondary text-end">' . $this->escape( $format_date( (string) ( $comment['created_at_utc'] ?? '' ) ) ) . '</div></div>';
                $html .= '<div class="mt-2">' . nl2br( $this->escape( (string) ( $comment['body'] ?? '' ) ) ) . '</div>';
                $html .= $this->renderActions( $scope, $status, $comment, $plugin_url, $csrf_token );
                $html .= '</article>';
            }
            $html .= '</div></section>';
        }
        $html .= '</div></section>';
        return( $html );
    }

    protected function renderActions( string $scope, string $status, array $comment, string $plugin_url, string $csrf_token ) : string {
        $actions = [];
        if ( $status === 'pending' ) {
            $actions = [ 'approved' => 'Approve', 'spam' => 'Spam', 'trash' => 'Trash' ];
        } elseif ( $status === 'approved' ) {
            $actions = [ 'spam' => 'Spam', 'trash' => 'Trash' ];
        } elseif ( $status === 'awaiting_verification' ) {
            $actions = [ 'spam' => 'Spam', 'trash' => 'Trash' ];
        } elseif ( $status === 'spam' ) {
            if ( (string) ( $comment['author_type'] ?? '' ) === 'account' || (string) ( $comment['verified_at_utc'] ?? '' ) !== '' ) {
                $actions['pending'] = 'Review';
            }
            $actions['trash'] = 'Trash';
        }

        $html = '<div class="d-flex flex-wrap gap-2 mt-3">';
        foreach ( $actions as $target_status => $label ) {
            $html .= '<form method="post" action="' . $this->escape( $plugin_url . '/moderate' ) . '">';
            $html .= $this->hiddenActionFields( $scope, $status, $comment, $csrf_token );
            $html .= '<input type="hidden" name="target_status" value="' . $target_status . '">';
            $button_class = $target_status === 'trash' ? 'btn-outline-danger' : ( $target_status === 'approved' ? 'btn-primary' : 'btn-outline-secondary' );
            $html .= '<button class="btn btn-sm ' . $button_class . '" type="submit">' . $label . '</button></form>';
        }
        if ( $status === 'trash' ) {
            $html .= '<form method="post" action="' . $this->escape( $plugin_url . '/restore' ) . '">' . $this->hiddenActionFields( $scope, $status, $comment, $csrf_token ) . '<button class="btn btn-sm btn-outline-secondary" type="submit">Restore</button></form>';
            $html .= '<form method="post" action="' . $this->escape( $plugin_url . '/delete' ) . '">' . $this->hiddenActionFields( $scope, $status, $comment, $csrf_token ) . '<button class="btn btn-sm btn-outline-danger" type="submit">Delete permanently</button></form>';
        }
        $html .= '</div>';
        if ( $status === 'approved' ) {
            $html .= '<details class="mt-3"><summary>Reply</summary><form method="post" action="' . $this->escape( $plugin_url . '/reply' ) . '" class="d-grid gap-2 mt-2">';
            $html .= $this->hiddenActionFields( $scope, $status, $comment, $csrf_token );
            $html .= '<label><span class="form-label">Reply</span><textarea class="form-control" name="body" rows="3" maxlength="4000" required></textarea></label><div><button class="btn btn-sm btn-primary" type="submit">Post reply</button></div></form></details>';
        }
        return( $html );
    }

    protected function hiddenActionFields( string $scope, string $status, array $comment, string $csrf_token ) : string {
        return(
            '<input type="hidden" name="tinymash_csrf" value="' . $this->escape( $csrf_token ) . '">'
            . '<input type="hidden" name="scope" value="' . $this->escape( $scope ) . '">'
            . '<input type="hidden" name="status" value="' . $this->escape( $status ) . '">'
            . '<input type="hidden" name="entry_id" value="' . $this->escape( (string) ( $comment['entry_id'] ?? '' ) ) . '">'
            . '<input type="hidden" name="comment_id" value="' . $this->escape( (string) ( $comment['id'] ?? '' ) ) . '">'
        );
    }

    protected function renderCheckbox( string $id, string $name, string $label, bool $checked ) : string {
        return(
            '<div class="form-check"><input class="form-check-input" id="' . $id . '" name="' . $name . '" type="checkbox" value="1"' . ( $checked ? ' checked' : '' ) . '>'
            . '<label class="form-check-label" for="' . $id . '">' . $label . '</label></div>'
        );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
