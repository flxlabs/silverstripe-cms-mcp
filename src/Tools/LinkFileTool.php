<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Assets\File;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\FieldIntrospector;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class LinkFileTool extends AbstractTool
{
    public function getName(): string
    {
        return 'link_file';
    }

    public function getDescription(): string
    {
        return 'Link an EXISTING File or Image record to a has_one File/Image field on a record '
            . '(valid field names: the "fileFields" block of list_fields). Use this when the asset '
            . 'is already in the CMS — do NOT re-upload it. Find existing file IDs via search_records '
            . '(class "SilverStripe\\Assets\\Image" or "SilverStripe\\Assets\\File", query = filename '
            . 'fragment). For many_many file relations (e.g. "Images"), use add_many_many_files '
            . 'instead. Writes to the draft stage only — call publish_record on the record '
            . 'afterwards to make the change live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
                'id' => ['type' => 'integer', 'description' => 'Record ID'],
                'field' => [
                    'type' => 'string',
                    'description' => 'Has_one File/Image field name, from list_fields\'s "fileFields" block.',
                ],
                'fileId' => [
                    'type' => 'integer',
                    'description' => 'ID of the existing File/Image record to link.',
                ],
            ],
            'required' => ['class', 'id', 'field', 'fileId'],
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
        $fileId = $this->requireInt($arguments, 'fileId');

        $fileFields = FieldIntrospector::singleton()->describeFileFields($class);
        if (!isset($fileFields[$field])) {
            throw new ToolException(
                "\"$field\" is not a has_one File/Image field on $class. "
                . 'Call list_fields and use a field from its "fileFields" block. '
                . 'For many_many relations, use add_many_many_files instead.'
            );
        }

        $file = File::get()->byID($fileId);
        if (!$file || !($file instanceof File)) {
            throw new ToolException("No File record found with id $fileId");
        }
        if (!$file->canView($actingMember)) {
            throw new ToolException("Permission denied: this member cannot view File #$fileId");
        }

        // A has_one "Image" field must not point at a plain File (and vice versa:
        // an Image satisfies a File-typed field, mirroring the CMS behaviour).
        $targetClass = $fileFields[$field]['relationClass'];
        if (!is_a($file, $targetClass)) {
            throw new ToolException(
                "Field \"$field\" on $class expects a $targetClass but File #$fileId is " . get_class($file)
            );
        }

        $record->setField($field . 'ID', $file->ID);

        if ($record->hasExtension(Versioned::class)) {
            $record->writeToStage(Versioned::DRAFT);
        } else {
            $record->write();
        }

        return $this->jsonResult([
            'class' => $class,
            'id' => $record->ID,
            'field' => $field,
            'file' => [
                'id' => $file->ID,
                'name' => $file->Name,
                'url' => $file->getAbsoluteURL(),
            ],
        ]);
    }
}
