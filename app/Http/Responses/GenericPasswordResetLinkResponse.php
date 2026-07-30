<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's default responses reveal, via a distinct message, whether the
 * submitted email has an account — a user-enumeration oracle, and one with
 * no rate limit by default. Binding both the success and failure response
 * contracts to this single class makes "unknown email", "known email, link
 * sent", and "known email, throttled by the password broker's own 60s
 * per-email cooldown" all produce the byte-identical response.
 */
final class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    private const string MESSAGE = 'If that account exists, we sent a password reset link.';

    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => self::MESSAGE], 200)
            : back()->with('status', self::MESSAGE);
    }
}
