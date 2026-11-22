<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RestoreDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cafex:restore 
                            {backup_file? : Specific backup file name or omit for latest}
                            {--force : Force overwrite live database without interactive prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore database from verified backup with checksum validation and safety guard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('force') && !app()->runningUnitTests()) {
            if (!$this->confirm('WARNING: Restoring will overwrite the current active database. Are you sure you want to proceed?', false)) {
                $this->warn('Restore cancelled by user.');
                return Command::SUCCESS;
            }
        }
        $backupDir = storage_path('app/backups');
        if (!File::exists($backupDir)) {
            $this->error("No backups directory found at {$backupDir}");
            return Command::FAILURE;
        }

        $dbConnection = config('database.default');
        $specificFile = $this->argument('backup_file');

        if ($specificFile) {
            $backupPath = "{$backupDir}/{$specificFile}";
        } else {
            // Find latest backup file (.sql for pgsql, .sqlite for sqlite)
            $ext = $dbConnection === 'pgsql' ? 'sql' : 'sqlite';
            $files = glob("{$backupDir}/*.{$ext}");
            if (empty($files)) {
                // Fallback check for any sql/sqlite files
                $files = glob("{$backupDir}/*.*");
                $files = array_filter($files, fn ($f) => str_ends_with($f, '.sql') || str_ends_with($f, '.sqlite'));
            }

            if (empty($files)) {
                $this->error("No backup files found to restore.");
                return Command::FAILURE;
            }
            rsort($files);
            $backupPath = $files[0];
        }

        if (!File::exists($backupPath)) {
            $this->error("Backup file does not exist: {$backupPath}");
            return Command::FAILURE;
        }

        // Verify checksum with companion JSON if available
        $jsonPath = preg_replace('/\.(sql|sqlite)$/', '.json', $backupPath);
        if (File::exists($jsonPath)) {
            $metadata = json_decode(File::get($jsonPath), true);
            $currentHash = hash_file('sha256', $backupPath);
            if (!empty($metadata['sha256_checksum']) && $metadata['sha256_checksum'] !== $currentHash) {
                $this->error("Checksum mismatch! The backup file may be corrupted.");
                return Command::FAILURE;
            }
            $this->info("Checksum verified OK: {$currentHash}");
        }

        if ($dbConnection === 'pgsql' || str_ends_with($backupPath, '.sql')) {
            $host = config('database.connections.pgsql.host');
            $port = config('database.connections.pgsql.port');
            $database = config('database.connections.pgsql.database');
            $username = config('database.connections.pgsql.username');
            $password = config('database.connections.pgsql.password');

            $cmd = sprintf(
                'PGPASSWORD=%s psql -h %s -p %s -U %s -d %s -f %s 2>&1',
                escapeshellarg($password),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($database),
                escapeshellarg($backupPath)
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                $this->error("psql restore failed with exit code {$returnCode}: " . implode("\n", $output));
                return Command::FAILURE;
            }
        } else {
            $sqlitePath = database_path('database.sqlite');
            File::copy($backupPath, $sqlitePath);
        }

        $this->info("Database successfully restored from " . basename($backupPath));
        return Command::SUCCESS;
    }
}
