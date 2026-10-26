<?php
declare(strict_types=1);
namespace carono\onlineTarget;

use yii\di\Instance;
use yii\queue\JobInterface;
use yii\queue\Queue;

class DeliveryJob implements JobInterface
{
    public string $endpoint = '';
    public string $transport = 'onlineLogTransport';
    public array $payload = [];
    private static bool $delivering = false;

    public static function isDelivering(): bool { return self::$delivering; }

    public function execute($queue)
    {
        self::$delivering = true;
        try {
            Instance::ensure($this->transport, TransportInterface::class)->send($this->endpoint, $this->payload);
        } finally {
            self::$delivering = false;
        }
    }
}
