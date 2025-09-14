<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;
use Monolog\LogRecord;

class JsonFormatter extends MonologJsonFormatter
{
    /**
     * Create a new JsonFormatter instance.
     */
    public function __construct(
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces = false
    ) {
        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);
    }

    /**
     * {@inheritdoc}
     */
    public function format(LogRecord $record): string
    {
        // Normalize the record to get consistent structure
        $normalized = $this->normalize($record);
        
        // Custom structure for our application
        $formatted = [
            'timestamp' => $normalized['datetime'],
            'level' => $normalized['level_name'],
            'message' => $normalized['message'],
            'channel' => $normalized['channel'],
            'context' => $normalized['context'] ?? [],
            'extra' => $normalized['extra'] ?? [],
        ];

        // Add trace_id if available in context or extra
        if (isset($normalized['context']['trace_id'])) {
            $formatted['trace_id'] = $normalized['context']['trace_id'];
        } elseif (isset($normalized['extra']['trace_id'])) {
            $formatted['trace_id'] = $normalized['extra']['trace_id'];
        }

        // Add user_id if available in context
        if (isset($normalized['context']['user_id'])) {
            $formatted['user_id'] = $normalized['context']['user_id'];
        }

        // Add request_id if available
        if (isset($normalized['context']['request_id'])) {
            $formatted['request_id'] = $normalized['context']['request_id'];
        }

        // Include stack trace for errors and exceptions
        if (in_array($normalized['level'], [400, 500, 550, 600]) && isset($normalized['context']['exception'])) {
            $formatted['exception'] = $normalized['context']['exception'];
        }

        // Clean up context to avoid duplication
        unset(
            $formatted['context']['trace_id'],
            $formatted['context']['user_id'], 
            $formatted['context']['request_id'],
            $formatted['context']['exception']
        );

        // Remove empty context and extra if configured
        if ($this->ignoreEmptyContextAndExtra) {
            if (empty($formatted['context'])) {
                unset($formatted['context']);
            }
            if (empty($formatted['extra'])) {
                unset($formatted['extra']);
            }
        }

        $json = $this->toJson($formatted, true);

        return $json . ($this->appendNewline ? "\n" : '');
    }

    /**
     * {@inheritdoc}
     */
    public function formatBatch(array $records): string
    {
        $message = '';
        foreach ($records as $record) {
            $message .= $this->format($record);
        }

        return $message;
    }
}
