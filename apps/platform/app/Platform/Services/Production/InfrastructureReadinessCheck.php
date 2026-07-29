<?php

declare(strict_types=1);

namespace App\Platform\Services\Production;

use App\Platform\Contracts\Production\ChecksReadiness;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Redis\RedisManager;

final readonly class InfrastructureReadinessCheck implements ChecksReadiness
{
    public function __construct(
        private ConnectionResolverInterface $database,
        private RedisManager $redis,
        private FilesystemManager $filesystems,
    ) {}

    public function check(): void
    {
        $this->database->connection()->select('select 1');
        $this->redis->connection()->command('ping');

        // The result may be false; completing the call proves the configured disk is reachable.
        $this->filesystems->disk()->exists('__qompose_readiness__');
    }
}
