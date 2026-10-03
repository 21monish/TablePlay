<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PublicAssetStorage
{
    public function store(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, 'public');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        if (config('filesystems.disks.public.driver') === 'local') {
            return '/storage/'.$path;
        }

        return Storage::disk('public')->url($path);
    }

    public function delete(?string $publicPath): void
    {
        $relativePath = $this->relativePath($publicPath);

        if ($relativePath !== null) {
            Storage::disk('public')->delete($relativePath);
        }
    }

    private function relativePath(?string $publicPath): ?string
    {
        if (! filled($publicPath)) {
            return null;
        }

        if (str_starts_with($publicPath, '/storage/')) {
            return substr($publicPath, 9);
        }

        $baseUrl = rtrim((string) config('filesystems.disks.public.url'), '/');
        if ($baseUrl !== '' && str_starts_with($publicPath, $baseUrl.'/')) {
            return ltrim(substr($publicPath, strlen($baseUrl)), '/');
        }

        return null;
    }
}
