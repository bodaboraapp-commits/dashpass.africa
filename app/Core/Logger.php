<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Structured JSON-lines logger written outside the public web root.
 *
 * Correlation IDs let a support engineer follow one request across log lines
 * without ever logging credentials, OTPs or session tokens.
 */
final class Logger
{
    private static ?string $correlationId = null;

    /** @var array<int, string> */
    private const LEVELS = [
        100 => 'debug',
        200 => 'info',
        250 => 'notice',
        300 => 'warning',
        400 => 'error',
        500 => 'critical',
    ];

    public static function setCorrelationId(?string $id): void
    {
        self::$correlationId = $id;
    }

    public static function correlationId(): string
    {
        return self::$correlationId ??= Uuid::v4();
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function security(string $message, array $context = []): void
    {
        self::log('warning', $message, $context + ['channel' => 'security']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function log(string $level, string $message, array $context): void
    {
        $config = (array) Config::get('logging', []);
        $threshold = $config['levels'][(string) ($config['level'] ?? 'debug')] ?? 100;
        $weight = $config['levels'][$level] ?? 200;

        if ($weight < $threshold) {
            return;
        }

        $channel = (string) ($context['channel'] ?? 'app');
        unset($context['channel']);

        $record = [
            'time' => Clock::nowDb(),
            'level' => $level,
            'channel' => $channel,
            'message' => $message,
            'correlation_id' => self::correlationId(),
            'context' => self::sanitize($context),
            'pid' => getmypid(),
        ];

        $directory = BASE_PATH . '/' . (string) ($config['path'] ?? 'storage/logs');

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log('MtaaPass logger: cannot create log directory ' . $directory);

            return;
        }

        $file = $directory . '/' . $channel . '-' . gmdate('Y-m-d') . '.log';
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('MtaaPass logger: cannot write to ' . $file);
        }
    }

    /**
     * Remove anything credential-shaped before a value reaches disk.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitize(array $context): array
    {
        $sensitive = '/pin|otp|password|secret|token|authorization|api[-_]?key|csrf/i';

        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match($sensitive, $key) === 1) {
                $context[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $context[$key] = self::sanitize($value);
            } elseif (is_string($value)) {
                $context[$key] = mb_substr($value, 0, 500);
            } elseif (!is_scalar($value) && $value !== null) {
                $context[$key] = '[object]';
            }
        }

        return $context;
    }
}
