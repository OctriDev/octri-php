<?php

declare(strict_types=1);

namespace Octri\Monitoring;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class Config
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $token,
        public readonly string $environment,
        public readonly ?string $release = null,
    ) {}
}

final class TraceContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly ?string $parentSpanId = null,
    ) {}
}

/**
 * Standalone Octri monitoring for PHP. Reporting is best-effort and failures
 * are always suppressed so telemetry cannot affect the application.
 */
final class Octri
{
    private const MAX_IDEMPOTENCY_KEY_LENGTH = 256;

    /**
     * Keys whose value never leaves the process. Compared against the key with
     * case and separators removed, so `api_key`, `apiKey` and `API-KEY` all
     * match `apikey`, and the test is a substring one, so `stripeSecretKey`
     * matches too.
     */
    private const SCRUB_KEYS = [
        'password', 'passwd', 'passphrase', 'secret', 'token', 'apikey',
        'authorization', 'credential', 'cookie', 'session', 'privatekey',
        'accesskey', 'cardnumber', 'creditcard', 'cvv', 'ssn',
    ];

    private const REDACTED = '[redacted]';
    private const TRUNCATED = '[truncated]';
    /** Deep enough for real context arrays, shallow enough to stay cheap. */
    private const MAX_SCRUB_DEPTH = 8;

    private const BEARER_RE = '/\\bbearer\\s+[\\w.~+\\/-]+=*/i';
    private const JWT_RE = '/\\beyJ[\\w-]+\\.[\\w-]+\\.[\\w-]+/';
    private const DIGIT_RUN_RE = '/\\b(?:\\d[ -]?){12,18}\\d\\b/';
    private const EMAIL_RE = '/[\\w.%+-]+@[\\w-]+(?:\\.[\\w-]+)+/';

    private static ?Config $config = null;

    /** @var list<string> */
    private static array $extraScrubKeys = [];

    private static ?Closure $beforeSend = null;

    public static function init(Config $config): void
    {
        self::$config = new Config(
            rtrim($config->url, '/'),
            $config->token,
            $config->environment,
            $config->release,
        );
    }

    public static function traceFromHeader(?string $traceparent): TraceContext
    {
        if ($traceparent !== null && preg_match(
            '/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/i',
            trim($traceparent),
            $matches,
        ) === 1 && !self::allZeros($matches[1]) && !self::allZeros($matches[2])) {
            return new TraceContext(strtolower($matches[1]), strtolower($matches[2]));
        }

        return new TraceContext(self::randomHex(16));
    }

    /**
     * Log an event without a generated Octri API SDK.
     *
     * Options use wire-style keys: level, timestamp, operationId, method, path,
     * statusCode, latencyMs, attempt, requestId, user, tags, context,
     * breadcrumbs, fingerprint, trace, spanId, and eventId.
     *
     * @param array<string, mixed> $options
     */
    public static function captureEvent(string $message, array $options = []): void
    {
        $config = self::$config;
        if ($config === null) return;
        $requestedEventId = $options['eventId'] ?? null;
        $eventId = is_string($requestedEventId) && self::safeIdempotencyKey($requestedEventId)
            ? $requestedEventId
            : self::randomHex(16);
        $tags = ['octri.origin' => 'standalone'];
        if (is_array($options['tags'] ?? null)) {
            $tags = array_merge($tags, $options['tags']);
        }
        $trace = $options['trace'] ?? null;

        $payload = self::compact([
            'eventId' => $eventId,
            'timestamp' => $options['timestamp'] ?? self::now(),
            'level' => $options['level'] ?? 'info',
            'message' => $message,
            'operationId' => $options['operationId'] ?? null,
            'method' => $options['method'] ?? null,
            'path' => $options['path'] ?? null,
            'statusCode' => $options['statusCode'] ?? null,
            'latencyMs' => $options['latencyMs'] ?? null,
            'attempt' => $options['attempt'] ?? null,
            'requestId' => $options['requestId'] ?? null,
            'environment' => $config->environment,
            'release' => $config->release,
            'user' => $options['user'] ?? null,
            'tags' => $tags,
            'context' => $options['context'] ?? null,
            'breadcrumbs' => $options['breadcrumbs'] ?? null,
            'fingerprint' => $options['fingerprint'] ?? null,
            'traceId' => $trace instanceof TraceContext ? $trace->traceId : null,
            'spanId' => $options['spanId'] ?? null,
        ]);
        self::post('/ingest', $payload, $eventId);
    }

    /** @param array<string, mixed> $options */
    public static function captureError(Throwable $error, array $options = []): void
    {
        $config = self::$config;
        if ($config === null) return;
        $trace = ($options['trace'] ?? null) instanceof TraceContext
            ? $options['trace']
            : self::traceFromHeader(null);
        $eventId = self::randomHex(16);
        $rawFrames = array_merge([[
            'function' => '{throw}',
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ]], $error->getTrace());
        $frames = array_map(static function (array $frame): array {
            $filename = (string) ($frame['file'] ?? 'unknown');
            $function = trim(
                (string) ($frame['class'] ?? '') .
                (string) ($frame['type'] ?? '') .
                (string) ($frame['function'] ?? '')
            );
            return [
                'function' => $function,
                'filename' => $filename,
                'lineno' => (int) ($frame['line'] ?? 0),
                'colno' => 0,
                'inApp' => !str_contains(str_replace('\\', '/', $filename), '/vendor/'),
            ];
        }, $rawFrames);

        $payload = self::compact([
            'eventId' => $eventId,
            'timestamp' => self::now(),
            'level' => $options['level'] ?? 'error',
            'operationId' => $options['operationId'] ?? null,
            'method' => $options['method'] ?? null,
            'path' => $options['path'] ?? null,
            'statusCode' => $options['statusCode'] ?? null,
            'environment' => $config->environment,
            'release' => $config->release,
            'traceId' => $trace->traceId,
            'spanId' => self::randomHex(8),
            'tags' => ['octri.origin' => 'server'],
            'error' => [
                'name' => $error::class,
                'message' => $error->getMessage(),
                'stack' => $error->getTraceAsString(),
                'frames' => $frames,
            ],
        ]);
        self::post('/ingest', $payload, $eventId);
    }

    /**
     * Report a completed span. Required keys: traceId, spanId, name, startTime.
     *
     * @param array<string, mixed> $span
     */
    public static function captureSpan(array $span): void
    {
        $config = self::$config;
        if ($config === null) return;
        foreach (['traceId', 'spanId', 'name', 'startTime'] as $required) {
            if (!is_string($span[$required] ?? null) || $span[$required] === '') return;
        }
        $payload = self::compact([
            'traceId' => $span['traceId'] ?? null,
            'spanId' => $span['spanId'] ?? null,
            'parentSpanId' => $span['parentSpanId'] ?? null,
            'environment' => $config->environment,
            'name' => $span['name'] ?? null,
            'service' => $span['service'] ?? 'server',
            'operationId' => $span['operationId'] ?? null,
            'startTime' => $span['startTime'] ?? null,
            'endTime' => $span['endTime'] ?? null,
            'status' => $span['status'] ?? 'ok',
        ]);
        self::post(
            '/traces',
            $payload,
            (string) ($span['traceId'] ?? '') . ':' . (string) ($span['spanId'] ?? ''),
        );
    }

    /** @param array<string, mixed> $payload */
    /**
     * Redacts more key names, on top of the built-in list. Matching ignores case
     * and separators and is a substring test, so `account` also covers
     * `accountNumber`.
     *
     *   Octri::addScrubFields('accountNumber', 'otp');
     */
    public static function addScrubFields(string ...$fields): void
    {
        foreach ($fields as $field) {
            $key = self::normalizeKey($field);
            if ($key !== '' && !in_array($key, self::$extraScrubKeys, true)) {
                self::$extraScrubKeys[] = $key;
            }
        }
    }

    /**
     * Runs a hook on every payload just before it is sent. Return the payload
     * (editing it is fine) to send it, or null to drop the event:
     *
     *   Octri::setBeforeSend(fn (array $payload) => $payload['path'] === '/health' ? null : $payload);
     *
     * Redaction still runs afterwards, so a hook cannot leak a credential by
     * accident. Pass null to remove the hook.
     */
    public static function setBeforeSend(?callable $hook): void
    {
        self::$beforeSend = $hook === null ? null : Closure::fromCallable($hook);
    }

    private static function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';
    }

    private static function isSecretKey(int|string $key): bool
    {
        $normalized = self::normalizeKey((string) $key);
        if ($normalized === '') return false;

        foreach ([...self::SCRUB_KEYS, ...self::$extraScrubKeys] as $candidate) {
            if (str_contains($normalized, $candidate)) return true;
        }
        return false;
    }

    /** Tells a card number from the order ids and timestamps that look like one. */
    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = ord($digits[$index]) - 48;
            if ($double) {
                $digit *= 2;
                if ($digit > 9) $digit -= 9;
            }
            $sum += $digit;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }

    /** Removes credentials and personal data that leaked into free text. */
    private static function scrubText(string $value): string
    {
        if ($value === '') return $value;

        $value = preg_replace(self::BEARER_RE, self::REDACTED, $value) ?? $value;
        $value = preg_replace(self::JWT_RE, self::REDACTED, $value) ?? $value;
        $value = preg_replace_callback(
            self::DIGIT_RUN_RE,
            static function (array $match): string {
                $digits = preg_replace('/\\D/', '', $match[0]) ?? '';
                return self::passesLuhn($digits) ? self::REDACTED : $match[0];
            },
            $value,
        ) ?? $value;

        return preg_replace(self::EMAIL_RE, self::REDACTED, $value) ?? $value;
    }

    /**
     * Redacts credential-shaped keys anywhere in the payload, and strips secrets
     * out of the free text around them. `user` is the field you deliberately
     * fill with an identity, so its strings are left alone; its keys are still
     * checked.
     */
    private static function scrubValue(mixed $value, int $depth, bool $text): mixed
    {
        if (is_string($value)) return $text ? self::scrubText($value) : $value;
        if (!is_array($value)) return $value;
        if ($depth >= self::MAX_SCRUB_DEPTH) return self::TRUNCATED;

        $out = [];
        foreach ($value as $key => $nested) {
            $out[$key] = self::isSecretKey($key)
                ? self::REDACTED
                : self::scrubValue($nested, $depth + 1, $text && $key !== 'user');
        }
        return $out;
    }

    /**
     * The last thing every payload passes through. Both the hook and the
     * redaction live here rather than in the capture methods, so nothing can be
     * reported around them.
     */
    private static function scrubPayload(array $payload): ?array
    {
        $hooked = self::$beforeSend === null ? $payload : (self::$beforeSend)($payload);
        if (!is_array($hooked)) return null;

        /** @var array $scrubbed */
        $scrubbed = self::scrubValue($hooked, 0, true);
        return $scrubbed;
    }

    private static function post(string $path, array $payload, string $idempotencyKey): void
    {
        $config = self::$config;
        if ($config === null) return;

        $handle = null;
        try {
            if (!self::safeIdempotencyKey($idempotencyKey) ||
                ($config->token !== null && $config->token !== '' && !self::safeHeaderValue($config->token))) {
                return;
            }
            $scrubbed = self::scrubPayload($payload);
            if ($scrubbed === null) return;
            $handle = curl_init($config->url . $path);
            if ($handle === false) return;
            $headers = [
                'Content-Type: application/json',
                'Idempotency-Key: ' . $idempotencyKey,
            ];
            if ($config->token !== null && $config->token !== '') {
                $headers[] = 'Authorization: Bearer ' . $config->token;
            }
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($scrubbed, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS => 1000,
            ]);
            curl_exec($handle);
        } catch (Throwable) {
            // Monitoring must never affect the application.
        } finally {
            if ($handle instanceof \CurlHandle) curl_close($handle);
        }
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function randomHex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Throwable) {
            return '';
        }
    }

    private static function safeHeaderValue(string $value): bool
    {
        return $value !== '' && !str_contains($value, "\r") && !str_contains($value, "\n");
    }

    /**
     * A caller-supplied event id becomes the Idempotency-Key header, so it is
     * bounded as well as newline-free.
     */
    private static function safeIdempotencyKey(string $value): bool
    {
        return self::safeHeaderValue($value) && strlen($value) <= self::MAX_IDEMPOTENCY_KEY_LENGTH;
    }

    private static function allZeros(string $value): bool
    {
        return trim($value, '0') === '';
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function compact(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }
}
