<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Context;
use App\Core\HttpException;
use App\Modules\Notifications\WhatsAppBroadcastService;
use App\Modules\Notifications\WhatsAppSettingsService;

/**
 * WhatsApp broadcast composer, delivery log and opt-out handling
 * (owner request 2026-11-15).
 */
final class WhatsAppBroadcastTest extends IntegrationTestCase
{
    private const ESTATE_ADMIN = '+254700000001';

    private const RESIDENT = '+254700000010';

    public function testComposerPageRendersForTheEstateAdmin(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $page = $this->get('/admin/broadcasts');

        $this->assertStatus(200, $page);
        $this->assertTextContains('WhatsApp broadcasts', $page);
        $this->assertTextContains('New broadcast', $page);
        $this->assertTextContains('Audience', $page);
        $this->assertTextContains('Approved template name', $page);
        $this->assertTextContains('Broadcast history', $page);
        $this->assertTextContains('WhatsApp is not connected', $page);
    }

    public function testResidentCannotOpenBroadcasts(): void
    {
        $this->loginAs(self::RESIDENT);
        $this->assertStatus(403, $this->get('/admin/broadcasts'));
    }

    public function testResidentCanOptOutAndBackInFromAccount(): void
    {
        $this->loginAs(self::RESIDENT);
        $estateId = $this->estateId();
        $userId = (int) $this->db->scalar('SELECT id FROM users WHERE phone = :phone', ['phone' => self::RESIDENT]);

        $response = $this->post('/app/account/whatsapp-optout', []);
        $this->assertStatus(302, $response);

        $row = $this->db->selectOne(
            'SELECT * FROM whatsapp_opt_outs WHERE estate_id = :estate_id AND user_id = :user_id',
            ['estate_id' => $estateId, 'user_id' => $userId]
        );
        $this->assertNotNull($row, 'The opt-out must be stored.');

        $page = $this->get('/app/account');
        $this->assertTextContains('Opted out', $page);

        $this->post('/app/account/whatsapp-optout', []);
        $this->assertNull($this->db->selectOne(
            'SELECT id FROM whatsapp_opt_outs WHERE estate_id = :estate_id AND user_id = :user_id',
            ['estate_id' => $estateId, 'user_id' => $userId]
        ));
    }

    public function testBroadcastSendsThroughTheEstateCredentials(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->assertStatus(200, $this->get('/admin/broadcasts'));
        $context = Context::current();
        $this->assertNotNull($context);
        $estateId = $this->estateId();
        $this->saveCredentials($context, $estateId);

        $transport = $this->fakeSendTransport();
        $broadcast = WhatsAppBroadcastService::make($context)->create([
            'title' => 'Water maintenance',
            'audience' => 'residents',
            'template_name' => 'estate_notification',
            'template_language' => 'en',
            'parameter_1' => 'Water off on Saturday 08:00-12:00',
            'preview_text' => 'Water off on Saturday',
            'action' => 'send',
        ], $transport);

        $this->assertSame('sent', $broadcast['status']);
        $this->assertTrue((int) $broadcast['recipients_total'] > 0, 'The broadcast must have opted-in recipients.');
        $this->assertSame((int) $broadcast['recipients_total'], (int) $broadcast['sent_count']);
        $this->assertSame(0, (int) $broadcast['failed_count']);

        $sent = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM whatsapp_broadcast_recipients WHERE broadcast_id = (SELECT id FROM whatsapp_broadcasts WHERE uuid = :uuid) AND status = 'sent'",
            ['uuid' => (string) $broadcast['uuid']]
        );
        $this->assertSame((int) $broadcast['recipients_total'], $sent);

        $audit = $this->db->selectOne("SELECT id FROM audit_logs WHERE action = 'whatsapp.broadcast_created' LIMIT 1");
        $this->assertNotNull($audit);

        $page = $this->get('/admin/broadcasts/' . rawurlencode((string) $broadcast['uuid']));
        $this->assertStatus(200, $page);
        $this->assertTextContains('Delivery log', $page);
        $this->assertTextContains('Water off on Saturday 08:00-12:00', $page);
    }

    public function testOptedOutPeopleAreSkipped(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->assertStatus(200, $this->get('/admin/broadcasts'));
        $context = Context::current();
        $this->assertNotNull($context);
        $estateId = $this->estateId();
        $this->saveCredentials($context, $estateId);

        $jane = $this->db->selectOne('SELECT id, phone FROM users WHERE phone = :phone', ['phone' => self::RESIDENT]);
        $this->db->insert('whatsapp_opt_outs', [
            'uuid' => bin2hex(random_bytes(16)),
            'estate_id' => $estateId,
            'user_id' => (int) $jane['id'],
            'phone' => (string) $jane['phone'],
            'reason' => 'Test opt-out',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $service = WhatsAppBroadcastService::make($context);
        $recipients = $service->recipients($estateId, 'residents');
        $phones = array_column($recipients, 'phone');
        $this->assertNotContains((string) $jane['phone'], $phones, 'Opted-out residents must not receive broadcasts.');

        $broadcast = $service->create([
            'title' => 'Opt-out check',
            'audience' => 'residents',
            'template_name' => 'estate_notification',
            'action' => 'send',
        ], $this->fakeSendTransport());
        $this->assertSame('sent', $broadcast['status']);

        $janeRecipient = $this->db->selectOne(
            'SELECT id FROM whatsapp_broadcast_recipients WHERE phone = :phone AND broadcast_id = (SELECT id FROM whatsapp_broadcasts WHERE uuid = :uuid)',
            ['phone' => (string) $jane['phone'], 'uuid' => (string) $broadcast['uuid']]
        );
        $this->assertNull($janeRecipient);
    }

    public function testScheduledBroadcastWaitsThenSendsNow(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->assertStatus(200, $this->get('/admin/broadcasts'));
        $context = Context::current();
        $this->assertNotNull($context);
        $estateId = $this->estateId();
        $this->saveCredentials($context, $estateId);

        $service = WhatsAppBroadcastService::make($context);
        $scheduled = $service->create([
            'title' => 'Scheduled notice',
            'audience' => 'staff',
            'template_name' => 'estate_notification',
            'scheduled_at' => date('Y-m-d H:i', strtotime('+1 day')),
            'action' => 'schedule',
        ], $this->fakeSendTransport());

        $this->assertSame('scheduled', $scheduled['status']);
        $this->assertSame(0, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM whatsapp_broadcast_recipients WHERE broadcast_id = (SELECT id FROM whatsapp_broadcasts WHERE uuid = :uuid)',
            ['uuid' => (string) $scheduled['uuid']]
        ));

        $sent = $service->dispatchByUuid((string) $scheduled['uuid'], $this->fakeSendTransport());
        $this->assertSame('sent', $sent['status']);
        $this->assertTrue((int) $sent['sent_count'] > 0, 'The scheduled broadcast must send after Send now.');
    }

    public function testBroadcastRequiresWhatsAppConnection(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->assertStatus(200, $this->get('/admin/broadcasts'));
        $context = Context::current();
        $this->assertNotNull($context);

        try {
            WhatsAppBroadcastService::make($context)->create([
                'title' => 'No connection',
                'audience' => 'residents',
                'template_name' => 'estate_notification',
                'action' => 'send',
            ], $this->fakeSendTransport());
            $this->assertTrue(false, 'Sending without a WhatsApp connection must fail.');
        } catch (HttpException $exception) {
            $this->assertSame('WHATSAPP_NOT_CONFIGURED', $exception->errorCode());
        }
    }

    private function saveCredentials(Context $context, int $estateId): void
    {
        WhatsAppSettingsService::make($context)->saveConnected(
            $estateId,
            '123456789012345',
            '987654321098765',
            'EAAG-broadcast-test-token'
        );
    }

    /**
     * @return callable(string, array<string,string>, string): array{status:int, body:string}
     */
    private function fakeSendTransport(): callable
    {
        return static fn (string $url, array $headers, string $body): array => [
            'status' => 200,
            'body' => json_encode(['messages' => [['id' => 'wamid.BROADCAST']]]),
        ];
    }

    private function estateId(): int
    {
        $estate = $this->db->selectOne("SELECT id FROM estates WHERE slug = 'greenview-residences' LIMIT 1");
        $this->assertNotNull($estate);

        return (int) $estate['id'];
    }
}
