<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    public function status(): array
    {
        $paths = $this->paths();

        return [
            'available' => $paths !== null,
            'message' => $paths
                ? 'Private TablePlay database tools are ready.'
                : 'Automatic backups become available in the one-click Windows installation.',
            'directory' => $paths['backup_directory'] ?? null,
        ] + $this->inventory($paths);
    }

    public function downloadPath(string $filename): string
    {
        if (! preg_match('/^tableplay-(?:auto-[a-z0-9-]+|\d{8}-\d{6})\.sql$/i', $filename)) {
            throw new RuntimeException('That backup filename is not valid.');
        }
        $paths = $this->paths();
        $root = $paths ? realpath($paths['backup_directory']) : false;
        $resolved = $root ? realpath($root.DIRECTORY_SEPARATOR.$filename) : false;
        if ($root === false || $resolved === false || dirname($resolved) !== $root || ! is_file($resolved)) {
            throw new RuntimeException('The selected backup file was not found.');
        }
        return $resolved;
    }

    public function create(int $retentionDays): array
    {
        $paths = $this->paths();
        if ($paths === null) {
            throw new RuntimeException('Private TablePlay database tools were not found. Use the one-click Windows installation for automatic backups.');
        }

        if (! is_dir($paths['backup_directory']) && ! mkdir($paths['backup_directory'], 0775, true) && ! is_dir($paths['backup_directory'])) {
            throw new RuntimeException('The TablePlay backup directory could not be created.');
        }

        $filename = 'tableplay-auto-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(5)).'.sql';
        $destination = $paths['backup_directory'].DIRECTORY_SEPARATOR.$filename;
        $process = new Process([
            $paths['mysqldump'],
            '--defaults-extra-file='.$paths['client_file'],
            '--single-transaction', '--routines', '--events', '--triggers', '--hex-blob',
            '--result-file='.$destination,
            (string) config('database.connections.mysql.database', 'tableplay'),
        ], $paths['runtime_root']);
        $process->setTimeout(600)->mustRun();

        if (! is_file($destination) || filesize($destination) < 1) {
            throw new RuntimeException('The database backup tool did not create a valid file.');
        }

        $deleted = $this->deleteExpiredAutomaticBackups($paths['backup_directory'], $retentionDays, $destination);

        return [
            'filename' => $filename,
            'size_bytes' => filesize($destination),
            'sha256' => hash_file('sha256', $destination),
            'retention_days' => $retentionDays,
            'expired_backups_deleted' => $deleted,
        ];
    }

    private function deleteExpiredAutomaticBackups(string $directory, int $retentionDays, string $keep): int
    {
        $root = realpath($directory);
        if ($root === false) {
            return 0;
        }

        $cutoff = now()->subDays(max(1, $retentionDays))->getTimestamp();
        $deleted = 0;
        foreach (glob($root.DIRECTORY_SEPARATOR.'tableplay-auto-*.sql') ?: [] as $file) {
            $resolved = realpath($file);
            if ($resolved === false || $resolved === realpath($keep) || dirname($resolved) !== $root) {
                continue;
            }
            if (filemtime($resolved) < $cutoff && unlink($resolved)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function inventory(?array $paths): array
    {
        if ($paths === null || ! is_dir($paths['backup_directory'])) {
            return ['count' => 0, 'total_bytes' => 0, 'files' => []];
        }

        $files = collect(glob($paths['backup_directory'].DIRECTORY_SEPARATOR.'tableplay-*.sql') ?: [])
            ->filter(fn (string $path) => preg_match('/^tableplay-(?:auto-[a-z0-9-]+|\d{8}-\d{6})\.sql$/i', basename($path)))
            ->map(fn (string $path) => [
                'filename' => basename($path),
                'type' => str_starts_with(strtolower(basename($path)), 'tableplay-auto-') ? 'automatic' : 'manual',
                'size_bytes' => filesize($path) ?: 0,
                'created_at' => date(DATE_ATOM, filemtime($path)),
            ])
            ->sortByDesc('created_at')
            ->values();

        return [
            'count' => $files->count(),
            'total_bytes' => $files->sum('size_bytes'),
            'files' => $files->take(10)->values()->all(),
        ];
    }

    private function paths(): ?array
    {
        $candidates = array_filter([
            config('tableplay.runtime_root'),
            dirname(base_path()),
        ]);

        foreach (array_unique($candidates) as $candidate) {
            $root = realpath((string) $candidate);
            if ($root === false) {
                continue;
            }
            $mysqldump = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'mysqldump.exe';
            $client = $root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'database-client.ini';
            if (is_file($mysqldump) && is_file($client)) {
                return [
                    'runtime_root' => $root,
                    'mysqldump' => $mysqldump,
                    'client_file' => $client,
                    'backup_directory' => $root.DIRECTORY_SEPARATOR.'backups',
                ];
            }
        }

        return null;
    }
}
