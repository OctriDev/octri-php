<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Octri.php';

use Octri\Monitoring\Config;
use Octri\Monitoring\Octri;

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$valid = Octri::traceFromHeader(
    '00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-01',
);
expect($valid->traceId === '4bf92f3577b34da6a3ce929d0e0e4736', 'valid trace id');
expect($valid->parentSpanId === '00f067aa0ba902b7', 'valid parent span id');

$invalid = Octri::traceFromHeader(
    '00-00000000000000000000000000000000-0000000000000000-01',
);
expect((bool) preg_match('/^[0-9a-f]{32}$/', $invalid->traceId), 'fresh trace id format');
expect($invalid->traceId !== str_repeat('0', 32), 'fresh trace id is non-zero');
expect($invalid->parentSpanId === null, 'invalid parent span is discarded');

// Malformed caller-controlled ids/tokens must remain fail-safe and must never
// reach cURL as injected headers.
Octri::init(new Config('http://127.0.0.1:1/', "token\r\nX-Injected: true", 'project-1'));
Octri::captureEvent('ignored', ['eventId' => "event\r\nX-Injected: true"]);
Octri::captureSpan(['traceId' => '', 'spanId' => '', 'name' => '', 'startTime' => '']);

fwrite(STDOUT, "Octri PHP tests passed\n");
