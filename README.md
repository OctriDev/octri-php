# Octri Monitoring for PHP

PHP 8.1+ monitoring with standalone events, exception capture, W3C trace
propagation, and span ingestion.

```php
use Octri\Monitoring\Config;
use Octri\Monitoring\Octri;

Octri::init(new Config(
    'https://monitoring.example.com',
    getenv('OCTRI_TOKEN') ?: null,
    '<your project id>',
    getenv('GIT_SHA') ?: null,
));

Octri::captureEvent('checkout.completed', [
    'tags' => ['region' => 'eu-west', 'plan' => 'growth'],
    'context' => ['orderId' => $order->id, 'total' => $order->total],
]);
```

The project-scoped URL, token, and environment are shown in Octri's Monitoring
connection settings. Omit the token only for an open self-hosted endpoint.
Requests are best-effort and carry an idempotency key.
