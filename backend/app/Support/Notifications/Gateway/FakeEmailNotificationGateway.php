<?php

namespace App\Support\Notifications\Gateway;

/**
 * Deterministic EmailNotificationGateway test double - the only
 * implementation ever bound while running under the "testing" environment
 * (see App\Providers\EmailNotificationServiceProvider). Never sends a real
 * email or contacts any Mailer, mirroring
 * App\Support\Notifications\Gateway\FakeTechnicianNotificationGateway
 * exactly.
 *
 * channelCode() deliberately returns "EMAIL_FAKE", not a real channel name -
 * fake test rows must never be mistakable for a real provider interaction
 * if a database row is ever inspected directly.
 */
final class FakeEmailNotificationGateway implements EmailNotificationGateway
{
    /** @var array<int, EmailDispatchResult> */
    private array $queuedResults = [];

    private ?EmailDispatchResult $stickyResult = null;

    /** @var array<int, EmailDispatchData> */
    public array $sendCalls = [];

    public function channelCode(): string
    {
        return 'EMAIL_FAKE';
    }

    public function queueNextResult(EmailDispatchResult $result): void
    {
        $this->queuedResults[] = $result;
    }

    public function alwaysReturn(EmailDispatchResult $result): void
    {
        $this->stickyResult = $result;
    }

    public function send(EmailDispatchData $data): EmailDispatchResult
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
        // same fake provider reference, mirroring FakeTechnicianNotificationGateway's
        // own default.
        return EmailDispatchResult::submitted('fake_email_'.$data->providerIdempotencyKey);
    }
}
