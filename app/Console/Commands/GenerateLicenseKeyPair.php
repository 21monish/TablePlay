<?php

namespace App\Console\Commands;

use App\Services\LicenseSignatureService;
use Illuminate\Console\Command;
use RuntimeException;

class GenerateLicenseKeyPair extends Command
{
    protected $signature = 'tableplay:license-keypair
        {--install : Securely install the new Cloud keypair into this application environment}
        {--force : Replace an existing keypair and public metadata file}
        {--key-id= : Stable identifier for this signing key (for example tableplay-market-v1)}
        {--public-output=installer/license-public-key.json : Public-only JSON file used by the restaurant installer build}';

    protected $description = 'Generate and securely install an Ed25519 Cloud signing keypair without displaying the private key.';

    public function handle(LicenseSignatureService $signatures): int
    {
        if (! $this->option('install')) {
            $this->error('No keypair was generated. Re-run with --install; use --force only for an intentional key rotation.');

            return self::FAILURE;
        }
        if (! extension_loaded('sodium')) {
            $this->error('The Sodium PHP extension is required before a TablePlay Ed25519 keypair can be generated.');

            return self::FAILURE;
        }

        $keyId = trim((string) ($this->option('key-id') ?: config('tableplay.license_key_id', 'tableplay-market-v1')));
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/', $keyId)) {
            $this->error('The key ID must start with a letter or number and contain only letters, numbers, dots, underscores or hyphens.');

            return self::FAILURE;
        }

        $environmentPath = base_path('.env');
        if (! is_file($environmentPath) || ! is_readable($environmentPath) || ! is_writable($environmentPath)) {
            $this->error('The application .env file must exist and be readable and writable. No keypair was generated.');

            return self::FAILURE;
        }
        $existingPublic = (string) config('tableplay.license_public_key', '');
        $existingPrivate = (string) config('tableplay.license_private_key', '');
        if (($existingPublic !== '' || $existingPrivate !== '') && ! $this->option('force')) {
            $this->error('A TablePlay signing key is already configured. Use tableplay:license-check to inspect it, or --force for an intentional rotation.');

            return self::FAILURE;
        }

        $publicOutput = $this->resolvePublicOutputPath((string) $this->option('public-output'));
        if (is_file($publicOutput) && ! $this->option('force')) {
            $this->error("The public metadata file already exists: {$publicOutput}. Use --force only for an intentional rotation.");

            return self::FAILURE;
        }

        $keys = $signatures->generateKeyPair();
        $publicBinary = base64_decode($keys['public_key'], true);
        $privateBinary = base64_decode($keys['private_key'], true);
        if (! is_string($publicBinary)
            || strlen($publicBinary) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! is_string($privateBinary)
            || strlen($privateBinary) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || ! hash_equals($publicBinary, sodium_crypto_sign_publickey_from_secretkey($privateBinary))) {
            if (is_string($privateBinary)) sodium_memzero($privateBinary);
            $this->error('The generated Ed25519 keypair failed validation. Nothing was installed.');

            return self::FAILURE;
        }
        $probe = random_bytes(32);
        $probeSignature = sodium_crypto_sign_detached($probe, $privateBinary);
        if (! sodium_crypto_sign_verify_detached($probeSignature, $probe, $publicBinary)) {
            sodium_memzero($privateBinary);
            $this->error('The generated Ed25519 keypair failed its signing self-test. Nothing was installed.');

            return self::FAILURE;
        }

        $fingerprint = hash('sha256', $publicBinary);
        $metadata = json_encode([
            'algorithm' => 'Ed25519',
            'key_id' => $keyId,
            'public_key' => $keys['public_key'],
            'public_key_sha256' => $fingerprint,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        try {
            $this->writePublicMetadata($publicOutput, $metadata);
            $this->installEnvironmentValues($environmentPath, [
                'TABLEPLAY_LICENSE_KEY_ID' => $keyId,
                'TABLEPLAY_LICENSE_PUBLIC_KEY' => $keys['public_key'],
                'TABLEPLAY_LICENSE_PRIVATE_KEY' => $keys['private_key'],
            ]);
            $this->callSilent('config:clear');
        } catch (\Throwable $error) {
            $this->error('The keypair could not be installed safely: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            sodium_memzero($privateBinary);
            $keys['private_key'] = '';
        }

        $this->info('The Ed25519 Cloud signing keypair was installed successfully.');
        $this->line('Key ID: '.$keyId);
        $this->line('Public-key fingerprint (SHA-256): '.$fingerprint);
        $this->line('Public installer metadata: '.$publicOutput);
        $this->warn('The private key was written only to the Cloud .env file and was not displayed or written to the installer metadata. Back up the Cloud .env securely.');

        return self::SUCCESS;
    }

    private function resolvePublicOutputPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') throw new RuntimeException('A public metadata output path is required.');
        if (! str_starts_with($path, '/') && ! preg_match('/\A[A-Za-z]:[\\\\\/]/', $path)) {
            $path = base_path($path);
        }

        return $path;
    }

    private function writePublicMetadata(string $path, string $metadata): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the public metadata directory: {$directory}");
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        try {
            if (file_put_contents($temporary, $metadata, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write the public metadata temporary file.');
            }
            @chmod($temporary, 0644);
            if (is_file($path) && ! unlink($path)) throw new RuntimeException('Cannot replace the existing public metadata file.');
            if (! rename($temporary, $path)) throw new RuntimeException('Cannot publish the public metadata file.');
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private function installEnvironmentValues(string $path, array $replacements): void
    {
        $content = file_get_contents($path);
        if ($content === false) throw new RuntimeException('Cannot read the application .env file.');
        $lineEnding = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $seen = [];
        $output = [];
        foreach ($lines as $line) {
            if (! preg_match('/\A\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $matches)) {
                $output[] = $line;
                continue;
            }
            $key = $matches[1];
            if (! array_key_exists($key, $replacements)) {
                $output[] = $line;
                continue;
            }
            if (! isset($seen[$key])) {
                $output[] = $key.'='.$replacements[$key];
                $seen[$key] = true;
            }
        }
        foreach ($replacements as $key => $value) {
            if (! isset($seen[$key])) $output[] = $key.'='.$value;
        }
        while ($output !== [] && end($output) === '') array_pop($output);
        $updated = implode($lineEnding, $output).$lineEnding;

        $temporary = $path.'.tableplay-keypair.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary, $updated, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the protected environment temporary file.');
        }
        @chmod($temporary, 0600);
        $backup = $path.'.tableplay-keypair-backup';
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                if (is_file($backup)) @unlink($backup);
                if (! rename($path, $backup)) throw new RuntimeException('Cannot prepare the existing environment for an atomic replacement.');
                if (! rename($temporary, $path)) {
                    @rename($backup, $path);
                    throw new RuntimeException('Cannot install the protected Cloud environment.');
                }
                @unlink($backup);
            } elseif (! rename($temporary, $path)) {
                throw new RuntimeException('Cannot atomically install the protected Cloud environment.');
            }
        } finally {
            if (is_file($temporary)) @unlink($temporary);
            if (! is_file($path) && is_file($backup)) @rename($backup, $path);
        }
    }
}
