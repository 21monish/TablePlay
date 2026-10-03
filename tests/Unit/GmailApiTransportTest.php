<?php

namespace Tests\Unit;

use App\Mail\GmailApiTransport;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class GmailApiTransportTest extends TestCase
{
    public function test_it_refreshes_a_token_and_sends_rfc822_mail_over_https(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'short-lived-access-token',
                'expires_in' => 3600,
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'id' => 'gmail-message-id',
            ]),
        ]);

        $transport = new GmailApiTransport(
            app(Factory::class),
            new Repository(new ArrayStore),
            'client-id',
            'client-secret',
            'refresh-token',
        );

        $sent = $transport->send(
            (new Email)
                ->from('sender@example.com')
                ->to('recipient@example.com')
                ->subject('Verify your email')
                ->text('Verification link')
        );

        $this->assertSame('gmail-message-id', $sent?->getMessageId());

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-token'
        );

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send') {
                return false;
            }

            $raw = strtr((string) $request['raw'], '-_', '+/');
            $raw .= str_repeat('=', (4 - strlen($raw) % 4) % 4);
            $decoded = base64_decode($raw, true);

            return $request->hasHeader('Authorization', 'Bearer short-lived-access-token')
                && is_string($decoded)
                && str_contains($decoded, 'Subject: Verify your email')
                && str_contains($decoded, 'recipient@example.com');
        });
    }

    public function test_it_fails_closed_when_oauth_credentials_are_missing(): void
    {
        Http::fake();

        $transport = new GmailApiTransport(
            app(Factory::class),
            new Repository(new ArrayStore),
            '',
            '',
            '',
        );

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('credentials are incomplete');

        $transport->send(
            (new Email)
                ->from('sender@example.com')
                ->to('recipient@example.com')
                ->subject('Verify')
                ->text('Body')
        );
    }
}
