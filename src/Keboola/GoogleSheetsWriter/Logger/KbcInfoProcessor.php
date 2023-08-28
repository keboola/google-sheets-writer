<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Logger;

use Monolog\Logger;

class KbcInfoProcessor
{
    public function __invoke(array $record): array
    {
        if ($record['level'] === Logger::DEBUG) {
            $record['context'] = array_merge(
                $record['context'],
                [
                    'kbc_run_id' => getenv('KBC_RUNID'),
                    'kbc_project_id' => getenv('KBC_PROJECTID'),
                    'kbc_config_id' => getenv('KBC_CONFIGID'),
                    'kbc_component_id' => getenv('KBC_COMPONENTID'),
                ]
            );
        }

        return $record;
    }
}
