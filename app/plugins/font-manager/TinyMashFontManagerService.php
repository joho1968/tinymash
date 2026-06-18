<?php

class TinyMashFontManagerService {

    protected const MAX_FONT_SIZE = 5242880;
    protected const ROLES = [
        'body' => 'Body text',
        'publication_title' => 'Publication titles',
        'heading' => 'Headings',
        'ui' => 'UI and navigation',
        'monospace' => 'Monospace/code',
    ];
    protected const ALLOWED_EXTENSIONS = [ 'woff2', 'woff' ];
    protected const ALLOWED_DISPLAYS = [ 'auto', 'block', 'swap', 'fallback', 'optional' ];
    protected const ALLOWED_STYLES = [ 'normal', 'italic' ];

    protected string $settings_filename;
    protected string $files_directory;

    public function __construct( string $settings_filename, string $files_directory ) {
        $this->settings_filename = $settings_filename;
        $this->files_directory = rtrim( $files_directory, DIRECTORY_SEPARATOR );
    }

    public function getRoles() : array {
        return( self::ROLES );
    }

    public function getSettings() : array {
        if ( ! is_file( $this->settings_filename ) || ! is_readable( $this->settings_filename ) ) {
            return( $this->getDefaultSettings() );
        }

        $json = file_get_contents( $this->settings_filename );
        $data = is_string( $json ) ? json_decode( $json, true, 32 ) : null;
        return( $this->normalizeSettings( is_array( $data ) ? $data : [] ) );
    }

    public function saveSettings( array $settings ) : array {
        $settings = $this->normalizeSettings( $settings );
        $directory = dirname( $this->settings_filename );
        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0775, true );
        }
        file_put_contents( $this->settings_filename, json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n", LOCK_EX );
        return( $settings );
    }

    public function getDefaultSettings() : array {
        return(
            [
                'families' => [],
                'assignments' => array_fill_keys( array_keys( self::ROLES ), '' ),
            ]
        );
    }

    public function normalizeSettings( array $input ) : array {
        $families = [];
        foreach ( is_array( $input['families'] ?? null ) ? $input['families'] : [] as $family ) {
            if ( ! is_array( $family ) ) {
                continue;
            }
            $normalized_family = $this->normalizeFamilyRecord( $family );
            if ( ! empty( $normalized_family ) ) {
                $families[$normalized_family['id']] = $normalized_family;
            }
        }

        $assignments = [];
        foreach ( self::ROLES as $role => $label ) {
            $family_id = $this->normalizeId( (string) ( $input['assignments'][$role] ?? '' ) );
            $assignments[$role] = isset( $families[$family_id] ) ? $family_id : '';
        }

        uasort(
            $families,
            static fn( array $left, array $right ) : int => strcasecmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) )
        );

        return(
            [
                'families' => array_values( $families ),
                'assignments' => $assignments,
            ]
        );
    }

    public function saveAssignments( array $data ) : array {
        $settings = $this->getSettings();
        $assignments = [];
        foreach ( self::ROLES as $role => $label ) {
            $assignments[$role] = $this->normalizeId( (string) ( $data['assignments'][$role] ?? '' ) );
        }
        $settings['assignments'] = $assignments;
        return( $this->saveSettings( $settings ) );
    }

    public function addUploadedFont( array $file, array $data ) : array {
        $settings = $this->getSettings();
        $families = [];
        foreach ( $settings['families'] as $family ) {
            $families[(string) $family['id']] = $family;
        }

        $family_id = $this->normalizeId( (string) ( $data['family_id'] ?? '' ) );
        $family_name = $this->normalizeFamilyName( (string) ( $data['family_name'] ?? '' ) );
        if ( $family_name === '' && $family_id !== '' && ! empty( $families[$family_id]['name'] ) ) {
            $family_name = (string) $families[$family_id]['name'];
        }
        if ( $family_name === '' ) {
            return( [ 'ok' => false, 'message' => 'Choose an existing family or enter a new font family name.' ] );
        }

        if ( empty( $file ) || ! is_array( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
            return( [ 'ok' => false, 'message' => 'Choose a WOFF2 or WOFF font file.' ] );
        }

        $tmp_name = (string) ( $file['tmp_name'] ?? '' );
        if ( $tmp_name === '' || ! is_uploaded_file( $tmp_name ) ) {
            return( [ 'ok' => false, 'message' => 'The uploaded font file could not be read.' ] );
        }

        $size = (int) ( $file['size'] ?? 0 );
        if ( $size <= 0 || $size > self::MAX_FONT_SIZE ) {
            return( [ 'ok' => false, 'message' => 'Font files must be 5 MB or smaller.' ] );
        }

        $original_filename = basename( (string) ( $file['name'] ?? 'font' ) );
        $extension = strtolower( pathinfo( $original_filename, PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, self::ALLOWED_EXTENSIONS, true ) ) {
            return( [ 'ok' => false, 'message' => 'Only WOFF2 and WOFF font files are supported in this version.' ] );
        }

        $mime = $this->detectMimeType( $tmp_name );
        if ( ! $this->mimeLooksLikeFont( $mime ) ) {
            return( [ 'ok' => false, 'message' => 'The uploaded file does not look like a web font.' ] );
        }
        $inferred_metadata = $this->inferFontMetadata( $tmp_name, $original_filename );

        if ( $family_id === '' ) {
            $family_id = $this->findFamilyIdByName( $family_name, $settings );
            if ( $family_id === '' ) {
                $family_id = $this->makeUniqueFamilyId( $family_name, $settings );
            }
        }
        if ( empty( $families[$family_id] ) ) {
            $families[$family_id] = [
                'id' => $family_id,
                'name' => $family_name,
                'files' => [],
                'created_at' => gmdate( 'c' ),
            ];
        } else {
            $families[$family_id]['name'] = $family_name;
        }

        if ( ! is_dir( $this->files_directory ) ) {
            mkdir( $this->files_directory, 0775, true );
        }

        $file_id = bin2hex( random_bytes( 12 ) );
        $stored_filename = $file_id . '.' . $extension;
        $target_filename = $this->files_directory . DIRECTORY_SEPARATOR . $stored_filename;
        if ( ! @ move_uploaded_file( $tmp_name, $target_filename ) ) {
            return( [ 'ok' => false, 'message' => 'The uploaded font file could not be stored.' ] );
        }

        $families[$family_id]['files'][] = [
            'id' => $file_id,
            'stored_filename' => $stored_filename,
            'original_filename' => $original_filename,
            'format' => $extension,
            'mime' => $mime,
            'size' => filesize( $target_filename ) ?: $size,
            'weight' => trim( (string) ( $data['weight'] ?? '' ) ) !== '' ? $this->normalizeWeight( (string) $data['weight'] ) : (int) ( $inferred_metadata['weight'] ?? 400 ),
            'style' => trim( (string) ( $data['style'] ?? '' ) ) !== '' ? $this->normalizeStyle( (string) $data['style'] ) : (string) ( $inferred_metadata['style'] ?? 'normal' ),
            'display' => $this->normalizeDisplay( (string) ( $data['display'] ?? 'swap' ) ),
            'uploaded_at' => gmdate( 'c' ),
        ];

        $settings['families'] = array_values( $families );
        $this->saveSettings( $settings );
        return( [ 'ok' => true, 'message' => 'Font file added.', 'family_id' => $family_id, 'file_id' => $file_id ] );
    }

    public function deleteFamily( string $family_id ) : array {
        $family_id = $this->normalizeId( $family_id );
        if ( $family_id === '' ) {
            return( [ 'ok' => false, 'message' => 'Font family not found.' ] );
        }

        $settings = $this->getSettings();
        $kept = [];
        $deleted = false;
        foreach ( $settings['families'] as $family ) {
            if ( (string) ( $family['id'] ?? '' ) !== $family_id ) {
                $kept[] = $family;
                continue;
            }
            $deleted = true;
            foreach ( is_array( $family['files'] ?? null ) ? $family['files'] : [] as $font_file ) {
                $this->deleteStoredFile( (string) ( $font_file['stored_filename'] ?? '' ) );
            }
        }
        if ( ! $deleted ) {
            return( [ 'ok' => false, 'message' => 'Font family not found.' ] );
        }

        foreach ( self::ROLES as $role => $label ) {
            if ( (string) ( $settings['assignments'][$role] ?? '' ) === $family_id ) {
                $settings['assignments'][$role] = '';
            }
        }
        $settings['families'] = $kept;
        $this->saveSettings( $settings );
        return( [ 'ok' => true, 'message' => 'Font family deleted.' ] );
    }

    public function deleteFontFile( string $family_id, string $file_id ) : array {
        $family_id = $this->normalizeId( $family_id );
        $file_id = $this->normalizeId( $file_id );
        if ( $family_id === '' || $file_id === '' ) {
            return( [ 'ok' => false, 'message' => 'Font file not found.' ] );
        }

        $settings = $this->getSettings();
        foreach ( $settings['families'] as $family_index => $family ) {
            if ( (string) ( $family['id'] ?? '' ) !== $family_id ) {
                continue;
            }
            $files = [];
            $deleted = false;
            foreach ( is_array( $family['files'] ?? null ) ? $family['files'] : [] as $font_file ) {
                if ( (string) ( $font_file['id'] ?? '' ) !== $file_id ) {
                    $files[] = $font_file;
                    continue;
                }
                $deleted = true;
                $this->deleteStoredFile( (string) ( $font_file['stored_filename'] ?? '' ) );
            }
            if ( ! $deleted ) {
                return( [ 'ok' => false, 'message' => 'Font file not found.' ] );
            }
            $settings['families'][$family_index]['files'] = $files;
            if ( empty( $files ) ) {
                foreach ( self::ROLES as $role => $label ) {
                    if ( (string) ( $settings['assignments'][$role] ?? '' ) === $family_id ) {
                        $settings['assignments'][$role] = '';
                    }
                }
            }
            $this->saveSettings( $settings );
            return( [ 'ok' => true, 'message' => 'Font file deleted.' ] );
        }

        return( [ 'ok' => false, 'message' => 'Font file not found.' ] );
    }

    public function updateFontFileMetadata( string $family_id, string $file_id, array $data ) : array {
        $family_id = $this->normalizeId( $family_id );
        $file_id = $this->normalizeId( $file_id );
        if ( $family_id === '' || $file_id === '' ) {
            return( [ 'ok' => false, 'message' => 'Font file not found.' ] );
        }

        $settings = $this->getSettings();
        foreach ( $settings['families'] as $family_index => $family ) {
            if ( (string) ( $family['id'] ?? '' ) !== $family_id ) {
                continue;
            }
            foreach ( is_array( $family['files'] ?? null ) ? $family['files'] : [] as $file_index => $font_file ) {
                if ( (string) ( $font_file['id'] ?? '' ) !== $file_id ) {
                    continue;
                }
                $settings['families'][$family_index]['files'][$file_index]['weight'] = $this->normalizeWeight( (string) ( $data['weight'] ?? '400' ) );
                $settings['families'][$family_index]['files'][$file_index]['style'] = $this->normalizeStyle( (string) ( $data['style'] ?? 'normal' ) );
                $settings['families'][$family_index]['files'][$file_index]['display'] = $this->normalizeDisplay( (string) ( $data['display'] ?? 'swap' ) );
                $this->saveSettings( $settings );
                return( [ 'ok' => true, 'message' => 'Font file updated.' ] );
            }
            return( [ 'ok' => false, 'message' => 'Font file not found.' ] );
        }

        return( [ 'ok' => false, 'message' => 'Font file not found.' ] );
    }

    public function getGeneratedCss() : string {
        $settings = $this->getSettings();
        $families = $this->getCssFamilyMap( $settings );
        if ( empty( $families ) ) {
            return( '' );
        }

        $css = $this->getFontFaceCss( $settings, $families );
        if ( $css === '' ) {
            return( '' );
        }

        $assignments = is_array( $settings['assignments'] ?? null ) ? $settings['assignments'] : [];
        $role_css = [];
        foreach ( self::ROLES as $role => $label ) {
            $family_id = (string) ( $assignments[$role] ?? '' );
            if ( $family_id !== '' && ! empty( $families[$family_id] ) ) {
                $role_css[$role] = '"' . $families[$family_id] . '"';
            }
        }

        if ( ! empty( $role_css['body'] ) ) {
            $css .= ':root{--tm-font-manager-body:' . $role_css['body'] . ',var(--bs-font-sans-serif);--bs-body-font-family:var(--tm-font-manager-body);}' . "\n";
        }
        if ( ! empty( $role_css['publication_title'] ) ) {
            $css .= ':root{--tm-font-serif:' . $role_css['publication_title'] . ',Cambria,Georgia,"Times New Roman",Times,serif;}' . "\n";
        }
        if ( ! empty( $role_css['monospace'] ) ) {
            $css .= ':root{--bs-font-monospace:' . $role_css['monospace'] . ',SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}' . "\n";
        }
        if ( ! empty( $role_css['heading'] ) ) {
            $css .= '.tm-theme-baseline h1,.tm-theme-baseline h2,.tm-theme-baseline h3,.tm-theme-baseline h4,.tm-theme-baseline h5,.tm-theme-baseline h6,.tm-theme-blocks h1,.tm-theme-blocks h2,.tm-theme-blocks h3,.tm-theme-blocks h4,.tm-theme-blocks h5,.tm-theme-blocks h6,.tm-theme-timeline h1,.tm-theme-timeline h2,.tm-theme-timeline h3,.tm-theme-timeline h4,.tm-theme-timeline h5,.tm-theme-timeline h6,.tm-theme-panel-magazine h1,.tm-theme-panel-magazine h2,.tm-theme-panel-magazine h3,.tm-theme-panel-magazine h4,.tm-theme-panel-magazine h5,.tm-theme-panel-magazine h6{font-family:' . $role_css['heading'] . ',var(--bs-body-font-family);}' . "\n";
        }
        if ( ! empty( $role_css['ui'] ) ) {
            $css .= '.tm-theme-baseline nav,.tm-theme-baseline .navbar,.tm-theme-baseline .btn,.tm-theme-blocks nav,.tm-theme-blocks .navbar,.tm-theme-blocks .btn,.tm-theme-timeline nav,.tm-theme-timeline .navbar,.tm-theme-timeline .btn,.tm-theme-panel-magazine nav,.tm-theme-panel-magazine .navbar,.tm-theme-panel-magazine .btn{font-family:' . $role_css['ui'] . ',var(--bs-body-font-family);}' . "\n";
        }

        return( trim( $css ) . "\n" );
    }

    public function getFontFaceCss( array $settings = [], array $families = [] ) : string {
        $settings = ! empty( $settings ) ? $this->normalizeSettings( $settings ) : $this->getSettings();
        $families = ! empty( $families ) ? $families : $this->getCssFamilyMap( $settings );
        if ( empty( $families ) ) {
            return( '' );
        }

        $css = "/* tinymash Font manager */\n";
        foreach ( $settings['families'] as $family ) {
            $family_id = (string) ( $family['id'] ?? '' );
            if ( empty( $families[$family_id] ) ) {
                continue;
            }
            foreach ( is_array( $family['files'] ?? null ) ? $family['files'] : [] as $font_file ) {
                $file_id = $this->normalizeId( (string) ( $font_file['id'] ?? '' ) );
                $format = strtolower( (string) ( $font_file['format'] ?? '' ) );
                if ( $file_id === '' || ! in_array( $format, self::ALLOWED_EXTENSIONS, true ) ) {
                    continue;
                }
                $css .= '@font-face{font-family:"' . $families[$family_id] . '";';
                $css .= 'src:url("/plugin-font-manager/font/' . rawurlencode( $file_id ) . '") format("' . ( $format === 'woff2' ? 'woff2' : 'woff' ) . '");';
                $css .= 'font-weight:' . $this->normalizeWeight( (string) ( $font_file['weight'] ?? '400' ) ) . ';';
                $css .= 'font-style:' . $this->normalizeStyle( (string) ( $font_file['style'] ?? 'normal' ) ) . ';';
                $css .= 'font-display:' . $this->normalizeDisplay( (string) ( $font_file['display'] ?? 'swap' ) ) . ';';
                $css .= "}\n";
            }
        }

        return( trim( $css ) . "\n" );
    }

    protected function getCssFamilyMap( array $settings ) : array {
        $families = [];
        foreach ( is_array( $settings['families'] ?? null ) ? $settings['families'] : [] as $family ) {
            if ( empty( $family['files'] ) || ! is_array( $family['files'] ) ) {
                continue;
            }
            $family_id = (string) ( $family['id'] ?? '' );
            if ( $family_id === '' ) {
                continue;
            }
            $css_family = $this->getCssFamilyName( $family_id );
            foreach ( $family['files'] as $font_file ) {
                if ( ! is_array( $font_file ) || empty( $font_file['id'] ) || empty( $font_file['format'] ) ) {
                    continue;
                }
                $file_id = $this->normalizeId( (string) $font_file['id'] );
                $format = strtolower( (string) $font_file['format'] );
                if ( $file_id !== '' && in_array( $format, self::ALLOWED_EXTENSIONS, true ) ) {
                    $families[$family_id] = $css_family;
                }
            }
        }
        return( $families );
    }

    public function findFontFile( string $file_id ) : array {
        $file_id = $this->normalizeId( $file_id );
        if ( $file_id === '' ) {
            return( [] );
        }

        foreach ( $this->getSettings()['families'] as $family ) {
            foreach ( is_array( $family['files'] ?? null ) ? $family['files'] : [] as $font_file ) {
                if ( (string) ( $font_file['id'] ?? '' ) !== $file_id ) {
                    continue;
                }
                $stored_filename = (string) ( $font_file['stored_filename'] ?? '' );
                $path = $this->files_directory . DIRECTORY_SEPARATOR . basename( $stored_filename );
                if ( is_file( $path ) && is_readable( $path ) ) {
                    $font_file['path'] = $path;
                    return( $font_file );
                }
            }
        }

        return( [] );
    }

    public function getCssFamilyName( string $family_id ) : string {
        return( 'tm-font-' . $this->normalizeId( $family_id ) );
    }

    public function inferFontMetadata( string $font_filename, string $original_filename = '' ) : array {
        $metadata = $this->inferFontMetadataFromFilename( $original_filename !== '' ? $original_filename : $font_filename );
        $extension = strtolower( pathinfo( $original_filename !== '' ? $original_filename : $font_filename, PATHINFO_EXTENSION ) );

        if ( $extension === 'woff' ) {
            $woff_metadata = $this->inferFontMetadataFromWoff( $font_filename );
            foreach ( [ 'weight', 'style' ] as $key ) {
                if ( isset( $woff_metadata[$key] ) ) {
                    $metadata[$key] = $woff_metadata[$key];
                }
            }
        }

        return(
            [
                'weight' => $this->normalizeWeight( (string) ( $metadata['weight'] ?? 400 ) ),
                'style' => $this->normalizeStyle( (string) ( $metadata['style'] ?? 'normal' ) ),
            ]
        );
    }

    protected function normalizeFamilyRecord( array $family ) : array {
        $id = $this->normalizeId( (string) ( $family['id'] ?? '' ) );
        $name = $this->normalizeFamilyName( (string) ( $family['name'] ?? '' ) );
        if ( $id === '' || $name === '' ) {
            return( [] );
        }

        $files = [];
        foreach ( is_array( $family['files'] ?? null ) ? $family['files'] : [] as $font_file ) {
            if ( ! is_array( $font_file ) ) {
                continue;
            }
            $file_id = $this->normalizeId( (string) ( $font_file['id'] ?? '' ) );
            $stored_filename = basename( (string) ( $font_file['stored_filename'] ?? '' ) );
            $format = strtolower( (string) ( $font_file['format'] ?? pathinfo( $stored_filename, PATHINFO_EXTENSION ) ) );
            if ( $file_id === '' || $stored_filename === '' || ! in_array( $format, self::ALLOWED_EXTENSIONS, true ) ) {
                continue;
            }
            $files[] = [
                'id' => $file_id,
                'stored_filename' => $stored_filename,
                'original_filename' => basename( (string) ( $font_file['original_filename'] ?? $stored_filename ) ),
                'format' => $format,
                'mime' => trim( (string) ( $font_file['mime'] ?? '' ) ),
                'size' => max( 0, (int) ( $font_file['size'] ?? 0 ) ),
                'weight' => $this->normalizeWeight( (string) ( $font_file['weight'] ?? '400' ) ),
                'style' => $this->normalizeStyle( (string) ( $font_file['style'] ?? 'normal' ) ),
                'display' => $this->normalizeDisplay( (string) ( $font_file['display'] ?? 'swap' ) ),
                'uploaded_at' => trim( (string) ( $font_file['uploaded_at'] ?? '' ) ),
            ];
        }

        return(
            [
                'id' => $id,
                'name' => $name,
                'files' => $files,
                'created_at' => trim( (string) ( $family['created_at'] ?? '' ) ),
            ]
        );
    }

    protected function makeUniqueFamilyId( string $family_name, array $settings ) : string {
        $base = $this->normalizeId( $family_name );
        if ( $base === '' ) {
            $base = 'font';
        }
        $used = [];
        foreach ( is_array( $settings['families'] ?? null ) ? $settings['families'] : [] as $family ) {
            $used[(string) ( $family['id'] ?? '' )] = true;
        }
        $candidate = $base;
        $counter = 2;
        while ( isset( $used[$candidate] ) ) {
            $candidate = $base . '-' . $counter;
            $counter++;
        }
        return( $candidate );
    }

    protected function findFamilyIdByName( string $family_name, array $settings ) : string {
        foreach ( is_array( $settings['families'] ?? null ) ? $settings['families'] : [] as $family ) {
            if ( strcasecmp( (string) ( $family['name'] ?? '' ), $family_name ) === 0 ) {
                return( $this->normalizeId( (string) ( $family['id'] ?? '' ) ) );
            }
        }
        return( '' );
    }

    protected function normalizeFamilyName( string $name ) : string {
        $name = function_exists( 'mb_trim' ) ? mb_trim( $name ) : trim( $name );
        $name = preg_replace( '/\s+/', ' ', $name ) ?? '';
        if ( function_exists( 'mb_substr' ) ) {
            return( mb_substr( $name, 0, 80 ) );
        }
        return( substr( $name, 0, 80 ) );
    }

    protected function normalizeId( string $id ) : string {
        $id = strtolower( trim( $id ) );
        $id = preg_replace( '/[^a-z0-9_-]+/', '-', $id ) ?? '';
        return( trim( $id, '-_' ) );
    }

    protected function normalizeWeight( string $weight ) : int {
        $weight = (int) $weight;
        if ( $weight < 100 || $weight > 900 ) {
            return( 400 );
        }
        return( (int) ( round( $weight / 100 ) * 100 ) );
    }

    protected function normalizeStyle( string $style ) : string {
        $style = strtolower( trim( $style ) );
        return( in_array( $style, self::ALLOWED_STYLES, true ) ? $style : 'normal' );
    }

    protected function normalizeDisplay( string $display ) : string {
        $display = strtolower( trim( $display ) );
        return( in_array( $display, self::ALLOWED_DISPLAYS, true ) ? $display : 'swap' );
    }

    protected function detectMimeType( string $filename ) : string {
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo !== false ) {
                $mime_type = finfo_file( $finfo, $filename );
                finfo_close( $finfo );
                if ( is_string( $mime_type ) && $mime_type !== '' ) {
                    return( $mime_type );
                }
            }
        }
        return( 'application/octet-stream' );
    }

    protected function mimeLooksLikeFont( string $mime ) : bool {
        $mime = strtolower( trim( $mime ) );
        return( in_array( $mime, [ 'font/woff', 'font/woff2', 'application/font-woff', 'application/x-font-woff', 'application/octet-stream' ], true ) );
    }

    protected function inferFontMetadataFromFilename( string $filename ) : array {
        $name = pathinfo( basename( $filename ), PATHINFO_FILENAME );
        $name = preg_replace( '/(?<=[a-z])(?=[A-Z])/', ' ', $name ) ?? $name;
        $name = strtolower( $name );
        $name = preg_replace( '/[^a-z0-9]+/', ' ', $name ) ?? '';
        $tokens = array_values( array_filter( explode( ' ', $name ), static fn( string $token ) : bool => $token !== '' ) );
        $joined = implode( ' ', $tokens );
        $weight = null;
        $style = null;

        foreach ( $tokens as $token ) {
            if ( preg_match( '/^[1-9]00$/', $token ) ) {
                $weight = (int) $token;
            }
        }

        if ( $weight === null ) {
            $weight_names = [
                100 => [ 'thin', 'hairline' ],
                200 => [ 'extralight', 'extra light', 'ultralight', 'ultra light' ],
                300 => [ 'light' ],
                400 => [ 'regular', 'book', 'roman', 'normal' ],
                500 => [ 'medium' ],
                600 => [ 'semibold', 'semi bold', 'demibold', 'demi bold' ],
                800 => [ 'extrabold', 'extra bold', 'ultrabold', 'ultra bold', 'heavy' ],
                900 => [ 'black', 'extrablack', 'extra black', 'ultrablack', 'ultra black' ],
                700 => [ 'bold' ],
            ];
            foreach ( $weight_names as $candidate_weight => $names ) {
                foreach ( $names as $name_part ) {
                    if ( preg_match( '/(^| )' . preg_quote( $name_part, '/' ) . '( |$)/', $joined ) === 1 ) {
                        $weight = $candidate_weight;
                        break 2;
                    }
                }
            }
        }

        if ( str_contains( $joined, 'italic' ) || str_contains( $joined, 'oblique' ) || str_contains( $joined, 'kursiv' ) ) {
            $style = 'italic';
        }

        return(
            [
                'weight' => $weight ?? 400,
                'style' => $style ?? 'normal',
            ]
        );
    }

    protected function inferFontMetadataFromWoff( string $font_filename ) : array {
        if ( ! is_file( $font_filename ) || ! is_readable( $font_filename ) ) {
            return( [] );
        }

        $handle = fopen( $font_filename, 'rb' );
        if ( ! is_resource( $handle ) ) {
            return( [] );
        }

        try {
            $header = fread( $handle, 44 );
            if ( ! is_string( $header ) || strlen( $header ) < 44 || substr( $header, 0, 4 ) !== 'wOFF' ) {
                return( [] );
            }
            $values = unpack( 'Nsignature/Nflavor/Nlength/nnumTables/nreserved/NtotalSfntSize/nmajorVersion/nminorVersion/NmetaOffset/NmetaLength/NmetaOrigLength/NprivOffset/NprivLength', $header );
            $num_tables = min( 128, max( 0, (int) ( $values['numTables'] ?? 0 ) ) );
            $tables = [];
            for ( $index = 0; $index < $num_tables; $index++ ) {
                $entry = fread( $handle, 20 );
                if ( ! is_string( $entry ) || strlen( $entry ) < 20 ) {
                    break;
                }
                $entry_values = unpack( 'a4tag/Noffset/NcompLength/NorigLength/Nchecksum', $entry );
                if ( ! is_array( $entry_values ) ) {
                    continue;
                }
                $tag = rtrim( (string) ( $entry_values['tag'] ?? '' ), "\0" );
                if ( in_array( $tag, [ 'OS/2', 'head' ], true ) ) {
                    $tables[$tag] = [
                        'offset' => max( 0, (int) ( $entry_values['offset'] ?? 0 ) ),
                        'comp_length' => max( 0, (int) ( $entry_values['compLength'] ?? 0 ) ),
                        'orig_length' => max( 0, (int) ( $entry_values['origLength'] ?? 0 ) ),
                    ];
                }
            }

            $metadata = [];
            $os2 = $this->readWoffTable( $handle, $tables['OS/2'] ?? [] );
            if ( strlen( $os2 ) >= 6 ) {
                $weight_values = unpack( 'nweight', substr( $os2, 4, 2 ) );
                if ( is_array( $weight_values ) ) {
                    $metadata['weight'] = $this->normalizeWeight( (string) ( $weight_values['weight'] ?? 400 ) );
                }
            }
            if ( strlen( $os2 ) >= 64 ) {
                $selection_values = unpack( 'nselection', substr( $os2, 62, 2 ) );
                $selection = is_array( $selection_values ) ? (int) ( $selection_values['selection'] ?? 0 ) : 0;
                if ( ( $selection & 0x0001 ) !== 0 || ( $selection & 0x0200 ) !== 0 ) {
                    $metadata['style'] = 'italic';
                }
            }

            $head = $this->readWoffTable( $handle, $tables['head'] ?? [] );
            if ( empty( $metadata['style'] ) && strlen( $head ) >= 46 ) {
                $style_values = unpack( 'nmacStyle', substr( $head, 44, 2 ) );
                $mac_style = is_array( $style_values ) ? (int) ( $style_values['macStyle'] ?? 0 ) : 0;
                if ( ( $mac_style & 0x0002 ) !== 0 ) {
                    $metadata['style'] = 'italic';
                }
            }

            return( $metadata );
        } finally {
            fclose( $handle );
        }
    }

    protected function readWoffTable( mixed $handle, array $table ) : string {
        $offset = max( 0, (int) ( $table['offset'] ?? 0 ) );
        $comp_length = max( 0, (int) ( $table['comp_length'] ?? 0 ) );
        $orig_length = max( 0, (int) ( $table['orig_length'] ?? 0 ) );
        if ( ! is_resource( $handle ) || $offset <= 0 || $comp_length <= 0 || $orig_length <= 0 ) {
            return( '' );
        }
        if ( fseek( $handle, $offset ) !== 0 ) {
            return( '' );
        }
        $data = fread( $handle, $comp_length );
        if ( ! is_string( $data ) || strlen( $data ) !== $comp_length ) {
            return( '' );
        }
        if ( $comp_length !== $orig_length ) {
            $decoded = @ gzuncompress( $data );
            return( is_string( $decoded ) ? $decoded : '' );
        }
        return( $data );
    }

    protected function deleteStoredFile( string $stored_filename ) : void {
        $stored_filename = basename( $stored_filename );
        if ( $stored_filename === '' ) {
            return;
        }
        $path = $this->files_directory . DIRECTORY_SEPARATOR . $stored_filename;
        if ( is_file( $path ) ) {
            @ unlink( $path );
        }
    }
}
