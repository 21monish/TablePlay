<?php

namespace App\Console\Commands;

use App\Models\AppRelease;
use App\Models\RestaurantSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BootstrapInstallation extends Command
{
    protected $signature = 'tableplay:bootstrap-installation
        {config : Absolute path to the temporary installer JSON file}
        {--delete-config : Delete the temporary installer JSON after a successful bootstrap}';

    protected $description = 'Apply the initial restaurant identity, administrator password, and bundled app releases.';

    public function handle(): int
    {
        $path = (string) $this->argument('config');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('The installer configuration file cannot be read.');

            return self::FAILURE;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->error('The installer configuration contains invalid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        try {
            $validated = Validator::make($data, [
                'restaurant_name' => ['required', 'string', 'max:255'],
                'admin_password' => ['required', 'string', 'min:10', 'max:255'],
                'admin_email' => ['nullable', 'email', 'max:255'],
                'releases' => ['sometimes', 'array'],
                'releases.*.app' => ['required', 'in:staff,customer'],
                'releases.*.platform' => ['required', 'in:android,windows'],
                'releases.*.version' => ['required', 'string', 'max:40'],
                'releases.*.build_number' => ['nullable', 'integer', 'min:1'],
                'releases.*.file_path' => ['required', 'string', 'max:255'],
                'releases.*.original_filename' => ['required', 'string', 'max:255'],
                'releases.*.mime_type' => ['required', 'string', 'max:120'],
                'releases.*.file_size' => ['required', 'integer', 'min:1'],
                'releases.*.sha256' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
                'releases.*.package_identifier' => ['nullable', 'string', 'max:255'],
                'releases.*.signing_certificate_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/i'],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->error($field.': '.$message);
                }
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($validated): void {
            RestaurantSetting::query()->updateOrCreate(
                ['id' => 1],
                ['restaurant_name' => trim($validated['restaurant_name'])],
            );

            $admin = User::query()->where('username', 'admin')->firstOrFail();
            $admin->forceFill([
                'name' => 'Administrator',
                'email' => $validated['admin_email'] ?? 'admin@tableplay.local',
                'password' => Hash::make($validated['admin_password']),
                'is_active' => true,
            ])->save();

            foreach ($validated['releases'] ?? [] as $release) {
                AppRelease::query()->updateOrCreate(
                    [
                        'app' => $release['app'],
                        'platform' => $release['platform'],
                        'version' => $release['version'],
                    ],
                    [
                        'build_number' => $release['build_number'] ?? null,
                        'release_notes' => 'Bundled with the TablePlay Windows installer.',
                        'mandatory' => false,
                        'file_path' => $release['file_path'],
                        'original_filename' => $release['original_filename'],
                        'mime_type' => $release['mime_type'],
                        'package_identifier' => $release['package_identifier'] ?? null,
                        'file_size' => $release['file_size'],
                        'sha256' => strtolower($release['sha256']),
                        'signing_certificate_sha256' => isset($release['signing_certificate_sha256'])
                            ? strtolower($release['signing_certificate_sha256'])
                            : null,
                        'verified_at' => now(),
                        'is_published' => true,
                        'published_at' => now(),
                    ],
                );
            }
        });

        if ($this->option('delete-config')) {
            @unlink($path);
        }

        $this->info('TablePlay installation bootstrap completed.');

        return self::SUCCESS;
    }
}
