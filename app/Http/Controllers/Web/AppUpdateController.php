<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AppInstallation;
use App\Models\AppRelease;
use App\Services\AppUpdateService;
use App\Services\AuditService;
use App\Services\PackageInspector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppUpdateController extends Controller
{
    public function index(AppUpdateService $updates)
    {
        $latest = collect(['stable', 'beta', 'pilot'])->mapWithKeys(fn (string $channel) => [
            $channel => collect(AppUpdateService::TARGETS)->mapWithKeys(
                fn (array $target, string $name) => [$name => $updates->latest($target['app'], $target['platform'], $channel)],
            ),
        ]);

        return view('admin.app-updates', [
            'releases' => AppRelease::with('uploader')->latest()->get(),
            'installations' => AppInstallation::with(['device', 'user'])->latest('last_checked_at')->get(),
            'latest' => $latest,
            'uploadMax' => ini_get('upload_max_filesize'),
            'postMax' => ini_get('post_max_size'),
        ]);
    }

    public function store(
        Request $request,
        AppUpdateService $updates,
        AuditService $audit,
        PackageInspector $inspector,
    ) {
        $data = $request->validate([
            'target' => ['required', 'string', 'in:staff-android,customer-android,staff-windows'],
            'version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/'],
            'build_number' => ['nullable', 'integer', 'min:1'],
            'minimum_supported_version' => ['nullable', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
            'package' => ['required', 'file', 'max:524288'],
            'mandatory' => ['nullable', 'boolean'],
            'publish' => ['nullable', 'boolean'],
            'channel' => ['nullable', 'in:stable,beta,pilot'],
            'rollout_percentage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $data['channel'] = $data['channel'] ?? 'stable';
        $data['rollout_percentage'] = (int) ($data['rollout_percentage'] ?? 100);

        $target = $updates->target($data['target']);
        $file = $request->file('package');
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension !== $target['extension']) {
            throw ValidationException::withMessages([
                'package' => "The {$data['target']} package must be a .{$target['extension']} file.",
            ]);
        }

        if ($target['platform'] === 'android' && empty($data['build_number'])) {
            throw ValidationException::withMessages([
                'build_number' => 'Android releases require a version code/build number.',
            ]);
        }

        if (! empty($data['minimum_supported_version'])
            && version_compare($data['minimum_supported_version'], $data['version'], '>')) {
            throw ValidationException::withMessages([
                'minimum_supported_version' => 'The minimum supported version cannot be newer than this release.',
            ]);
        }

        if (AppRelease::where([
            'app' => $target['app'],
            'platform' => $target['platform'],
            'version' => $data['version'],
        ])->exists()) {
            throw ValidationException::withMessages([
                'version' => 'That version already exists for this application target.',
            ]);
        }

        $publishedRelease = $updates->latest($target['app'], $target['platform'], $data['channel']);
        if ($request->boolean('publish')
            && $publishedRelease
            && version_compare($data['version'], $publishedRelease->version, '<')) {
            throw ValidationException::withMessages([
                'version' => "Version {$data['version']} is older than the published {$publishedRelease->version} release.",
            ]);
        }

        if ($target['platform'] === 'android') {
            $highestBuild = AppRelease::where([
                'app' => $target['app'],
                'platform' => $target['platform'],
            ])->max('build_number');

            if ($highestBuild !== null && (int) $data['build_number'] <= $highestBuild) {
                throw ValidationException::withMessages([
                    'build_number' => "The Android build number must be higher than $highestBuild.",
                ]);
            }
        }

        $inspection = $inspector->inspect(
            $file,
            $data['target'],
            $data['version'],
            isset($data['build_number']) ? (int) $data['build_number'] : null,
        );

        if ($target['platform'] === 'android') {
            $knownCertificate = AppRelease::where([
                'app' => $target['app'],
                'platform' => $target['platform'],
            ])->whereNotNull('signing_certificate_sha256')->value('signing_certificate_sha256');

            if ($knownCertificate
                && ! hash_equals($knownCertificate, $inspection['signing_certificate_sha256'])) {
                throw ValidationException::withMessages([
                    'package' => 'This APK uses a different signing certificate from earlier releases for this app.',
                ]);
            }
        }

        $storedName = Str::uuid().'.'.$extension;
        $directory = "{$data['target']}/{$data['version']}";
        $path = Storage::disk('updates')->putFileAs($directory, $file, $storedName);

        try {
            $release = DB::transaction(function () use ($request, $data, $target, $file, $path, $inspection) {
                if ($request->boolean('publish')) {
                    AppRelease::where([
                        'app' => $target['app'],
                        'platform' => $target['platform'],
                        'channel' => $data['channel'],
                    ])->update(['is_published' => false]);
                }

                return AppRelease::create([
                    'app' => $target['app'],
                    'platform' => $target['platform'],
                    'channel' => $data['channel'],
                    'rollout_percentage' => $data['rollout_percentage'],
                    'version' => $data['version'],
                    'build_number' => $data['build_number'] ?? null,
                    'release_notes' => $data['release_notes'] ?? null,
                    'mandatory' => $request->boolean('mandatory'),
                    'minimum_supported_version' => $data['minimum_supported_version'] ?? null,
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    ...$inspection,
                    'is_published' => $request->boolean('publish'),
                    'published_at' => $request->boolean('publish') ? now() : null,
                    'uploaded_by' => $request->user()->id,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('updates')->delete($path);
            throw $exception;
        }

        $audit->record($request, 'app_release.uploaded', $release, null, $release->toArray());

        return back()->with('status', $release->is_published
            ? 'Application update uploaded and published.'
            : 'Application update uploaded as a draft.');
    }

    public function publish(
        Request $request,
        AppRelease $release,
        AuditService $audit,
        AppUpdateService $updates,
    ) {
        abort_unless(Storage::disk('updates')->exists($release->file_path), 422, 'The package file is missing.');
        abort_unless($release->verified_at, 422, 'This package has not passed release verification.');
        $publishedRelease = $updates->latest($release->app, $release->platform, $release->channel);
        abort_if(
            $publishedRelease
                && ! $publishedRelease->is($release)
                && version_compare($release->version, $publishedRelease->version, '<'),
            422,
            "Version {$release->version} is older than the published {$publishedRelease->version} release.",
        );
        $old = $release->toArray();

        DB::transaction(function () use ($release) {
            AppRelease::where('app', $release->app)
                ->where('platform', $release->platform)
                ->where('channel', $release->channel)
                ->whereKeyNot($release->id)
                ->update(['is_published' => false]);
            $release->update(['is_published' => true, 'published_at' => now()]);
        });

        $audit->record($request, 'app_release.published', $release, $old, $release->fresh()->toArray());

        return back()->with('status', "{$release->version} is now published.");
    }

    public function unpublish(Request $request, AppRelease $release, AuditService $audit)
    {
        $old = $release->toArray();
        $release->update(['is_published' => false]);
        $audit->record($request, 'app_release.unpublished', $release, $old, $release->fresh()->toArray());

        return back()->with('status', "{$release->version} has been unpublished.");
    }
}
