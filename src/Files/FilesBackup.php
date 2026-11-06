<?php

declare(strict_types=1);

namespace BackupTool\Files;

use BackupTool\Backup;
use BackupTool\Config;
use BackupTool\Uploader\Uploader;
use Psr\Log\LoggerInterface;
use RuntimeException;

readonly class FilesBackup extends Backup
{
    /** @param array<string, Uploader> $uploaders */
    public function __construct(private Path $path, Config $config, array $uploaders, LoggerInterface $logger)
    {
        parent::__construct($path->id(), $config->filesDir, $config->filesKeepDays, $uploaders, $logger);
    }

    protected function extension(): string
    {
        return '.tar.gz';
    }

    protected function write(string $file): void
    {
        // tar stores a symlinked path as the bare link, so archive the folder it points to
        $realPath = realpath($this->path->path);
        if ($realPath === false) {
            throw new RuntimeException("{$this->path->path} does not exist");
        }

        $excludes = array_map(fn (string $pattern) => "--exclude=$pattern", $this->path->exclude);
        $this->gzipOutput([['tar', '-cf', '-', ...$excludes, '-C', dirname($realPath), '--', basename($realPath)]], $file);
    }
}
