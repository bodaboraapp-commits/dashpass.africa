<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Core\ValidationException;
use App\Integrations\WhatsApp\WhatsAppCloudProvider;

/**
 * WhatsApp message template sync (owner request 2026-11-15).
 *
 * Pulls the estate's templates from the WhatsApp Business Account with the Meta
 * Graph API (`GET /{waba_id}/message_templates`), stores name/language/status/
 * category/body + parameter count and lets the broadcast composer pick a real
 * approved template. The HTTP GET transport is injectable for tests.
 *
 * Only templates returned by Meta are stored; a re-sync removes templates that no
 * longer exist for the estate so stale entries cannot be selected.
 */
final class WhatsAppTemplateService
{
    /** @var callable(string, array<string,string>): array{status:int, body:string} */
    private $getTransport;

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        ?callable $getTransport = null
    ) {
        $this->getTransport = $getTransport ?? WhatsAppCloudProvider::defaultGetTransport(...);
    }

    public static function make(Context $context, ?callable $getTransport = null): self
    {
        return new self($context, $context->db, $getTransport);
    }

    /**
     * Sync the estate's templates from Meta.
     *
     * @return array{synced:int, removed:int}
     */
    public function sync(int $estateId): array
    {
        $settings = WhatsAppSettingsService::make($this->context);
        $credentials = $settings->credentials($estateId);

        if ($credentials === null || !$settings->isConfigured($estateId)) {
            throw new HttpException(
                422,
                'WHATSAPP_NOT_CONFIGURED',
                'Connect the estate WhatsApp channel before syncing templates.'
            );
        }

        $wabaId = trim((string) ($credentials['waba_id'] ?? ''));

        if ($wabaId === '') {
            throw new HttpException(
                422,
                'WHATSAPP_WABA_MISSING',
                'The estate WhatsApp settings have no WhatsApp Business Account ID. Add it or use Connect with Meta.'
            );
        }

        $version = (string) Config::get('whatsapp.graph_version', 'v21.0');
        $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($wabaId)
            . '/message_templates?fields=id,name,status,category,language,components&limit=100';
        $headers = [
            'Authorization' => 'Bearer ' . (string) $credentials['access_token'],
            'Accept' => 'application/json',
        ];

        $templates = [];
        $pages = 0;

        while ($url !== '' && $pages < 5) {
            $pages++;

            try {
                $response = ($this->getTransport)($url, $headers);
            } catch (\Throwable) {
                throw new HttpException(422, 'WHATSAPP_TEMPLATES_SYNC_FAILED', 'Could not reach Meta to sync templates. Try again.');
            }

            $status = (int) ($response['status'] ?? 0);
            $decoded = json_decode((string) ($response['body'] ?? ''), true);

            if ($status < 200 || $status >= 300 || !is_array($decoded) || isset($decoded['error'])) {
                throw new HttpException(422, 'WHATSAPP_TEMPLATES_SYNC_FAILED', 'Meta rejected the template sync. Check the WhatsApp credentials and WABA ID.');
            }

            foreach ((array) ($decoded['data'] ?? []) as $template) {
                if (is_array($template) && trim((string) ($template['name'] ?? '')) !== '') {
                    $templates[] = $template;
                }
            }

            $url = (string) ($decoded['paging']['next'] ?? '');
        }

        $now = Clock::nowDb();
        $kept = [];

        foreach ($templates as $template) {
            $name = trim((string) $template['name']);
            $language = trim((string) ($template['language'] ?? 'en')) ?: 'en';
            $kept[] = [$name, $language];

            [$bodyText, $parameterCount] = $this->extractBody((array) ($template['components'] ?? []));
            $existing = $this->db->selectOne(
                'SELECT id FROM whatsapp_templates WHERE estate_id = :estate_id AND name = :name AND language = :language LIMIT 1',
                ['estate_id' => $estateId, 'name' => $name, 'language' => $language]
            );

            $payload = [
                'waba_id' => $wabaId,
                'meta_template_id' => mb_substr(trim((string) ($template['id'] ?? '')), 0, 80),
                'status' => strtoupper(trim((string) ($template['status'] ?? 'APPROVED'))) ?: 'APPROVED',
                'category' => trim((string) ($template['category'] ?? '')) ?: null,
                'body_text' => $bodyText,
                'parameter_count' => $parameterCount,
                'components' => json_encode($template['components'] ?? [], JSON_UNESCAPED_SLASHES) ?: null,
                'synced_at' => $now,
                'updated_at' => $now,
            ];

            if ($existing !== null) {
                $this->db->update('whatsapp_templates', $payload, ['id' => (int) $existing['id']]);
            } else {
                $payload['uuid'] = Uuid::v4();
                $payload['estate_id'] = $estateId;
                $payload['name'] = $name;
                $payload['language'] = $language;
                $payload['created_at'] = $now;
                $this->db->insert('whatsapp_templates', $payload);
            }
        }

        $removed = 0;

        if ($kept !== []) {
            $placeholders = [];
            $params = ['estate_id' => $estateId];

            foreach ($kept as $index => [$name, $language]) {
                $placeholders[] = '(:name_' . $index . ', :language_' . $index . ')';
                $params['name_' . $index] = $name;
                $params['language_' . $index] = $language;
            }

            $removed = (int) $this->db->execute(
                'DELETE FROM whatsapp_templates WHERE estate_id = :estate_id AND (name, language) NOT IN (' . implode(',', $placeholders) . ')',
                $params
            );
        } else {
            $removed = (int) $this->db->execute(
                'DELETE FROM whatsapp_templates WHERE estate_id = :estate_id',
                ['estate_id' => $estateId]
            );
        }

        try {
            $this->context->audit->record('whatsapp.templates_synced', 'estate', $estateId, [
                'synced' => count($kept),
                'removed' => $removed,
            ]);
        } catch (\Throwable) {
            // Sync must not fail because auditing failed.
        }

        return ['synced' => count($kept), 'removed' => $removed];
    }

    /**
     * Synced templates for the estate, approved first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForEstate(int $estateId): array
    {
        $rows = $this->db->select(
            "SELECT * FROM whatsapp_templates
             WHERE estate_id = :estate_id
             ORDER BY FIELD(status, 'APPROVED', 'PENDING', 'REJECTED'), name ASC, language ASC",
            ['estate_id' => $estateId]
        );

        return array_map([$this, 'present'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $estateId, string $uuid): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM whatsapp_templates WHERE estate_id = :estate_id AND uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );

        return $row === null ? null : $this->present($row);
    }

    /**
     * The synced template matching a name + language (null when not synced).
     *
     * @return array<string, mixed>|null
     */
    public function findByName(int $estateId, string $name, string $language): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM whatsapp_templates WHERE estate_id = :estate_id AND name = :name AND language = :language LIMIT 1',
            ['estate_id' => $estateId, 'name' => $name, 'language' => $language]
        );

        return $row === null ? null : $this->present($row);
    }

    /**
     * Validate a broadcast parameter count against the synced template (if known).
     *
     * Meta rejects a mismatch, so fail early with a clear validation message.
     *
     * @param array<int, string> $parameters
     */
    public function assertParameterCount(int $estateId, string $name, string $language, array $parameters): void
    {
        $template = $this->findByName($estateId, $name, $language);

        if ($template === null) {
            return;
        }

        $expected = (int) $template['parameter_count'];

        if (count($parameters) !== $expected) {
            throw new ValidationException([
                'parameters' => [
                    'The template "' . $name . '" (' . $language . ') expects exactly '
                    . $expected . ' parameter' . ($expected === 1 ? '' : 's') . '.',
                ],
            ]);
        }
    }

    /**
     * Extract the BODY text and the number of {{n}} placeholders.
     *
     * @param array<int, mixed> $components
     *
     * @return array{0: string, 1: int}
     */
    private function extractBody(array $components): array
    {
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            if (strtoupper((string) ($component['type'] ?? '')) !== 'BODY') {
                continue;
            }

            $text = trim((string) ($component['text'] ?? ''));
            preg_match_all('/\{\{\s*\d+\s*\}\}/', $text, $matches);

            return [$text !== '' ? $text : null, count(array_unique($matches[0]))];
        }

        return [null, 0];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'language' => (string) ($row['language'] ?? 'en'),
            'status' => (string) ($row['status'] ?? 'APPROVED'),
            'category' => $row['category'] === null ? null : (string) $row['category'],
            'body_text' => $row['body_text'] === null ? null : (string) $row['body_text'],
            'parameter_count' => (int) ($row['parameter_count'] ?? 0),
            'synced_at' => (string) ($row['synced_at'] ?? ''),
        ];
    }
}
