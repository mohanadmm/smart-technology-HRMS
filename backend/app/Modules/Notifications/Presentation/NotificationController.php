<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Presentation;

use App\Modules\Notifications\Application\NotificationInbox;
use App\Support\Http\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Auth only, no permission: every account reads and marks only its own rows. */
final class NotificationController
{
    public function __construct(private readonly NotificationInbox $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = $this->inbox->list(self::reader($request), (int) ($data['per_page'] ?? 20));

        return ApiEnvelope::collection($request, $page['data'], $page['pagination']);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        return ApiEnvelope::single($request, $this->inbox->markRead(self::reader($request), $notification));
    }

    private static function reader(Request $request): Authenticatable
    {
        return $request->user() ?? throw new AuthenticationException;
    }
}
