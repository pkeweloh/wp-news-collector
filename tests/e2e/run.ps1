# Runs every tests/e2e/test-*.php inside the docker WordPress (wpcli container)
# and fails if any of them does. The dev environment must be up:
#   docker compose -f docker/docker-compose.yml up -d
# Usage: .\tests\e2e\run.ps1 [name-fragment]

param([string] $Only = '')

$container = 'wp-news-collector-dev-wpcli-1'
$pluginDir = '/var/www/html/wp-content/plugins/wp-news-collector'
$tests     = Get-ChildItem (Join-Path $PSScriptRoot 'test-*.php') | Sort-Object Name
if ($Only) { $tests = $tests | Where-Object { $_.Name -like "*$Only*" } }
if (-not $tests) { Write-Error 'No e2e tests matched.'; exit 2 }

$failed = @()
foreach ($t in $tests) {
    Write-Host "== $($t.Name)"
    docker exec $container wp eval-file "$pluginDir/tests/e2e/$($t.Name)"
    if ($LASTEXITCODE -ne 0) { $failed += $t.Name }
    Write-Host ''
}

if ($failed) {
    Write-Host "FAILED: $($failed -join ', ')"
    exit 1
}
Write-Host "All e2e suites passed ($($tests.Count))."
exit 0
