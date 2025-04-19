<?php
namespace App\Core;

use Monolog\Formatter\JsonFormatter as BaseJsonFormatter;

class PrettyJsonFormatter extends BaseJsonFormatter
{
    public function __construct(
        int  $batchMode                  = self::BATCH_MODE_NEWLINES,
        bool $appendNewline              = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces         = true
    ) {
        parent::__construct(
            $batchMode,
            $appendNewline,
            $ignoreEmptyContextAndExtra,
            $includeStacktraces
        );
    }

    /**
     * Force JSON_PRETTY_PRINT (plus unescaped slashes/unicode).
     */
    protected function toJson(mixed $data, bool $ignoreErrors = false): string
    {
        // JSON_PRETTY_PRINT indents, UNESCAPED_* keep it human‑readable
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $json  = json_encode($data, $flags);
        if ($this->appendNewline) {
            $json .= "\n";
        }
        return $json === false
            ? ($ignoreErrors ? '' : parent::toJson($data, $ignoreErrors))
            : $json;
    }
}
