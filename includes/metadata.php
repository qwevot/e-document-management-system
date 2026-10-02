<?php
// Document Metadata Extraction and Inspection Engine
// Extracts embedded properties from Office Open XML (DOCX, XLSX, PPTX), PDF, and Images.

class DocumentMetadataExtractor
{
    /**
     * Extract metadata from an uploaded file based on its extension.
     * Returns an associative array of extracted attributes.
     */
    public static function extract(string $filePath, string $extension): array
    {
        $ext = strtolower(trim($extension));

        $metadata = [
            'title'        => null,
            'creator'      => null,
            'subject'      => null,
            'description'  => null,
            'keywords'     => null,
            'category'     => null,
            'created_at'   => null,
            'modified_at'  => null,
            'page_count'   => null,
            'app_name'     => null,
        ];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return $metadata;
        }

        try {
            if (in_array($ext, ['docx', 'xlsx', 'pptx'], true) && extension_loaded('zip')) {
                return array_merge($metadata, self::extractOfficeXml($filePath));
            } elseif ($ext === 'pdf') {
                return array_merge($metadata, self::extractPdf($filePath));
            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && function_exists('exif_read_data')) {
                return array_merge($metadata, self::extractImage($filePath));
            }
        } catch (\Throwable $e) {
            // Silently fall back to basic metadata if extraction fails
            error_log("Metadata extraction error for {$filePath}: " . $e->getMessage());
        }

        return $metadata;
    }

    /**
     * Extract metadata from Office Open XML archives (DOCX, XLSX, PPTX).
     */
    private static function extractOfficeXml(string $filePath): array
    {
        $meta = [];
        $zip = new \ZipArchive();

        if ($zip->open($filePath) === true) {
            // Check for docProps/core.xml
            $coreXml = $zip->getFromName('docProps/core.xml');
            if ($coreXml !== false) {
                // Parse namespaces
                $xml = @simplexml_load_string($coreXml);
                if ($xml !== false) {
                    $namespaces = $xml->getNamespaces(true);

                    // Dublin Core (dc) namespace
                    if (isset($namespaces['dc'])) {
                        $dc = $xml->children($namespaces['dc']);
                        if (!empty($dc->title))       $meta['title'] = (string)$dc->title;
                        if (!empty($dc->creator))     $meta['creator'] = (string)$dc->creator;
                        if (!empty($dc->subject))     $meta['subject'] = (string)$dc->subject;
                        if (!empty($dc->description)) $meta['description'] = (string)$dc->description;
                    }

                    // Core Properties (cp) namespace
                    if (isset($namespaces['cp'])) {
                        $cp = $xml->children($namespaces['cp']);
                        if (!empty($cp->keywords))    $meta['keywords'] = (string)$cp->keywords;
                        if (!empty($cp->category))    $meta['category'] = (string)$cp->category;
                        if (!empty($cp->lastModifiedBy) && empty($meta['creator'])) {
                            $meta['creator'] = (string)$cp->lastModifiedBy;
                        }
                    }

                    // Dublin Core Terms (dcterms) namespace
                    if (isset($namespaces['dcterms'])) {
                        $dcterms = $xml->children($namespaces['dcterms']);
                        if (!empty($dcterms->created))  $meta['created_at'] = (string)$dcterms->created;
                        if (!empty($dcterms->modified)) $meta['modified_at'] = (string)$dcterms->modified;
                    }
                }
            }
            $zip->close();
        }

        return array_filter($meta, fn($v) => $v !== null && trim((string)$v) !== '');
    }

    /**
     * Extract basic PDF metadata using binary stream matching.
     */
    private static function extractPdf(string $filePath): array
    {
        $meta = [];
        $content = @file_get_contents($filePath, false, null, 0, 65536); // Read first 64KB
        if ($content === false) return $meta;

        // Simple regex matching for standard PDF trailer info dictionary
        if (preg_match('/\/Title\s*\(([^)]+)\)/i', $content, $m)) {
            $meta['title'] = trim($m[1]);
        }
        if (preg_match('/\/Author\s*\(([^)]+)\)/i', $content, $m)) {
            $meta['creator'] = trim($m[1]);
        }
        if (preg_match('/\/Subject\s*\(([^)]+)\)/i', $content, $m)) {
            $meta['subject'] = trim($m[1]);
        }
        if (preg_match('/\/Keywords\s*\(([^)]+)\)/i', $content, $m)) {
            $meta['keywords'] = trim($m[1]);
        }
        if (preg_match('/\/Creator\s*\(([^)]+)\)/i', $content, $m) && empty($meta['creator'])) {
            $meta['creator'] = trim($m[1]);
        }

        return array_filter($meta, fn($v) => $v !== null && trim((string)$v) !== '');
    }

    /**
     * Extract Image EXIF metadata.
     */
    private static function extractImage(string $filePath): array
    {
        $meta = [];
        $exif = @exif_read_data($filePath);
        if ($exif && is_array($exif)) {
            if (!empty($exif['Title']))       $meta['title'] = $exif['Title'];
            if (!empty($exif['Author']))      $meta['creator'] = $exif['Author'];
            if (!empty($exif['Artist']))      $meta['creator'] = $exif['Artist'];
            if (!empty($exif['DateTimeOriginal'])) $meta['created_at'] = $exif['DateTimeOriginal'];
        }
        return array_filter($meta, fn($v) => $v !== null && trim((string)$v) !== '');
    }
}
