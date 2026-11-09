<?php
// LibreOffice Document Conversion Engine
// Detects embedded portable LibreOffice, system installation, or PATH binary.

/**
 * Locate the soffice binary executable across portable and system paths.
 */
function get_libreoffice_binary(): ?string
{
    $candidates = [
        // 1. Embedded Portable paths in project bin/
        __DIR__ . '/../bin/LibreOfficePortable/App/libreoffice/program/soffice.exe',
        __DIR__ . '/../bin/LibreOfficePortable/App/LibreOffice/program/soffice.exe',
        __DIR__ . '/../bin/libreoffice/program/soffice.exe',
        __DIR__ . '/../bin/soffice.exe',
        __DIR__ . '/../bin/LibreOfficePortable/LibreOfficePortable.exe',

        // 2. Standard Windows Installations
        'C:\Program Files\LibreOffice\program\soffice.exe',
        'C:\Program Files (x86)\LibreOffice\program\soffice.exe',
    ];

    foreach ($candidates as $path) {
        $real = realpath($path);
        if ($real && file_exists($real)) {
            return $real;
        }
        if (file_exists($path)) {
            return $path;
        }
    }

    // 3. Check System PATH
    $whichCmd = (PHP_OS_FAMILY === 'Windows') ? 'where.exe soffice.exe 2>nul' : 'which soffice 2>/dev/null';
    $pathFromEnv = @shell_exec($whichCmd);
    if (!empty($pathFromEnv)) {
        $firstLine = trim(explode("\n", trim($pathFromEnv))[0]);
        if ($firstLine !== '' && file_exists($firstLine)) {
            return $firstLine;
        }
    }

    return null;
}

/**
 * Check whether LibreOffice conversion is available.
 */
function is_libreoffice_available(): bool
{
    return get_libreoffice_binary() !== null;
}

/**
 * Convert a document file (.docx, .doc, .rtf, .odt, .xlsx, .xls, .csv, .pptx) to vector PDF.
 * Returns the absolute path to the generated PDF file, or null on failure.
 */
function convert_document_to_pdf(string $sourceFilePath, string $originalExt = ''): ?string
{
    if (!file_exists($sourceFilePath) || !is_readable($sourceFilePath)) {
        return null;
    }

    $soffice = get_libreoffice_binary();
    if (!$soffice) {
        if (function_exists('log_activity')) {
            log_activity('system.pdf_conversion_failed', 'document', null, [
                'source'    => basename($sourceFilePath),
                'extension' => $originalExt,
                'reason'    => 'LibreOffice (soffice) binary not found'
            ]);
        }
        return null;
    }

    // Cache directory for converted PDFs
    $cacheDir = __DIR__ . '/../uploads/cache/pdf';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    $diskExt = strtolower(pathinfo($sourceFilePath, PATHINFO_EXTENSION));
    $fileExt = $originalExt !== '' ? strtolower(ltrim($originalExt, '.')) : $diskExt;
    $isSpreadsheet = in_array($fileExt, ['xlsx', 'xls', 'csv', 'ods'], true);

    // Hash includes conversion mode flag so spreadsheet improvements invalidate older sliced PDF caches
    $hashPrefix = $isSpreadsheet ? 'calc_v2_' : 'doc_v1_';
    $fileHash = md5($hashPrefix . $sourceFilePath . filemtime($sourceFilePath) . filesize($sourceFilePath));
    $cachedPdfPath = $cacheDir . '/' . $fileHash . '.pdf';

    // If cached version already exists, return it immediately
    if (file_exists($cachedPdfPath) && filesize($cachedPdfPath) > 0) {
        return $cachedPdfPath;
    }

    // Serialize concurrent conversions for the same file via lockfile
    $lockPath = $cacheDir . '/' . $fileHash . '.lock';
    $lockFp = @fopen($lockPath, 'c');
    if ($lockFp) {
        @flock($lockFp, LOCK_EX);
        clearstatcache(true, $cachedPdfPath);
        if (file_exists($cachedPdfPath) && filesize($cachedPdfPath) > 0) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
            @unlink($lockPath);
            return $cachedPdfPath;
        }
    }

    $cleanupLock = static function () use ($lockFp, $lockPath): void {
        if ($lockFp) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
            @unlink($lockPath);
        }
    };

    // Create a temporary working directory for this conversion process
    $tempDir = $cacheDir . '/tmp_' . bin2hex(random_bytes(8));
    if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
        $cleanupLock();
        return null;
    }

    // If stored as .bin on disk, stage a hardlink/copy with the true extension so LibreOffice selects the right import filter
    $inputPath = $sourceFilePath;
    if ($diskExt === 'bin' && $fileExt !== '' && $fileExt !== 'bin' && preg_match('/^[a-z0-9]+$/', $fileExt)) {
        $stagedInput = $tempDir . '/source.' . $fileExt;
        if (@link($sourceFilePath, $stagedInput) || @copy($sourceFilePath, $stagedInput)) {
            $inputPath = $stagedInput;
        }
    }

    // Execute headless LibreOffice conversion with isolated user profile to prevent concurrent lock collisions
    $escapedSoffice = escapeshellarg($soffice);
    $escapedTempDir = escapeshellarg($tempDir);
    $escapedSource  = escapeshellarg($inputPath);
    $profileUrl     = 'file:///' . ltrim(str_replace('\\', '/', $tempDir), '/') . '/profile';
    $escapedProfile = escapeshellarg("-env:UserInstallation={$profileUrl}");

    // For spreadsheets, use LibreOffice Calc PDF Export filter with SinglePageSheets=true
    // This auto-fits each worksheet dynamically onto a continuous vector page without slicing columns across pages.
    if ($isSpreadsheet) {
        $pdfFilter = 'pdf:calc_pdf_Export:{\"SinglePageSheets\":{\"type\":\"boolean\",\"value\":\"true\"}}';
        $cmd = "{$escapedSoffice} {$escapedProfile} --headless --convert-to \"{$pdfFilter}\" --outdir {$escapedTempDir} {$escapedSource} 2>&1";
    } else {
        $cmd = "{$escapedSoffice} {$escapedProfile} --headless --convert-to pdf --outdir {$escapedTempDir} {$escapedSource} 2>&1";
    }

    exec($cmd, $output, $returnCode);

    $cleanupTemp = static function () use ($tempDir): void {
        if (is_dir($tempDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($tempDir);
        }
    };

    // Look for the output .pdf in $tempDir
    $files = glob($tempDir . '/*.pdf');
    if (!empty($files) && file_exists($files[0]) && filesize($files[0]) > 0) {
        rename($files[0], $cachedPdfPath);
        $cleanupTemp();
        $cleanupLock();
        return $cachedPdfPath;
    }

    // Fallback attempt for spreadsheets with standard pdf converter if custom filter failed
    if ($isSpreadsheet) {
        $fallbackCmd = "{$escapedSoffice} {$escapedProfile} --headless --convert-to pdf --outdir {$escapedTempDir} {$escapedSource} 2>&1";
        exec($fallbackCmd, $fbOutput, $fbCode);
        $files = glob($tempDir . '/*.pdf');
        if (!empty($files) && file_exists($files[0]) && filesize($files[0]) > 0) {
            rename($files[0], $cachedPdfPath);
            $cleanupTemp();
            $cleanupLock();
            return $cachedPdfPath;
        }
    }

    if (function_exists('log_activity')) {
        log_activity('system.pdf_conversion_failed', 'document', null, [
            'source'      => basename($sourceFilePath),
            'extension'   => $fileExt,
            'return_code' => $fbCode ?? $returnCode ?? null,
            'output'      => array_slice($fbOutput ?? $output ?? [], 0, 5)
        ]);
    }

    $cleanupTemp();
    $cleanupLock();
    return null;
}

/**
 * Remove any cached converted PDF and lockfile associated with a physical source file prior to purging.
 */
function purge_cached_pdf_for_source(string $sourceFilePath): int
{
    if (!file_exists($sourceFilePath) || !is_file($sourceFilePath)) {
        return 0;
    }

    $cacheDir = __DIR__ . '/../uploads/cache/pdf';
    if (!is_dir($cacheDir)) {
        return 0;
    }

    $mtime = @filemtime($sourceFilePath);
    $size  = @filesize($sourceFilePath);
    if ($mtime === false || $size === false) {
        return 0;
    }

    // Check both raw path and resolved realpath across both document and spreadsheet cache prefixes
    $candidatePaths = array_unique(array_filter([$sourceFilePath, realpath($sourceFilePath) ?: null]));
    $unlinked = 0;

    foreach ($candidatePaths as $pathVariant) {
        foreach (['doc_v1_', 'calc_v2_'] as $prefix) {
            $hash = md5($prefix . $pathVariant . $mtime . $size);
            foreach (['.pdf', '.lock'] as $ext) {
                $target = $cacheDir . '/' . $hash . $ext;
                if (is_file($target) && @unlink($target)) {
                    $unlinked++;
                }
            }
        }
    }

    return $unlinked;
}
