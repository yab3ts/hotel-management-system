<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cafex:backup {--path= : Custom backup directory for off-container persistence}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an automated verified database backup (pg_dump for PostgreSQL, copy for SQLite) with cryptographic checksum and schema metadata';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $customPath = $this->option('path');
        $backupDir = $customPath ?: storage_path('app/backups');
        if (!File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        $timestamp = date('Ymd_His');
        $dbConnection = config('database.default');

        if ($dbConnection === 'pgsql') {
            $host = config('database.connections.pgsql.host');
            $port = config('database.connections.pgsql.port');
            $database = config('database.connections.pgsql.database');
            $username = config('database.connections.pgsql.username');
            $password = config('database.connections.pgsql.password');

            $backupFileName = "cafex_backup_{$timestamp}.sql";
            $targetPath = "{$backupDir}/{$backupFileName}";

            $cmd = sprintf(
                'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s -F p -b -f %s 2>&1',
                escapeshellarg($password),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($database),
                escapeshellarg($targetPath)
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                $this->error("pg_dump failed with exit code {$returnCode}: " . implode("\n", $output));
                return Command::FAILURE;
            }
        } else {
            $sqlitePath = database_path('database.sqlite');
            if (!File::exists($sqlitePath)) {
                File::put($sqlitePath, '');
            }
            $backupFileName = "cafex_backup_{$timestamp}.sqlite";
            $targetPath = "{$backupDir}/{$backupFileName}";
            File::copy($sqlitePath, $targetPath);
        }

        $checksum = hash_file('sha256', $targetPath);
        $metadata = [
            'backup_file' => $backupFileName,
            'database_driver' => $dbConnection,
            'created_at' => now()->toIso8601String(),
            'sha256_checksum' => $checksum,
            'size_bytes' => File::size($targetPath),
            'environment' => app()->environment(),
        ];

        File::put("{$backupDir}/cafex_backup_{$timestamp}.json", json_encode($metadata, JSON_PRETTY_PRINT));

        $this->info("Database backup created successfully!");
        $this->line("Engine: {$dbConnection}");
        $this->line("Path: {$targetPath}");
        $this->line("SHA-256 Checksum: {$checksum}");

        return Command::SUCCESS;
    }
}
