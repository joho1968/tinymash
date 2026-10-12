<?php
namespace app\classes;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class TinyMashMailer {

    protected ?TinyMashMarkdownRenderer $markdown_renderer = null;

    public function __construct( ?TinyMashMarkdownRenderer $markdown_renderer = null ) {
        $this->markdown_renderer = $markdown_renderer;
    }

    public function buildPublicMessage(
        string $reason,
        string $text_body,
        string $html_body,
        string $site_name,
        string $site_url,
        array $actions = []
    ) : array {
        $reason = preg_replace( '/\s+/u', ' ', $this->normalizeMailText( $reason ) ) ?? '';
        $text_body = rtrim( str_replace( [ "\r\n", "\r" ], "\n", $text_body ) );
        $site_name = $this->normalizeSiteName( $site_name );
        $site_url = $this->normalizePublicMailUrl( $site_url );
        if ( $reason === '' || $text_body === '' ) {
            throw new \InvalidArgumentException( 'public_message' );
        }
        if ( mb_trim( $html_body ) === '' ) {
            $html_body = nl2br( htmlspecialchars( $text_body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
        }

        $text_footer = "---\n";
        $html_footer = '<hr>';
        foreach ( $actions as $action ) {
            if ( ! is_array( $action ) ) {
                continue;
            }
            $label = preg_replace( '/\s+/u', ' ', $this->normalizeMailText( (string) ( $action['label'] ?? '' ) ) ) ?? '';
            $url = $this->normalizePublicMailUrl( (string) ( $action['url'] ?? '' ) );
            if ( $label === '' || $url === '' ) {
                continue;
            }
            $text_footer .= $label . ': ' . $url . "\n";
            $html_footer .= '<p><a href="' . htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">' . htmlspecialchars( $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</a></p>';
        }

        $text_footer .= 'Sent by ' . $site_name;
        $html_footer .= '<p>Sent by ' . htmlspecialchars( $site_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        if ( $site_url !== '' ) {
            $text_footer .= ' (' . $site_url . ')';
            $escaped_site_url = htmlspecialchars( $site_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
            $html_footer .= ' (<a href="' . $escaped_site_url . '">' . $escaped_site_url . '</a>)';
        }
        $text_footer .= " using tinymash.\n";
        $html_footer .= ' using tinymash.</p>';

        return(
            [
                'text' => $reason . "\n\n" . $text_body . "\n\n" . $text_footer,
                'html' => '<p>' . htmlspecialchars( $reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</p>' . $html_body . $html_footer,
            ]
        );
    }

    public function sendMessage( array $settings, string $recipient_email, string $subject, string $text_body, string $html_body = '', array $headers = [], string $site_name = 'tinymash' ) : void {
        $smtp_settings = $this->normalizeSettings( $settings );
        if ( ! $smtp_settings['enabled'] ) {
            throw new \InvalidArgumentException( 'smtp_disabled' );
        }
        if ( $smtp_settings['host'] === '' ) {
            throw new \InvalidArgumentException( 'smtp_host' );
        }
        if ( $recipient_email === '' || filter_var( $recipient_email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'email' );
        }
        if ( $smtp_settings['from_email'] === '' || filter_var( $smtp_settings['from_email'], FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'smtp_from_email' );
        }

        $subject = preg_replace( '/[\r\n]+/u', ' ', $this->normalizeMailText( $subject ) ) ?? '';
        $text_body = $this->replaceMailEmojiShortcodes( $text_body );
        if ( $subject === '' ) {
            throw new \InvalidArgumentException( 'subject' );
        }
        if ( mb_trim( $text_body ) === '' ) {
            throw new \InvalidArgumentException( 'body' );
        }
        if ( $html_body === '' ) {
            $html_body = nl2br( htmlspecialchars( $text_body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
        } else {
            $html_body = $this->replaceMailEmojiShortcodes( $html_body );
        }

        $site_name = $this->normalizeSiteName( $site_name );
        $transport = Transport::fromDsn( $this->buildDsn( $smtp_settings ) );
        $mailer = new Mailer( $transport );
        $from_address = $this->buildAddress( $smtp_settings['from_email'], $smtp_settings['from_name'] !== '' ? $smtp_settings['from_name'] : $site_name );
        $envelope_sender_email = $smtp_settings['reply_to_email'] !== '' ? $smtp_settings['reply_to_email'] : $smtp_settings['from_email'];

        $message = ( new Email() )
            ->to( $recipient_email )
            ->subject( $subject )
            ->text( $text_body )
            ->html( $html_body )
            ->from( $from_address )
            ->returnPath( $envelope_sender_email );
        if ( $smtp_settings['reply_to_email'] !== '' ) {
            $message->replyTo( $smtp_settings['reply_to_email'] );
        }

        foreach ( $headers as $header_name => $header_value ) {
            $header_name = trim( (string) $header_name );
            $header_value = preg_replace( '/[\r\n]+/u', ' ', trim( (string) $header_value ) ) ?? '';
            if ( preg_match( '/^[A-Za-z0-9-]{1,64}$/', $header_name ) !== 1 || $header_value === '' ) {
                continue;
            }
            $message->getHeaders()->addTextHeader( $header_name, $header_value );
        }

        try {
            $mailer->send(
                $message,
                new Envelope(
                    new Address( $envelope_sender_email ),
                    [ new Address( $recipient_email ) ]
                )
            );
        } catch ( TransportExceptionInterface $e ) {
            throw new \RuntimeException( 'smtp_send_failed', 0, $e );
        }
    }

    public function sendSystemNotificationEmail( array $settings, string $recipient_email, string $subject, string $text_body, string $site_name = 'tinymash' ) : void {
        $smtp_settings = $this->normalizeSettings( $settings );
        if ( ! $smtp_settings['enabled'] ) {
            throw new \InvalidArgumentException( 'smtp_disabled' );
        }
        if ( $smtp_settings['host'] === '' ) {
            throw new \InvalidArgumentException( 'smtp_host' );
        }
        if ( $recipient_email === '' || filter_var( $recipient_email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'email' );
        }
        if ( mb_trim( $subject ) === '' ) {
            throw new \InvalidArgumentException( 'subject' );
        }
        if ( mb_trim( $text_body ) === '' ) {
            throw new \InvalidArgumentException( 'body' );
        }
        if ( $smtp_settings['from_email'] === '' || filter_var( $smtp_settings['from_email'], FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'smtp_from_email' );
        }

        $site_name = $this->normalizeSiteName( $site_name );
        $subject = $this->normalizeMailText( $subject );
        $text_body = $this->replaceMailEmojiShortcodes( $text_body );

        $transport = Transport::fromDsn( $this->buildDsn( $smtp_settings ) );
        $mailer = new Mailer( $transport );
        $from_address = $this->buildAddress( $smtp_settings['from_email'], $smtp_settings['from_name'] !== '' ? $smtp_settings['from_name'] : $site_name );
        $envelope_sender_email = $smtp_settings['reply_to_email'] !== '' ? $smtp_settings['reply_to_email'] : $smtp_settings['from_email'];
        $html_body = nl2br( htmlspecialchars( $text_body, ENT_QUOTES, 'UTF-8' ) );

        $message = ( new Email() )
            ->to( $recipient_email )
            ->subject( $subject )
            ->text( $text_body )
            ->html( '<pre style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre-wrap;">' . $html_body . '</pre>' )
            ->from( $from_address )
            ->returnPath( $envelope_sender_email );
        if ( $smtp_settings['reply_to_email'] !== '' ) {
            $message->replyTo( $smtp_settings['reply_to_email'] );
        }

        try {
            $mailer->send(
                $message,
                new Envelope(
                    new Address( $envelope_sender_email ),
                    [ new Address( $recipient_email ) ]
                )
            );
        } catch ( TransportExceptionInterface $e ) {
            throw new \RuntimeException( 'smtp_send_failed', 0, $e );
        }
    }

    public function sendProfileEmailConfirmation( array $settings, string $recipient_email, string $confirm_url, string $site_name = 'tinymash' ) : void {
        $smtp_settings = $this->normalizeSettings( $settings );
        if ( ! $smtp_settings['enabled'] ) {
            throw new \InvalidArgumentException( 'smtp_disabled' );
        }
        if ( $smtp_settings['host'] === '' ) {
            throw new \InvalidArgumentException( 'smtp_host' );
        }
        if ( $recipient_email === '' || filter_var( $recipient_email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'email' );
        }
        if ( $confirm_url === '' ) {
            throw new \InvalidArgumentException( 'confirm_url' );
        }
        if ( $smtp_settings['from_email'] === '' || filter_var( $smtp_settings['from_email'], FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'smtp_from_email' );
        }

        $site_name = $this->normalizeSiteName( $site_name );

        $transport = Transport::fromDsn( $this->buildDsn( $smtp_settings ) );
        $mailer = new Mailer( $transport );
        $from_address = $this->buildAddress( $smtp_settings['from_email'], $smtp_settings['from_name'] !== '' ? $smtp_settings['from_name'] : $site_name );
        $envelope_sender_email = $smtp_settings['reply_to_email'] !== '' ? $smtp_settings['reply_to_email'] : $smtp_settings['from_email'];
        $public_message = $this->buildPublicMessage(
            'You are receiving this e-mail because a request was made to change the e-mail address on your tinymash account.',
            "Confirm the new address here:\n" . $confirm_url . "\n\nIf you did not request this change, you can ignore this message.",
            '<p><a href="' . htmlspecialchars( $confirm_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">Confirm the new address</a></p><p>If you did not request this change, you can ignore this message.</p>',
            $site_name,
            $this->publicMailSiteUrl( $settings, $confirm_url )
        );

        $message = ( new Email() )
            ->to( $recipient_email )
            ->subject( $this->buildSiteSubject( $site_name, 'Confirm your e-mail address' ) )
            ->text( $public_message['text'] )
            ->html( $public_message['html'] )
            ->from( $from_address )
            ->returnPath( $envelope_sender_email );
        if ( $smtp_settings['reply_to_email'] !== '' ) {
            $message->replyTo( $smtp_settings['reply_to_email'] );
        }

        try {
            $mailer->send(
                $message,
                new Envelope(
                    new Address( $envelope_sender_email ),
                    [ new Address( $recipient_email ) ]
                )
            );
        } catch ( TransportExceptionInterface $e ) {
            throw new \RuntimeException( 'smtp_send_failed', 0, $e );
        }
    }

    public function sendPasswordResetEmail( array $settings, string $recipient_email, string $reset_url, string $site_name = 'tinymash' ) : void {
        $smtp_settings = $this->normalizeSettings( $settings );
        if ( ! $smtp_settings['enabled'] ) {
            throw new \InvalidArgumentException( 'smtp_disabled' );
        }
        if ( $smtp_settings['host'] === '' ) {
            throw new \InvalidArgumentException( 'smtp_host' );
        }
        if ( $recipient_email === '' || filter_var( $recipient_email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'email' );
        }
        if ( $reset_url === '' ) {
            throw new \InvalidArgumentException( 'reset_url' );
        }
        if ( $smtp_settings['from_email'] === '' || filter_var( $smtp_settings['from_email'], FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'smtp_from_email' );
        }

        $site_name = $this->normalizeSiteName( $site_name );

        $transport = Transport::fromDsn( $this->buildDsn( $smtp_settings ) );
        $mailer = new Mailer( $transport );
        $from_address = $this->buildAddress( $smtp_settings['from_email'], $smtp_settings['from_name'] !== '' ? $smtp_settings['from_name'] : $site_name );
        $envelope_sender_email = $smtp_settings['reply_to_email'] !== '' ? $smtp_settings['reply_to_email'] : $smtp_settings['from_email'];
        $public_message = $this->buildPublicMessage(
            'You are receiving this e-mail because a password reset was requested for your tinymash account.',
            "Reset your password here:\n" . $reset_url . "\n\nIf you did not request this change, you can ignore this message.",
            '<p><a href="' . htmlspecialchars( $reset_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">Reset your password</a></p><p>If you did not request this change, you can ignore this message.</p>',
            $site_name,
            $this->publicMailSiteUrl( $settings, $reset_url )
        );

        $message = ( new Email() )
            ->to( $recipient_email )
            ->subject( $this->buildSiteSubject( $site_name, 'Reset your password' ) )
            ->text( $public_message['text'] )
            ->html( $public_message['html'] )
            ->from( $from_address )
            ->returnPath( $envelope_sender_email );
        if ( $smtp_settings['reply_to_email'] !== '' ) {
            $message->replyTo( $smtp_settings['reply_to_email'] );
        }

        try {
            $mailer->send(
                $message,
                new Envelope(
                    new Address( $envelope_sender_email ),
                    [ new Address( $recipient_email ) ]
                )
            );
        } catch ( TransportExceptionInterface $e ) {
            throw new \RuntimeException( 'smtp_send_failed', 0, $e );
        }
    }

    public function sendTestEmail( array $settings, string $recipient_email, string $site_name = 'tinymash' ) : void {
        $smtp_settings = $this->normalizeSettings( $settings );
        if ( ! $smtp_settings['enabled'] ) {
            throw new \InvalidArgumentException( 'smtp_disabled' );
        }
        if ( $smtp_settings['host'] === '' ) {
            throw new \InvalidArgumentException( 'smtp_host' );
        }
        if ( $recipient_email === '' || filter_var( $recipient_email, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'smtp_test_email' );
        }
        if ( $smtp_settings['from_email'] === '' || filter_var( $smtp_settings['from_email'], FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'smtp_from_email' );
        }

        $site_name = $this->normalizeSiteName( $site_name );

        $transport = Transport::fromDsn( $this->buildDsn( $smtp_settings ) );
        $mailer = new Mailer( $transport );
        $timestamp_utc = gmdate( 'Y-m-d H:i:s T' );
        $from_address = $this->buildAddress( $smtp_settings['from_email'], $smtp_settings['from_name'] !== '' ? $smtp_settings['from_name'] : $site_name );
        $envelope_sender_email = $smtp_settings['reply_to_email'] !== '' ? $smtp_settings['reply_to_email'] : $smtp_settings['from_email'];

        $message = ( new Email() )
            ->to( $recipient_email )
            ->subject( $this->buildSiteSubject( $site_name, 'SMTP test e-mail' ) )
            ->text(
                "This is a tinymash SMTP test e-mail.\n\n" .
                'Site: ' . $site_name . "\n" .
                'Host: ' . $smtp_settings['host'] . ':' . $smtp_settings['port'] . "\n" .
                'Sent at: ' . $timestamp_utc . "\n"
            )
            ->html(
                '<p>This is a <strong>tinymash</strong> SMTP test e-mail.</p>' .
                '<ul>' .
                '<li><strong>Site:</strong> ' . htmlspecialchars( $site_name, ENT_QUOTES, 'UTF-8' ) . '</li>' .
                '<li><strong>Host:</strong> ' . htmlspecialchars( $smtp_settings['host'] . ':' . $smtp_settings['port'], ENT_QUOTES, 'UTF-8' ) . '</li>' .
                '<li><strong>Sent at:</strong> ' . htmlspecialchars( $timestamp_utc, ENT_QUOTES, 'UTF-8' ) . '</li>' .
                '</ul>'
            )
            ->from( $from_address )
            ->returnPath( $envelope_sender_email );
        if ( $smtp_settings['reply_to_email'] !== '' ) {
            $message->replyTo( $smtp_settings['reply_to_email'] );
        }

        try {
            $mailer->send(
                $message,
                new Envelope(
                    new Address( $envelope_sender_email ),
                    [ new Address( $recipient_email ) ]
                )
            );
        } catch ( TransportExceptionInterface $e ) {
            throw new \RuntimeException( 'smtp_send_failed', 0, $e );
        }
    }

    protected function buildDsn( array $settings ) : string {
        $scheme = $settings['encryption'] === 'ssl' ? 'smtps' : 'smtp';
        $credentials = '';
        if ( $settings['username'] !== '' ) {
            $credentials = rawurlencode( $settings['username'] );
            if ( $settings['password'] !== '' ) {
                $credentials .= ':' . rawurlencode( $settings['password'] );
            }
            $credentials .= '@';
        }

        $query = [];
        if ( $settings['encryption'] === 'tls' ) {
            $query[] = 'auto_tls=true';
        } elseif ( $settings['encryption'] === 'none' ) {
            $query[] = 'auto_tls=false';
        }

        $dsn = $scheme . '://' . $credentials . $settings['host'] . ':' . $settings['port'];
        if ( ! empty( $query ) ) {
            $dsn .= '?' . implode( '&', $query );
        }

        return( $dsn );
    }

    protected function normalizeSettings( array $settings ) : array {
        return(
            [
                'enabled' => ! empty( $settings['smtp_enabled'] ),
                'host' => trim( (string) ( $settings['smtp_host'] ?? '' ) ),
                'port' => (int) ( $settings['smtp_port'] ?? 587 ),
                'username' => trim( (string) ( $settings['smtp_username'] ?? '' ) ),
                'password' => (string) ( $settings['smtp_password'] ?? '' ),
                'encryption' => strtolower( trim( (string) ( $settings['smtp_encryption'] ?? 'tls' ) ) ),
                'from_email' => mb_trim( (string) ( $settings['smtp_from_email'] ?? '' ) ),
                'from_name' => mb_trim( (string) ( $settings['smtp_from_name'] ?? '' ) ),
                'reply_to_email' => mb_trim( (string) ( $settings['smtp_reply_to_email'] ?? '' ) ),
            ]
        );
    }

    protected function buildAddress( string $email, string $name = '' ) : Address {
        $name = $this->normalizeMailText( $name );
        if ( $name !== '' ) {
            return( new Address( $email, $name ) );
        }

        return( new Address( $email ) );
    }

    protected function buildSiteSubject( string $site_name, string $suffix ) : string {
        return( '[' . $this->normalizeSiteName( $site_name ) . '] ' . $this->normalizeMailText( $suffix ) );
    }

    protected function normalizeSiteName( string $site_name ) : string {
        $site_name = $this->normalizeMailText( $site_name );
        return( $site_name !== '' ? $site_name : 'tinymash' );
    }

    protected function normalizeMailText( string $text ) : string {
        $text = mb_trim( $text );
        if ( $text === '' ) {
            return( '' );
        }

        return( $this->replaceMailEmojiShortcodes( $text ) );
    }

    protected function replaceMailEmojiShortcodes( string $text ) : string {
        if ( $text === '' ) {
            return( '' );
        }

        $markdown_renderer = $this->getMarkdownRenderer();
        if ( $markdown_renderer instanceof TinyMashMarkdownRenderer ) {
            return( $markdown_renderer->replaceEmojiShortcodes( $text ) );
        }

        return( $text );
    }

    protected function normalizePublicMailUrl( string $url ) : string {
        $url = trim( $url );
        if ( $url === '' || filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
            return( '' );
        }
        $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
        return( in_array( $scheme, [ 'http', 'https' ], true ) ? rtrim( $url, '/' ) : '' );
    }

    protected function publicMailSiteUrlFromActionUrl( string $url ) : string {
        $url = $this->normalizePublicMailUrl( $url );
        if ( $url === '' ) {
            return( '' );
        }
        $scheme = (string) parse_url( $url, PHP_URL_SCHEME );
        $host = (string) parse_url( $url, PHP_URL_HOST );
        $port = parse_url( $url, PHP_URL_PORT );
        if ( $scheme === '' || $host === '' ) {
            return( '' );
        }
        return( $scheme . '://' . $host . ( is_int( $port ) ? ':' . $port : '' ) );
    }

    protected function publicMailSiteUrl( array $settings, string $action_url ) : string {
        $site_url = $this->normalizePublicMailUrl( (string) ( $settings['public_site_url'] ?? '' ) );
        return( $site_url !== '' ? $site_url : $this->publicMailSiteUrlFromActionUrl( $action_url ) );
    }

    protected function getMarkdownRenderer() : ?TinyMashMarkdownRenderer {
        if ( $this->markdown_renderer instanceof TinyMashMarkdownRenderer ) {
            return( $this->markdown_renderer );
        }
        if ( class_exists( TinyMashMarkdownRenderer::class ) ) {
            $this->markdown_renderer = new TinyMashMarkdownRenderer();
        }

        return( $this->markdown_renderer );
    }

}
