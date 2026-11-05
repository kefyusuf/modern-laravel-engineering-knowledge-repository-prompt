<?php

namespace MLE\Examples\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * observability/log-context — request correlation middleware.
 */
final class CorrelateRequest
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = $request->header('X-Request-Id') ?? (string) Str::uuid();

        Log::withContext([
            'request_id' => $requestId,
            'tenant_id' => $request->header('X-Tenant-Id'),
            'path' => $request->path(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
