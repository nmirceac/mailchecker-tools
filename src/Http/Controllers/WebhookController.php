<?php

namespace MailcheckerTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use MailcheckerTools\Webhooks\WebhookProcessor;
use Throwable;

class WebhookController
{
    public function __invoke(Request $request, WebhookProcessor $processor): JsonResponse
    {
        $event = (array) $request->json()->all();
        $id = (string) ($request->header('X-Mailcheck-Webhook-Id') ?: ($event['id'] ?? sha1($request->getContent())));
        $key = 'mailchecker-tools:event:'.$id;

        // Each event is processed once, even if the platform retries it.
        if (! Cache::add($key, true, (int) config('mailchecker.webhook.dedupe_ttl', 86400))) {
            return response()->json(['ok' => true, 'result' => 'duplicate']);
        }

        try {
            $result = $processor->process($event);
        } catch (Throwable $e) {
            Cache::forget($key); // let the platform's retry try again
            throw $e;
        }

        return response()->json(['ok' => true, 'result' => $result]);
    }
}
