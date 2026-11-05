<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Audit\Infrastructure\SystemLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes one request_log row per /api/* request, after the response is sent. Global, so unmatched 404s and 413s are logged too.
 * State goes on request attributes, never on $this: the kernel builds a fresh instance for terminate().
 */
final class RecordRequest
{
    public const STARTED_AT = 'log_started_at';

    public const DURATION_MS = 'log_duration_ms';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $started = (float) ($request->server('REQUEST_TIME_FLOAT') ?? (defined('LARAVEL_START') ? LARAVEL_START : microtime(true)));
        $request->attributes->set(self::STARTED_AT, $started);
        $request->attributes->set(self::DURATION_MS, max(0, (int) round((microtime(true) - $started) * 1000)));

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        // The nginx healthcheck calls health every 10 seconds; no person sent those.
        if (! $request->is('api/*') || ($request->isMethod('GET') && $request->is('api/v1/health'))) {
            return;
        }

        SystemLog::request($request, $response);
    }
}
