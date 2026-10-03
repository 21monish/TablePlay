param(
    [ValidateRange(1, 65535)]
    [int] $Port = 8000
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

Push-Location $projectRoot
try {
    Write-Host "Starting TablePlay at http://0.0.0.0:$Port"
    Write-Host 'Uploads up to 256 MB are enabled for this TablePlay process only.'

    & php `
        -d upload_max_filesize=256M `
        -d post_max_size=260M `
        -d max_input_time=600 `
        -d max_execution_time=600 `
        artisan serve --host=0.0.0.0 --port=$Port

    if ($LASTEXITCODE -ne 0) {
        throw "TablePlay server stopped with exit code $LASTEXITCODE."
    }
}
finally {
    Pop-Location
}
