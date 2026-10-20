<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Tests\Unit;

use Mnd\DmPlatform\Exceptions\InvalidSignatureException;
use Mnd\DmPlatform\Exceptions\DmPlatformException;
use Mnd\DmPlatform\Webhook\WebhookVerifier;
use PHPUnit\Framework\TestCase;

class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec-1';

    public function testAFreshSignatureIsAccepted(): void
    {
        $body   = $this->body();
        $now    = 1_700_000_000;
        $header = WebhookVerifier::sign(self::SECRET, $body, $now);

        $event = (new WebhookVerifier(self::SECRET))->verify($header, $body, $now);

        $this->assertSame('01920000-0000-7000-8000-0000000000aa', $event->eventId);
        $this->assertSame('tenant.provisioned', $event->type);
        $this->assertSame('ru-1', $event->instance);
        $this->assertSame(3, $event->version);
        $this->assertSame('school.example', $event->data['domain']);
    }

    public function testAnOldSignatureIsRefused(): void
    {
        $body   = $this->body();
        $header = WebhookVerifier::sign(self::SECRET, $body, 1_700_000_000);

        $this->expectException(InvalidSignatureException::class);
        (new WebhookVerifier(self::SECRET))->verify($header, $body, 1_700_000_000 + 301);
    }

    public function testAWrongSecretIsRefused(): void
    {
        $body   = $this->body();
        $header = WebhookVerifier::sign('other', $body, 1_700_000_000);

        $this->expectException(InvalidSignatureException::class);
        (new WebhookVerifier(self::SECRET))->verify($header, $body, 1_700_000_000);
    }

    public function testABodyThatIsNotAnEventIsRefusedAfterTheSignatureMatches(): void
    {
        $body   = '{"event_id":"x"}';
        $header = WebhookVerifier::sign(self::SECRET, $body, 1_700_000_000);

        $this->expectException(DmPlatformException::class);
        (new WebhookVerifier(self::SECRET))->verify($header, $body, 1_700_000_000);
    }

    public function testAMalformedHeaderIsRefused(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);

        foreach ([null, '', 'v1=abc', 't=1700000000', 't=abc,v1=abc', 't=1700000000,v1='] as $header) {
            try {
                $verifier->verify($header, $this->body(), 1_700_000_000);
                $this->fail('Header ' . var_export($header, true) . ' was expected to be refused');
            } catch (InvalidSignatureException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnyOfSeveralSignaturesMatchesDuringRotation(): void
    {
        $body    = $this->body();
        $now     = 1_700_000_000;
        $current = WebhookVerifier::sign(self::SECRET, $body, $now);
        $old     = WebhookVerifier::sign('whsec-old', $body, $now);
        $header  = $old . ',' . substr($current, strpos($current, 'v1='));

        $event = (new WebhookVerifier(self::SECRET))->verify($header, $body, $now);

        $this->assertSame('tenant.provisioned', $event->type);
    }

    public function testAnUppercaseSignatureIsAccepted(): void
    {
        $body   = $this->body();
        $header = WebhookVerifier::sign(self::SECRET, $body, 1_700_000_000);
        $header = 't=1700000000,v1=' . strtoupper(substr($header, strpos($header, 'v1=') + 3));

        $event = (new WebhookVerifier(self::SECRET))->verify($header, $body, 1_700_000_000);

        $this->assertSame(3, $event->version);
    }

    public function testAnEmptySecretRefusesEverything(): void
    {
        $body = $this->body();

        $this->expectException(InvalidSignatureException::class);
        (new WebhookVerifier(''))->verify(WebhookVerifier::sign('', $body, 1_700_000_000), $body, 1_700_000_000);
    }

    private function body(): string
    {
        return json_encode(
            [
            'event_id'           => '01920000-0000-7000-8000-0000000000aa',
            'type'               => 'tenant.provisioned',
            'tenant_external_id' => '01920000-0000-7000-8000-000000000001',
            'instance'           => 'ru-1',
            'occurred_at'        => '2026-10-20T10:00:00+03:00',
            'version'            => 3,
            'data'               => ['domain' => 'school.example', 'admin_user_id' => null],
            ],
            JSON_THROW_ON_ERROR
        );
    }
}
