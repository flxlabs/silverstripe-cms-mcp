<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class MoveFileTool extends AbstractTool
{
    public function getName(): string
    {
        return 'move_file';
    }

    public function getDescription(): string
    {
        return 'Move a File or Image record to a different folder by updating its ParentID. '
            . 'The file is written to the draft stage only — call publish_record on any owning '
            . 'record to make the change visible on the live site.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'ID of the File or Image record to move',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the target Folder. Use 0 to move to the assets root.',
                ],
            ],
            'required' => ['id', 'parent_id'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $id = $this->requireInt($arguments, 'id');
        $parentId = $this->requireInt($arguments, 'parent_id');

        // Find the file record
        $file = File::get()->byID($id);
        if (!$file || !$file->exists()) {
            throw new ToolException("File with ID $id not found.");
        }

        // Validate it's a File or Image
        if (!($file instanceof File)) {
            throw new ToolException("Record ID $id is not a File or Image (got {$file->ClassName}).");
        }

        // Validate the parent folder
        if ($parentId <= 0) {
            throw new ToolException("Invalid parent_id $parentId.");
        }

        $parent = Folder::get()->byID($parentId);
        if (!$parent || !$parent->exists()) {
            throw new ToolException("Parent folder with ID $parentId not found.");
        }
        if (!($parent instanceof Folder)) {
            throw new ToolException("Record ID $parentId is not a Folder (got {$parent->ClassName}).");
        }

        // Update the ParentID
        $file->ParentID = $parentId;
        $file->writeToStage(Versioned::DRAFT);

        return $this->jsonResult([
            'id' => $file->ID,
            'class' => $file->ClassName,
            'title' => $file->Title,
            'filename' => $file->Name,
            'parent_id' => $file->ParentID,
            'parent_title' => $parentId > 0 ? $parent->Title : null,
            'url' => $file->getAbsoluteURL(),
        ]);
    }

    /**
     * Extract an integer from arguments, throwing if missing or invalid.
     */
    protected function requireInt(array $arguments, string $key): int
    {
        if (!isset($arguments[$key]) || !is_numeric($arguments[$key])) {
            throw new ToolException("Missing required integer argument \"$key\"");
        }
        return (int) $arguments[$key];
    }
}
