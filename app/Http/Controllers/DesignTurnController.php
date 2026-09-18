<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Design\RunDesignTurnAction;
use App\Http\Requests\DesignTurnRequest;
use Illuminate\Http\JsonResponse;

final class DesignTurnController
{
    public function __invoke(DesignTurnRequest $request, RunDesignTurnAction $action): JsonResponse
    {
        return new JsonResponse($action(
            $request->string('spec')->value(),
            $request->string('message')->value(),
            $request->history(),
        ));
    }
}
