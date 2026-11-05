<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Assets\File;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class RenameFileTool extends AbstractTool
{
    public function getName(): string
    {
        return 'rename_file';
    }

    public function getDescription(): string
    {
        return 'Rename a CMS File. Change the Title, the Filename '
            . '(physical file on disk), or both. If only one is provided, the other is left unchanged. '
            . 'Pass the file ID, and either "title" or "filename" (or both). Writes to the draft '
            . 'stage only — call publish_record on any owning record to make the change live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'ID of the File record to rename',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'New Title for the file. If omitted, the existing title is unchanged.',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'New Filename (physical file on disk). If omitted, the existing filename is unchanged.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $id = $this->requireInt($arguments, 'id');
        $title = $arguments['title'] ?? null;
        $filename = $arguments['filename'] ?? null;

        if ($title === null && $filename === null) {
            throw new ToolException('Provide at least "title" or "filename" to rename the file.');
        }

        $file = File::get()->byID($id);
        if (!$file || !$file->exists()) {
            throw new ToolException("No File record found with id $id");
        }

        if (!$file->canEdit($actingMember)) {
            throw new ToolException("Permission denied: cannot edit File #$id");
        }

        $oldTitle = $file->Title;
        $oldFilename = $file->Filename;

        if ($title !== null) {
            $file->Title = $title;
        }

        if ($filename !== null) {
            $file->renameFile($filename);
        }

        $file->writeToStage(Versioned::DRAFT);

        return $this->jsonResult([
            'id' => $file->ID,
            'title' => [
                'old' => $oldTitle,
                'new' => $file->Title,
            ],
            'filename' => [
                'old' => $oldFilename,
                'new' => $file->Filename,
            ],
        ]);
    }
}
