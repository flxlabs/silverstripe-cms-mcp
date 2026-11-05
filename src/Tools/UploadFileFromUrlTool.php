<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Upload;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class UploadFileFromUrlTool extends AbstractTool
{
    private const MAX_CONTENT_BYTES = 15 * 1024 * 1024;
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function getName(): string
    {
        return 'upload_file_from_url';
    }

    public function getDescription(): string
    {
        return 'Download a file from a URL and create a File or Image record in the SilverStripe '
            . 'asset store. Returns the ID of the created record. The file is written to the draft '
            . 'stage only — call publish_record on any owning record to make it visible on the live site.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL of the file to download (http/https)',
                ],
                'folder' => [
                    'type' => 'string',
                    'description' => 'Optional: FolderPath in the asset store, e.g. "products" or "products/logos"',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $url = $this->requireString($arguments, 'url');
        $folder = $arguments['folder'] ?? null;

        // Validate URL scheme
        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw new ToolException("Invalid URL: $url");
        }
        if (!in_array(strtolower($parsed['scheme']), self::ALLOWED_SCHEMES, true)) {
            throw new ToolException("Unsupported URL scheme: {$parsed['scheme']}. Only http and https are allowed.");
        }

        // Download the file
        $content = $this->downloadFile($url);
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new ToolException(sprintf('File is too large: max %d bytes', self::MAX_CONTENT_BYTES));
        }

        // Write to temp file for Upload and MIME detection
        $tmpPath = tempnam(sys_get_temp_dir(), 'cmsmcp_');
        file_put_contents($tmpPath, $content);

        // Determine filename from URL
        $filename = $this->extractFilename($url);

        // Determine class (Image vs File) based on MIME type from the temp file
        $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';
        $class = str_starts_with($mimeType, 'image/') ? Image::class : File::class;

        // Suppress is_uploaded_file check (synthetic temp file, not a real PHP upload)
        $previousCheck = Upload_Validator::config()->get('use_is_uploaded_file');
        Config::modify()->set(Upload_Validator::class, 'use_is_uploaded_file', false);

        try {
            $tmpFile = [
                'name' => $filename,
                'tmp_name' => $tmpPath,
                'size' => filesize($tmpPath),
                'error' => 0,
            ];

            $file = Injector::inst()->create($class);
            $file->Title = pathinfo($filename, PATHINFO_FILENAME);
            $upload = Upload::create();
            $upload->setReplaceFile(false);

            $upload->loadIntoFile($tmpFile, $file);

            if ($upload->isError()) {
                throw new ToolException('Upload failed: ' . implode('; ', $upload->getErrors()));
            }
        } finally {
            Config::modify()->set(Upload_Validator::class, 'use_is_uploaded_file', $previousCheck);
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }

        if ($file->hasExtension(Versioned::class)) {
            $file->writeToStage(Versioned::DRAFT);
        }

        return $this->jsonResult([
            'id' => $file->ID,
            'class' => $class,
            'title' => $file->Title,
            'filename' => $file->Name,
            'url' => $file->getAbsoluteURL(),
            'mimeType' => $mimeType,
        ]);
    }

    /**
     * Download a file from a URL.
     */
    private function downloadFile(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'follow_location' => true,
                'max_redirects' => 5,
                'user_agent' => 'SilverStripe-MCP/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = file_get_contents($url, false, $context);
        if ($content === false) {
            throw new ToolException("Failed to download file from URL: $url");
        }

        return $content;
    }

    /**
     * Extract a filename from the URL, falling back to a generated name.
     */
    private function extractFilename(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $basename = basename($path);

        // If URL path doesn't give us a reasonable filename, generate one
        if ($basename === '' || strlen($basename) > 255 || !str_contains($basename, '.')) {
            // Try to infer extension from URL path segments or query string
            $extension = '';

            // Check path segments for known extensions (with or without dot)
            $segments = explode('/', trim($path, '/'));
            $lastSegment = end($segments);
            $knownExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'zip', 'doc', 'docx'];

            if (in_array(strtolower($lastSegment), $knownExtensions, true)) {
                $extension = '.' . $lastSegment;
            } elseif (preg_match('/\.(png|jpg|jpeg|gif|webp|svg|pdf|zip|doc|docx)$/i', $path, $matches)) {
                $extension = $matches[0];
            } elseif (preg_match('/\.(png|jpg|jpeg|gif|webp|svg|pdf|zip|doc|docx)$/i', $parsed['query'] ?? '', $matches)) {
                $extension = $matches[0];
            } else {
                $extension = '.bin';
            }

            $basename = 'uploaded_' . bin2hex(random_bytes(8)) . $extension;
        }

        // Sanitize: keep only safe characters
        $basename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);

        return $basename;
    }

    /**
     * Map a MIME type to a file extension.
     */
    private function getExtensionFromMime(string $mimeType): string
    {
        $map = [
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'image/svg+xml' => '.svg',
            'application/pdf' => '.pdf',
            'application/zip' => '.zip',
            'application/msword' => '.doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            'text/plain' => '.txt',
        ];

        return $map[$mimeType] ?? '.bin';
    }
}
