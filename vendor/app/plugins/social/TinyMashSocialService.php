<?php

class TinyMashSocialService {

    protected string $site_settings_filename;

    public function __construct( string $site_settings_filename ) {
        $this->site_settings_filename = $site_settings_filename;
    }

    public function getLinkTypes() : array {
        return(
            [
                'website' => [ 'label' => 'Website', 'placeholder' => 'https://example.com', 'scheme' => 'url', 'stroke' => true, 'path' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z M3.6 9h16.8 M3.6 15h16.8 M12 3c2.1 2.2 3.2 5.2 3.2 9s-1.1 6.8-3.2 9c-2.1-2.2-3.2-5.2-3.2-9S9.9 5.2 12 3Z' ],
                'email' => [ 'label' => 'E-mail', 'placeholder' => 'hello@example.com', 'scheme' => 'email', 'path' => 'M2.25 6.75A2.25 2.25 0 0 1 4.5 4.5h15a2.25 2.25 0 0 1 2.25 2.25v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25Zm2.25-.75a.75.75 0 0 0-.75.75v.516l8.25 5.156 8.25-5.156V6.75a.75.75 0 0 0-.75-.75Zm15.75 3.034-7.852 4.908a.75.75 0 0 1-.796 0L3.75 9.034v8.216c0 .414.336.75.75.75h15a.75.75 0 0 0 .75-.75Z' ],
                'rss' => [ 'label' => 'RSS', 'placeholder' => 'https://example.com/feed.xml', 'scheme' => 'url', 'path' => 'M5.25 17.25a2.25 2.25 0 1 1 0 4.5 2.25 2.25 0 0 1 0-4.5ZM3.75 4.5a.75.75 0 0 1 .75-.75c9.665 0 17.25 7.585 17.25 17.25a.75.75 0 0 1-1.5 0c0-8.837-6.913-15.75-15.75-15.75a.75.75 0 0 1-.75-.75Zm0 6a.75.75 0 0 1 .75-.75c6.314 0 11.25 4.936 11.25 11.25a.75.75 0 0 1-1.5 0c0-5.486-4.264-9.75-9.75-9.75a.75.75 0 0 1-.75-.75Z' ],
                'fediverse' => [ 'label' => 'Fediverse / ActivityPub', 'placeholder' => '@user@example.social', 'scheme' => 'url', 'path' => 'M10.91 4.442L0 10.74v2.52L8.727 8.22v10.077l2.182 1.26zM6.545 12l-4.364 2.52 4.364 2.518zm6.545-2.52L17.455 12l-4.364 2.52zm0-5.038L24 10.74v2.52l-10.91 6.298v-2.52L21.819 12 13.091 6.96z' ],
                'mastodon' => [ 'label' => 'Mastodon', 'placeholder' => '@user@mastodon.social', 'scheme' => 'url', 'path' => 'M23.268 5.313c-.35-2.578-2.617-4.61-5.304-5.004C17.51.242 15.792 0 11.813 0h-.03c-3.98 0-4.835.242-5.288.309C3.882.692 1.496 2.518.917 5.127.64 6.412.61 7.837.661 9.143c.074 1.874.088 3.745.26 5.611.118 1.24.325 2.47.62 3.68.55 2.237 2.777 4.098 4.96 4.857 2.336.792 4.849.923 7.256.38.265-.061.527-.132.786-.213.585-.184 1.27-.39 1.774-.753a.057.057 0 0 0 .023-.043v-1.809a.052.052 0 0 0-.02-.041.053.053 0 0 0-.046-.01 20.282 20.282 0 0 1-4.709.545c-2.73 0-3.463-1.284-3.674-1.818a5.593 5.593 0 0 1-.319-1.433.053.053 0 0 1 .066-.054c1.517.363 3.072.546 4.632.546.376 0 .75 0 1.125-.01 1.57-.044 3.224-.124 4.768-.422.038-.008.077-.015.11-.024 2.435-.464 4.753-1.92 4.989-5.604.008-.145.03-1.52.03-1.67.002-.512.167-3.63-.024-5.545zm-3.748 9.195h-2.561V8.29c0-1.309-.55-1.976-1.67-1.976-1.23 0-1.846.79-1.846 2.35v3.403h-2.546V8.663c0-1.56-.617-2.35-1.848-2.35-1.112 0-1.668.668-1.67 1.977v6.218H4.822V8.102c0-1.31.337-2.35 1.011-3.12.696-.77 1.608-1.164 2.74-1.164 1.311 0 2.302.5 2.962 1.498l.638 1.06.638-1.06c.66-.999 1.65-1.498 2.96-1.498 1.13 0 2.043.395 2.74 1.164.675.77 1.012 1.81 1.012 3.12z' ],
                'matrix' => [ 'label' => 'Matrix', 'placeholder' => '@user:matrix.org', 'scheme' => 'matrix', 'path' => 'M.632.55v22.9H2.28V24H0V0h2.28v.55zm7.043 7.26v1.157h.033c.309-.443.683-.784 1.117-1.024.433-.245.936-.365 1.5-.365.54 0 1.033.107 1.481.314.448.208.785.582 1.02 1.108.254-.374.6-.706 1.034-.992.434-.287.95-.43 1.546-.43.453 0 .872.056 1.26.167.388.11.716.286.993.53.276.245.489.559.646.951.152.392.23.863.23 1.417v5.728h-2.349V11.52c0-.286-.01-.559-.032-.812a1.755 1.755 0 0 0-.18-.66 1.106 1.106 0 0 0-.438-.448c-.194-.11-.457-.166-.785-.166-.332 0-.6.064-.803.189a1.38 1.38 0 0 0-.48.499 1.946 1.946 0 0 0-.231.696 5.56 5.56 0 0 0-.06.785v4.768h-2.35v-4.8c0-.254-.004-.503-.018-.752a2.074 2.074 0 0 0-.143-.688 1.052 1.052 0 0 0-.415-.503c-.194-.125-.476-.19-.854-.19-.111 0-.259.024-.439.074-.18.051-.36.143-.53.282-.171.138-.319.337-.439.595-.12.259-.18.6-.18 1.02v4.966H5.46V7.81zm15.693 15.64V.55H21.72V0H24v24h-2.28v-.55z' ],
                'bluesky' => [ 'label' => 'Bluesky', 'placeholder' => 'https://bsky.app/profile/user.bsky.social', 'scheme' => 'url', 'path' => 'M5.202 2.857C7.954 4.922 10.913 9.11 12 11.358c1.087-2.247 4.046-6.436 6.798-8.501C20.783 1.366 24 .213 24 3.883c0 .732-.42 6.156-.667 7.037-.856 3.061-3.978 3.842-6.755 3.37 4.854.826 6.089 3.562 3.422 6.299-5.065 5.196-7.28-1.304-7.847-2.97-.104-.305-.152-.448-.153-.327 0-.121-.05.022-.153.327-.568 1.666-2.782 8.166-7.847 2.97-2.667-2.737-1.432-5.473 3.422-6.3-2.777.473-5.899-.308-6.755-3.369C.42 10.04 0 4.615 0 3.883c0-3.67 3.217-2.517 5.202-1.026' ],
                'codeberg' => [ 'label' => 'Codeberg', 'placeholder' => 'https://codeberg.org/user', 'scheme' => 'url', 'path' => 'M11.999.747A11.974 11.974 0 0 0 0 12.75c0 2.254.635 4.465 1.833 6.376L11.837 6.19c.072-.092.251-.092.323 0l4.178 5.402h-2.992l.065.239h3.113l.882 1.138h-3.674l.103.374h3.86l.777 1.003h-4.358l.135.483h4.593l.695.894h-5.038l.165.589h5.326l.609.785h-5.717l.182.65h6.038l.562.727h-6.397l.183.65h6.717A12.003 12.003 0 0 0 24 12.75 11.977 11.977 0 0 0 11.999.747zm3.654 19.104.182.65h5.326c.173-.204.353-.433.513-.65zm.385 1.377.18.65h3.563c.233-.198.485-.428.712-.65zm.383 1.377.182.648h1.203c.356-.204.685-.412 1.042-.648z' ],
                'github' => [ 'label' => 'GitHub', 'placeholder' => 'https://github.com/user', 'scheme' => 'url', 'path' => 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12' ],
                'youtube' => [ 'label' => 'YouTube', 'placeholder' => 'https://youtube.com/@channel', 'scheme' => 'url', 'path' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z' ],
                'instagram' => [ 'label' => 'Instagram', 'placeholder' => 'https://instagram.com/user', 'scheme' => 'url', 'path' => 'M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077' ],
                'facebook' => [ 'label' => 'Facebook', 'placeholder' => 'https://facebook.com/page', 'scheme' => 'url', 'path' => 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z' ],
                'x' => [ 'label' => 'X', 'placeholder' => 'https://x.com/user', 'scheme' => 'url', 'path' => 'M14.234 10.162 22.977 0h-2.072l-7.591 8.824L7.251 0H.258l9.168 13.343L.258 24H2.33l8.016-9.318L16.749 24h6.993zm-2.837 3.299-.929-1.329L3.076 1.56h3.182l5.965 8.532.929 1.329 7.754 11.09h-3.182z' ],
                'generic' => [ 'label' => 'Other link', 'placeholder' => 'https://example.com/profile', 'scheme' => 'url', 'path' => 'M8.465 11.293a.75.75 0 0 1 1.061 1.061l-2.122 2.122a3 3 0 1 0 4.243 4.243l2.122-2.121a.75.75 0 1 1 1.06 1.06l-2.12 2.122a4.5 4.5 0 0 1-6.365-6.364Zm3.889-3.89a.75.75 0 1 1 1.06 1.061l-4.95 4.95a.75.75 0 1 1-1.06-1.06Zm1.06-1.06 2.122-2.122a4.5 4.5 0 1 1 6.364 6.364l-2.121 2.122a.75.75 0 0 1-1.061-1.061l2.121-2.122a3 3 0 1 0-4.242-4.243l-2.122 2.122a.75.75 0 0 1-1.06-1.06Z' ],
            ]
        );
    }

    public function getSiteSettings() : array {
        if ( ! is_file( $this->site_settings_filename ) || ! is_readable( $this->site_settings_filename ) ) {
            return( $this->getDefaultSettings() );
        }

        $json = file_get_contents( $this->site_settings_filename );
        $data = is_string( $json ) ? json_decode( $json, true, 16 ) : null;
        return( $this->normalizeSettings( is_array( $data ) ? $data : [] ) );
    }

    public function saveSiteSettings( array $input ) : array {
        $settings = $this->normalizeSettings( $input );
        $directory = dirname( $this->site_settings_filename );
        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0775, true );
        }

        file_put_contents(
            $this->site_settings_filename,
            json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n",
            LOCK_EX
        );

        return( $settings );
    }

    public function normalizeSettings( array $input ) : array {
        return(
            [
                'enabled' => array_key_exists( 'enabled', $input ) ? ! empty( $input['enabled'] ) : true,
                'show_in_footer' => array_key_exists( 'show_in_footer', $input ) ? ! empty( $input['show_in_footer'] ) : true,
                'show_in_sidebar' => ! empty( $input['show_in_sidebar'] ),
                'fallback_to_site_for_author' => array_key_exists( 'fallback_to_site_for_author', $input ) ? ! empty( $input['fallback_to_site_for_author'] ) : true,
                'section_label' => $this->normalizeSectionLabel( (string) ( $input['section_label'] ?? '' ) ),
                'links' => $this->normalizeLinks( $input['links'] ?? [] ),
            ]
        );
    }

    public function getDefaultSettings() : array {
        return(
            [
                'enabled' => true,
                'show_in_footer' => true,
                'show_in_sidebar' => false,
                'fallback_to_site_for_author' => true,
                'section_label' => '',
                'links' => [],
            ]
        );
    }

    public function normalizeLinks( mixed $links ) : array {
        $normalized = [];
        $types = $this->getLinkTypes();
        foreach ( is_array( $links ) ? $links : [] as $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }

            $type = $this->normalizeType( (string) ( $link['type'] ?? 'generic' ) );
            if ( $type === '' || ! isset( $types[$type] ) ) {
                $type = 'generic';
            }

            $address = $this->normalizeAddress( $type, (string) ( $link['address'] ?? '' ) );
            if ( $address === '' ) {
                continue;
            }

            $label = trim( (string) ( $link['label'] ?? '' ) );
            $normalized[] = [
                'enabled' => array_key_exists( 'enabled', $link ) ? ! empty( $link['enabled'] ) : true,
                'type' => $type,
                'label' => $label,
                'address' => $address,
                'url' => $this->resolveUrl( $type, $address ),
            ];
        }

        return( array_slice( $normalized, 0, 24 ) );
    }

    public function normalizePostedSettings( array $data ) : array {
        return(
            [
                'enabled' => ! empty( $data['enabled'] ),
                'show_in_footer' => ! empty( $data['show_in_footer'] ),
                'show_in_sidebar' => ! empty( $data['show_in_sidebar'] ),
                'fallback_to_site_for_author' => ! empty( $data['fallback_to_site_for_author'] ),
                'section_label' => $this->normalizeSectionLabel( (string) ( $data['section_label'] ?? '' ) ),
                'links' => $this->normalizeLinks( $data['links'] ?? [] ),
            ]
        );
    }

    public function renderLinksHtml( array $settings, string $heading = 'Social links', bool $content_safe = false, string $layout = 'icons' ) : string {
        $settings = $this->normalizeSettings( $settings );
        if ( empty( $settings['enabled'] ) || empty( $settings['links'] ) ) {
            return( '' );
        }
        $layout = $this->normalizeLayout( $layout );

        $section_label = $this->normalizeSectionLabel( (string) ( $settings['section_label'] ?? '' ) );
        $nav_label = $section_label !== '' ? $section_label : $heading;
        $items = [];
        foreach ( $settings['links'] as $link ) {
            if ( empty( $link['enabled'] ) ) {
                continue;
            }
            $type = (string) ( $link['type'] ?? 'generic' );
            $label = trim( (string) ( $link['label'] ?? '' ) );
            if ( $label === '' ) {
                $label = $this->getTypeLabel( $type );
            }
            $url = trim( (string) ( $link['url'] ?? '' ) );
            if ( $url === '' ) {
                continue;
            }
            $address = trim( (string) ( $link['address'] ?? '' ) );
            $items[] = [
                'type' => $type,
                'type_label' => $this->getTypeLabel( $type ),
                'label' => $label,
                'url' => $url,
                'address' => $address,
                'title' => $label . ( $address !== '' ? ' - ' . $address : '' ),
            ];
        }
        if ( empty( $items ) ) {
            return( '' );
        }

        $layout_class = $layout === 'icons' ? 'tm-social-links-icons' : 'tm-social-links-layout-' . $layout;
        $html = $content_safe
            ? '<div class="tm-social-links ' . $this->escape( $layout_class ) . '" role="navigation" aria-label="' . $this->escape( $nav_label ) . '">'
            : '<nav class="tm-social-links ' . $this->escape( $layout_class ) . '" aria-label="' . $this->escape( $nav_label ) . '">';
        if ( $section_label !== '' ) {
            $html .= '<div class="tm-social-section-label">' . $this->escape( $section_label ) . '</div>';
        }

        if ( $layout === 'list' ) {
            $html .= '<ul class="tm-social-layout-list">';
            foreach ( $items as $item ) {
                $html .= '<li class="tm-social-layout-list-item"><a class="tm-social-layout-link tm-social-link-' . $this->escape( (string) $item['type'] ) . '" href="' . $this->escape( (string) $item['url'] ) . '" rel="me noopener noreferrer" target="_blank" title="' . $this->escape( (string) $item['title'] ) . '">';
                $html .= '<span class="tm-social-layout-icon">' . ( $content_safe ? $this->renderIconSpan( (string) $item['type'] ) : $this->renderIconSvg( (string) $item['type'] ) ) . '</span>';
                $html .= '<span class="tm-social-layout-copy"><span class="tm-social-layout-title">' . $this->escape( (string) $item['label'] ) . '</span>';
                if ( (string) $item['address'] !== '' ) {
                    $html .= '<span class="tm-social-layout-address text-truncate" title="' . $this->escape( (string) $item['url'] ) . '">' . $this->escape( (string) $item['address'] ) . '</span>';
                }
                $html .= '</span></a></li>';
            }
            $html .= '</ul>';
            $html .= $content_safe ? '</div>' : '</nav>';
            return( $html );
        }

        if ( $layout === 'cards' ) {
            $html .= '<div class="tm-social-card-grid">';
            foreach ( $items as $item ) {
                $html .= '<a class="tm-social-card tm-social-link-' . $this->escape( (string) $item['type'] ) . '" href="' . $this->escape( (string) $item['url'] ) . '" rel="me noopener noreferrer" target="_blank" title="' . $this->escape( (string) $item['title'] ) . '">';
                $html .= '<span class="tm-social-card-header"><span class="tm-social-layout-icon">' . ( $content_safe ? $this->renderIconSpan( (string) $item['type'] ) : $this->renderIconSvg( (string) $item['type'] ) ) . '</span><span class="tm-social-card-type">' . $this->escape( (string) $item['type_label'] ) . '</span></span>';
                $html .= '<span class="tm-social-card-body"><span class="tm-social-layout-title">' . $this->escape( (string) $item['label'] ) . '</span>';
                if ( (string) $item['address'] !== '' ) {
                    $html .= '<span class="tm-social-layout-address text-truncate" title="' . $this->escape( (string) $item['url'] ) . '">' . $this->escape( (string) $item['address'] ) . '</span>';
                }
                $html .= '</span></a>';
            }
            $html .= '</div>';
            $html .= $content_safe ? '</div>' : '</nav>';
            return( $html );
        }

        $html .= '<ul class="tm-social-links-list">';
        foreach ( $items as $item ) {
            $html .= '<li class="tm-social-links-item"><a class="tm-social-link tm-social-link-' . $this->escape( (string) $item['type'] ) . '" href="' . $this->escape( (string) $item['url'] ) . '" rel="me noopener noreferrer" target="_blank" aria-label="' . $this->escape( (string) $item['label'] ) . '" title="' . $this->escape( (string) $item['title'] ) . '">';
            $html .= $content_safe ? $this->renderIconSpan( (string) $item['type'] ) : $this->renderIconSvg( (string) $item['type'] );
            $html .= '<span class="visually-hidden">' . $this->escape( (string) $item['label'] ) . '</span></a></li>';
        }
        $html .= $content_safe ? '</ul></div>' : '</ul></nav>';

        return( $html );
    }

    public function renderAdminFormFields( array $settings, string $prefix = '', string $submit_label = '', bool $show_author_fallback_setting = true ) : string {
        $settings = $this->normalizeSettings( $settings );
        $links = $settings['links'];
        $link_types = $this->getLinkTypes();
        uasort(
            $link_types,
            static fn( array $left, array $right ) : int => strcasecmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) )
        );
        for ( $index = count( $links ); $index < 8; $index++ ) {
            $links[] = [ 'enabled' => false, 'type' => 'generic', 'label' => '', 'address' => '', 'url' => '' ];
        }

        $name = static function( string $field ) use ( $prefix ) : string {
            return( $prefix !== '' ? $prefix . '[' . $field . ']' : $field );
        };

        $html = '<div class="row g-3 mb-3">';
        $html .= '<div class="col-12 col-lg-6"><div class="h-100 p-3 bg-body-tertiary rounded-3">';
        $html .= '<div class="d-grid gap-2">';
        $html .= '<div class="form-check"><input class="form-check-input" id="tm-social-enabled" name="' . $this->escape( $name( 'enabled' ) ) . '" type="checkbox" value="1"' . ( ! empty( $settings['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-social-enabled">Enable Social output</label></div>';
        $html .= '<div class="form-check"><input class="form-check-input" id="tm-social-footer" name="' . $this->escape( $name( 'show_in_footer' ) ) . '" type="checkbox" value="1"' . ( ! empty( $settings['show_in_footer'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-social-footer">Show in footer slot</label></div>';
        $html .= '<div class="form-check"><input class="form-check-input" id="tm-social-sidebar" name="' . $this->escape( $name( 'show_in_sidebar' ) ) . '" type="checkbox" value="1"' . ( ! empty( $settings['show_in_sidebar'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-social-sidebar">Show in sidebar slot</label></div>';
        if ( $show_author_fallback_setting ) {
            $html .= '<div class="form-check"><input class="form-check-input" id="tm-social-fallback-to-site-for-author" name="' . $this->escape( $name( 'fallback_to_site_for_author' ) ) . '" type="checkbox" value="1"' . ( ! empty( $settings['fallback_to_site_for_author'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-social-fallback-to-site-for-author">Use site links when profile links are empty</label></div>';
        }
        $html .= '</div></div></div>';
        $html .= '<div class="col-12 col-lg-6"><div class="h-100 p-3 bg-body-tertiary rounded-3">';
        $html .= '<div class="d-grid gap-3">';
        $html .= '<div><label class="form-label small mb-1" for="tm-social-section-label">Section label</label><input class="form-control" id="tm-social-section-label" name="' . $this->escape( $name( 'section_label' ) ) . '" type="text" maxlength="80" placeholder="Find me" value="' . $this->escape( (string) ( $settings['section_label'] ?? '' ) ) . '"><div class="form-text">Optional label shown above the links.</div></div>';
        if ( $submit_label !== '' ) {
            $html .= '<button class="btn btn-primary" type="submit">' . $this->escape( $submit_label ) . '</button>';
        }
        $html .= '</div></div></div>';
        $html .= '</div>';

        $links_name = $name( 'links' );
        $html .= '<div class="row row-cols-1 row-cols-lg-2 row-cols-xxl-3 g-2" data-tm-social-link-list data-tm-reorder-list data-tm-reorder-name="' . $this->escape( $links_name ) . '" data-tm-reorder-id-prefix="tm-social-link" data-tm-reorder-swap-threshold="0.65">';
        foreach ( $links as $index => $link ) {
            $field_prefix = $links_name . '[' . (int) $index . ']';
            $html .= '<div class="col" data-tm-social-link-row data-tm-reorder-item draggable="false"><div class="h-100 p-2 bg-body-secondary rounded-3">';
            $html .= '<div class="d-flex justify-content-end gap-1 mb-2"><button class="btn btn-outline-secondary btn-sm" type="button" data-tm-social-link-move="up" data-tm-reorder-move="up" title="Move earlier" aria-label="Move social link earlier"><span class="bi bi-arrow-up" aria-hidden="true"></span></button><button class="btn btn-outline-secondary btn-sm" type="button" data-tm-social-link-move="down" data-tm-reorder-move="down" title="Move later" aria-label="Move social link later"><span class="bi bi-arrow-down" aria-hidden="true"></span></button><button class="btn btn-outline-secondary btn-sm" type="button" data-tm-social-link-drag-handle data-tm-reorder-handle title="Drag to reorder" aria-label="Drag to reorder social link"><span class="bi bi-grip-vertical" aria-hidden="true"></span></button></div>';
            $html .= '<div class="row g-2 align-items-end">';
            $html .= '<div class="col-12 col-sm-4"><div class="form-check mb-sm-2"><input class="form-check-input" id="tm-social-link-enabled-' . (int) $index . '" name="' . $this->escape( $field_prefix . '[enabled]' ) . '" type="checkbox" value="1" data-tm-social-link-field="enabled" data-tm-reorder-field="enabled"' . ( ! empty( $link['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-social-link-enabled-' . (int) $index . '">Active</label></div></div>';
            $html .= '<div class="col-12 col-sm-8"><label class="form-label small mb-1" for="tm-social-link-type-' . (int) $index . '">Type</label><select class="form-select" id="tm-social-link-type-' . (int) $index . '" name="' . $this->escape( $field_prefix . '[type]' ) . '" data-tm-social-link-field="type" data-tm-reorder-field="type">';
            foreach ( $link_types as $type => $definition ) {
                $html .= '<option value="' . $this->escape( $type ) . '"' . ( (string) ( $link['type'] ?? 'generic' ) === $type ? ' selected' : '' ) . '>' . $this->escape( (string) $definition['label'] ) . '</option>';
            }
            $html .= '</select></div>';
            $html .= '<div class="col-12"><label class="form-label small mb-1" for="tm-social-link-label-' . (int) $index . '">Label</label><input class="form-control" id="tm-social-link-label-' . (int) $index . '" name="' . $this->escape( $field_prefix . '[label]' ) . '" type="text" maxlength="80" value="' . $this->escape( (string) ( $link['label'] ?? '' ) ) . '" data-tm-social-link-field="label" data-tm-reorder-field="label"></div>';
            $html .= '<div class="col-12"><label class="form-label small mb-1" for="tm-social-link-address-' . (int) $index . '">Address</label><input class="form-control" id="tm-social-link-address-' . (int) $index . '" name="' . $this->escape( $field_prefix . '[address]' ) . '" type="text" maxlength="300" value="' . $this->escape( (string) ( $link['address'] ?? '' ) ) . '" data-tm-social-link-field="address" data-tm-reorder-field="address"></div>';
            $html .= '</div></div></div>';
        }
        $html .= '</div>';

        return( $html );
    }

    protected function normalizeType( string $type ) : string {
        $type = strtolower( trim( $type ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $type ) ?? '' );
    }

    protected function normalizeSectionLabel( string $label ) : string {
        $label = function_exists( 'mb_trim' ) ? mb_trim( $label ) : trim( $label );
        $label = preg_replace( '/\s+/', ' ', $label ) ?? '';
        if ( function_exists( 'mb_substr' ) ) {
            return( mb_substr( $label, 0, 80 ) );
        }
        return( substr( $label, 0, 80 ) );
    }

    protected function normalizeLayout( string $layout ) : string {
        $layout = strtolower( trim( $layout ) );
        return( in_array( $layout, [ 'icons', 'list', 'cards' ], true ) ? $layout : 'icons' );
    }

    protected function normalizeAddress( string $type, string $address ) : string {
        $address = function_exists( 'mb_trim' ) ? mb_trim( $address ) : trim( $address );
        $address = preg_replace( '/\s+/', '', $address ) ?? '';
        if ( $address === '' ) {
            return( '' );
        }
        if ( $type === 'email' ) {
            return( filter_var( $address, FILTER_VALIDATE_EMAIL ) !== false ? $address : '' );
        }
        if ( $type === 'matrix' ) {
            return( preg_match( '/^@[A-Za-z0-9._=\-\/]+:[A-Za-z0-9.-]+$/', $address ) === 1 ? $address : '' );
        }
        if ( in_array( $type, [ 'fediverse', 'mastodon' ], true ) ) {
            $fediverse_handle = $this->normalizeFediverseHandle( $address );
            if ( $fediverse_handle !== '' ) {
                return( $fediverse_handle );
            }
        }

        if ( ! str_starts_with( strtolower( $address ), 'http://' ) && ! str_starts_with( strtolower( $address ), 'https://' ) ) {
            $address = 'https://' . ltrim( $address, '/' );
        }
        $scheme = strtolower( (string) ( parse_url( $address, PHP_URL_SCHEME ) ?? '' ) );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || filter_var( $address, FILTER_VALIDATE_URL ) === false ) {
            return( '' );
        }

        return( $address );
    }

    protected function resolveUrl( string $type, string $address ) : string {
        if ( $type === 'email' ) {
            return( 'mailto:' . $address );
        }
        if ( $type === 'matrix' ) {
            return( 'https://matrix.to/#/' . rawurlencode( $address ) );
        }
        if ( in_array( $type, [ 'fediverse', 'mastodon' ], true ) && preg_match( '/^@([^@\s]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})$/', $address, $matches ) === 1 ) {
            return( 'https://' . strtolower( $matches[2] ) . '/@' . rawurlencode( $matches[1] ) );
        }

        return( $address );
    }

    protected function normalizeFediverseHandle( string $address ) : string {
        if ( str_starts_with( $address, '@' ) ) {
            $address = substr( $address, 1 );
        }
        if ( str_starts_with( $address, '@' ) ) {
            return( '' );
        }
        if ( preg_match( '/^([A-Za-z0-9._-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})$/', $address, $matches ) !== 1 ) {
            return( '' );
        }
        $host = strtolower( $matches[2] );
        if ( str_contains( $host, '..' ) || str_starts_with( $host, '-' ) || str_ends_with( $host, '-' ) ) {
            return( '' );
        }

        return( '@' . $matches[1] . '@' . $host );
    }

    protected function getTypeLabel( string $type ) : string {
        $types = $this->getLinkTypes();
        return( (string) ( $types[$type]['label'] ?? 'Link' ) );
    }

    protected function renderIconSvg( string $type ) : string {
        $types = $this->getLinkTypes();
        $definition = is_array( $types[$type] ?? null ) ? $types[$type] : $types['generic'];
        $path = trim( (string) ( $definition['path'] ?? $types['generic']['path'] ) );
        if ( ! empty( $definition['stroke'] ) ) {
            return( '<svg class="tm-social-icon" aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="' . $this->escape( $path ) . '"></path></svg>' );
        }
        return( '<svg class="tm-social-icon" aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="' . $this->escape( $path ) . '"></path></svg>' );
    }

    protected function renderIconSpan( string $type ) : string {
        $types = $this->getLinkTypes();
        $type = isset( $types[$type] ) ? $type : 'generic';
        return( '<span class="tm-social-icon tm-social-icon-font tm-social-icon-font-' . $this->escape( $type ) . '" aria-hidden="true"></span>' );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
