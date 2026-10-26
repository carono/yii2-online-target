<?php
declare(strict_types=1);
namespace carono\onlineTarget;

interface TransportInterface
{
    /** Return only on success; throw on failure so the application's queue can retry. */
    public function send(string $endpoint, array $payload): void;
}
