<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure;

use App\Http\Middleware\AddRequestId;
use App\Http\Middleware\RecordRequest;
use App\Modules\Audit\Domain\LogMasker;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** The one writer of request_log rows. Never throws: logging must not break the request it logs. */
final class SystemLog
{
    public static function request(Request $request, Response $response): void
    {
        try {
            $status = $response->getStatusCode();
            $requestId = $request->attributes->get(AddRequestId::ATTRIBUTE);
            $startedAt = $request->attributes->get(RecordRequest::STARTED_AT);
            $duration = $request->attributes->get(RecordRequest::DURATION_MS);
            $userAgent = $request->userAgent();
            $query = $request->query->all();
            $route = $request->route();

            $row = [
                'id' => (string) Str::uuid7(),
                // Not masked or cut: it links the row to error_log and the X-Request-Id header.
                'request_id' => is_string($requestId) ? $requestId : (string) Str::uuid(),
                // hasUser(), never $request->user(): the bearer resolver would touch or delete the session.
                'user_id' => Auth::hasUser() ? Auth::id() : null,
                'method' => self::cut($request->getMethod(), 10),
                'path' => self::cut($request->path(), 2048),
                'route' => $route instanceof Route ? self::cut($route->uri(), 255) : null,
                'query' => $query === [] ? null : LogMasker::encode($query),
                'status' => $status,
                'duration_ms' => is_int($duration) ? $duration : 0,   // the column is NOT NULL; 0 = unknown
                'ip_address' => $request->ip(),
                'user_agent' => $userAgent === null ? null : self::cut($userAgent, 512),
                'request_body' => $status >= 400 ? self::requestBody($request) : null,
                'response_body' => $status >= 400 && $status < 500 ? self::responseBody($response) : null,
                'occurred_at' => is_float($startedAt) ? Carbon::createFromTimestamp($startedAt) : now(),
            ];

            // A savepoint inside an open transaction: a failed insert rolls back only itself.
            DB::transaction(fn () => DB::table('request_log')->insert($row));
        } catch (Throwable $e) {
            // Only the class: the message may quote the row. Never to error_log (no loop).
            Log::warning('system_log.request_failed', ['class' => $e::class]);
        }
    }

    private static function cut(string $text, int $length): string
    {
        return mb_substr(LogMasker::text($text), 0, $length);
    }

    private static function requestBody(Request $request): ?string
    {
        $body = array_diff_key($request->input(), $request->query->all()) + self::files($request->allFiles());

        return $body === [] ? null : LogMasker::encode($body);
    }

    /**
     * Uploaded files keep only their name and size.
     *
     * @param  array<mixed>  $files
     * @return array<mixed>
     */
    private static function files(array $files): array
    {
        return array_map(fn (mixed $f) => match (true) {
            $f instanceof UploadedFile => ['name' => LogMasker::text($f->getClientOriginalName()), 'size' => $f->getSize()],
            is_array($f) => self::files($f),
            default => null,
        }, $files);
    }

    private static function responseBody(Response $response): ?string
    {
        $content = $response->getContent();
        $decoded = is_string($content) ? json_decode($content, true) : null;

        return is_array($decoded) ? LogMasker::encode($decoded) : null;
    }
}
