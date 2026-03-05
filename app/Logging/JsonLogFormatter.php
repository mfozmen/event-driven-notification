<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

class JsonLogFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $data = [
            'timestamp' => $record->datetime->format('c'),
            'level' => strtoupper($record->level->name),
            'message' => $record->message,
        ];

        foreach (['correlation_id', 'notification_id', 'channel'] as $key) {
            if (isset($record->context[$key])) {
                $data[$key] = $record->context[$key];
            }
        }

        $remaining = array_diff_key($record->context, array_flip(['correlation_id', 'notification_id', 'channel']));
        if ($remaining !== []) {
            $data['context'] = $remaining;
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES)."\n";
    }

    public function formatBatch(array $records): string
    {
        $formatted = '';
        foreach ($records as $record) {
            $formatted .= $this->format($record);
        }

        return $formatted;
    }
}
