#!/bin/sh
set -e

if [ -n "$REDIS_PASSWORD" ]; then
    exec redis-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru --requirepass "$REDIS_PASSWORD"
else
    exec redis-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru
fi