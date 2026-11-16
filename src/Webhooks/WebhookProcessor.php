<?php

namespace MailcheckerTools\Webhooks;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MailcheckerTools\MailcheckerTools;
use MailcheckerTools\Models\EmailCheck;

/**
 * Handles a verified webhook event:
 *   {"id", "type", "created_at", "application": {"id", "name"}, "data": {…check…}}
 * Returns what happened, for the response body and for logs.
 */
class WebhookProcessor
{
    /** @param  array<string, mixed>  $event */
    public function process(array $event): string
    {
        return match ($event['type'] ?? null) {
            'webhook.test' => 'tested',
            'check.completed' => $this->check((array) ($event['data'] ?? [])),
            default => 'ignored',
        };
    }

    /**
     * Applies a check resource (webhook data, or GET /checks/{id} from mailchecker:sync).
     * A check this app didn't save (made with the client directly) is recorded too.
     *
     * @param  array<string, mixed>  $data
     */
    public function check(array $data): string
    {
        if (! isset($data['id'], $data['email'])) {
            Log::warning('mailchecker-tools: check event without id/email', ['data' => $data]);

            return 'invalid';
        }

        $class = MailcheckerTools::checkModel();
        $check = $class::query()->where('check_id', $data['id'])->first()
            ?? (isset($data['reference']) && Str::isUuid((string) $data['reference'])
                ? $class::query()->where('reference', $data['reference'])->first()
                : null);

        if ($check === null) {
            /** @var EmailCheck $check */
            $check = new $class([
                'email' => Str::lower((string) $data['email']),
                'reference' => isset($data['reference']) && Str::isUuid((string) $data['reference']) ? $data['reference'] : (string) Str::uuid(),
            ]);
            $check->applyApiCheck($data);

            return 'created';
        }

        return $check->applyApiCheck($data) ? 'updated' : 'unchanged';
    }
}
