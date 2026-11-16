<?php

namespace MailcheckerTools\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Mailcheck-Signature = hex HMAC-SHA256 of the raw body with the application's signing
 * secret (MAILCHECKER_WEBHOOK_SECRET). X-Mailcheck-Timestamp, when sent, must be recent.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = (string) $request->header('X-Mailcheck-Signature', '');
        if ($signature === '') {
            return $this->reject('Missing signature');
        }

        $secret = (string) config('mailchecker.webhook.secret');
        if ($secret === '') {
            Log::error('mailchecker-tools: MAILCHECKER_WEBHOOK_SECRET is not set; rejecting webhook. Run `php artisan mailchecker:setup --write-env`.');

            return $this->reject('Webhook secret not configured');
        }

        if (! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return $this->reject('Invalid signature');
        }

        $timestamp = $request->header('X-Mailcheck-Timestamp');
        $tolerance = (int) config('mailchecker.webhook.tolerance', 300);
        if ($timestamp !== null && $tolerance > 0 && abs(time() - (int) $timestamp) > $tolerance) {
            return $this->reject('Stale timestamp');
        }

        return $next($request);
    }

    private function reject(string $message): Response
    {
        return response()->json(['error' => ['message' => $message, 'status' => 401]], 401);
    }
}
