<?php

declare(strict_types=1);

namespace Octri\Monitoring;

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
    private static ?Config $config = null;

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
        $eventId = is_string($requestedEventId) && self::safeHeaderValue($requestedEventId)
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
    private static function post(string $path, array $payload, string $idempotencyKey): void
    {
        $config = self::$config;
        if ($config === null) return;

        $handle = null;
        try {
            if (!self::safeHeaderValue($idempotencyKey) ||
                ($config->token !== null && $config->token !== '' && !self::safeHeaderValue($config->token))) {
                return;
            }
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
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
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
