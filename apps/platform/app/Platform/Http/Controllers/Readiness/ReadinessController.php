<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Readiness;

use App\Platform\Contracts\Production\ReadinessCheck;
use App\Shared\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ReadinessController extends Controller
{
    public function __invoke(ReadinessCheck $readiness): JsonResponse
    {
        try {
            $readiness->check();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(
                ['status' => 'unavailable'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return response()->json(['status' => 'ready']);
    }
}
