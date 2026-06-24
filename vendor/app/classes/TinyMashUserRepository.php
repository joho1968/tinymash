<?php
namespace app\classes;

class TinyMashUserRepository {

    protected string $users_directory;

    public function __construct( string $users_directory ) {
        $this->users_directory = rtrim( $users_directory, DIRECTORY_SEPARATOR );
    }

    public function getAllUsers() : array {
        $data = $this->readUsersData();
        $users = [];
        foreach ( $data['users'] as $user ) {
            if ( ! is_array( $user ) ) {
                continue;
            }
            $users[] = $this->normalizeUser( $user );
        }

        usort(
            $users,
            function( array $left, array $right ) : int {
                return( strcmp( $left['username'], $right['username'] ) );
            }
        );

        return( $users );
    }

    public function getUserByUsername( string $username ) : ?array {
        $auth_record = $this->getAuthRecordByUsername( $username );
        if ( ! is_array( $auth_record ) ) {
            return( null );
        }

        return( $this->normalizeUser( $auth_record ) );
    }

    public function getAuthRecordByUsername( string $username ) : ?array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            return( null );
        }

        $user_filename = $this->getUserFilename( $username );
        if ( ! is_file( $user_filename ) || ! is_readable( $user_filename ) ) {
            return( null );
        }

        $user = $this->readUserFile( $user_filename );
        if ( ! is_array( $user ) ) {
            return( null );
        }

        $normalized_user = $this->normalizeStoredUser( $user );
        if ( empty( $normalized_user['username'] ) || $normalized_user['username'] !== $username ) {
            return( null );
        }

        return( $normalized_user );
    }

    public function getAuthRecordByEmail( string $email ) : ?array {
        $email = $this->normalizeEmail( $email );
        if ( $email === '' ) {
            return( null );
        }

        foreach ( $this->getAllUsers() as $user ) {
            if ( ! is_array( $user ) ) {
                continue;
            }
            if ( $this->normalizeEmail( (string) ( $user['email'] ?? '' ) ) !== $email ) {
                continue;
            }

            return( $this->getAuthRecordByUsername( (string) ( $user['username'] ?? '' ) ) );
        }

        return( null );
    }

    public function getUserByAuthorSlug( string $author_slug ) : ?array {
        return( $this->getUserByUsername( $author_slug ) );
    }

    public function getDisplayLabelByAuthorSlug( string $author_slug ) : string {
        $user = $this->getUserByAuthorSlug( $author_slug );
        if ( ! is_array( $user ) ) {
            return( $this->normalizeUsername( $author_slug ) );
        }

        return( $this->buildDisplayLabel( $user ) );
    }

    public function saveUser( array $input ) : array {
        $data = $this->readUsersData();
        $username = $this->normalizeUsername( (string) ( $input['username'] ?? '' ) );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'username' );
        }
        $original_username = $this->normalizeUsername( (string) ( $input['original_username'] ?? '' ) );
        $display_name = $this->normalizeDisplayName( (string) ( $input['display_name'] ?? '' ) );

        $role = strtolower( trim( (string) ( $input['role'] ?? 'creator' ) ) );
        if ( ! in_array( $role, [ 'superadmin', 'creator' ], true ) ) {
            throw new \InvalidArgumentException( 'role' );
        }

        $email = $this->normalizeEmail( (string) ( $input['email'] ?? '' ) );
        if ( $email !== '' && filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'email' );
        }

        $password = (string) ( $input['password'] ?? '' );
        $confirm_password = (string) ( $input['confirm_password'] ?? '' );
        $active = ! empty( $input['active'] );
        $content_active = ! empty( $input['content_active'] );

        if ( ( $password !== '' || $confirm_password !== '' ) && $password !== $confirm_password ) {
            throw new \InvalidArgumentException( 'confirm_password' );
        }

        $existing_index = null;
        foreach ( $data['users'] as $index => $user ) {
            if ( ! is_array( $user ) || empty( $user['username'] ) ) {
                continue;
            }
            $normalized_existing_username = $this->normalizeUsername( (string) $user['username'] );
            if ( $original_username !== '' ) {
                if ( $normalized_existing_username === $original_username ) {
                    $existing_index = $index;
                    break;
                }
                continue;
            }

            if ( $normalized_existing_username === $username ) {
                $existing_index = $index;
                break;
            }
        }

        if ( $original_username === '' && $existing_index !== null ) {
            throw new \InvalidArgumentException( 'username_taken' );
        }

        foreach ( $data['users'] as $index => $user ) {
            if ( ! is_array( $user ) ) {
                continue;
            }
            if ( $existing_index !== null && $index === $existing_index ) {
                continue;
            }

            $existing_username = $this->normalizeUsername( (string) ( $user['username'] ?? '' ) );
            $existing_email = $this->normalizeEmail( (string) ( $user['email'] ?? '' ) );

            if ( $existing_username !== '' && $existing_username === $username ) {
                throw new \InvalidArgumentException( 'username_taken' );
            }
            if ( $email !== '' && $existing_email !== '' && $existing_email === $email ) {
                throw new \InvalidArgumentException( 'email_taken' );
            }
        }

        if ( $existing_index === null && $password === '' ) {
            throw new \InvalidArgumentException( 'password' );
        }

        $existing_user = $existing_index !== null && is_array( $data['users'][$existing_index] ?? null )
            ? $this->normalizeStoredUser( $data['users'][$existing_index] )
            : [];
        $autosave_mode = ! array_key_exists( 'autosave_mode', $input ) && $existing_index !== null
            ? (string) ( $existing_user['autosave_mode'] ?? 'inherit' )
            : $this->normalizeAutosaveMode( (string) ( $input['autosave_mode'] ?? 'inherit' ) );
        if ( $autosave_mode === '' ) {
            throw new \InvalidArgumentException( 'autosave_mode' );
        }
        $autosave_interval_seconds = ! array_key_exists( 'autosave_interval_seconds', $input ) && $existing_index !== null
            ? ( $existing_user['autosave_interval_seconds'] ?? null )
            : $this->normalizeAutosaveIntervalSeconds( $input['autosave_interval_seconds'] ?? null );
        $classic_smileys_mode = ! array_key_exists( 'classic_smileys_mode', $input ) && $existing_index !== null
            ? (string) ( $existing_user['classic_smileys_mode'] ?? 'inherit' )
            : $this->normalizeClassicSmileysMode( (string) ( $input['classic_smileys_mode'] ?? 'inherit' ) );
        if ( $classic_smileys_mode === '' ) {
            throw new \InvalidArgumentException( 'classic_smileys_mode' );
        }

        if ( $existing_index === null ) {
            $password_hash = password_hash( $password, PASSWORD_DEFAULT );
            if ( ! is_string( $password_hash ) || $password_hash === '' ) {
                throw new \RuntimeException( 'Unable to hash password.' );
            }

            $data['users'][] = [
                'username' => $username,
                'password_hash' => $password_hash,
                'display_name' => $display_name,
                'role' => $role,
                'active' => $active,
                'content_active' => $content_active,
                'email' => $email,
                'author_slug' => $username,
                'autosave_mode' => $autosave_mode,
                'autosave_interval_seconds' => $autosave_interval_seconds,
                'classic_smileys_mode' => $classic_smileys_mode,
                'theme_mode' => 'auto',
                'timezone' => '',
                'default_entry_type' => 'post',
                'default_status' => 'unpublished',
                'default_aggregate_to_root' => false,
                'discourage_search_indexing' => false,
                'public_theme_key' => 'inherit',
                'public_screen_mode' => 'inherit',
                'public_banner_source' => 'inherit',
                'public_banner_media_id' => '',
                'public_background_source' => 'inherit',
                'public_background_media_id' => '',
                'public_background_render_mode' => 'scaled',
                'public_theme_settings' => [],
                'plugin_settings' => [],
            ];
        } else {
            $data['users'][$existing_index]['username'] = $username;
            $data['users'][$existing_index]['display_name'] = $display_name;
            $data['users'][$existing_index]['role'] = $role;
            $data['users'][$existing_index]['active'] = $active;
            $data['users'][$existing_index]['content_active'] = $content_active;
            $data['users'][$existing_index]['email'] = $email;
            $data['users'][$existing_index]['author_slug'] = $username;
            $data['users'][$existing_index]['autosave_mode'] = $autosave_mode;
            $data['users'][$existing_index]['autosave_interval_seconds'] = $autosave_interval_seconds;
            $data['users'][$existing_index]['classic_smileys_mode'] = $classic_smileys_mode;

            if ( $password !== '' ) {
                $password_hash = password_hash( $password, PASSWORD_DEFAULT );
                if ( ! is_string( $password_hash ) || $password_hash === '' ) {
                    throw new \RuntimeException( 'Unable to hash password.' );
                }
                $data['users'][$existing_index]['password_hash'] = $password_hash;
            } elseif ( ! empty( $existing_user['password_hash'] ) ) {
                $data['users'][$existing_index]['password_hash'] = (string) $existing_user['password_hash'];
            }
        }

        $this->writeUsersData( $data );
        $saved_user = $this->getUserByUsername( $username );
        if ( ! is_array( $saved_user ) ) {
            throw new \RuntimeException( 'Unable to reload saved user.' );
        }

        return( $saved_user );
    }

    public function setAccountActive( string $username, bool $active ) : bool {
        return( $this->updateBooleanField( $username, 'active', $active ) );
    }

    public function setContentActive( string $username, bool $active ) : bool {
        return( $this->updateBooleanField( $username, 'content_active', $active ) );
    }

    public function deleteUser( string $username ) : ?array {
        $data = $this->readUsersData();
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $deleted_user = null;
        $remaining_users = [];
        foreach ( $data['users'] as $user ) {
            if ( ! is_array( $user ) || empty( $user['username'] ) ) {
                continue;
            }
            if ( $this->normalizeUsername( (string) $user['username'] ) === $username ) {
                $deleted_user = $this->normalizeUser( $user );
                continue;
            }
            $remaining_users[] = $user;
        }

        if ( $deleted_user === null ) {
            return( null );
        }

        $data['users'] = array_values( $remaining_users );
        $this->writeUsersData( $data );
        return( $deleted_user );
    }

    public function saveOwnProfile( string $username, array $input, bool $can_change_email ) : array {
        $data = $this->readUsersData();
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $user_index = null;
        $current_user = null;
        foreach ( $data['users'] as $index => $user ) {
            if ( ! is_array( $user ) || empty( $user['username'] ) ) {
                continue;
            }
            if ( $this->normalizeUsername( (string) $user['username'] ) !== $username ) {
                continue;
            }

            $user_index = $index;
            $current_user = $this->normalizeStoredUser( $user );
            break;
        }

        if ( $user_index === null || ! is_array( $current_user ) ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $email = $this->normalizeEmail( (string) ( $input['email'] ?? '' ) );
        $display_name = $this->normalizeDisplayName( (string) ( $input['display_name'] ?? ( $current_user['display_name'] ?? '' ) ) );
        if ( ! $can_change_email ) {
            $email = (string) ( $current_user['email'] ?? '' );
        }
        if ( $email !== '' && filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'email' );
        }

        foreach ( $data['users'] as $index => $user ) {
            if ( $index === $user_index || ! is_array( $user ) ) {
                continue;
            }
            $existing_email = $this->normalizeEmail( (string) ( $user['email'] ?? '' ) );
            if ( $email !== '' && $existing_email !== '' && $existing_email === $email ) {
                throw new \InvalidArgumentException( 'email_taken' );
            }
        }

        $timezone = trim( (string) ( $input['timezone'] ?? '' ) );
        if ( $timezone !== '' ) {
            try {
                new \DateTimeZone( $timezone );
            } catch ( \Throwable $e ) {
                throw new \InvalidArgumentException( 'timezone' );
            }
        }

        $autosave_mode = $this->normalizeAutosaveMode( (string) ( $input['autosave_mode'] ?? 'inherit' ) );
        if ( $autosave_mode === '' ) {
            throw new \InvalidArgumentException( 'autosave_mode' );
        }
        $autosave_interval_seconds = $this->normalizeAutosaveIntervalSeconds( $input['autosave_interval_seconds'] ?? null );
        $classic_smileys_mode = $this->normalizeClassicSmileysMode( (string) ( $input['classic_smileys_mode'] ?? 'inherit' ) );
        if ( $classic_smileys_mode === '' ) {
            throw new \InvalidArgumentException( 'classic_smileys_mode' );
        }

        $default_entry_type = $this->normalizeDefaultEntryType( (string) ( $input['default_entry_type'] ?? 'post' ) );
        if ( $default_entry_type === '' ) {
            throw new \InvalidArgumentException( 'default_entry_type' );
        }

        $default_status = $this->normalizeDefaultEntryStatus( (string) ( $input['default_status'] ?? 'unpublished' ) );
        if ( $default_status === '' ) {
            throw new \InvalidArgumentException( 'default_status' );
        }

        $password = (string) ( $input['password'] ?? '' );
        $updated_user = $current_user;
        $updated_user['display_name'] = $display_name;
        $updated_user['email'] = $email;
        $updated_user['timezone'] = $timezone;
        $updated_user['autosave_mode'] = $autosave_mode;
        $updated_user['autosave_interval_seconds'] = $autosave_interval_seconds;
        $updated_user['classic_smileys_mode'] = $classic_smileys_mode;
        $updated_user['default_entry_type'] = $default_entry_type;
        $updated_user['default_status'] = $default_status;
        $updated_user['default_aggregate_to_root'] = ! empty( $input['default_aggregate_to_root'] );
        $updated_user['discourage_search_indexing'] = ! empty( $input['discourage_search_indexing'] );
        $updated_user['public_theme_key'] = $this->normalizePublicThemeKey( (string) ( $input['public_theme_key'] ?? ( $current_user['public_theme_key'] ?? 'inherit' ) ) ) ?: 'inherit';
        $updated_user['public_screen_mode'] = $this->normalizePublicScreenMode( (string) ( $input['public_screen_mode'] ?? ( $current_user['public_screen_mode'] ?? 'inherit' ) ) ) ?: 'inherit';
        $updated_user['public_banner_source'] = $this->normalizePublicBackgroundSource( (string) ( $input['public_banner_source'] ?? ( $current_user['public_banner_source'] ?? 'inherit' ) ) ) ?: 'inherit';
        $updated_user['public_banner_media_id'] = trim( (string) ( $input['public_banner_media_id'] ?? ( $current_user['public_banner_media_id'] ?? '' ) ) );
        $updated_user['public_background_source'] = $this->normalizePublicBackgroundSource( (string) ( $input['public_background_source'] ?? ( $current_user['public_background_source'] ?? 'inherit' ) ) ) ?: 'inherit';
        $updated_user['public_background_media_id'] = trim( (string) ( $input['public_background_media_id'] ?? ( $current_user['public_background_media_id'] ?? '' ) ) );
        $updated_user['public_background_render_mode'] = $this->normalizePublicBackgroundRenderMode( (string) ( $input['public_background_render_mode'] ?? ( $current_user['public_background_render_mode'] ?? 'scaled' ) ) ) ?: 'scaled';
        if ( array_key_exists( 'public_theme_settings', $input ) && is_array( $input['public_theme_settings'] ) ) {
            $updated_user['public_theme_settings'] = $this->mergePublicThemeSettings(
                is_array( $current_user['public_theme_settings'] ?? null ) ? $current_user['public_theme_settings'] : [],
                $input['public_theme_settings']
            );
        }
        if ( array_key_exists( 'plugin_settings', $input ) && is_array( $input['plugin_settings'] ) ) {
            $updated_user['plugin_settings'] = $this->mergePluginSettings(
                is_array( $current_user['plugin_settings'] ?? null ) ? $current_user['plugin_settings'] : [],
                $input['plugin_settings']
            );
        }

        if ( $password !== '' ) {
            $password_hash = password_hash( $password, PASSWORD_DEFAULT );
            if ( ! is_string( $password_hash ) || $password_hash === '' ) {
                throw new \RuntimeException( 'Unable to hash password.' );
            }
            $updated_user['password_hash'] = $password_hash;
        }

        $data['users'][$user_index] = $updated_user;
        $this->writeUsersData( $data );

        $saved_user = $this->getUserByUsername( $username );
        if ( ! is_array( $saved_user ) ) {
            throw new \RuntimeException( 'Unable to reload saved user.' );
        }

        return( $saved_user );
    }

    public function requestOwnEmailChange( string $username, string $new_email ) : array {
        $data = $this->readUsersData();
        $username = $this->normalizeUsername( $username );
        $new_email = $this->normalizeEmail( $new_email );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'username' );
        }
        if ( $new_email === '' || filter_var( $new_email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'email' );
        }

        $user_index = null;
        foreach ( $data['users'] as $index => $user ) {
            if ( ! is_array( $user ) || empty( $user['username'] ) ) {
                continue;
            }
            if ( $this->normalizeUsername( (string) $user['username'] ) === $username ) {
                $user_index = $index;
                break;
            }
        }
        if ( $user_index === null ) {
            throw new \InvalidArgumentException( 'username' );
        }

        foreach ( $data['users'] as $index => $user ) {
            if ( $index === $user_index || ! is_array( $user ) ) {
                continue;
            }
            $existing_email = $this->normalizeEmail( (string) ( $user['email'] ?? '' ) );
            $existing_pending_email = $this->normalizeEmail( (string) ( $user['pending_email'] ?? '' ) );
            if ( $existing_email !== '' && $existing_email === $new_email ) {
                throw new \InvalidArgumentException( 'email_taken' );
            }
            if ( $existing_pending_email !== '' && $existing_pending_email === $new_email ) {
                throw new \InvalidArgumentException( 'email_taken' );
            }
        }

        $token = bin2hex( random_bytes( 24 ) );
        $requested_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        $data['users'][$user_index]['pending_email'] = $new_email;
        $data['users'][$user_index]['pending_email_change_token'] = $token;
        $data['users'][$user_index]['pending_email_change_requested_at_utc'] = $requested_at_utc;
        $this->writeUsersData( $data );

        return(
            [
                'username' => $username,
                'pending_email' => $new_email,
                'pending_email_change_token' => $token,
                'pending_email_change_requested_at_utc' => $requested_at_utc,
            ]
        );
    }

    public function confirmOwnEmailChange( string $username, string $token ) : ?array {
        $data = $this->readUsersData();
        $username = $this->normalizeUsername( $username );
        $token = trim( $token );
        if ( $username === '' || $token === '' ) {
            return( null );
        }

        $user_index = null;
        $current_user = null;
        foreach ( $data['users'] as $index => $user ) {
            if ( ! is_array( $user ) || empty( $user['username'] ) ) {
                continue;
            }
            if ( $this->normalizeUsername( (string) $user['username'] ) === $username ) {
                $user_index = $index;
                $current_user = $this->normalizeStoredUser( $user );
                break;
            }
        }
        if ( $user_index === null || ! is_array( $current_user ) ) {
            return( null );
        }

        $pending_email = $this->normalizeEmail( (string) ( $data['users'][$user_index]['pending_email'] ?? '' ) );
        $pending_token = trim( (string) ( $data['users'][$user_index]['pending_email_change_token'] ?? '' ) );
        if ( $pending_email === '' || $pending_token === '' || ! hash_equals( $pending_token, $token ) ) {
            return( null );
        }

        foreach ( $data['users'] as $index => $user ) {
            if ( $index === $user_index || ! is_array( $user ) ) {
                continue;
            }
            $existing_email = $this->normalizeEmail( (string) ( $user['email'] ?? '' ) );
            if ( $existing_email !== '' && $existing_email === $pending_email ) {
                throw new \InvalidArgumentException( 'email_taken' );
            }
        }

        $data['users'][$user_index]['email'] = $pending_email;
        $data['users'][$user_index]['pending_email'] = '';
        $data['users'][$user_index]['pending_email_change_token'] = '';
        $data['users'][$user_index]['pending_email_change_requested_at_utc'] = '';
        $this->writeUsersData( $data );

        return( $this->getUserByUsername( $username ) );
    }

    public function clearPendingEmailChange( string $username ) : bool {
        $data = $this->readUsersData();
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $updated = false;
        foreach ( $data['users'] as $index => $user ) {
            if ( ! is_array( $user ) || empty( $user['username'] ) ) {
                continue;
            }
            if ( $this->normalizeUsername( (string) $user['username'] ) !== $username ) {
                continue;
            }

            $data['users'][$index]['pending_email'] = '';
            $data['users'][$index]['pending_email_change_token'] = '';
            $data['users'][$index]['pending_email_change_requested_at_utc'] = '';
            $updated = true;
            break;
        }

        if ( ! $updated ) {
            return( false );
        }

        $this->writeUsersData( $data );
        return( true );
    }

    public function setPasswordForUsername( string $username, string $password ) : bool {
        $data = $this->readUsersData();
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'username' );
        }
        if ( trim( $password ) === '' ) {
            throw new \InvalidArgumentException( 'password' );
        }

        $password_hash = password_hash( $password, PASSWORD_DEFAULT );
        if ( ! is_string( $password_hash ) || $password_hash === '' ) {
            throw new \RuntimeException( 'Unable to hash password.' );
        }

        $updated = false;
        foreach ( $data['users'] as $index => $user ) {
            if ( ! is_array( $user ) || empty( $user['username'] ) ) {
                continue;
            }
            if ( $this->normalizeUsername( (string) $user['username'] ) !== $username ) {
                continue;
            }

            $data['users'][$index]['password_hash'] = $password_hash;
            $updated = true;
            break;
        }

        if ( ! $updated ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $this->writeUsersData( $data );
        return( true );
    }

    public function countUsersByRole( string $role ) : int {
        $role = strtolower( trim( $role ) );
        $count = 0;
        foreach ( $this->getAllUsers() as $user ) {
            if ( strtolower( (string) ( $user['role'] ?? '' ) ) === $role ) {
                $count++;
            }
        }

        return( $count );
    }

    public function isAuthorContentPublic( string $author_slug ) : bool {
        $author_slug = $this->normalizeUsername( $author_slug );
        if ( $author_slug === '' ) {
            return( true );
        }
        if ( str_starts_with( $author_slug, '_deleted_' ) ) {
            return( false );
        }

        foreach ( $this->getAllUsers() as $user ) {
            if ( $user['username'] !== $author_slug ) {
                continue;
            }

            return( $user['content_active'] );
        }

        return( true );
    }

    public function discouragesSearchIndexing( string $author_slug ) : bool {
        $author_slug = $this->normalizeUsername( $author_slug );
        if ( $author_slug === '' ) {
            return( false );
        }

        foreach ( $this->getAllUsers() as $user ) {
            if ( (string) ( $user['username'] ?? '' ) !== $author_slug ) {
                continue;
            }

            return( ! empty( $user['discourage_search_indexing'] ) );
        }

        return( false );
    }

    public function getPluginSettings( string $username, string $plugin_key ) : array {
        $user = $this->getUserByUsername( $username );
        if ( ! is_array( $user ) ) {
            return( [] );
        }

        $plugin_key = $this->normalizePluginKey( $plugin_key );
        if ( $plugin_key === '' ) {
            return( [] );
        }

        $plugin_settings = $user['plugin_settings'][$plugin_key] ?? [];
        return( is_array( $plugin_settings ) ? $plugin_settings : [] );
    }

    public function getPublicThemeSettings( string $username, string $theme_key ) : array {
        $user = $this->getUserByUsername( $username );
        if ( ! is_array( $user ) ) {
            return( [] );
        }

        $theme_key = $this->normalizeThemeKey( $theme_key );
        if ( $theme_key === '' ) {
            return( [] );
        }

        $theme_settings = $user['public_theme_settings'][$theme_key] ?? [];
        return( is_array( $theme_settings ) ? $theme_settings : [] );
    }

    public function getPublicThemeKey( string $username ) : string {
        $user = $this->getUserByUsername( $username );
        if ( ! is_array( $user ) ) {
            return( 'inherit' );
        }

        return( $this->normalizePublicThemeKey( (string) ( $user['public_theme_key'] ?? 'inherit' ) ) ?: 'inherit' );
    }

    public function getPublicScreenMode( string $username ) : string {
        $user = $this->getUserByUsername( $username );
        if ( ! is_array( $user ) ) {
            return( 'inherit' );
        }

        return( $this->normalizePublicScreenMode( (string) ( $user['public_screen_mode'] ?? 'inherit' ) ) ?: 'inherit' );
    }

    public function importStoredUser( array $user, string $password = '', bool $replace_existing = false ) : array {
        $normalized_user = $this->normalizeStoredUser( $user );
        $username = (string) ( $normalized_user['username'] ?? '' );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $existing_user = $this->getAuthRecordByUsername( $username );
        if ( is_array( $existing_user ) && ! $replace_existing ) {
            throw new \InvalidArgumentException( 'username_taken' );
        }

        $incoming_email = strtolower( trim( (string) ( $normalized_user['email'] ?? '' ) ) );
        if ( $incoming_email !== '' ) {
            foreach ( $this->getAllUsers() as $candidate_user ) {
                if ( ! is_array( $candidate_user ) ) {
                    continue;
                }
                if ( (string) ( $candidate_user['username'] ?? '' ) === $username ) {
                    continue;
                }
                if ( strtolower( trim( (string) ( $candidate_user['email'] ?? '' ) ) ) === $incoming_email ) {
                    throw new \InvalidArgumentException( 'email_taken' );
                }
            }
        }

        if ( $password !== '' ) {
            $password_hash = password_hash( $password, PASSWORD_DEFAULT );
            if ( ! is_string( $password_hash ) || $password_hash === '' ) {
                throw new \RuntimeException( 'Unable to hash password.' );
            }
            $normalized_user['password_hash'] = $password_hash;
        }

        if ( trim( (string) ( $normalized_user['password_hash'] ?? '' ) ) === '' ) {
            throw new \InvalidArgumentException( 'password' );
        }

        $data = $this->readUsersData();
        $remaining_users = [];
        foreach ( $data['users'] as $existing_entry ) {
            if ( ! is_array( $existing_entry ) ) {
                continue;
            }
            if ( $this->normalizeUsername( (string) ( $existing_entry['username'] ?? '' ) ) === $username ) {
                continue;
            }
            $remaining_users[] = $existing_entry;
        }

        $remaining_users[] = $normalized_user;
        $data['users'] = $remaining_users;
        $this->writeUsersData( $data );

        $saved_user = $this->getUserByUsername( $username );
        if ( ! is_array( $saved_user ) ) {
            throw new \RuntimeException( 'Unable to reload imported user.' );
        }

        return( $saved_user );
    }

    protected function updateBooleanField( string $username, string $field, bool $value ) : bool {
        $data = $this->readUsersData();
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $updated = false;
        foreach ( $data['users'] as $index => $user ) {
            if ( ! is_array( $user ) || empty( $user['username'] ) ) {
                continue;
            }
            if ( $user['username'] !== $username ) {
                continue;
            }

            $data['users'][$index][$field] = $value;
            $updated = true;
            break;
        }

        if ( ! $updated ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $this->writeUsersData( $data );
        return( true );
    }

    protected function readUsersData() : array {
        $users = [];
        foreach ( $this->getUserFiles() as $user_file ) {
            $user = $this->readUserFile( $user_file );
            if ( ! is_array( $user ) ) {
                continue;
            }
            $normalized_user = $this->normalizeStoredUser( $user );
            if ( empty( $normalized_user['username'] ) ) {
                continue;
            }
            $users[] = $normalized_user;
        }

        return( [ 'users' => $users ] );
    }

    protected function writeUsersData( array $data ) : void {
        $this->ensureUsersDirectory();

        $desired_usernames = [];
        foreach ( $data['users'] as $user ) {
            if ( ! is_array( $user ) ) {
                continue;
            }
            $normalized_user = $this->normalizeStoredUser( $user );
            if ( empty( $normalized_user['username'] ) ) {
                continue;
            }

            $desired_usernames[] = $normalized_user['username'];
            $user_payload = $normalized_user;
            unset( $user_payload['author_slug'] );

            $user_json = json_encode( $user_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            if ( ! is_string( $user_json ) || $user_json === '' ) {
                throw new \RuntimeException( 'Unable to encode user data.' );
            }

            if ( file_put_contents( $this->getUserFilename( $normalized_user['username'] ), $user_json . PHP_EOL, LOCK_EX ) === false ) {
                throw new \RuntimeException( 'Unable to write user file for "' . $normalized_user['username'] . '"' );
            }
        }

        $desired_usernames = array_values( array_unique( $desired_usernames ) );
        foreach ( $this->getUserFiles() as $user_file ) {
            $username = $this->normalizeUsername( basename( $user_file, '.json' ) );
            if ( $username === '' || in_array( $username, $desired_usernames, true ) ) {
                continue;
            }
            unlink( $user_file );
        }
    }

    protected function getUserFiles() : array {
        if ( ! is_dir( $this->users_directory ) ) {
            return( [] );
        }

        $user_files = glob( $this->users_directory . DIRECTORY_SEPARATOR . '*.json' );
        if ( ! is_array( $user_files ) ) {
            return( [] );
        }

        $filtered_files = [];
        foreach ( $user_files as $user_file ) {
            if ( ! is_string( $user_file ) || ! is_file( $user_file ) ) {
                continue;
            }
            $filtered_files[] = $user_file;
        }

        sort( $filtered_files );
        return( $filtered_files );
    }

    protected function getUserFilename( string $username ) : string {
        return( $this->users_directory . DIRECTORY_SEPARATOR . $this->normalizeUsername( $username ) . '.json' );
    }

    protected function readUserFile( string $user_file ) : ?array {
        if ( ! is_file( $user_file ) || ! is_readable( $user_file ) ) {
            return( null );
        }

        $json = file_get_contents( $user_file );
        if ( ! is_string( $json ) || $json === '' ) {
            return( null );
        }

        $user = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
        return( is_array( $user ) ? $user : null );
    }

    protected function ensureUsersDirectory() : void {
        if ( is_dir( $this->users_directory ) ) {
            return;
        }
        if ( ! mkdir( $this->users_directory, 0775, true ) && ! is_dir( $this->users_directory ) ) {
            throw new \RuntimeException( 'Unable to create users directory.' );
        }
    }

    protected function normalizeStoredUser( array $user ) : array {
        $username = $this->normalizeUsername( (string) ( $user['username'] ?? '' ) );
        if ( $username === '' ) {
            return( [] );
        }

        return(
            [
                'username' => $username,
                'password_hash' => ! empty( $user['password_hash'] ) ? (string) $user['password_hash'] : '',
                'display_name' => $this->normalizeDisplayName( (string) ( $user['display_name'] ?? '' ) ),
                'role' => ! empty( $user['role'] ) ? (string) $user['role'] : 'creator',
                'active' => ! empty( $user['active'] ),
                'content_active' => array_key_exists( 'content_active', $user ) ? ! empty( $user['content_active'] ) : true,
                'email' => ! empty( $user['email'] ) ? (string) $user['email'] : '',
                'author_slug' => $username,
                'autosave_mode' => $this->normalizeAutosaveMode( (string) ( $user['autosave_mode'] ?? 'inherit' ) ) ?: 'inherit',
                'autosave_interval_seconds' => $this->normalizeAutosaveIntervalSeconds( $user['autosave_interval_seconds'] ?? null ),
                'classic_smileys_mode' => $this->normalizeClassicSmileysMode( (string) ( $user['classic_smileys_mode'] ?? 'inherit' ) ) ?: 'inherit',
                'theme_mode' => $this->normalizeThemeMode( (string) ( $user['theme_mode'] ?? 'auto' ) ) ?: 'auto',
                'timezone' => ! empty( $user['timezone'] ) ? (string) $user['timezone'] : '',
                'default_entry_type' => $this->normalizeDefaultEntryType( (string) ( $user['default_entry_type'] ?? 'post' ) ) ?: 'post',
                'default_status' => $this->normalizeDefaultEntryStatus( (string) ( $user['default_status'] ?? 'unpublished' ) ) ?: 'unpublished',
                'default_aggregate_to_root' => ! empty( $user['default_aggregate_to_root'] ),
                'discourage_search_indexing' => ! empty( $user['discourage_search_indexing'] ),
                'public_theme_key' => $this->normalizePublicThemeKey( (string) ( $user['public_theme_key'] ?? 'inherit' ) ) ?: 'inherit',
                'public_screen_mode' => $this->normalizePublicScreenMode( (string) ( $user['public_screen_mode'] ?? 'inherit' ) ) ?: 'inherit',
                'public_banner_source' => $this->normalizePublicBackgroundSource( (string) ( $user['public_banner_source'] ?? 'inherit' ) ) ?: 'inherit',
                'public_banner_media_id' => trim( (string) ( $user['public_banner_media_id'] ?? '' ) ),
                'public_background_source' => $this->normalizePublicBackgroundSource( (string) ( $user['public_background_source'] ?? 'inherit' ) ) ?: 'inherit',
                'public_background_media_id' => trim( (string) ( $user['public_background_media_id'] ?? '' ) ),
                'public_background_render_mode' => $this->normalizePublicBackgroundRenderMode( (string) ( $user['public_background_render_mode'] ?? 'scaled' ) ) ?: 'scaled',
                'public_theme_settings' => $this->normalizePublicThemeSettings( $user['public_theme_settings'] ?? [] ),
                'plugin_settings' => $this->normalizePluginSettings( $user['plugin_settings'] ?? [] ),
                'pending_email' => ! empty( $user['pending_email'] ) ? (string) $user['pending_email'] : '',
                'pending_email_change_token' => ! empty( $user['pending_email_change_token'] ) ? (string) $user['pending_email_change_token'] : '',
                'pending_email_change_requested_at_utc' => ! empty( $user['pending_email_change_requested_at_utc'] ) ? (string) $user['pending_email_change_requested_at_utc'] : '',
            ]
        );
    }

    protected function normalizeUser( array $user ) : array {
        $normalized_user = $this->normalizeStoredUser( $user );
        unset( $normalized_user['password_hash'] );
        unset( $normalized_user['pending_email_change_token'] );
        return( $normalized_user );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( function_exists( 'mb_trim' ) ? mb_trim( $username ) : trim( $username ) );
        if ( $username === '' ) {
            return( '' );
        }
        if ( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) !== 1 ) {
            return( '' );
        }

        return( $username );
    }

    protected function normalizeDisplayName( string $display_name ) : string {
        $display_name = function_exists( 'mb_trim' ) ? mb_trim( $display_name ) : trim( $display_name );
        if ( $display_name === '' ) {
            return( '' );
        }

        return( mb_substr( $display_name, 0, 120 ) );
    }

    protected function normalizeEmail( string $email ) : string {
        $email = function_exists( 'mb_trim' ) ? mb_trim( $email ) : trim( $email );
        if ( $email === '' ) {
            return( '' );
        }

        return( function_exists( 'mb_strtolower' ) ? mb_strtolower( $email ) : strtolower( $email ) );
    }

    protected function buildDisplayLabel( array $user ) : string {
        $display_name = $this->normalizeDisplayName( (string) ( $user['display_name'] ?? '' ) );
        if ( $display_name !== '' ) {
            return( $display_name );
        }

        return( (string) ( $user['username'] ?? '' ) );
    }

    protected function normalizePluginKey( string $plugin_key ) : string {
        $plugin_key = strtolower( trim( $plugin_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $plugin_key ) ?? '' );
    }

    protected function normalizeThemeKey( string $theme_key ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $theme_key ) ?? '' );
    }

    protected function normalizePublicBackgroundSource( string $source ) : string {
        $source = strtolower( trim( $source ) );
        return( in_array( $source, [ 'inherit', 'none', 'custom' ], true ) ? $source : '' );
    }

    protected function normalizePublicBackgroundRenderMode( string $mode ) : string {
        $mode = strtolower( trim( $mode ) );
        return( in_array( $mode, [ 'as_is', 'centered', 'scaled', 'tiled' ], true ) ? $mode : '' );
    }

    protected function normalizePublicThemeSettings( mixed $public_theme_settings ) : array {
        if ( ! is_array( $public_theme_settings ) ) {
            return( [] );
        }

        $normalized_settings = [];
        foreach ( $public_theme_settings as $theme_key => $settings ) {
            if ( ! is_string( $theme_key ) || ! is_array( $settings ) ) {
                continue;
            }

            $normalized_theme_key = $this->normalizeThemeKey( $theme_key );
            if ( $normalized_theme_key === '' ) {
                continue;
            }

            $normalized_settings[$normalized_theme_key] = [];
            foreach ( $settings as $setting_key => $setting_value ) {
                if ( ! is_string( $setting_key ) ) {
                    continue;
                }

                $normalized_setting_key = strtolower( trim( $setting_key ) );
                if ( $normalized_setting_key === '' ) {
                    continue;
                }

                if ( is_scalar( $setting_value ) || $setting_value === null ) {
                    $normalized_settings[$normalized_theme_key][$normalized_setting_key] = trim( (string) $setting_value );
                }
            }

            if ( empty( $normalized_settings[$normalized_theme_key] ) ) {
                unset( $normalized_settings[$normalized_theme_key] );
            }
        }

        return( $normalized_settings );
    }

    protected function normalizePluginSettings( mixed $plugin_settings ) : array {
        if ( ! is_array( $plugin_settings ) ) {
            return( [] );
        }

        $normalized_settings = [];
        foreach ( $plugin_settings as $plugin_key => $settings ) {
            if ( ! is_string( $plugin_key ) || ! is_array( $settings ) ) {
                continue;
            }
            $normalized_plugin_key = $this->normalizePluginKey( $plugin_key );
            if ( $normalized_plugin_key === '' ) {
                continue;
            }

            $normalized_settings[$normalized_plugin_key] = [];
            foreach ( $settings as $setting_key => $setting_value ) {
                if ( ! is_string( $setting_key ) ) {
                    continue;
                }
                $normalized_settings[$normalized_plugin_key][$setting_key] = $setting_value;
            }
        }

        return( $normalized_settings );
    }

    protected function mergePluginSettings( array $existing_settings, array $incoming_settings ) : array {
        $merged_settings = $this->normalizePluginSettings( $existing_settings );
        foreach ( $this->normalizePluginSettings( $incoming_settings ) as $plugin_key => $plugin_settings ) {
            if ( ! isset( $merged_settings[$plugin_key] ) || ! is_array( $merged_settings[$plugin_key] ) ) {
                $merged_settings[$plugin_key] = [];
            }
            foreach ( $plugin_settings as $setting_key => $setting_value ) {
                $merged_settings[$plugin_key][$setting_key] = $setting_value;
            }
        }

        return( $merged_settings );
    }

    protected function mergePublicThemeSettings( array $existing_settings, array $incoming_settings ) : array {
        $merged_settings = $this->normalizePublicThemeSettings( $existing_settings );
        foreach ( $incoming_settings as $theme_key => $theme_settings ) {
            if ( ! is_string( $theme_key ) || ! is_array( $theme_settings ) ) {
                continue;
            }

            $normalized_theme_key = $this->normalizeThemeKey( $theme_key );
            if ( $normalized_theme_key === '' ) {
                continue;
            }

            $normalized_theme_settings = [];
            foreach ( $theme_settings as $setting_key => $setting_value ) {
                if ( ! is_string( $setting_key ) ) {
                    continue;
                }

                $normalized_setting_key = strtolower( trim( $setting_key ) );
                if ( $normalized_setting_key === '' ) {
                    continue;
                }

                $normalized_theme_settings[$normalized_setting_key] = trim( (string) $setting_value );
            }

            $normalized_theme_settings = array_filter(
                $normalized_theme_settings,
                static fn( mixed $value ) : bool => trim( (string) $value ) !== ''
            );

            if ( empty( $normalized_theme_settings ) ) {
                unset( $merged_settings[$normalized_theme_key] );
                continue;
            }

            if ( ! isset( $merged_settings[$normalized_theme_key] ) || ! is_array( $merged_settings[$normalized_theme_key] ) ) {
                $merged_settings[$normalized_theme_key] = [];
            }

            foreach ( $normalized_theme_settings as $setting_key => $setting_value ) {
                $merged_settings[$normalized_theme_key][$setting_key] = $setting_value;
            }
        }

        return( $merged_settings );
    }

    protected function normalizeAutosaveMode( string $autosave_mode ) : string {
        $autosave_mode = strtolower( trim( $autosave_mode ) );
        if ( in_array( $autosave_mode, [ 'inherit', 'enabled', 'disabled' ], true ) ) {
            return( $autosave_mode );
        }

        return( '' );
    }

    protected function normalizeThemeMode( string $theme_mode ) : string {
        $theme_mode = strtolower( trim( $theme_mode ) );
        if ( in_array( $theme_mode, [ 'auto', 'light', 'dark' ], true ) ) {
            return( $theme_mode );
        }

        return( '' );
    }

    protected function normalizePublicThemeKey( string $theme_key ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        if ( $theme_key === '' || $theme_key === 'inherit' ) {
            return( 'inherit' );
        }

        return( $this->normalizeThemeKey( $theme_key ) );
    }

    protected function normalizePublicScreenMode( string $screen_mode ) : string {
        $screen_mode = strtolower( trim( $screen_mode ) );
        if ( in_array( $screen_mode, [ 'inherit', 'auto', 'light', 'dark' ], true ) ) {
            return( $screen_mode );
        }

        return( '' );
    }

    protected function normalizeClassicSmileysMode( string $classic_smileys_mode ) : string {
        $classic_smileys_mode = strtolower( trim( $classic_smileys_mode ) );
        if ( in_array( $classic_smileys_mode, [ 'inherit', 'enabled', 'disabled' ], true ) ) {
            return( $classic_smileys_mode );
        }

        return( '' );
    }

    protected function normalizeDefaultEntryType( string $entry_type ) : string {
        $entry_type = strtolower( trim( $entry_type ) );
        if ( in_array( $entry_type, [ 'post', 'page' ], true ) ) {
            return( $entry_type );
        }

        return( '' );
    }

    protected function normalizeDefaultEntryStatus( string $status ) : string {
        $status = strtolower( trim( $status ) );
        if ( in_array( $status, [ 'published', 'unpublished' ], true ) ) {
            return( $status );
        }

        return( '' );
    }

    protected function normalizeAutosaveIntervalSeconds( mixed $value ) : ?int {
        if ( $value === null || $value === '' ) {
            return( null );
        }

        $interval_seconds = (int) $value;
        if ( $interval_seconds < 30 || $interval_seconds > 180 ) {
            throw new \InvalidArgumentException( 'autosave_interval_seconds' );
        }

        return( $interval_seconds );
    }

}// TinyMashUserRepository
