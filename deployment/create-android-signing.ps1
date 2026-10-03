param(
    [string] $KeyAlias = 'tableplay'
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$signingDirectory = Join-Path $PSScriptRoot 'signing'
$keystorePath = Join-Path $signingDirectory 'tableplay-release.p12'
$propertyFiles = @(
    (Join-Path $projectRoot 'staff_app\android\key.properties'),
    (Join-Path $projectRoot 'tablet_app\android\key.properties')
)

if (Test-Path -LiteralPath $keystorePath) {
    throw "A release keystore already exists at $keystorePath. It was not overwritten."
}

foreach ($propertyFile in $propertyFiles) {
    if (Test-Path -LiteralPath $propertyFile) {
        throw "Signing configuration already exists at $propertyFile. It was not overwritten."
    }
}

if (-not (Get-Command keytool -ErrorAction SilentlyContinue)) {
    throw 'Java keytool is unavailable. Install a JDK or add its bin directory to PATH.'
}

function ConvertFrom-SecureValue([Security.SecureString] $Value) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

function ConvertTo-JavaPropertyValue([string] $Value) {
    return $Value.Replace('\', '\\').Replace(':', '\:').Replace('=', '\=').Replace('#', '\#').Replace('!', '\!')
}

$firstSecure = Read-Host 'Create a private release-keystore password (12+ characters)' -AsSecureString
$secondSecure = Read-Host 'Enter the same password again' -AsSecureString
$password = ConvertFrom-SecureValue $firstSecure
$passwordAgain = ConvertFrom-SecureValue $secondSecure

try {
    if ($password -notmatch '^[\x21-\x7E]{12,}$') {
        throw 'Use at least 12 printable ASCII characters with no spaces for the release-keystore password.'
    }
    if ($password -cne $passwordAgain) {
        throw 'The two passwords do not match.'
    }

    New-Item -ItemType Directory -Path $signingDirectory -Force | Out-Null

    & keytool -genkeypair `
        -keystore $keystorePath `
        -storetype PKCS12 `
        -storepass $password `
        -keypass $password `
        -alias $KeyAlias `
        -keyalg RSA `
        -keysize 4096 `
        -validity 10000 `
        -dname 'CN=TablePlay, OU=Restaurant Apps, O=TablePlay, L=Local, ST=Local, C=IN'

    if ($LASTEXITCODE -ne 0) {
        throw "keytool failed with exit code $LASTEXITCODE."
    }

    $safeStorePath = ConvertTo-JavaPropertyValue ($keystorePath.Replace('\', '/'))
    $safePassword = ConvertTo-JavaPropertyValue $password
    $safeAlias = ConvertTo-JavaPropertyValue $KeyAlias
    $properties = @(
        "storeFile=$safeStorePath",
        "storePassword=$safePassword",
        "keyPassword=$safePassword",
        "keyAlias=$safeAlias"
    )

    foreach ($propertyFile in $propertyFiles) {
        [IO.File]::WriteAllLines(
            $propertyFile,
            $properties,
            [Text.UTF8Encoding]::new($false)
        )
    }

    Write-Host 'Android release signing is configured for both TablePlay apps.'
    Write-Host "Back up this file securely: $keystorePath"
    Write-Host 'Losing the keystore or its password prevents future in-place Android updates.'
}
catch {
    if (Test-Path -LiteralPath $keystorePath) {
        Remove-Item -LiteralPath $keystorePath -Force
    }
    foreach ($propertyFile in $propertyFiles) {
        if (Test-Path -LiteralPath $propertyFile) {
            Remove-Item -LiteralPath $propertyFile -Force
        }
    }
    throw
}
finally {
    $password = $null
    $passwordAgain = $null
}
