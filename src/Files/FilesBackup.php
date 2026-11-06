<?php

declare(strict_types=1);

namespace DbBackup\Files;

use DbBackup\Backup;
use DbBackup\Config;
use DbBackup\Uploader\Uploader;
use Psr\Log\LoggerInterface;

readonly class FilesBackup extends Backup
{
    private Archiver $archiver;

    /** @param array<string, Uploader> $uploaders */
    public function __construct(Config $config, array $uploaders, LoggerInterface $logger)
    {
        parent::__construct($config->filesDir, $config->filesKeepDays, $uploaders, $logger);

        $this->archiver = new Archiver();
    }

    public function run(Files $files): bool
    {
        return $this->runLocked($files->id(), fn (string $dir) => $this->archiver->archive($files, $dir));
    }
}
