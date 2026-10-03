<?php

namespace App\Http\Middleware;

use App\Models\{Device, User};
use App\Services\BroadcastChannelAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeBroadcastChannel
{
    public function __construct(private BroadcastChannelAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $channel = (string) $request->input('channel_name');

        abort_unless(
            ($actor instanceof User || $actor instanceof Device)
                && $this->access->allows($actor, $channel),
            403,
            'You are not authorized for this TablePlay channel.'
        );

        return $next($request);
    }
}
