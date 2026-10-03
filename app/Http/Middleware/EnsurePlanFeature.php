<?php

namespace App\Http\Middleware;

use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeature
{
    public function __construct(private EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $state = $this->entitlements->state();

        if ($state['licensed'] && (bool) data_get($state, 'features.'.$feature, false)) {
            return $next($request);
        }

        $message = $state['licensed']
            ? 'Your current TablePlay plan does not include this feature.'
            : 'This feature is unavailable because the TablePlay licence is not active.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'message' => $message,
                'error' => 'plan_feature_unavailable',
                'feature' => $feature,
                'plan' => data_get($state, 'plan.slug'),
                'license_status' => $state['status'] ?? 'missing',
            ], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, $message);
    }
}
