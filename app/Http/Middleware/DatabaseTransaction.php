<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Wraps every request in a DB transaction so a failed request never leaves
 * partial writes behind — commits on a successful/redirect response, rolls
 * back on an error response or a thrown exception (then re-throws, so the
 * exception still reaches the JSON error shaping in bootstrap/app.php).
 */
class DatabaseTransaction
{
    public function handle(Request $request, Closure $next): Response
    {
        DB::beginTransaction();

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($response->isSuccessful() || $response->isRedirection()) {
            DB::commit();
        } else {
            DB::rollBack();
        }

        return $response;
    }
}
