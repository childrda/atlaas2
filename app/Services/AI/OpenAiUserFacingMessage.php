<?php

namespace App\Services\AI;

use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Exceptions\ApiKeyIsMissing;
use Throwable;

/**
 * Maps OpenAI / transport exceptions to safe user-visible strings (no secrets).
 */
final class OpenAiUserFacingMessage
{
    public static function from(Throwable $e): string
    {
        if ($e instanceof ApiKeyIsMissing) {
            return 'OpenAI is not configured on this server (missing API key). Please contact your administrator.';
        }

        if ($e instanceof RateLimitException) {
            return 'The AI service is rate-limited. Please wait a minute and try again.';
        }

        if ($e instanceof ErrorException) {
            $code = $e->getStatusCode();
            if (in_array($code, [401, 403], true)) {
                return 'The AI service rejected the request (invalid API key or permissions). Ask your administrator to check OPENAI_API_KEY and billing.';
            }
            if ($code === 429) {
                return 'The AI service rate limit was reached. Please try again shortly.';
            }
            if ($code >= 500) {
                return 'The AI provider returned a server error. Please try again in a few minutes.';
            }
        }

        if ($e instanceof TransporterException) {
            return 'Could not reach the AI service (network). Check server connectivity and OPENAI_BASE_URL if you use a custom endpoint.';
        }

        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'could not resolve host') || str_contains($msg, 'connection refused') || str_contains($msg, 'timed out')) {
            return 'Could not connect to the AI service. Check network, firewall, and DNS on the server.';
        }

        $out = 'We could not get a response from the AI. Please try again.';
        if (config('app.debug')) {
            $out .= ' ('.$e->getMessage().')';
        }

        return $out;
    }
}
