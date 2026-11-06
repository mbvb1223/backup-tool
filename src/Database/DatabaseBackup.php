<?php

declare(strict_types=1);

namespace BackupTool\Database;

use BackupTool\Backup;
use BackupTool\Config;
use BackupTool\Uploader\Uploader;
use Psr\Log\LoggerInterface;
use RuntimeException;

readonly class DatabaseBackup extends Backup
{
    public function __construct(private Database $db, private Config $config, array $uploaders, LoggerInterface $logger)
    {
        parent::__construct($db->id(), $config->dbDir, $config->dbKeepDays, $uploaders, $logger);
    }

    protected function extension(): string
    {
        return '.sql.gz';
    }

    protected function write(string $file): void
    {
        $db = $this->db;
        $credentialsFile = $this->writeCredentialsFile();
        try {
            $mysqldump = [$this->config->mysqldump, "--defaults-file=$credentialsFile", ...$this->config->mysqldumpOptions];

            $commands = [];
            if ($db->excludeData) {
                $commands[] = [...$mysqldump, '--no-data', '--skip-routines', '--skip-events', $db->name, ...$db->excludeData];
            }
            $ignoreTables = array_map(fn (string $table) => "--ignore-table=$db->name.$table", [...$db->exclude, ...$db->excludeData]);
            $commands[] = [...$mysqldump, ...$ignoreTables, $db->name, ...$db->include];

            $this->gzipOutput($commands, $file);
        } finally {
            unlink($credentialsFile);
        }
    }

    private function writeCredentialsFile(): string
    {
        $lines = ['[client]'];
        foreach (['host', 'port', 'socket', 'user', 'password'] as $key) {
            if (($this->db->server[$key] ?? '') !== '') {
                $lines[] = $key . '="' . addcslashes((string) $this->db->server[$key], '"\\') . '"';
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'backup-tool');
        if ($path === false || file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new RuntimeException('cannot write the mysqldump credentials file');
        }

        return $path;
    }
}
