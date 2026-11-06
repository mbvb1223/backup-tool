<?php

declare(strict_types=1);

namespace BackupTool;

use BackupTool\Database\Database;
use BackupTool\Files\Path;
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
        if ($db && !$databases) {
            throw new RuntimeException("'db' has no databases: add 'connections' => [name => ['databases' => [...]]]");
        }
        if ($files && !$paths) {
            throw new RuntimeException("'files' has no paths: add 'paths' => [name => path]");
        }

        $dbKeepDays = self::keepDays($db, 'db');
        $filesKeepDays = self::keepDays($files, 'files');
        foreach ($uploaders as $name => $settings) {
            self::keepDays($settings, "uploaders.$name");
        }
        if (($dbKeepDays === 0 || $filesKeepDays === 0) && !$uploaders) {
            throw new RuntimeException("'keep_days' => 0 deletes every backup right away: add an uploader or keep them longer");
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
            foreach ($server['databases'] ?? throw new RuntimeException("db.connections.$connection: 'databases' is not set") as $name => $options) {
                if (is_string($options)) {
                    [$name, $options] = [$options, []];
                }
                $databases[] = new Database(
                    connection: (string) $connection,
                    name: (string) $name,
                    server: $server,
                    include: $options['include'] ?? [],
                    exclude: $options['exclude'] ?? [],
                    excludeData: $options['exclude_data'] ?? [],
                );
            }
        }

        return $databases;
    }

    private static function paths(array $entries): array
    {
        $paths = [];
        foreach ($entries as $name => $entry) {
            if (is_string($entry)) {
                $entry = ['path' => $entry];
            }
            $paths[] = new Path(
                name: (string) $name,
                path: rtrim($entry['path'] ?? '', '/'),
                exclude: $entry['exclude'] ?? [],
            );
        }

        return $paths;
    }

    private static function keepDays(array $settings, string $section): ?int
    {
        $days = $settings['keep_days'] ?? null;
        if ($days !== null && (!is_int($days) || $days < 0)) {
            throw new RuntimeException("'$section.keep_days' must be a whole number of days >= 0, or left out to keep forever");
        }

        return $days;
    }
}
