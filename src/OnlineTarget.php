<?php
declare(strict_types=1);

namespace carono\onlineTarget;

use Yii;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\log\Logger;
use yii\log\Target;
use yii\queue\Queue;

/** Sends serializable batches to an application-owned queue. */
class OnlineTarget extends Target
{
    public $queue = 'queue';
    public $endpoint = '';
    /** Worker component ID implementing TransportInterface. Credentials stay in worker configuration. */
    public $transport = 'onlineLogTransport';
    public $application = '';
    public $environment = '';
    public $batchSize = 100;
    public $logVars = [];
    public $exportInterval = 1;
    /** Optional callable(array $event): array for redaction and enrichment before enqueueing. */
    public $processor;
    /** Optional callable(\Throwable $error): void. Must not log through this target. */
    public $onEnqueueFailure;
    private static bool $exporting = false;

    public function init()
    {
        parent::init();
        if (!is_string($this->endpoint) || !filter_var($this->endpoint, FILTER_VALIDATE_URL)
            || !in_array(parse_url($this->endpoint, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($this->endpoint, PHP_URL_USER) !== null || parse_url($this->endpoint, PHP_URL_PASS) !== null) {
            throw new InvalidConfigException('endpoint must be an HTTP(S) URL without embedded credentials.');
        }
        if (!is_int($this->batchSize) || $this->batchSize < 1 || !is_string($this->transport) || $this->transport === '') {
            throw new InvalidConfigException('A positive batchSize and worker transport component ID are required.');
        }
        if (($this->processor !== null && !is_callable($this->processor)) || ($this->onEnqueueFailure !== null && !is_callable($this->onEnqueueFailure))) {
            throw new InvalidConfigException('Callbacks must be callable.');
        }
    }

    public function export()
    {
        if (self::$exporting || DeliveryJob::isDelivering()) {
            return;
        }
        self::$exporting = true;
        try {
            $queue = Instance::ensure($this->queue, Queue::class);
            if ($queue instanceof \yii\queue\sync\Queue) {
                throw new InvalidConfigException('OnlineTarget requires an asynchronous queue.');
            }
            foreach (array_chunk($this->messages, $this->batchSize) as $messages) {
                $events = [];
                foreach ($messages as $message) {
                    $value = $message[0];
                    $event = [
                        'id' => bin2hex(random_bytes(16)),
                        'timestamp' => (float) $message[3],
                        'level' => Logger::getLevelName($message[1]),
                        'category' => (string) $message[2],
                        'message' => $value instanceof \Throwable ? $value->getMessage() : $value,
                        'trace' => array_map(static fn(array $frame): array => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'type', 'function'])), $message[4] ?? []),
                    ];
                    if ($value instanceof \Throwable) {
                        $event['exception'] = ['class' => get_class($value), 'code' => $value->getCode(), 'file' => $value->getFile(), 'line' => $value->getLine(), 'trace' => array_map(static fn(array $frame): array => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'type', 'function'])), $value->getTrace())];
                    }
                    if ($this->processor !== null) {
                        $event = ($this->processor)($event);
                    }
                    // Normalize now: closures, exceptions and application objects never enter the queue.
                    $events[] = json_decode(json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                }
                $job = new DeliveryJob();
                $job->endpoint = $this->endpoint;
                $job->transport = $this->transport;
                $job->payload = ['version' => 1, 'application' => (string) $this->application, 'environment' => (string) $this->environment, 'events' => $events];
                if ($queue->push($job) === null) {
                    throw new \RuntimeException('Queue did not accept the log batch.');
                }
            }
        } catch (\Throwable $error) {
            if ($this->onEnqueueFailure !== null) {
                try { ($this->onEnqueueFailure)($error); } catch (\Throwable $ignored) { error_log('OnlineTarget failure callback failed.'); }
            } else {
                error_log('OnlineTarget could not enqueue a log batch.');
            }
        } finally {
            self::$exporting = false;
        }
    }
}
