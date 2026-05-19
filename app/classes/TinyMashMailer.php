<?php
namespace app\classes;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class TinyMashMailer {

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
        if ( trim( $subject ) === '' ) {
            throw new \InvalidArgumentException( 'subject' );
        }
        if ( trim( $text_body ) === '' ) {
            throw new \InvalidArgumentException( 'body' );
        }
        if ( $smtp_settings['from_email'] === '' || filter_var( $smtp_settings['from_email'], FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'smtp_from_email' );
        }

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

        $transport = Transport::fromDsn( $this->buildDsn( $smtp_settings ) );
        $mailer = new Mailer( $transport );
        $from_address = $this->buildAddress( $smtp_settings['from_email'], $smtp_settings['from_name'] );
        $envelope_sender_email = $smtp_settings['reply_to_email'] !== '' ? $smtp_settings['reply_to_email'] : $smtp_settings['from_email'];

        $message = ( new Email() )
            ->to( $recipient_email )
            ->subject( '[' . $site_name . '] Confirm your e-mail address' )
            ->text(
                "A request was made to change the e-mail address on your tinymash account.\n\n" .
                "Confirm the new address here:\n" .
                $confirm_url . "\n\n" .
                "If you did not request this change, you can ignore this message.\n"
            )
            ->html(
                '<p>A request was made to change the e-mail address on your <strong>tinymash</strong> account.</p>' .
                '<p><a href="' . htmlspecialchars( $confirm_url, ENT_QUOTES, 'UTF-8' ) . '">Confirm the new address</a></p>' .
                '<p>If you did not request this change, you can ignore this message.</p>'
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

        $transport = Transport::fromDsn( $this->buildDsn( $smtp_settings ) );
        $mailer = new Mailer( $transport );
        $from_address = $this->buildAddress( $smtp_settings['from_email'], $smtp_settings['from_name'] !== '' ? $smtp_settings['from_name'] : $site_name );
        $envelope_sender_email = $smtp_settings['reply_to_email'] !== '' ? $smtp_settings['reply_to_email'] : $smtp_settings['from_email'];

        $message = ( new Email() )
            ->to( $recipient_email )
            ->subject( '[' . $site_name . '] Reset your password' )
            ->text(
                "A request was made to reset the password for your tinymash account.\n\n"
                . "Reset your password here:\n"
                . $reset_url . "\n\n"
                . "If you did not request this change, you can ignore this message.\n"
            )
            ->html(
                '<p>A request was made to reset the password for your <strong>tinymash</strong> account.</p>'
                . '<p><a href="' . htmlspecialchars( $reset_url, ENT_QUOTES, 'UTF-8' ) . '">Reset your password</a></p>'
                . '<p>If you did not request this change, you can ignore this message.</p>'
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

        $transport = Transport::fromDsn( $this->buildDsn( $smtp_settings ) );
        $mailer = new Mailer( $transport );
        $timestamp_utc = gmdate( 'Y-m-d H:i:s T' );
        $from_address = $this->buildAddress( $smtp_settings['from_email'], $smtp_settings['from_name'] );
        $envelope_sender_email = $smtp_settings['reply_to_email'] !== '' ? $smtp_settings['reply_to_email'] : $smtp_settings['from_email'];

        $message = ( new Email() )
            ->to( $recipient_email )
            ->subject( '[' . $site_name . '] SMTP test e-mail' )
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
                'from_email' => trim( (string) ( $settings['smtp_from_email'] ?? '' ) ),
                'from_name' => trim( (string) ( $settings['smtp_from_name'] ?? '' ) ),
                'reply_to_email' => trim( (string) ( $settings['smtp_reply_to_email'] ?? '' ) ),
            ]
        );
    }

    protected function buildAddress( string $email, string $name = '' ) : Address {
        if ( $name !== '' ) {
            return( new Address( $email, $name ) );
        }

        return( new Address( $email ) );
    }

}// TinyMashMailer
