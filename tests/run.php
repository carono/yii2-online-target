<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

use carono\onlineTarget\OnlineTarget;
use carono\onlineTarget\DeliveryJob;
use carono\onlineTarget\TransportInterface;
use yii\log\Logger;

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
class MemoryQueue extends yii\queue\Queue {
    public array $jobs = [];
    protected function pushMessage($message, $ttr, $delay, $priority) { $this->jobs[] = $message; return count($this->jobs); }
}
class RecordingTransport extends yii\base\Component implements TransportInterface {
    public array $received = [];
    public bool $fail = false;
    public function send(string $endpoint, array $payload): void {
        $this->received[] = $payload;
        if ($this->fail) { throw new RuntimeException('Retry'); }
    }
}
$app = new yii\console\Application(['id' => 'test', 'basePath' => __DIR__, 'components' => [
    'queue' => ['class' => MemoryQueue::class], 'onlineLogTransport' => ['class' => RecordingTransport::class],
]]);
$target = new OnlineTarget(['endpoint' => 'https://example.invalid/ingest', 'application' => 'test', 'batchSize' => 1, 'levels' => ['error', 'warning', 'info']]);
$target->collect([
    [['text' => 'hello'], Logger::LEVEL_INFO, 'test', 1234.5, []],
    [new RuntimeException('failure'), Logger::LEVEL_ERROR, 'test', 1235.5, []],
    ['excluded', Logger::LEVEL_TRACE, 'test', 1236.5, []],
], true);
check(count($app->queue->jobs) === 2, 'Batching or filtering failed');
check($app->onlineLogTransport->received === [], 'Producer performed delivery');
$job = $app->queue->serializer->unserialize($app->queue->jobs[0]);
check($job instanceof DeliveryJob, 'Job serialization failed');
$job->execute($app->queue);
$id = $job->payload['events'][0]['id'];
check(strlen($id) === 32 && $job->payload['events'][0]['timestamp'] === 1234.5, 'Event format failed');
check(!str_contains(serialize($job), 'token'), 'Credentials in job');
$app->onlineLogTransport->fail = true;
try { $job->execute($app->queue); throw new LogicException('Failure swallowed'); } catch (RuntimeException $e) { check($e->getMessage() === 'Retry', 'Wrong failure'); }
check(!DeliveryJob::isDelivering(), 'Delivery guard not restored');
$app->onlineLogTransport->fail = false;
$job->execute($app->queue);
check($app->onlineLogTransport->received[2]['events'][0]['id'] === $id, 'Retry changed event ID');
$exceptionJob = $app->queue->serializer->unserialize($app->queue->jobs[1]);
check($exceptionJob->payload['events'][0]['exception']['class'] === RuntimeException::class, 'Exception lost');
$failures = 0;
$sync = new OnlineTarget(['endpoint' => 'https://example.invalid/ingest', 'queue' => new yii\queue\sync\Queue(), 'onEnqueueFailure' => static function () use (&$failures) { ++$failures; }]);
$sync->collect([['hello', Logger::LEVEL_INFO, 'test', 1.0, []]], true);
check($failures === 1, 'Synchronous queue not rejected');
echo "All tests passed.\n";
