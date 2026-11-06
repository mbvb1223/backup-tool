<?php

declare(strict_types=1);

namespace DbBackup\Database;

use DbBackup\Backup;
use DbBackup\Config;
use DbBackup\Uploader\Uploader;
use Psr\Log\LoggerInterface;

readonly class DatabaseBackup extends Backup
{
    private MysqlDumper $dumper;

    /** @param array<string, Uploader> $uploaders */
    public function __construct(Config $config, array $uploaders, LoggerInterface $logger)
    {
        parent::__construct($config->dbDir, $config->dbKeepDays, $uploaders, $logger);

        $this->dumper = new MysqlDumper($config);
    }

    public function run(Database $db): bool
    {
        return $this->runLocked($db->id(), fn (string $dir) => $this->dumper->dump($db, $dir));
    }
}
