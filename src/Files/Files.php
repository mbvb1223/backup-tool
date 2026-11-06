<?php

declare(strict_types=1);

namespace DbBackup\Files;

use RuntimeException;

readonly class Files
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
