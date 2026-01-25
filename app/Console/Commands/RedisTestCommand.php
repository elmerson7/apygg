<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class RedisTestCommand extends Command
{
    protected $signature = 'redis:test';

    protected $description = 'Test Redis connectivity and configuration';

    public function handle()
    {
        $this->info('Testing Redis connectivity...');
        $this->newLine();

        try {
            $result = Redis::ping();
            $this->info('✅ Redis connection: OK');
            $this->line('   Response: '.$result);
        } catch (\Exception $e) {
            $this->error('❌ Redis connection failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        try {
            Cache::store('redis')->put('test_key', 'test_value', 10);
            $value = Cache::store('redis')->get('test_key');
            if ($value === 'test_value') {
                $this->info('✅ Redis cache: OK');
                Cache::store('redis')->forget('test_key');
            } else {
                $this->error('❌ Redis cache: Failed to retrieve value');

                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('❌ Redis cache failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $queueConnection = config('queue.default');
        $this->info('✅ Queue connection: '.$queueConnection);

        $sessionDriver = config('session.driver');
        $this->info('✅ Session driver: '.$sessionDriver);

        $this->newLine();
        $this->info('Redis Configuration:');
        $this->line('   Host: '.config('database.redis.default.host'));
        $this->line('   Port: '.config('database.redis.default.port'));
        $this->line('   Database: '.config('database.redis.default.database'));
        $this->line('   Max Retries: '.config('database.redis.default.max_retries'));
        $this->line('   Backoff Algorithm: '.config('database.redis.default.backoff_algorithm'));

        $this->newLine();
        $this->info('✅ All Redis tests passed!');

        return Command::SUCCESS;
    }
}
