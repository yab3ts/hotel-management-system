<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request and apply defensive HTTP security headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Reject CRLF / HTTP header injection attempts in query strings or custom headers (#48)
        $rawQuery = (string) $request->server->get('QUERY_STRING', '');
        if (preg_match('/(%0d|%0a|\r|\n)/i', $rawQuery)) {
            return response()->json(['error' => 'Malformed request parameters rejected.'], 400);
        }

        foreach (['X-Tenant-ID', 'X-Tenant', 'X-Branch-ID', 'X-Guest-Secret'] as $headerName) {
            $val = $request->header($headerName);
            if (is_string($val) && preg_match('/(\r|\n)/', $val)) {
                return response()->json(['error' => 'Invalid header format rejected.'], 400);
            }
        }

        /** @var Response $response */
        $response = $next($request);

        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // Prevent browser/proxy caching of sensitive API responses (#87)
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        // Content-Security-Policy with clickjacking frame-ancestors protection (#46)
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; frame-ancestors 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https: blob:; font-src 'self' https: data:; connect-src 'self' https: ws: wss:;"
        );

        return $response;
    }
}
