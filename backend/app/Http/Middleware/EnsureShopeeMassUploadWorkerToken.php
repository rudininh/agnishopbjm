<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopeeMassUploadWorkerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('shopee_mass_upload.worker_token', ''));
        $provided = trim((string) $request->bearerToken());

        abort_unless($expected !== '' && $provided !== '' && hash_equals($expected, $provided), 401, 'Token worker upload Gitashop tidak valid.');

        return $next($request);
    }
}
