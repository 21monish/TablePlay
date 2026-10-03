<?php

namespace App\Console\Commands;

use App\Models\AppRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BootstrapUpgrade extends Command
{
    protected $signature = 'tableplay:bootstrap-upgrade {config} {--delete-config}';
    protected $description = 'Publish application packages bundled with a verified TablePlay server upgrade.';

    public function handle(): int
    {
        $path = (string) $this->argument('config');
        if (! is_file($path) || ! is_readable($path)) { $this->error('Upgrade configuration cannot be read.'); return self::FAILURE; }
        try { $raw = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { $this->error($e->getMessage()); return self::FAILURE; }
        $validator = Validator::make($raw, [
            'releases' => ['required', 'array'], 'releases.*.app' => ['required', 'in:staff,customer'],
            'releases.*.platform' => ['required', 'in:android,windows'], 'releases.*.version' => ['required', 'string', 'max:40'],
            'releases.*.build_number' => ['nullable', 'integer', 'min:1'], 'releases.*.file_path' => ['required', 'string', 'max:255'],
            'releases.*.original_filename' => ['required', 'string', 'max:255'], 'releases.*.mime_type' => ['required', 'string', 'max:120'],
            'releases.*.file_size' => ['required', 'integer', 'min:1'], 'releases.*.sha256' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
            'releases.*.package_identifier' => ['nullable', 'string', 'max:255'],
            'releases.*.signing_certificate_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        if ($validator->fails()) { foreach ($validator->errors()->all() as $error) $this->error($error); return self::FAILURE; }
        DB::transaction(function () use ($validator) {
            foreach ($validator->validated()['releases'] as $release) {
                AppRelease::where('app', $release['app'])->where('platform', $release['platform'])->update(['is_published' => false]);
                AppRelease::updateOrCreate(
                    ['app' => $release['app'], 'platform' => $release['platform'], 'version' => $release['version']],
                    ['build_number' => $release['build_number'] ?? null, 'release_notes' => 'Bundled with a verified TablePlay server upgrade.',
                     'mandatory' => false, 'file_path' => $release['file_path'], 'original_filename' => $release['original_filename'],
                     'mime_type' => $release['mime_type'], 'file_size' => $release['file_size'], 'sha256' => strtolower($release['sha256']),
                     'package_identifier' => $release['package_identifier'] ?? null,
                     'signing_certificate_sha256' => isset($release['signing_certificate_sha256']) ? strtolower($release['signing_certificate_sha256']) : null,
                     'verified_at' => now(), 'is_published' => true, 'published_at' => now()],
                );
            }
        });
        if ($this->option('delete-config')) @unlink($path);
        $this->info('TablePlay upgrade packages published.');
        return self::SUCCESS;
    }
}
