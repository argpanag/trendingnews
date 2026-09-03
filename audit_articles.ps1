<#
.SYNOPSIS
    Audit all article HTML files for template consistency.
.DESCRIPTION
    Checks each article for:
    - lang="el" attribute
    - ← Πίσω back link (not ← Back)
    - <footer> section
    - <nav> with category/country badges
    - Proper HTML formatting (not minified)
    - JSON-LD structured data
    - Canonical URL
    - OG meta tags
.NOTES
    Usage: powershell -ExecutionPolicy Bypass -File audit_articles.ps1
           powershell -ExecutionPolicy Bypass -File audit_articles.ps1 -Verbose
#>
param(
    [switch]$Verbose
)

$ErrorActionPreference = "Continue"
$articlesDir = Join-Path $PSScriptRoot "articles"

if (-not (Test-Path $articlesDir)) {
    Write-Host "ERROR: articles directory not found at $articlesDir" -ForegroundColor Red
    exit 1
}

$articleDirs = Get-ChildItem -Path $articlesDir -Directory
$total = $articleDirs.Count
$passed = 0
$failed = 0
$warnings = @()
$errors = @()

Write-Host "=== Article Template Audit ===" -ForegroundColor Cyan
Write-Host "Scanning $total articles...`n"

foreach ($dir in $articleDirs) {
    $slug = $dir.Name
    $htmlPath = Join-Path $dir.FullName "index.html"
    $articleIssues = @()
    $articleWarnings = @()

    if (-not (Test-Path $htmlPath)) {
        $errors += [PSCustomObject]@{ Slug = $slug; Issue = "Missing index.html" }
        Write-Host "  [FAIL] $slug - Missing index.html" -ForegroundColor Red
        $failed++
        continue
    }

    $html = Get-Content -Path $htmlPath -Raw -Encoding UTF8

    # Check lang="el"
    if ($html -notmatch 'lang="el"') {
        $articleIssues += "lang=""el"" missing or incorrect"
    }

    # Check ← Back link
    if ($html -notmatch '← Back') {
        $articleIssues += "Missing English back link (← Back)"
    }

    # Check for Greek ← Back
    if ($html -match '← Πίσω') {
        $articleIssues += "Greek '← Πίσω' found (should be '← Back')"
    }

    # Check <footer> section
    if ($html -notmatch '<footer') {
        $articleIssues += "Missing <footer> section"
    }

    # Check <nav> with badges
    if ($html -notmatch '<nav') {
        $articleIssues += "Missing <nav> with badges"
    }

    # Check JSON-LD structured data
    if ($html -notmatch 'application/ld\+json') {
        $articleIssues += "Missing JSON-LD structured data"
    }

    # Check canonical URL
    if ($html -notmatch 'rel="canonical"') {
        $articleIssues += "Missing canonical URL"
    }

    # Check OG meta tags
    if ($html -notmatch 'og:title') {
        $articleIssues += "Missing og:title meta tag"
    }

    if ($html -notmatch 'og:image') {
        $articleWarnings += "Missing og:image meta tag"
    }

    # Check article:published_time
    if ($html -notmatch 'article:published_time') {
        $articleWarnings += "Missing article:published_time meta tag"
    }

    # Check HTML formatting (not minified)
    $lineCount = ($html -split "`n").Count
    if ($lineCount -lt 10) {
        $articleIssues += "Minified/single-line HTML ($lineCount lines)"
    }

    # Check stylesheet link
    if ($html -notmatch 'css/style\.css') {
        $articleWarnings += "Missing CSS stylesheet link"
    }

    # Report
    if ($articleIssues.Count -eq 0 -and $articleWarnings.Count -eq 0) {
        Write-Host "  [PASS] $slug" -ForegroundColor Green
        $passed++
    } else {
        $failed++
        if ($articleIssues.Count -gt 0) {
            Write-Host "  [FAIL] $slug" -ForegroundColor Red
            foreach ($issue in $articleIssues) {
                Write-Host "         - $issue" -ForegroundColor Red
                $errors += [PSCustomObject]@{ Slug = $slug; Issue = $issue }
            }
        }
        if ($articleWarnings.Count -gt 0 -and $Verbose) {
            foreach ($warn in $articleWarnings) {
                Write-Host "         [WARN] $warn" -ForegroundColor Yellow
                $warnings += [PSCustomObject]@{ Slug = $slug; Issue = $warn }
            }
        }
    }
}

# Summary
Write-Host "`n=== Summary ===" -ForegroundColor Cyan
Write-Host "Total articles:  $total"
Write-Host "Passed:          $passed" -ForegroundColor Green
Write-Host "Failed:          $failed" -ForegroundColor $(if ($failed -gt 0) { "Red" } else { "Green" })
Write-Host "Warnings:        $($warnings.Count)" -ForegroundColor $(if ($warnings.Count -gt 0) { "Yellow" } else { "Green" })

if ($errors.Count -gt 0) {
    Write-Host "`n=== Errors ===" -ForegroundColor Red
    $errors | Format-Table -AutoSize
}

if ($Verbose -and $warnings.Count -gt 0) {
    Write-Host "`n=== Warnings ===" -ForegroundColor Yellow
    $warnings | Format-Table -AutoSize
}

if ($failed -eq 0) {
    Write-Host "`nAll articles have consistent template structure." -ForegroundColor Green
} else {
    Write-Host "`n$failed article(s) have template issues. Run fix_articles.php --apply to fix." -ForegroundColor Yellow
}

exit $(if ($failed -gt 0) { 1 } else { 0 })
