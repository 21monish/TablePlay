<?php

namespace App\Mail;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Throwable;

class GmailApiTransport extends AbstractTransport
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->ensureConfigured();
        $accessToken = $this->accessToken();

        try {
            $response = $this->http
                ->withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post(self::SEND_URL, [
                    'raw' => $this->base64UrlEncode($message->toString()),
                ]);
        } catch (Throwable $exception) {
            throw new TransportException('The Gmail API could not be reached.', 0, $exception);
        }

        if (! $response->successful()) {
            $reason = $response->json('error.message');

            throw new TransportException(sprintf(
                'The Gmail API rejected the message (HTTP %d)%s.',
                $response->status(),
                is_string($reason) && $reason !== '' ? ': '.$reason : ''
            ));
        }

        $messageId = $response->json('id');

        if (is_string($messageId) && $messageId !== '') {
            $message->setMessageId($messageId);
        }
    }

    private function accessToken(): string
    {
        $cacheKey = 'tableplay:gmail-api:'.hash('sha256', $this->clientId.'|'.$this->refreshToken);
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->timeout(15)
                ->post(self::TOKEN_URL, [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->refreshToken,
                    'grant_type' => 'refresh_token',
                ]);
        } catch (Throwable $exception) {
            throw new TransportException('Google OAuth could not be reached.', 0, $exception);
        }

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            $reason = $response->json('error_description') ?: $response->json('error');

            throw new TransportException(sprintf(
                'Google OAuth rejected the Gmail credentials (HTTP %d)%s.',
                $response->status(),
                is_string($reason) && $reason !== '' ? ': '.$reason : ''
            ));
        }

        $expiresIn = max(120, (int) $response->json('expires_in', 3600));
        $this->cache->put($cacheKey, $accessToken, now()->addSeconds($expiresIn - 60));

        return $accessToken;
    }

    private function ensureConfigured(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            throw new TransportException('The Gmail API OAuth credentials are incomplete.');
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }
}
