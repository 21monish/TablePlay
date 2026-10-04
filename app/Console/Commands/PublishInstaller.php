<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PublishInstaller extends Command
{
    protected $signature = 'tableplay:publish-installer
        {file : Absolute path to the release TablePlay-Setup.exe}
        {--force : Replace the currently published installer object}';

    protected $description = 'Verify and stream the configured TablePlay Setup release to protected update storage';

    public function handle(): int
    {
        $file = realpath((string) $this->argument('file'));
        if ($file === false || ! is_file($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'exe') {
            $this->error('Provide an existing TablePlay Setup .exe file.');

            return self::FAILURE;
        }

        $installer = (array) config('app_updates.installer');
        $version = trim((string) ($installer['version'] ?? ''));
        $expectedHash = strtolower(trim((string) ($installer['sha256'] ?? '')));
        $diskName = trim((string) ($installer['disk'] ?? 'updates'));
        $path = ltrim(trim((string) ($installer['path'] ?? '')), '/');

        if ($version === '' || $path === '' || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            $this->error('Configure TABLEPLAY_INSTALLER_VERSION, TABLEPLAY_INSTALLER_PATH and TABLEPLAY_INSTALLER_SHA256 first.');

            return self::FAILURE;
        }

        $actualHash = hash_file('sha256', $file);
        if (! is_string($actualHash) || ! hash_equals($expectedHash, strtolower($actualHash))) {
            $this->error('The installer SHA-256 does not match TABLEPLAY_INSTALLER_SHA256. Nothing was uploaded.');

            return self::FAILURE;
        }

        $disk = Storage::disk($diskName);
        if ($disk->exists($path) && ! $this->option('force')) {
            $this->error("An installer is already published at {$path}. Use --force only for an intentional replacement.");

            return self::FAILURE;
        }

        $stream = fopen($file, 'rb');
        if ($stream === false) {
            $this->error('The installer could not be opened for streaming.');

            return self::FAILURE;
        }

        try {
            $written = $disk->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        if (! $written || ! $disk->exists($path) || $disk->size($path) !== filesize($file)) {
            $disk->delete($path);
            $this->error('The installer upload was incomplete and the partial object was removed.');

            return self::FAILURE;
        }

        $this->info("TablePlay Setup {$version} published successfully.");
        $this->line('Storage path: '.$path);
        $this->line('SHA-256: '.$actualHash);

        return self::SUCCESS;
    }
}
