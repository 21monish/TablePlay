[CmdletBinding()]
param(
    [string] $PhpSource = 'C:\xampp\php',
    [string] $MariaDbSource = 'C:\xampp\mysql',
    [string] $VcRedistSource = '',
    [string] $LicensePublicKeyFile = '',
    [string] $RuntimeIdentifier = 'win-x64',
    [string] $RuntimeFrameworkVersion = '9.0.19',
    [string] $Version = '2.5.6',
    [switch] $NoRestore
)

$ErrorActionPreference = 'Stop'
$installerRoot = (Resolve-Path -LiteralPath $PSScriptRoot).Path
$VcRedistSource = if ([string]::IsNullOrWhiteSpace($VcRedistSource)) {
    Join-Path $installerRoot 'prerequisites\vc_redist.x64.exe'
} else {
    $VcRedistSource
}
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $installerRoot '..')).Path
$LicensePublicKeyFile = if ([string]::IsNullOrWhiteSpace($LicensePublicKeyFile)) {
    Join-Path $installerRoot 'license-public-key.json'
} else {
    $LicensePublicKeyFile
}
$buildRoot = Join-Path $installerRoot 'build'
$payloadRoot = Join-Path $buildRoot 'payload'
$publishRoot = Join-Path $buildRoot 'publish'
$outputRoot = Join-Path $projectRoot 'dist\installer'
$payloadZip = Join-Path $buildRoot 'TablePlay-payload.zip'

function Assert-ChildPath {
    param([string] $Path, [string] $Parent)
    $parentFull = [IO.Path]::GetFullPath($Parent).TrimEnd('\') + '\'
    $pathFull = [IO.Path]::GetFullPath($Path)
    if (-not $pathFull.StartsWith($parentFull, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Unsafe build path outside $Parent`: $Path"
    }
}

function Reset-BuildDirectory {
    param([string] $Path)
    Assert-ChildPath -Path $Path -Parent $installerRoot
    if (Test-Path -LiteralPath $Path) {
        Remove-Item -LiteralPath $Path -Recurse -Force
    }
    New-Item -ItemType Directory -Path $Path -Force | Out-Null
}

function Copy-Directory {
    param([string] $Source, [string] $Destination)
    if (-not (Test-Path -LiteralPath $Source -PathType Container)) {
        throw "Required source directory is missing: $Source"
    }
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    foreach ($item in Get-ChildItem -LiteralPath $Source -Force) {
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            Write-Host "Skipping reparse point: $($item.FullName)"
            continue
        }
        $target = Join-Path $Destination $item.Name
        if ($item.PSIsContainer) {
            Copy-Directory -Source $item.FullName -Destination $target
        } else {
            Copy-Item -LiteralPath $item.FullName -Destination $target -Force
        }
    }
}

function Invoke-DotnetPublish {
    param([string] $Project, [string] $Output)
    $parsedVersion = [Version]::Parse($Version)
    $fileVersion = '{0}.{1}.{2}.0' -f $parsedVersion.Major, $parsedVersion.Minor, $parsedVersion.Build
    $arguments = @(
        'publish', $Project,
        '--configuration', 'Release',
        '--runtime', $RuntimeIdentifier,
        '--self-contained', 'true',
        "-p:RuntimeFrameworkVersion=$RuntimeFrameworkVersion",
        "-p:Version=$Version",
        "-p:AssemblyVersion=$fileVersion",
        "-p:FileVersion=$fileVersion",
        '-p:PublishSingleFile=true',
        '-p:IncludeNativeLibrariesForSelfExtract=true',
        '-p:DebugType=None',
        '-p:DebugSymbols=false',
        '--output', $Output
    )
    if ($NoRestore) {
        $arguments += '--no-restore'
    }
    & dotnet @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "dotnet publish failed for $Project"
    }
}

function Read-LicenseTrust {
    param([string] $Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "The TablePlay Ed25519 public-key file is missing: $Path. Generate the Cloud keypair securely, export only its public metadata, and pass -LicensePublicKeyFile."
    }

    $raw = Get-Content -LiteralPath $Path -Raw
    $document = $raw | ConvertFrom-Json
    Assert-NoLicenseSecrets -Value $document

    $algorithm = [string] $document.algorithm
    $keyId = [string] $document.key_id
    $encodedPublicKey = [string] $document.public_key
    if ($algorithm -cne 'Ed25519') {
        throw 'The installer public-key file must use algorithm Ed25519.'
    }
    if ($keyId -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$') {
        throw 'The installer public-key key_id is missing or invalid.'
    }

    try {
        $publicKeyBytes = [Convert]::FromBase64String($encodedPublicKey)
    } catch {
        throw 'The installer Ed25519 public key is not valid Base64.'
    }
    if ($publicKeyBytes.Length -ne 32) {
        throw "The installer Ed25519 public key must decode to exactly 32 bytes; received $($publicKeyBytes.Length)."
    }

    $normalizedPublicKey = [Convert]::ToBase64String($publicKeyBytes)
    $sha256 = [Security.Cryptography.SHA256]::Create()
    try {
        $fingerprintBytes = $sha256.ComputeHash($publicKeyBytes)
    } finally {
        $sha256.Dispose()
    }
    $fingerprint = -join ($fingerprintBytes | ForEach-Object { $_.ToString('x2') })
    if ($document.public_key_sha256 -and -not ([string] $document.public_key_sha256).Equals($fingerprint, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The installer public-key fingerprint does not match the supplied Ed25519 public key.'
    }

    $trustedPublicKeys = [ordered]@{}
    $trustedProperty = $document.PSObject.Properties['trusted_public_keys']
    if ($null -ne $trustedProperty -and $null -ne $trustedProperty.Value) {
        if ($trustedProperty.Value -isnot [PSCustomObject]) {
            throw 'The installer trusted_public_keys value must be a JSON object keyed by license key ID.'
        }
        $trustedProperties = @($trustedProperty.Value.PSObject.Properties)
        if ($trustedProperties.Count -gt 64) {
            throw 'The installer public-key file cannot contain more than 64 trusted Ed25519 keys.'
        }
        foreach ($property in ($trustedProperties | Sort-Object Name)) {
            $trustedKeyId = [string] $property.Name
            if ($trustedKeyId -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$') {
                throw "The trusted Ed25519 key ID '$trustedKeyId' is invalid."
            }
            try {
                $trustedBytes = [Convert]::FromBase64String([string] $property.Value)
            } catch {
                throw "The trusted Ed25519 public key '$trustedKeyId' is not valid Base64."
            }
            if ($trustedBytes.Length -ne 32) {
                throw "The trusted Ed25519 public key '$trustedKeyId' must decode to exactly 32 bytes; received $($trustedBytes.Length)."
            }
            $trustedPublicKeys[$trustedKeyId] = [Convert]::ToBase64String($trustedBytes)
        }
    }
    if ($trustedPublicKeys.Contains($keyId) -and -not ([string] $trustedPublicKeys[$keyId]).Equals($normalizedPublicKey, [StringComparison]::Ordinal)) {
        throw 'The current installer public key conflicts with the same key ID in trusted_public_keys.'
    }
    if (-not $trustedPublicKeys.Contains($keyId) -and $trustedPublicKeys.Count -ge 64) {
        throw 'The installer public-key file cannot add its current key because trusted_public_keys already contains 64 historical keys.'
    }
    $trustedPublicKeys[$keyId] = $normalizedPublicKey

    return [ordered]@{
        algorithm = 'Ed25519'
        key_id = $keyId
        public_key = $normalizedPublicKey
        public_key_sha256 = $fingerprint
        trusted_public_keys = $trustedPublicKeys
    }
}

function Assert-NoLicenseSecrets {
    param(
        [Parameter(Mandatory = $false)] $Value,
        [string] $Path = 'root'
    )

    if ($null -eq $Value -or $Value -is [string] -or $Value.GetType().IsPrimitive) { return }
    if ($Value -is [System.Collections.IDictionary]) {
        foreach ($key in $Value.Keys) {
            $name = [string] $key
            if ($name -match '(?i)private|secret') {
                throw "The public-key file contains a forbidden private/secret property at $Path.$name. Never pass a Cloud private key to the installer build."
            }
            Assert-NoLicenseSecrets -Value $Value[$key] -Path "$Path.$name"
        }
        return
    }
    if ($Value -is [PSCustomObject]) {
        foreach ($property in $Value.PSObject.Properties) {
            if ($property.Name -match '(?i)private|secret') {
                throw "The public-key file contains a forbidden private/secret property at $Path.$($property.Name). Never pass a Cloud private key to the installer build."
            }
            Assert-NoLicenseSecrets -Value $property.Value -Path "$Path.$($property.Name)"
        }
        return
    }
    if ($Value -is [System.Collections.IEnumerable]) {
        foreach ($item in $Value) { Assert-NoLicenseSecrets -Value $item -Path $Path }
    }
}

$licenseTrust = Read-LicenseTrust -Path $LicensePublicKeyFile

Write-Host 'Preparing clean TablePlay installer build directories...'
Reset-BuildDirectory -Path $buildRoot
New-Item -ItemType Directory -Path $payloadRoot,$publishRoot,$outputRoot -Force | Out-Null

Write-Host 'Publishing native Windows components...'
$servicePublish = Join-Path $publishRoot 'service'
$managerPublish = Join-Path $publishRoot 'manager'
$uninstallerPublish = Join-Path $publishRoot 'uninstaller'
$setupPublish = Join-Path $publishRoot 'setup'
Invoke-DotnetPublish -Project (Join-Path $installerRoot 'src\TablePlay.ServiceHost\TablePlay.ServiceHost.csproj') -Output $servicePublish
Invoke-DotnetPublish -Project (Join-Path $installerRoot 'src\TablePlay.ServerManager\TablePlay.ServerManager.csproj') -Output $managerPublish
Invoke-DotnetPublish -Project (Join-Path $installerRoot 'src\TablePlay.Uninstaller\TablePlay.Uninstaller.csproj') -Output $uninstallerPublish
Invoke-DotnetPublish -Project (Join-Path $installerRoot 'src\TablePlay.Setup\TablePlay.Setup.csproj') -Output $setupPublish

Write-Host 'Copying the sanitized Laravel production server...'
$serverRoot = Join-Path $payloadRoot 'server'
New-Item -ItemType Directory -Path $serverRoot -Force | Out-Null
foreach ($directory in @('app','config','public','resources','routes','vendor')) {
    Copy-Directory -Source (Join-Path $projectRoot $directory) -Destination (Join-Path $serverRoot $directory)
}

# Never package the development machine's public storage junction or its files.
# Setup creates a fresh link to the restaurant installation's private storage.
$stagedPublicStorage = Join-Path $serverRoot 'public\storage'
if (Test-Path -LiteralPath $stagedPublicStorage) {
    Remove-Item -LiteralPath $stagedPublicStorage -Recurse -Force
}

New-Item -ItemType Directory -Path (Join-Path $serverRoot 'bootstrap') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $projectRoot 'bootstrap\app.php') -Destination (Join-Path $serverRoot 'bootstrap\app.php') -Force
Copy-Item -LiteralPath (Join-Path $projectRoot 'bootstrap\providers.php') -Destination (Join-Path $serverRoot 'bootstrap\providers.php') -Force
New-Item -ItemType Directory -Path (Join-Path $serverRoot 'bootstrap\cache') -Force | Out-Null

New-Item -ItemType Directory -Path (Join-Path $serverRoot 'database') -Force | Out-Null
foreach ($directory in @('factories','migrations','seeders')) {
    Copy-Directory -Source (Join-Path $projectRoot "database\$directory") -Destination (Join-Path $serverRoot "database\$directory")
}

foreach ($file in @('artisan','composer.json','composer.lock')) {
    Copy-Item -LiteralPath (Join-Path $projectRoot $file) -Destination (Join-Path $serverRoot $file) -Force
}

$storageDirectories = @(
    'app\private',
    'app\public',
    'app\updates',
    'framework\cache\data',
    'framework\sessions',
    'framework\testing',
    'framework\views',
    'logs'
)
foreach ($directory in $storageDirectories) {
    New-Item -ItemType Directory -Path (Join-Path $serverRoot "storage\$directory") -Force | Out-Null
}

$commandDirectory = Join-Path $serverRoot 'app\Console\Commands'
New-Item -ItemType Directory -Path $commandDirectory -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $installerRoot 'laravel\BootstrapInstallation.php') -Destination (Join-Path $commandDirectory 'BootstrapInstallation.php') -Force

Write-Host 'Copying portable PHP and MariaDB runtimes...'
Copy-Directory -Source $PhpSource -Destination (Join-Path $payloadRoot 'php')
$stagedPhpIni = Join-Path $payloadRoot 'php\php.ini'
if (Test-Path -LiteralPath $stagedPhpIni) {
    Remove-Item -LiteralPath $stagedPhpIni -Force
}

# PHP for Windows requires the Microsoft Visual C++ x64 runtime. Bundle the
# official redistributable so a clean/offline restaurant PC is installable.
if (-not (Test-Path -LiteralPath $VcRedistSource -PathType Leaf)) {
    throw "Microsoft Visual C++ x64 Redistributable is missing: $VcRedistSource. Download the official vc_redist.x64.exe into installer\prerequisites before building."
}
$prerequisitesRoot = Join-Path $payloadRoot 'prerequisites'
New-Item -ItemType Directory -Path $prerequisitesRoot -Force | Out-Null
Copy-Item -LiteralPath $VcRedistSource -Destination (Join-Path $prerequisitesRoot 'vc_redist.x64.exe') -Force

$databaseRoot = Join-Path $payloadRoot 'database'
New-Item -ItemType Directory -Path $databaseRoot -Force | Out-Null
Copy-Directory -Source (Join-Path $MariaDbSource 'bin') -Destination (Join-Path $databaseRoot 'bin')
Copy-Directory -Source (Join-Path $MariaDbSource 'share') -Destination (Join-Path $databaseRoot 'share')
foreach ($license in @('COPYING','CREDITS','README.md','THIRDPARTY')) {
    $source = Join-Path $MariaDbSource $license
    if (Test-Path -LiteralPath $source) {
        Copy-Item -LiteralPath $source -Destination (Join-Path $databaseRoot $license) -Force
    }
}

Write-Host 'Adding TablePlay services, manager, uninstaller and signed app releases...'
New-Item -ItemType Directory -Path (Join-Path $payloadRoot 'services'),(Join-Path $payloadRoot 'server-manager'),(Join-Path $payloadRoot 'uninstaller'),(Join-Path $payloadRoot 'packages') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $servicePublish 'TablePlay.ServiceHost.exe') -Destination (Join-Path $payloadRoot 'services\TablePlay.ServiceHost.exe') -Force
Copy-Item -LiteralPath (Join-Path $managerPublish 'TablePlay.ServerManager.exe') -Destination (Join-Path $payloadRoot 'server-manager\TablePlay.ServerManager.exe') -Force
Copy-Item -LiteralPath (Join-Path $uninstallerPublish 'TablePlay.Uninstaller.exe') -Destination (Join-Path $payloadRoot 'uninstaller\TablePlay.Uninstaller.exe') -Force

$releaseDefinitions = @(
    [ordered]@{
        app = 'staff'; platform = 'android'; version = '1.9.2'; build_number = 2014
        source = 'dist\TablePlay-Staff-Android-arm64-v1.9.2-release.apk'; payload_file = 'staff-android.apk'
        storage_file = 'tableplay-staff-android-1.9.2.apk'; original_filename = 'TablePlay-Staff-Android-arm64-v1.9.2-release.apk'
        mime_type = 'application/vnd.android.package-archive'; package_identifier = 'com.tableplay.tableplay_staff'
        signing_certificate_sha256 = '4adba0c171bb6a0ec83246d28a2c37a911efe4ee9921d097d3825d7e9c061a0e'
    },
    [ordered]@{
        app = 'customer'; platform = 'android'; version = '1.12.1'; build_number = 2015
        source = 'dist\TablePlay-Customer-Android-v1.12.1-release.apk'; payload_file = 'customer-android.apk'
        storage_file = 'tableplay-customer-android-1.12.1.apk'; original_filename = 'TablePlay-Customer-Android-v1.12.1-release.apk'
        mime_type = 'application/vnd.android.package-archive'; package_identifier = 'com.tableplay.tableplay_tablet'
        signing_certificate_sha256 = '4adba0c171bb6a0ec83246d28a2c37a911efe4ee9921d097d3825d7e9c061a0e'
    },
    [ordered]@{
        app = 'staff'; platform = 'windows'; version = '1.9.2'; build_number = 14
        source = 'dist\TablePlay-Staff-Windows-x64-v1.9.2.zip'; payload_file = 'staff-windows.zip'
        storage_file = 'tableplay-staff-windows-1.9.2.zip'; original_filename = 'TablePlay-Staff-Windows-x64-v1.9.2.zip'
        mime_type = 'application/zip'; package_identifier = $null; signing_certificate_sha256 = $null
    }
)

foreach ($release in $releaseDefinitions) {
    $source = Join-Path $projectRoot $release.source
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Required release file is missing: $source"
    }
    Copy-Item -LiteralPath $source -Destination (Join-Path $payloadRoot "packages\$($release.payload_file)") -Force
    $release.Remove('source')
}

$criticalPayloadFiles = @(
    'server\artisan',
    'server\composer.lock',
    'php\php.exe',
    'database\bin\mysqld.exe',
    'database\bin\mysql.exe',
    'database\bin\mysqldump.exe',
    'services\TablePlay.ServiceHost.exe',
    'server-manager\TablePlay.ServerManager.exe',
    'uninstaller\TablePlay.Uninstaller.exe',
    'prerequisites\vc_redist.x64.exe',
    'packages\staff-android.apk',
    'packages\customer-android.apk',
    'packages\staff-windows.zip'
)
$payloadFiles = foreach ($relativePath in $criticalPayloadFiles) {
    $absolutePath = Join-Path $payloadRoot $relativePath
    if (-not (Test-Path -LiteralPath $absolutePath -PathType Leaf)) { throw "Critical payload file is missing: $relativePath" }
    $item = Get-Item -LiteralPath $absolutePath
    [ordered]@{
        relative_path = $relativePath.Replace('\', '/')
        length = $item.Length
        sha256 = (Get-FileHash -LiteralPath $absolutePath -Algorithm SHA256).Hash.ToLowerInvariant()
    }
}

$manifest = [ordered]@{
    product = 'TablePlay'
    installer_version = $Version
    built_at = [DateTimeOffset]::Now.ToString('o')
    architecture = $RuntimeIdentifier
    licensing = $licenseTrust
    releases = $releaseDefinitions
    payload_files = $payloadFiles
}
$manifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $payloadRoot 'payload-manifest.json') -Encoding utf8

Write-Host 'Compressing the self-contained installer payload...'
if (Test-Path -LiteralPath $payloadZip) {
    Remove-Item -LiteralPath $payloadZip -Force
}
Compress-Archive -Path (Join-Path $payloadRoot '*') -DestinationPath $payloadZip -CompressionLevel Optimal

Write-Host 'Creating the single TablePlay-Setup executable...'
$setupHost = Join-Path $setupPublish 'TablePlay.SetupHost.exe'
$finalSetup = Join-Path $outputRoot "TablePlay-Setup-v$Version.exe"
if (Test-Path -LiteralPath $finalSetup) {
    Remove-Item -LiteralPath $finalSetup -Force
}

$output = [IO.File]::Create($finalSetup)
try {
    $hostStream = [IO.File]::OpenRead($setupHost)
    try { $hostStream.CopyTo($output) } finally { $hostStream.Dispose() }
    $payloadStream = [IO.File]::OpenRead($payloadZip)
    try {
        $payloadLength = $payloadStream.Length
        $payloadStream.CopyTo($output)
    } finally { $payloadStream.Dispose() }
    $writer = [IO.BinaryWriter]::new($output, [Text.Encoding]::UTF8, $true)
    try {
        $writer.Write([long]$payloadLength)
        $writer.Write([Text.Encoding]::ASCII.GetBytes('TABLEPLAY_PAYLOAD_V1'))
    } finally { $writer.Dispose() }
} finally {
    $output.Dispose()
}

$hash = Get-FileHash -LiteralPath $finalSetup -Algorithm SHA256
$hash.Hash.ToLowerInvariant() | Set-Content -LiteralPath "$finalSetup.sha256" -Encoding ascii

Write-Host ''
Write-Host 'TablePlay installer build complete.' -ForegroundColor Green
Write-Host "Installer: $finalSetup"
Write-Host "Size:      $([math]::Round((Get-Item -LiteralPath $finalSetup).Length / 1MB, 1)) MB"
Write-Host "SHA-256:   $($hash.Hash)"
Write-Host 'The installer is not Authenticode-signed unless your release pipeline signs the final EXE after this script completes.' -ForegroundColor Yellow
