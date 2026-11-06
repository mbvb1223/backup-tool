<?php

declare(strict_types=1);

namespace BackupTool\Database;

use BackupTool\Backup;
use BackupTool\Config;
use BackupTool\Uploader\Uploader;
use Psr\Log\LoggerInterface;

readonly class DatabaseBackup extends Backup
{
    private MysqlDumper $dumper;

    /** @param array<string, Uploader> $uploaders */
    public function __construct(private Database $db, Config $config, array $uploaders, LoggerInterface $logger)
    {
        parent::__construct($db->id(), $config->dbDir, $config->dbKeepDays, $uploaders, $logger);

        $this->dumper = new MysqlDumper($config);
    }

    protected function createBackupFile(string $dir): string
    {
        return $this->dumper->dump($this->db, $dir);
    }
}
