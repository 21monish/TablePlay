<?php

namespace App\Services;

class OutboundMailSafety
{
    public static function canDeliver(?string $name = null, array $seen = []): bool
    {
        $name ??= (string) config('mail.default');
        if (! $name || in_array($name, $seen, true) || count($seen) >= 10) {
            return false;
        }
        $mailer = config('mail.mailers.'.$name);
        if (! is_array($mailer)) {
            return false;
        }
        $transport = $mailer['transport'] ?? '';
        if (in_array($transport, ['', 'log', 'array'], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $mailer['mailers'] ?? [];
            if (! is_array($children) || ! $children) {
                return false;
            }
            foreach ($children as $child) {
                if (! is_string($child) || ! self::canDeliver($child, [...$seen, $name])) {
                    return false;
                }
            }
        }

        return true;
    }
}
