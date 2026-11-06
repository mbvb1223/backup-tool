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
    public function __construct(private Files $files, Config $config, array $uploaders, LoggerInterface $logger)
    {
        parent::__construct($files->id(), $config->filesDir, $config->filesKeepDays, $uploaders, $logger);

        $this->archiver = new Archiver();
    }

    protected function dump(string $dir): string
    {
        return $this->archiver->archive($this->files, $dir);
    }
}
