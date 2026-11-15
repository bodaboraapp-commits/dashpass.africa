<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Context;
use App\Core\ValidationException;
use App\Modules\Notifications\WhatsAppBroadcastService;
use App\Modules\Notifications\WhatsAppSettingsService;
use App\Modules\Notifications\WhatsAppTemplateService;

/**
 * WhatsApp template sync from Meta (owner request 2026-11-15).
 *
 * Templates are pulled from the estate WABA (/message_templates), stored with body
 * + parameter count, offered in the broadcast composer, and the parameter count is
 * validated before a broadcast is sent.
 */
final class WhatsAppTemplateSyncTest extends IntegrationTestCase
{
    private const ESTATE_ADMIN = '+254700000001';

    public function setUp(): void
    {
        parent::setUp();
        Config::set('whatsapp.graph_version', 'v21.0');
    }

    public function testSyncStoresTemplatesAndRemovesStaleOnes(): void
    {
        $context = $this->adminContext();
        $estateId = $this->estateId();
        $this->saveCredentials($context, $estateId);

        $pages = 0;
        $transport = function (string $url, array $headers) use (&$pages): array {
            $pages++;

            if ($pages === 1) {
                return [
                    'status' => 200,
                    'body' => json_encode(['data' => [
                        [
                            'id' => 'tpl-1',
                            'name' => 'water_notice',
                            'status' => 'APPROVED',
                            'category' => 'UTILITY',
                            'language' => 'en',
                            'components' => [['type' => 'BODY', 'text' => 'Water off {{1}} from {{2}}.']],
                        ],
                        [
                            'id' => 'tpl-2',
                            'name' => 'estate_update',
                            'status' => 'PENDING',
                            'category' => 'UTILITY',
                            'language' => 'en',
                            'components' => [['type' => 'BODY', 'text' => 'Estate update: no parameters.']],
                        ],
                    ]]),
                ];
            }

            // Second sync: Meta now only returns the first template.
            return [
                'status' => 200,
                'body' => json_encode(['data' => [
                    [
                        'id' => 'tpl-1',
                        'name' => 'water_notice',
                        'status' => 'APPROVED',
                        'category' => 'UTILITY',
                        'language' => 'en',
                        'components' => [['type' => 'BODY', 'text' => 'Water off {{1}} from {{2}}.']],
                    ],
                ]]),
            ];
        };

        $service = WhatsAppTemplateService::make($context, $transport);
        $first = $service->sync($estateId);
        $this->assertSame(2, $first['synced']);

        $templates = $service->listForEstate($estateId);
        $this->assertSame(2, count($templates));
        $this->assertSame('water_notice', $templates[0]['name'], 'Approved templates sort first.');
        $this->assertSame(2, $templates[0]['parameter_count']);
        $this->assertSame('Water off {{1}} from {{2}}.', $templates[0]['body_text']);

        $second = $service->sync($estateId);
        $this->assertSame(1, $second['synced']);
        $this->assertSame(1, $second['removed']);
        $this->assertSame(1, count($service->listForEstate($estateId)));
    }

    public function testComposerShowsSyncedTemplatesAndBody(): void
    {
        $context = $this->adminContext();
        $estateId = $this->estateId();
        $this->saveCredentials($context, $estateId);
        WhatsAppTemplateService::make($context, $this->templateTransport())->sync($estateId);

        $page = $this->get('/admin/broadcasts');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Approved template (synced from Meta)', $page);
        $this->assertTextContains('water_notice', $page);
        $this->assertTextContains('Synced templates', $page);
        $this->assertTextContains('Water off {{1}} from {{2}}.', $page);
        $this->assertTextContains('Sync templates from Meta', $page);
    }

    public function testBroadcastRejectsWrongParameterCountForSyncedTemplate(): void
    {
        $context = $this->adminContext();
        $estateId = $this->estateId();
        $this->saveCredentials($context, $estateId);
        $templates = WhatsAppTemplateService::make($context, $this->templateTransport())->sync($estateId);
        $this->assertSame(1, $templates['synced']);

        $template = WhatsAppTemplateService::make($context)->listForEstate($estateId)[0];
        $this->assertSame('water_notice', $template['name']);

        try {
            WhatsAppBroadcastService::make($context)->create([
                'title' => 'Missing params',
                'audience' => 'residents',
                'template_name' => 'water_notice',
                'template_language' => 'en',
                'action' => 'send',
            ], $this->fakeSendTransport());
            $this->assertTrue(false, 'A parameter mismatch must be rejected before sending.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertArrayHasKey('parameters', $errors);
            $this->assertStringContainsString('expects exactly 2', implode(' ', $errors['parameters']));
        }

        // Matching parameters send (with the fake transport).
        $broadcast = WhatsAppBroadcastService::make($context)->create([
            'title' => 'Water notice',
            'audience' => 'residents',
            'template_name' => 'water_notice',
            'template_language' => 'en',
            'parameter_1' => 'Saturday',
            'parameter_2' => '08:00',
            'action' => 'send',
        ], $this->fakeSendTransport());
        $this->assertSame('sent', $broadcast['status']);
    }

    public function testSyncRequiresAWhatsAppConnection(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $response = $this->post('/admin/broadcasts/sync-templates', []);
        $this->assertStatus(302, $response);

        $page = $this->get('/admin/broadcasts');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Connect the estate WhatsApp channel before syncing templates', $page);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function adminContext(): Context
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->assertStatus(200, $this->get('/admin/broadcasts'));
        $context = Context::current();
        $this->assertNotNull($context);

        return $context;
    }

    private function saveCredentials(Context $context, int $estateId): void
    {
        WhatsAppSettingsService::make($context)->saveConnected(
            $estateId,
            '123456789012345',
            '987654321098765',
            'EAAG-template-test-token'
        );
    }

    /**
     * @return callable(string, array<string,string>): array{status:int, body:string}
     */
    private function templateTransport(): callable
    {
        return static fn (string $url, array $headers): array => [
            'status' => 200,
            'body' => json_encode(['data' => [
                [
                    'id' => 'tpl-1',
                    'name' => 'water_notice',
                    'status' => 'APPROVED',
                    'category' => 'UTILITY',
                    'language' => 'en',
                    'components' => [['type' => 'BODY', 'text' => 'Water off {{1}} from {{2}}.']],
                ],
            ]]),
        ];
    }

    /**
     * @return callable(string, array<string,string>, string): array{status:int, body:string}
     */
    private function fakeSendTransport(): callable
    {
        return static fn (string $url, array $headers, string $body): array => [
            'status' => 200,
            'body' => json_encode(['messages' => [['id' => 'wamid.TEMPLATE']]]),
        ];
    }

    private function estateId(): int
    {
        $estate = $this->db->selectOne("SELECT id FROM estates WHERE slug = 'greenview-residences' LIMIT 1");
        $this->assertNotNull($estate);

        return (int) $estate['id'];
    }
}
