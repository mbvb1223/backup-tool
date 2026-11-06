<?php

declare(strict_types=1);

namespace DbBackup\Database;

use RuntimeException;

readonly class Database
{
    public function __construct(
        public string $connection,
        public string $name,
        public array $server,
        public array $include = [],
        public array $exclude = [],
        public array $excludeData = [],
    ) {
        if ($include && ($exclude || $excludeData)) {
            throw new RuntimeException("$this->connection/$this->name: use either include or exclude/exclude_data, not both");
        }
    }

    public function id(): string
    {
        return "db/$this->connection/$this->name";
    }
}
