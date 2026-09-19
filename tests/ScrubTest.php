<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Octri.php';

use Octri\Monitoring\Config;
use Octri\Monitoring\Octri;

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

Octri::init(new Config('https://monitoring.example.com', null, 'project-1'));

// The scrubber is what stands between the application and the wire, so it is
// exercised directly rather than through a stubbed transport.
$scrubber = new ReflectionMethod(Octri::class, 'scrubPayload');
$scrub = static fn (array $payload): ?array => $scrubber->invoke(null, $payload);

// ── Keys ─────────────────────────────────────────────────────────────────────

$context = $scrub(['context' => [
    'api_key' => 'sk_live_1',
    'apiKey' => 'sk_live_2',
    'X-API-KEY' => 'sk_live_3',
    'stripeSecretKey' => 'sk_live_4',
    'Authorization' => 'Bearer abc',
    'refresh_token' => 'rt_1',
    'cookie' => 'sid=1',
    'orderId' => 'A-1024',
    'author' => 'ada',
]])['context'];

foreach (['api_key', 'apiKey', 'X-API-KEY', 'stripeSecretKey', 'Authorization', 'refresh_token', 'cookie'] as $key) {
    expect($context[$key] === '[redacted]', "$key is redacted");
}
expect($context['orderId'] === 'A-1024', 'an ordinary field is kept');
expect($context['author'] === 'ada', 'a key that merely contains a scrub word is kept');

$nested = $scrub(['context' => ['upstream' => ['headers' => [['authorization' => 'Bearer abc']]]]]);
expect(
    $nested['context']['upstream']['headers'][0]['authorization'] === '[redacted]',
    'nested and list values are redacted too',
);

Octri::addScrubFields('accountNumber');
$extra = $scrub(['context' => ['accountNumber' => '12345678', 'orderId' => 'A-1024']])['context'];
expect($extra['accountNumber'] === '[redacted]', 'an extra scrub field is redacted');
expect($extra['orderId'] === 'A-1024', 'extra scrub fields are additive, not a replacement');

// ── Free text ────────────────────────────────────────────────────────────────

$message = $scrub(['message' => '401 from billing: Authorization: Bearer sk_live_abc123 rejected'])['message'];
expect(!str_contains($message, 'sk_live_abc123'), 'a bearer token in a message is stripped');
expect(str_contains($message, '[redacted]'), 'the stripped token is marked');

$message = $scrub(['message' => 'token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.7Hk2 expired'])['message'];
expect($message === 'token [redacted] expired', 'a JWT in a message is stripped');

$message = $scrub(['message' => 'no account for ada@example.com'])['message'];
expect($message === 'no account for [redacted]', 'an email in a message is stripped');

$message = $scrub(['message' => 'charge 4242 4242 4242 4242 failed for order 1234567890123'])['message'];
expect(!str_contains($message, '4242'), 'a card number is stripped');
expect(str_contains($message, '1234567890123'), 'an order number is not');

// ── The user field ───────────────────────────────────────────────────────────

// The identity the dashboard keys on is `id`, which survives. Direct
// identifiers under the user are redacted like they are in every generated SDK.
$user = $scrub(['user' => ['id' => 'u_1', 'email' => 'ada@example.com', 'sessionToken' => 'st_1', 'customerPhone' => '+1 555 0100']])['user'];
expect($user['id'] === 'u_1', 'the id you set is reported');
expect($user['email'] === '[redacted]', 'an email under user is redacted');
expect($user['sessionToken'] === '[redacted]', 'a credential under user is still redacted');
expect($user['customerPhone'] === '[redacted]', 'an identifier word inside a longer key is redacted');

$context = $scrub(['context' => ['billingAddress' => ['line1' => '1 High St'], 'avatarUrl' => 'https://cdn.example.com/a.png', 'queryTimeMs' => 12]])['context'];
expect($context['billingAddress'] === '[redacted]', 'address inside a longer key is redacted');
expect($context['avatarUrl'] === 'https://cdn.example.com/a.png', 'a short ambiguous word is not on the list');
expect($context['queryTimeMs'] === 12, 'query is not on the list either');

// ── beforeSend ───────────────────────────────────────────────────────────────

Octri::setBeforeSend(static function (array $payload): ?array {
    if ($payload['message'] === 'noise') return null;
    $payload['context'] = ['note' => 'call ada@example.com'];
    return $payload;
});

expect($scrub(['message' => 'noise']) === null, 'returning null drops the event');
$hooked = $scrub(['message' => 'signal']);
expect($hooked['context']['note'] === 'call [redacted]', 'redaction runs after the hook');

Octri::setBeforeSend(null);
expect($scrub(['message' => 'noise']) !== null, 'the hook can be removed');

fwrite(STDOUT, "Octri PHP scrubbing tests passed\n");
