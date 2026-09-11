# octri/monitoring (PHP)

**Error and performance monitoring for PHP applications.** Report an uncaught
`Throwable` with its stack frames, emit your own application events, time spans
into a request waterfall, and continue a W3C distributed trace that started in
whichever client called you.

Octri turns an OpenAPI spec into a documentation site, client SDKs for ten
languages, an MCP server your AI assistant can call, and monitoring for the
API behind them. This package is the PHP monitoring runtime, and it works on
its own: a generated Octri API SDK is not required. See
[octri.dev/monitoring](https://octri.dev/monitoring).

PHP 8.1 or newer, with `ext-curl` and `ext-json`.

## Install

```bash
composer require octri/monitoring
```

## Setup

Call `init` once during bootstrap.

```php
use Octri\Monitoring\Config;
use Octri\Monitoring\Octri;

Octri::init(new Config(
    'https://monitoring.example.com',   // your monitoring base URL
    getenv('OCTRI_TOKEN') ?: null,      // your project ingest token
    '<your project id>',                // the dashboard project id
    getenv('GIT_SHA') ?: null,          // optional
));
```

Hosted users copy the project-scoped URL, token, and environment from the
Monitoring connection settings in the dashboard. Pass `null` for the token only
when you point at an open self-hosted ingest endpoint.

Every call is best effort and carries an idempotency key. Transport failures are
suppressed, so a monitoring outage cannot affect the application it is watching.

## Application events

```php
Octri::captureEvent('checkout.completed', [
    'level'   => 'info',
    'user'    => ['id' => $customer->id],
    'tags'    => ['region' => 'eu-west', 'plan' => 'growth'],
    'context' => ['orderId' => $order->id],
]);
```

The options array also accepts `breadcrumbs`, `fingerprint`, `operationId`,
`method`, `path`, `statusCode`, `latencyMs`, `attempt`, `requestId`, and
`timestamp`. Supplying `eventId` makes a retried delivery idempotent.

## Errors

```php
try {
    $response = $handler->handle($request);
} catch (Throwable $error) {
    Octri::captureError($error, [
        'method'     => 'POST',
        'path'       => '/orders',
        'statusCode' => 500,
    ]);
    throw $error;
}
```

To cover everything a request can throw, register it once at bootstrap:

```php
set_exception_handler(static fn (Throwable $e) => Octri::captureError($e));
```

## Joining the caller's trace

Your generated client SDK sends `traceparent: 00-<traceId>-<spanId>-01` on every
request. Read it on the way in and pass the result as `trace`, and the dashboard
groups the client call and the server error under one `traceId`: the request that
failed, beside the frame that threw.

```php
$trace = Octri::traceFromHeader($_SERVER['HTTP_TRACEPARENT'] ?? null);

Octri::captureError($error, ['trace' => $trace]);
```

`traceFromHeader(null)` starts a fresh trace, so the same code path works for
traffic that arrives without a header.

## Spans

A span is a completed unit of work with ISO-8601 timestamps. Report one per
request to get the waterfall, and one per sub-operation to see where the time
went inside it.

```php
$started = (new DateTimeImmutable())->format(DATE_ATOM);
$rows = $db->query($sql);

Octri::captureSpan([
    'traceId'      => $trace->traceId,
    'spanId'       => $spanId,
    'parentSpanId' => $trace->parentSpanId,
    'name'         => 'orders.list',
    'operationId'  => 'listOrders',
    'startTime'    => $started,
    'endTime'      => (new DateTimeImmutable())->format(DATE_ATOM),
]);
```

`traceId`, `spanId`, `name`, and `startTime` are required; a span missing any of
them is dropped. Spans sharing a `traceId` nest by `parentSpanId` in the
dashboard waterfall.

---

## What gets redacted

Payloads are scrubbed on the way out, so a credential that ended up in a log
line or a context object never reaches the dashboard.

Any key whose name looks like a credential (`password`, `secret`, `token`,
`apiKey`, `authorization`, `cookie`, `ssn` and the rest of the usual list) has
its value replaced with `[redacted]`, at any depth. Matching ignores case and
separators, so `api_key`, `apiKey` and `X-API-KEY` are all the same key.

Free text is swept too: the message, an error message and its stack, and
anything else you send as a string. Bearer tokens, JWTs, card numbers and email
addresses come out as `[redacted]`. A card number has to pass the Luhn check
first, so an order number or a timestamp survives.

`user` is the exception. It is the field you fill with an identity on purpose,
so `user.email` is reported exactly as you set it. Credential-shaped keys inside
it are still redacted.

Add your own key names:

```php
Octri::addScrubFields('accountNumber', 'otp');
```

Or take the payload yourself, and return `null` to drop the event:

```php
Octri::setBeforeSend(fn (array $payload) => ($payload['path'] ?? null) === '/health' ? null : $payload);
```

Redaction runs after your hook, so a hook cannot leak a credential by accident.

## The rest of Octri

| Product | What it does |
|---|---|
| [API Studio](https://octri.dev/api-studio) | Your OpenAPI spec becomes a hosted documentation site with a live request playground, editable page by page. |
| [SDK Studio](https://octri.dev/sdk-studio) | The same spec becomes client libraries for ten languages, versioned and released together. |
| [MCP](https://octri.dev/mcp) | Your endpoints and docs become tools an AI assistant can call, generated from the same spec. |
| [Monitoring](https://octri.dev/monitoring) | Errors, traces, uptime and releases for the API, joined to the SDK calls that reached it. |

### Monitoring runtimes

- [Node](https://github.com/octridev/octri-node)
- [Python](https://github.com/octridev/octri-python)
- [Go](https://github.com/octridev/octri-go)
- [Ruby](https://github.com/octridev/octri-ruby)
- [Rust](https://github.com/octridev/octri-rust)
- [PHP](https://github.com/octridev/octri-php)
- [Java](https://github.com/octridev/octri-java)
- [Kotlin](https://github.com/octridev/octri-kotlin)
- [Swift](https://github.com/octridev/octri-swift)
- [Dart](https://github.com/octridev/octri-dart)

### More

- [Documentation](https://docs.octri.dev/docs)
- [Pricing](https://octri.dev/pricing)
- [Changelog](https://docs.octri.dev/changelog)

MIT licensed.
