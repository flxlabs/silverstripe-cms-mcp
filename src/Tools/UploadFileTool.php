<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Assets\Upload;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\FieldIntrospector;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class UploadFileTool extends AbstractTool
{
    private static int $max_content_bytes = 15 * 1024 * 1024;

    public function getName(): string
    {
        return 'upload_file';
    }

    public function getDescription(): string
    {
        return 'Upload a file and attach it to a File/Image relation field on a record '
            . '(discover valid field names via list_fields). Content must be base64-encoded. '
            . 'The upload is written to the draft stage only — call publish_record on the '
            . 'record afterwards to make it, and any owned files, visible on the live site.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
                'id' => ['type' => 'integer', 'description' => 'Record ID'],
                'field' => ['type' => 'string', 'description' => 'File/Image has_one field name, from list_fields'],
                'filename' => ['type' => 'string', 'description' => 'Filename including extension, e.g. photo.jpg'],
                'contentBase64' => ['type' => 'string', 'description' => 'Base64-encoded file content'],
            ],
            'required' => ['class', 'id', 'field', 'filename', 'contentBase64'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $record = $this->getViewableRecord($arguments, $actingMember);
        $class = get_class($record);

        if (!$record->canEdit($actingMember)) {
            throw new ToolException("Permission denied: this member cannot edit this $class record");
        }

        $field = $this->requireString($arguments, 'field');
        $fileFields = FieldIntrospector::singleton()->describeFileFields($class);
        if (!isset($fileFields[$field])) {
            throw new ToolException("\"$field\" is not a File/Image field on $class. Call list_fields to see valid fields.");
        }

        $filename = $this->requireString($arguments, 'filename');
        $contentBase64 = $this->requireString($arguments, 'contentBase64');

        $content = base64_decode($contentBase64, true);
        if ($content === false) {
            throw new ToolException('contentBase64 is not valid base64');
        }

        $maxBytes = static::config()->get('max_content_bytes');
        if (strlen($content) > $maxBytes) {
            throw new ToolException(sprintf('File is too large: max %d bytes', $maxBytes));
        }

        $relationClass = $fileFields[$field]['relationClass'];
        $tmpPath = tempnam(sys_get_temp_dir(), 'cmsmcp_');
        file_put_contents($tmpPath, $content);

        // Synthetic (non-$_FILES) uploads fail is_uploaded_file() by design; this module's
        // temp file is written by upload_file above, not by PHP's own upload handling.
        $previousCheck = Upload_Validator::config()->get('use_is_uploaded_file');
        Config::modify()->set(Upload_Validator::class, 'use_is_uploaded_file', false);

        try {
            $tmpFile = [
                'name' => $filename,
                'tmp_name' => $tmpPath,
                'size' => filesize($tmpPath),
                'error' => 0,
            ];

            $file = Injector::inst()->create($relationClass);
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

        $record->{"{$field}ID"} = $file->ID;
        $record->hasExtension(Versioned::class) ? $record->writeToStage(Versioned::DRAFT) : $record->write();

        return $this->jsonResult([
            'class' => $class,
            'id' => $record->ID,
            'field' => $field,
            'file' => ['id' => $file->ID, 'title' => $file->Title, 'filename' => $file->Name],
        ]);
    }
}
