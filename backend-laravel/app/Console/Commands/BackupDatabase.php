<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cafex:backup
                            {--path= : Custom backup directory for persistence}
                            {--remote-disk= : Laravel filesystem disk name for off-server upload (e.g. s3)}
                            {--no-encrypt : Disable AES-256-GCM encryption (not recommended)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an AES-256-GCM encrypted verified database backup with optional off-server upload';

    public static function resolveEncryptionKey(): string
    {
        $rawKey = env('BACKUP_ENCRYPTION_KEY') ?: (string) config('app.key');
        if (str_starts_with($rawKey, 'base64:')) {
            $decoded = base64_decode(substr($rawKey, 7), true);
            if ($decoded !== false) {
                $rawKey = $decoded;
            }
        }
        return hash('sha256', $rawKey, true);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $customPath = $this->option('path');
        $backupDir = $customPath ?: storage_path('app/backups');
        if (!File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0750, true);
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
            $plainPath = "{$backupDir}/{$backupFileName}";

            $cmd = sprintf(
                'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s -F p -b -f %s 2>&1',
                escapeshellarg((string) $password),
                escapeshellarg((string) $host),
                escapeshellarg((string) $port),
                escapeshellarg((string) $username),
                escapeshellarg((string) $database),
                escapeshellarg($plainPath)
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                $this->error("pg_dump failed with exit code {$returnCode}: " . implode("\n", $output));
                return Command::FAILURE;
            }
        } else {
            $sqlitePath = config('database.connections.sqlite.database');
            if ($sqlitePath === ':memory:' || empty($sqlitePath)) {
                $sqlitePath = database_path('database.sqlite');
            }
            if (!File::exists($sqlitePath)) {
                File::put($sqlitePath, '');
            }
            $backupFileName = "cafex_backup_{$timestamp}.sqlite";
            $plainPath = "{$backupDir}/{$backupFileName}";
            File::copy($sqlitePath, $plainPath);
        }

        $shouldEncrypt = !$this->option('no-encrypt');
        $targetPath = $plainPath;
        $finalFileName = $backupFileName;
        $cipher = 'none';

        if ($shouldEncrypt) {
            $plaintext = File::get($plainPath);
            $iv = random_bytes(12); // 96-bit GCM nonce
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                self::resolveEncryptionKey(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($ciphertext === false) {
                $this->error('Failed to encrypt database backup.');
                return Command::FAILURE;
            }

            $finalFileName = "{$backupFileName}.enc";
            $targetPath = "{$backupDir}/{$finalFileName}";
            // File envelope: magic("CFXB") + 12B IV + 16B GCM auth tag + ciphertext
            File::put($targetPath, 'CFXB' . $iv . $tag . $ciphertext);
            File::delete($plainPath);
            $cipher = 'aes-256-gcm';
        }

        $checksum = hash_file('sha256', $targetPath);
        $metadata = [
            'backup_file' => $finalFileName,
            'database_driver' => $dbConnection,
            'encrypted' => $shouldEncrypt,
            'cipher' => $cipher,
            'created_at' => now()->toIso8601String(),
            'sha256_checksum' => $checksum,
            'size_bytes' => File::size($targetPath),
            'environment' => app()->environment(),
            'off_server_disk' => null,
        ];

        $remoteDisk = $this->option('remote-disk') ?: env('BACKUP_REMOTE_DISK');
        if (!empty($remoteDisk)) {
            Storage::disk($remoteDisk)->put("backups/{$finalFileName}", File::get($targetPath));
            $metadata['off_server_disk'] = $remoteDisk;
            Storage::disk($remoteDisk)->put("backups/cafex_backup_{$timestamp}.json", json_encode($metadata, JSON_PRETTY_PRINT));
        }

        File::put("{$backupDir}/cafex_backup_{$timestamp}.json", json_encode($metadata, JSON_PRETTY_PRINT));

        $this->info("Database backup created successfully!");
        $this->line("Engine: {$dbConnection} | Encrypted: " . ($shouldEncrypt ? 'YES (aes-256-gcm)' : 'NO'));
        $this->line("Path: {$targetPath}");
        $this->line("SHA-256 Checksum: {$checksum}");

        return Command::SUCCESS;
    }
}
