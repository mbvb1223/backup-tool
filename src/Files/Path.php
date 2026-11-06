<?php

declare(strict_types=1);

namespace BackupTool\Files;

use RuntimeException;

readonly class Path
{
    public function __construct(
        public string $name,
        public string $path,
        public array $exclude = [],
    ) {
        if ($path === '') {
            throw new RuntimeException("{$this->id()}: 'path' is not set");
        }
    }

    public function id(): string
    {
        return "files/$this->name";
    }
}
