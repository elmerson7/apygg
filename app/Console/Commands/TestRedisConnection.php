<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;

class TestRedisConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Redis connectivity for cache, sessions, and queues';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Testing Redis connectivity...');
        $this->newLine();

        // Test Redis connection
        $this->info('1. Testing Redis connection...');
        try {
            $redis = Redis::connection();
            $redis->ping();
            $this->info('   ✅ Redis connection: OK');
        } catch (\Exception $e) {
            $this->error('   ❌ Redis connection: FAILED');
            $this->error('   Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test Cache
        $this->info('2. Testing Cache (Redis)...');
        try {
            $key = 'test_cache_' . time();
            $value = 'test_value';
            Cache::put($key, $value, 60);
            $retrieved = Cache::get($key);
            if ($retrieved === $value) {
                $this->info('   ✅ Cache: OK');
                Cache::forget($key);
            } else {
                $this->error('   ❌ Cache: FAILED (value mismatch)');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Cache: FAILED');
            $this->error('   Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test Sessions (Redis)
        $this->info('3. Testing Sessions (Redis)...');
        try {
            $sessionKey = 'test_session_' . time();
            $sessionValue = 'test_session_value';
            Redis::connection('default')->setex($sessionKey, 120, $sessionValue);
            $retrieved = Redis::connection('default')->get($sessionKey);
            if ($retrieved === $sessionValue) {
                $this->info('   ✅ Sessions: OK');
                Redis::connection('default')->del($sessionKey);
            } else {
                $this->error('   ❌ Sessions: FAILED (value mismatch)');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Sessions: FAILED');
            $this->error('   Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test Queue connection
        $this->info('4. Testing Queue (Redis)...');
        try {
            $queueConnection = config('queue.default');
            $queueDriver = config("queue.connections.{$queueConnection}.driver");
            if ($queueDriver === 'redis') {
                $this->info('   ✅ Queue driver: Redis');
                $this->info('   ✅ Queue connection: ' . $queueConnection);
            } else {
                $this->warn('   ⚠️  Queue driver: ' . $queueDriver . ' (not Redis)');
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Queue: FAILED');
            $this->error('   Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test Queue priorities
        $this->info('5. Testing Queue priorities...');
        try {
            $priorities = ['high', 'default', 'low'];
            foreach ($priorities as $priority) {
                $connection = "redis-{$priority}";
                if (config("queue.connections.{$connection}")) {
                    $this->info("   ✅ Queue '{$priority}': configured");
                } else {
                    $this->warn("   ⚠️  Queue '{$priority}': not configured");
                }
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Queue priorities: FAILED');
            $this->error('   Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('✅ All Redis tests passed!');
        return Command::SUCCESS;
    }
}
