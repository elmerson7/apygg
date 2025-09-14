<?php

namespace App\Logging;

use Monolog\LogRecord;

class AddTraceIdProcessor
{
    /**
     * Add trace_id to all log records
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $request = request();

        if ($request && $traceId = $request->attributes->get('trace_id')) {
            $record->extra['trace_id'] = $traceId;
        }

        return $record;
    }
}
