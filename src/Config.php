<?php

declare(strict_types=1);

namespace BackupTool;

use BackupTool\Database\Database;
use BackupTool\Files\Files;
use RuntimeException;

readonly class Config
{
    public function __construct(
        public string $dbDir,
        public ?int $dbKeepDays,
        public string $mysqldump,
        public array $mysqldumpOptions,
        public array $databases,
        public string $filesDir,
        public ?int $filesKeepDays,
        public array $paths,
        public array $uploaders,
        public string $logFile,
        public string $slackWebhook,
    ) {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException("Config not found: $file (copy config.example.php to config.php)");
        }
        $dir = dirname(realpath($file));
        $config = require $file;

        $db = $config['db'] ?? [];
        $files = $config['files'] ?? [];
        $uploaders = $config['uploaders'] ?? [];
        $log = $config['log'] ?? [];

        $databases = self::databases($db['connections'] ?? []);
        $paths = self::paths($files['paths'] ?? []);
        if (!$databases && !$paths) {
            throw new RuntimeException("Nothing to back up in $file: add 'db' => ['connections' => ...] or 'files' => ['paths' => ...]");
        }
        $dbKeepDays = $db['keep_days'] ?? null;
        $filesKeepDays = $files['keep_days'] ?? null;
        if (($dbKeepDays === 0 || $filesKeepDays === 0) && !$uploaders) {
            throw new RuntimeException("'keep_days' => 0 deletes every dump right away: add an uploader or keep them longer");
        }

        return new self(
            dbDir: rtrim($db['dir'] ?? "$dir/backups", '/'),
            dbKeepDays: $dbKeepDays,
            mysqldump: $db['mysqldump'] ?? 'mysqldump',
            mysqldumpOptions: $db['mysqldump_options'] ?? [],
            databases: $databases,
            filesDir: rtrim($files['dir'] ?? "$dir/backups", '/'),
            filesKeepDays: $filesKeepDays,
            paths: $paths,
            uploaders: $uploaders,
            logFile: $log['file'] ?? "$dir/backup.log",
            slackWebhook: $log['slack_webhook'] ?? '',
        );
    }

    private static function databases(array $connections): array
    {
        $databases = [];
        foreach ($connections as $connection => $server) {
            foreach ($server['databases'] ?? [] as $name => $tables) {
                if (is_int($name)) {
                    [$name, $tables] = [$tables, []];
                }
                $databases[] = new Database(
                    connection: (string) $connection,
                    name: (string) $name,
                    server: $server,
                    include: $tables['include'] ?? [],
                    exclude: $tables['exclude'] ?? [],
                    excludeData: $tables['exclude_data'] ?? [],
                );
            }
        }

        return $databases;
    }

    private static function paths(array $entries): array
    {
        $files = [];
        foreach ($entries as $name => $entry) {
            if (is_string($entry)) {
                $entry = ['path' => $entry];
            }
            $files[] = new Files(
                name: (string) $name,
                path: rtrim($entry['path'] ?? '', '/'),
                exclude: $entry['exclude'] ?? [],
            );
        }

        return $files;
    }
}
