<#
 * MensaManager - Digitale Schulverpflegung
 * Copyright (C) 2026 Lukas Trausch
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see [https://www.gnu.org/licenses/](https://www.gnu.org/licenses/).
#>

[CmdletBinding()]
param(
    [string]$OutputDir = "release",
    [string]$ReleaseLabel = "",
    [string]$PackagePrefix = "mensamanager",
    [switch]$SkipBuild,
    [switch]$InstallDependencies,
    [switch]$SkipOuterZip
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Write-Step {
    param([string]$Message)
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Resolve-FullPath {
    param(
        [string]$BasePath,
        [string]$Path
    )

    if ([System.IO.Path]::IsPathRooted($Path)) {
        return [System.IO.Path]::GetFullPath($Path)
    }

    return [System.IO.Path]::GetFullPath((Join-Path $BasePath $Path))
}

function Ensure-Directory {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path | Out-Null
    }
}

function Remove-IfExists {
    param([string]$Path)

    if (Test-Path -LiteralPath $Path) {
        for ($attempt = 1; $attempt -le 3; $attempt++) {
            try {
                $items = @(Get-ChildItem -LiteralPath $Path -Recurse -Force -ErrorAction SilentlyContinue)
                foreach ($item in $items) {
                    if ($item.Attributes -band [System.IO.FileAttributes]::ReadOnly) {
                        $item.Attributes = ($item.Attributes -band (-bnot [System.IO.FileAttributes]::ReadOnly))
                    }
                }

                $rootItem = Get-Item -LiteralPath $Path -Force -ErrorAction SilentlyContinue
                if ($null -ne $rootItem -and ($rootItem.Attributes -band [System.IO.FileAttributes]::ReadOnly)) {
                    $rootItem.Attributes = ($rootItem.Attributes -band (-bnot [System.IO.FileAttributes]::ReadOnly))
                }

                Remove-Item -LiteralPath $Path -Recurse -Force
                return
            }
            catch {
                if ($attempt -eq 3) {
                    throw
                }

                Start-Sleep -Milliseconds (200 * $attempt)
            }
        }
    }
}

function Copy-DirectoryContents {
    param(
        [string]$Source,
        [string]$Destination
    )

    Ensure-Directory -Path $Destination

    $entries = Get-ChildItem -LiteralPath $Source -Force
    foreach ($entry in $entries) {
        Copy-Item -LiteralPath $entry.FullName -Destination (Join-Path $Destination $entry.Name) -Recurse -Force
    }
}

function Invoke-NpmCommand {
    param(
        [string]$WorkingDirectory,
        [string[]]$Arguments
    )

    Push-Location $WorkingDirectory
    try {
        & npm.cmd @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "npm $($Arguments -join ' ') failed in $WorkingDirectory."
        }
    }
    finally {
        Pop-Location
    }
}

function Ensure-AppDependencies {
    param(
        [string]$ProjectDirectory,
        [bool]$ShouldInstall
    )

    $nodeModulesDirectory = Join-Path $ProjectDirectory "node_modules"
    if (Test-Path -LiteralPath $nodeModulesDirectory) {
        return
    }

    if (-not $ShouldInstall) {
        throw "Missing node_modules in $ProjectDirectory. Run the script with -InstallDependencies or install dependencies manually first."
    }

    Write-Step "Installing dependencies in $ProjectDirectory"
    Invoke-NpmCommand -WorkingDirectory $ProjectDirectory -Arguments @("ci")
}

function Add-PathToZipArchive {
    param(
        [System.IO.Compression.ZipArchive]$Archive,
        [string]$SourcePath,
        [string]$EntryName
    )

    $item = Get-Item -LiteralPath $SourcePath
    $normalizedEntryName = ($EntryName -replace '\\', '/').TrimStart('/')

    if ($item.PSIsContainer) {
        $children = @(Get-ChildItem -LiteralPath $item.FullName -Force)
        if ($children.Count -eq 0) {
            $Archive.CreateEntry(($normalizedEntryName.TrimEnd('/') + '/')) | Out-Null
            return
        }

        foreach ($child in $children) {
            $childEntryName = if ([string]::IsNullOrWhiteSpace($normalizedEntryName)) {
                $child.Name
            } else {
                "$normalizedEntryName/$($child.Name)"
            }

            Add-PathToZipArchive -Archive $Archive -SourcePath $child.FullName -EntryName $childEntryName
        }

        return
    }

    $entry = $Archive.CreateEntry($normalizedEntryName, [System.IO.Compression.CompressionLevel]::Optimal)
    $entryStream = $entry.Open()
    $fileStream = [System.IO.File]::OpenRead($item.FullName)

    try {
        $fileStream.CopyTo($entryStream)
    }
    finally {
        $fileStream.Dispose()
        $entryStream.Dispose()
    }
}

function Compress-Paths {
    param(
        [string[]]$SourcePaths,
        [string]$DestinationZip
    )

    Remove-IfExists -Path $DestinationZip

    $destinationDirectory = Split-Path -Parent $DestinationZip
    if (-not [string]::IsNullOrWhiteSpace($destinationDirectory)) {
        Ensure-Directory -Path $destinationDirectory
    }

    $fileStream = $null
    $archive = $null

    try {
        $fileStream = [System.IO.File]::Open($DestinationZip, [System.IO.FileMode]::Create)
        $archive = [System.IO.Compression.ZipArchive]::new(
            $fileStream,
            [System.IO.Compression.ZipArchiveMode]::Create,
            $false
        )

        foreach ($sourcePath in $SourcePaths) {
            $item = Get-Item -LiteralPath $sourcePath
            Add-PathToZipArchive -Archive $archive -SourcePath $item.FullName -EntryName $item.Name
        }
    }
    finally {
        if ($null -ne $archive) {
            $archive.Dispose()
        }
        if ($null -ne $fileStream) {
            $fileStream.Dispose()
        }
    }
}

function Get-GitRevision {
    param([string]$RepositoryRoot)

    $gitCommand = Get-Command git -ErrorAction SilentlyContinue
    if ($null -eq $gitCommand) {
        return $null
    }

    Push-Location $RepositoryRoot
    try {
        $revision = (& git rev-parse --short HEAD 2>$null)
        if ($LASTEXITCODE -ne 0) {
            return $null
        }

        return ($revision | Select-Object -First 1).Trim()
    }
    finally {
        Pop-Location
    }
}

$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$repositoryRoot = Resolve-FullPath -BasePath $scriptDirectory -Path ".."
$resolvedOutputBase = Resolve-FullPath -BasePath $repositoryRoot -Path $OutputDir
$tempBase = Join-Path ([System.IO.Path]::GetTempPath()) "MensaManagerPackaging"

if ([string]::IsNullOrWhiteSpace($ReleaseLabel)) {
    $ReleaseLabel = Get-Date -Format "yyyyMMdd-HHmmss"
}

$releaseRoot = Join-Path $resolvedOutputBase $ReleaseLabel
$workRoot = Join-Path $tempBase $ReleaseLabel
$bundleStageRoot = Join-Path $workRoot "app-bundle"
$uploadRoot = Join-Path $releaseRoot "upload-package"
$bundleZipName = "$PackagePrefix-apps.zip"
$uploadZipName = "$PackagePrefix-upload-package.zip"
$bundleZipPath = Join-Path $releaseRoot $bundleZipName
$uploadZipPath = Join-Path $releaseRoot $uploadZipName
$manifestPath = Join-Path $releaseRoot "release-manifest.json"
$uploadReadmePath = Join-Path $uploadRoot "UPLOAD-README.txt"

$appMappings = @(
    @{ Project = "mensaportal"; ReleaseName = "user" },
    @{ Project = "mensalehrer"; ReleaseName = "lehrer" },
    @{ Project = "mensaadmin"; ReleaseName = "admin" }
)

try {
    foreach ($app in $appMappings) {
        $projectDirectory = Join-Path $repositoryRoot $app.Project
        $distDirectory = Join-Path $projectDirectory "dist"

        if (-not (Test-Path -LiteralPath $projectDirectory)) {
            throw "Project directory not found: $projectDirectory"
        }

        if (-not $SkipBuild) {
            Ensure-AppDependencies -ProjectDirectory $projectDirectory -ShouldInstall:$InstallDependencies.IsPresent
            Write-Step "Building $($app.Project)"
            Invoke-NpmCommand -WorkingDirectory $projectDirectory -Arguments @("run", "build")
        }

        if (-not (Test-Path -LiteralPath $distDirectory)) {
            throw "Build output missing for $($app.Project). Expected: $distDirectory"
        }
    }

    Write-Step "Preparing release folder $releaseRoot"
    Ensure-Directory -Path $resolvedOutputBase
    Ensure-Directory -Path $tempBase
    Remove-IfExists -Path $releaseRoot
    Remove-IfExists -Path $workRoot
    Ensure-Directory -Path $releaseRoot
    Ensure-Directory -Path $workRoot
    Ensure-Directory -Path $bundleStageRoot
    Ensure-Directory -Path $uploadRoot

    foreach ($app in $appMappings) {
        $projectDirectory = Join-Path $repositoryRoot $app.Project
        $distDirectory = Join-Path $projectDirectory "dist"
        $bundleTargetDirectory = Join-Path $bundleStageRoot $app.ReleaseName
        Ensure-Directory -Path $bundleTargetDirectory
        Copy-DirectoryContents -Source $distDirectory -Destination $bundleTargetDirectory
    }

    Write-Step "Creating app bundle $bundleZipName"
    $bundleZipSources = @()
    foreach ($app in $appMappings) {
        $bundleZipSources += (Join-Path $bundleStageRoot $app.ReleaseName)
    }
    Compress-Paths -SourcePaths $bundleZipSources -DestinationZip $bundleZipPath

    Write-Step "Copying installer payload"
    Copy-Item -LiteralPath (Join-Path $repositoryRoot "index.php") -Destination (Join-Path $uploadRoot "index.php") -Force
    Copy-Item -LiteralPath (Join-Path $repositoryRoot "installer") -Destination (Join-Path $uploadRoot "installer") -Recurse -Force

    $sharedPhpTarget = Join-Path $uploadRoot "shared\php"
    Ensure-Directory -Path $sharedPhpTarget
    Copy-DirectoryContents -Source (Join-Path $repositoryRoot "shared\php") -Destination $sharedPhpTarget

    $docsTarget = Join-Path $uploadRoot "docs"
    Ensure-Directory -Path $docsTarget
    Copy-Item -LiteralPath (Join-Path $repositoryRoot "docs\installer.md") -Destination (Join-Path $docsTarget "installer.md") -Force
    Copy-Item -LiteralPath $bundleZipPath -Destination (Join-Path $uploadRoot $bundleZipName) -Force

    $gitRevision = Get-GitRevision -RepositoryRoot $repositoryRoot
    $manifest = [ordered]@{
        generatedAt = (Get-Date).ToString("o")
        releaseLabel = $ReleaseLabel
        packagePrefix = $PackagePrefix
        repositoryRoot = $repositoryRoot
        gitRevision = $gitRevision
        appBundle = $bundleZipName
        uploadFolder = "upload-package"
        uploadArchive = $(if ($SkipOuterZip) { $null } else { $uploadZipName })
        includedProjects = @($appMappings | ForEach-Object { $_.Project })
    }
    $manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $manifestPath -Encoding UTF8

    $uploadReadme = @(
        "MensaManager upload package",
        "",
        "1. Upload the complete contents of this folder into the web root of your PHP server.",
        "2. Keep the inner app archive '$bundleZipName' in the same directory as index.php.",
        "3. Open the target URL in the browser.",
        "4. The installer extracts the app archive and guides you through the setup.",
        "",
        "Important:",
        "- The inner archive must stay zipped for the installer.",
        "- shared/php/mm_security.php is already included.",
        "- ZipArchive must be available on the server.",
        "- After installation the installer locks itself via shared/.installer-lock.json."
    )
    $uploadReadme | Set-Content -LiteralPath $uploadReadmePath -Encoding UTF8

    if (-not $SkipOuterZip) {
        Write-Step "Creating upload archive $uploadZipName"
        $uploadArchiveSources = Get-ChildItem -LiteralPath $uploadRoot -Force | Select-Object -ExpandProperty FullName
        Compress-Paths -SourcePaths $uploadArchiveSources -DestinationZip $uploadZipPath
    }

    try {
        Remove-IfExists -Path $workRoot
    }
    catch {
        Write-Host "Note: Temporary work folder could not be removed: $workRoot" -ForegroundColor DarkYellow
    }

    Write-Host ""
    Write-Host "Release ready:" -ForegroundColor Green
    Write-Host "  Folder:  $uploadRoot"
    Write-Host "  Apps:    $bundleZipPath"
    if (-not $SkipOuterZip) {
        Write-Host "  Upload:  $uploadZipPath"
    }
    Write-Host "  Manifest:$manifestPath"
}
catch {
    Write-Warning $_.Exception.Message

    try {
        if (Test-Path -LiteralPath $releaseRoot) {
            Remove-IfExists -Path $releaseRoot
        }
    }
    catch {
        Write-Warning "Failed to clean partial release folder: $releaseRoot"
    }

    throw
}
