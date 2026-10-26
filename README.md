# Yii2 Online Target

A generic Yii2 log target that queues JSON batches for an arbitrary HTTP receiver.
No service-specific URLs, queue drivers or worker configuration are bundled.

## Installation

```sh
composer require carono/yii2-online-target
```

PHP 8.1+, cURL, Yii2 and yii2-queue are required. Configure an asynchronous
yii2-queue component and run its workers in your application. The synchronous
driver is rejected because it would perform HTTP requests in the application process.

## Configuration

Merge these components into your web and console application configurations:

```php
'components' => [
    // 'queue' => your existing asynchronous queue configuration,
    'onlineLogTransport' => [
        'class' => \carono\onlineTarget\HttpTransport::class,
        'token' => getenv('LOG_INGEST_TOKEN') ?: '',
        'connectTimeout' => 2,
        'timeout' => 10,
        // 'headers' => ['X-Custom-Header' => 'value'],
    ],
    'log' => [
        'flushInterval' => 1,
        'targets' => [
            [
                'class' => \carono\onlineTarget\OnlineTarget::class,
                'queue' => 'queue',
                'transport' => 'onlineLogTransport',
                'endpoint' => getenv('LOG_INGEST_URL'),
                'application' => 'my-application',
                'environment' => 'production',
                'levels' => ['error', 'warning', 'info'],
                'exportInterval' => 1,
                'batchSize' => 100,
                'except' => ['yii\\queue\\*'],
                // 'processor' => static function (array $event): array { return $event; },
            ],
        ],
    ],
],
```

Use HTTPS in production. Keep credentials in environment variables or secret
configuration. Queue payloads contain no transport credentials. Configure the
transport in workers too; queued jobs resolve the component when executing.
Install the same package version in producers and workers.

The target uses Yii2 level/category filtering. `processor` runs before enqueueing
and can redact or enrich an event. Superglobals and session identifiers are not
collected by default. Messages can still contain secrets: redact them in the
application. Exception traces omit arguments and objects.

`flushInterval = 1` and `exportInterval = 1` enqueue immediately; larger intervals
reduce queue overhead. Actual delivery latency depends on your queue and workers.
An enqueue operation itself can incur backend latency. Configure queue timeouts
in the application. Enqueue failures go to PHP's error log, or to the optional
`onEnqueueFailure(Throwable $error)` callback. They do not interrupt the request.
Keep a separate file target for local fallback. No durable local spool is provided.

## Wire format

POST JSON, `Content-Type: application/json`, optional `Authorization: Bearer …`:

```json
{
  "version": 1,
  "application": "my-application",
  "environment": "production",
  "events": [{
    "id": "0123456789abcdef0123456789abcdef",
    "timestamp": 1700000000.123456,
    "level": "error",
    "category": "application",
    "message": "Operation failed",
    "trace": []
  }]
}
```

Timestamps are Unix seconds with fractional precision. Messages preserve JSON
scalars/arrays; exceptions use a string message plus an `exception` object with
`class`, `code`, `file`, `line`, and `trace`. IDs are generated before enqueueing
and remain stable across job retries. Receivers should deduplicate by event ID
and accept a whole batch atomically. Matching historical file records requires
the application to also record the same ID; text equality is not an event ID.

Any 2xx response acknowledges the complete batch. Redirects and other statuses
throw an exception. Responses are discarded without buffering. TLS verification
is enabled. Exceptions never include credentials or response bodies.
The application owns retry policy, attempt count, backoff, TTR and failed-job
handling; the library does not override them. Delivery is at least once only
when the application's queue/retry configuration provides it.

Use `TransportInterface` for alternative authentication or protocols; register
your implementation as the worker transport component. Avoid routing worker
queue errors back into this target. Direct transport logging is suppressed
during delivery, but later worker error logs require category exclusions.

## Development

```sh
composer install
composer validate --strict
composer test
```

MIT licensed.
