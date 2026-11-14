<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QueryTokenToBearer
{
    /**
     * If the request contains a token query parameter and no Authorization header,
     * inject it as a Bearer token so Sanctum can authenticate direct browser requests
     * (e.g. downloads, window.open, iframes).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has('token') && !$request->headers->has('Authorization')) {
            $token = $request->query('token');
            if (is_string($token) && !empty($token)) {
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }

        return $next($request);
    }
}

