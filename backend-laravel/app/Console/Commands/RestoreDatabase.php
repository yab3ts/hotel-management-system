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
                            {--path= : Custom backup directory}
                            {--force : Force overwrite live database without interactive prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore database from verified & AES-256-GCM encrypted backup with checksum validation';

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

        $backupDir = $this->option('path') ?: storage_path('app/backups');
        if (!File::exists($backupDir)) {
            $this->error("No backups directory found at {$backupDir}");
            return Command::FAILURE;
        }

        $dbConnection = config('database.default');
        $specificFile = $this->argument('backup_file');

        if ($specificFile) {
            $backupPath = str_starts_with($specificFile, '/') ? $specificFile : "{$backupDir}/{$specificFile}";
        } else {
            $files = glob("{$backupDir}/cafex_backup_*") ?: [];
            $files = array_filter(
                $files,
                fn ($f) => preg_match('/\.(sql|sqlite)(\.enc)?$/', $f) === 1
            );

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

        // Verify SHA-256 checksum with companion JSON if available
        $jsonPath = preg_replace('/\.(sql|sqlite)(\.enc)?$/', '.json', $backupPath);
        if (File::exists($jsonPath)) {
            $metadata = json_decode(File::get($jsonPath), true);
            $currentHash = hash_file('sha256', $backupPath);
            if (!empty($metadata['sha256_checksum']) && !hash_equals($metadata['sha256_checksum'], $currentHash)) {
                $this->error("Checksum mismatch! The backup file may be corrupted or tampered with.");
                return Command::FAILURE;
            }
            $this->info("Checksum verified OK: {$currentHash}");
        }

        $workingPlainPath = $backupPath;
        $tempDecryptedPath = null;

        try {
            if (str_ends_with($backupPath, '.enc')) {
                $raw = File::get($backupPath);
                if (strlen($raw) < 32 || substr($raw, 0, 4) !== 'CFXB') {
                    $this->error('Invalid encrypted backup envelope.');
                    return Command::FAILURE;
                }
                $iv = substr($raw, 4, 12);
                $tag = substr($raw, 16, 16);
                $ciphertext = substr($raw, 32);

                $decrypted = openssl_decrypt(
                    $ciphertext,
                    'aes-256-gcm',
                    BackupDatabase::resolveEncryptionKey(),
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag
                );

                if ($decrypted === false) {
                    $this->error('Decryption or AEAD authentication tag verification failed.');
                    return Command::FAILURE;
                }

                $tempDecryptedPath = $backupPath . '.tmp_restore';
                File::put($tempDecryptedPath, $decrypted);
                $workingPlainPath = $tempDecryptedPath;
            }

            $isSqlDump = preg_match('/\.sql(\.enc)?$/', $backupPath) === 1;
            if ($isSqlDump) {
                $host = config('database.connections.pgsql.host');
                $port = config('database.connections.pgsql.port');
                $database = config('database.connections.pgsql.database');
                $username = config('database.connections.pgsql.username');
                $password = config('database.connections.pgsql.password');

                $cmd = sprintf(
                    'PGPASSWORD=%s psql -h %s -p %s -U %s -d %s -f %s 2>&1',
                    escapeshellarg((string) $password),
                    escapeshellarg((string) $host),
                    escapeshellarg((string) $port),
                    escapeshellarg((string) $username),
                    escapeshellarg((string) $database),
                    escapeshellarg($workingPlainPath)
                );

                exec($cmd, $output, $returnCode);

                if ($returnCode !== 0) {
                    $this->error("psql restore failed with exit code {$returnCode}: " . implode("\n", $output));
                    return Command::FAILURE;
                }
            } else {
                $sqlitePath = config('database.connections.sqlite.database');
                if ($sqlitePath === ':memory:' || empty($sqlitePath)) {
                    $sqlitePath = database_path('database.sqlite');
                }
                File::copy($workingPlainPath, $sqlitePath);
            }
        } finally {
            if ($tempDecryptedPath && File::exists($tempDecryptedPath)) {
                File::delete($tempDecryptedPath);
            }
        }

        $this->info("Database successfully restored from " . basename($backupPath));
        return Command::SUCCESS;
    }
}
