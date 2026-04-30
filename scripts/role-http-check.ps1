param(
    [string]$BaseUrl = "http://127.0.0.1:8000"
)

$ErrorActionPreference = "Stop"

$pages = @(
    "/owner",
    "/stock",
    "/cuisine",
    "/ventes",
    "/rapport"
)

$accounts = @(
    @{ email = "superadmin@badiboss.test"; expected_redirect = "/super-admin"; role = "super_admin"; restaurant = $null },
    @{ email = "owner-gombe@badiboss.test"; expected_redirect = "/owner"; role = "owner"; restaurant = "Badi Saveurs Gombe" },
    @{ email = "manager-gombe@badiboss.test"; expected_redirect = "/owner"; role = "manager"; restaurant = "Badi Saveurs Gombe" },
    @{ email = "stock-gombe@badiboss.test"; expected_redirect = "/stock"; role = "stock_manager"; restaurant = "Badi Saveurs Gombe" },
    @{ email = "kitchen-gombe@badiboss.test"; expected_redirect = "/cuisine"; role = "kitchen"; restaurant = "Badi Saveurs Gombe" },
    @{ email = "server-gombe@badiboss.test"; expected_redirect = "/ventes"; role = "cashier_server"; restaurant = "Badi Saveurs Gombe" }
)

function Invoke-Curl {
    param(
        [string[]]$Arguments
    )

    $output = & curl.exe @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "curl a échoué: $($Arguments -join ' ')"
    }

    return [string]$output
}

function New-CookieFile {
    $path = Join-Path $PSScriptRoot ("role-check-" + [guid]::NewGuid().ToString("N") + ".txt")
    New-Item -ItemType File -Path $path -Force | Out-Null
    return $path
}

function Parse-Headers {
    param([string]$RawResponse)

    $lines = $RawResponse -split "`r?`n"
    $statusLine = $lines | Select-Object -First 1
    $headers = @{}

    foreach ($line in ($lines | Select-Object -Skip 1)) {
        if ($line -eq "") {
            break
        }

        $parts = $line -split ":\s*", 2
        if ($parts.Count -eq 2) {
            $headers[$parts[0]] = $parts[1]
        }
    }

    return @{
        status_line = $statusLine
        headers = $headers
    }
}

$results = @()

foreach ($account in $accounts) {
    $cookieFile = New-CookieFile

    try {
        Invoke-Curl @("-s", "-c", $cookieFile, "-b", $cookieFile, "$BaseUrl/login") | Out-Null

        $loginResponse = Invoke-Curl @(
            "-s",
            "-i",
            "-c", $cookieFile,
            "-b", $cookieFile,
            "-X", "POST",
            "-d", "email=$($account.email)&password=password",
            "$BaseUrl/login"
        )
        $parsedLogin = Parse-Headers -RawResponse $loginResponse

        $pageResults = @()
        foreach ($page in $pages) {
            $body = Invoke-Curl @("-s", "-L", "-c", $cookieFile, "-b", $cookieFile, "$BaseUrl$page")
            $status = Invoke-Curl @("-s", "-o", "NUL", "-w", "%{http_code}", "-L", "-c", $cookieFile, "-b", $cookieFile, "$BaseUrl$page")

            $pageResults += [pscustomobject]@{
                page = $page
                status = [int]$status
                contains_restaurant = if ($account.restaurant) { $body -match [regex]::Escape($account.restaurant) } else { $false }
                contains_login_form = $body -match 'name="email"' -and $body -match 'name="password"'
                contains_forbidden = $body -match '403 Forbidden'
            }
        }

        $results += [pscustomobject]@{
            email = $account.email
            role = $account.role
            expected_redirect = $account.expected_redirect
            login_status = if ($parsedLogin.status_line -match 'HTTP/\d\.\d\s+(\d+)') { [int]$matches[1] } else { 0 }
            login_location = $parsedLogin.headers['Location']
            pages = $pageResults
        }
    } finally {
        Remove-Item -LiteralPath $cookieFile -Force -ErrorAction SilentlyContinue
    }
}

$results | ConvertTo-Json -Depth 6
