<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
        {
            if (!$request->is('api/*')) {
                return $next($request);
            }

            $startTime = microtime(true);
            $requestId = (string) Str::uuid();

            $response = $next($request);

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            try {
                ApiRequestLog::create([
                    'user_id'      => $request->user()?->id,
                    'request_id'   => $requestId,
                    'method'       => $request->method(),
                    'path'         => $request->path(),
                    'ip_address'   => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                    'status_code'  => $response->getStatusCode(),
                    'duration_ms'  => $duration,
                    'requested_at' => now(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        }
}
