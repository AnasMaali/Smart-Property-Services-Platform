<?php

namespace App\Support\Notifications\Gateway;

/**
 * Deterministic TechnicianNotificationGateway test double - the only
 * implementation ever bound while running under the "testing" environment
 * (see App\Providers\TechnicianNotificationServiceProvider). Never makes a
 * network call of any kind, mirroring
 * App\Support\Payment\Gateway\FakePaymentGateway exactly.
 *
 * channelCode() deliberately returns "WHATSAPP_FAKE", not a real channel
 * name - fake test rows must never be mistakable for a real provider
 * interaction if a database row is ever inspected directly.
 */
final class FakeTechnicianNotificationGateway implements TechnicianNotificationGateway
{
    /** @var array<int, NotificationDispatchResult> */
    private array $queuedResults = [];

    private ?NotificationDispatchResult $stickyResult = null;

    /** @var array<int, NotificationDispatchData> */
    public array $sendCalls = [];

    public function channelCode(): string
    {
        return 'WHATSAPP_FAKE';
    }

    public function queueNextResult(NotificationDispatchResult $result): void
    {
        $this->queuedResults[] = $result;
    }

    public function alwaysReturn(NotificationDispatchResult $result): void
    {
        $this->stickyResult = $result;
    }

    public function send(NotificationDispatchData $data): NotificationDispatchResult
    {
        $this->sendCalls[] = $data;

        if ($this->queuedResults !== []) {
            return array_shift($this->queuedResults);
        }

        if ($this->stickyResult !== null) {
            return $this->stickyResult;
        }

        // Deterministic, idempotency-key-derived default - the same
        // logical retry (same providerIdempotencyKey) always yields the
        // same fake provider reference, mirroring FakePaymentGateway's own
        // default for refunds/payments.
        return NotificationDispatchResult::submitted('fake_wamid_'.$data->providerIdempotencyKey);
    }
}
