<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Assets\File;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class AddManyManyFilesTool extends AbstractTool
{
    public function getName(): string
    {
        return 'add_many_many_files';
    }

    public function getDescription(): string
    {
        return 'Add existing File or Image records to a many_many relation on any MCP-exposed class. '
            . 'Pass the class name (from list_classes), record ID, relation name (e.g. "Images", '
            . '"AdditionalSchemes", "Downloads"), and an array of file/image IDs. Only files the '
            . 'acting member can view are added. Writes to the draft stage only — call publish_record '
            . 'to make changes live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => [
                    'type' => 'string',
                    'description' => 'A class returned by list_classes',
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => 'Record ID',
                ],
                'relation' => [
                    'type' => 'string',
                    'description' => 'Name of the many_many relation, e.g. "Images", "AdditionalSchemes", "Downloads"',
                ],
                'fileIds' => [
                    'type' => 'array',
                    'description' => 'Array of File/Image IDs to add to the relation',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['class', 'id', 'relation', 'fileIds'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $class = $this->requireExposableClass($arguments);
        $id = $this->requireInt($arguments, 'id');
        $relation = $this->requireString($arguments, 'relation');
        $fileIds = $arguments['fileIds'] ?? [];

        if (!is_array($fileIds) || empty($fileIds)) {
            throw new ToolException('"fileIds" must be a non-empty array of integers');
        }

        // Validate the record
        $record = DataObject::get($class)->byID($id);
        if (!$record) {
            throw new ToolException("No $class record found with id $id");
        }

        if (!$record->canEdit($actingMember)) {
            throw new ToolException("Permission denied: cannot edit this $class record");
        }

        // Validate the relation is a many_many
        $manyMany = $this->getManyManyRelations($class);
        if (!isset($manyMany[$relation])) {
            throw new ToolException(
                "\"$relation\" is not a many_many relation on $class. "
                    . 'Valid many_many relations: ' . implode(', ', array_keys($manyMany))
            );
        }

        // Validate each file/image
        $validIds = [];
        foreach ($fileIds as $fileId) {
            $fileId = (int) $fileId;
            $file = File::get()->byID($fileId);
            if (!$file || !($file instanceof File)) {
                continue;
            }
            if (!$file->canView($actingMember)) {
                continue;
            }
            $validIds[] = $fileId;
        }

        if (!$validIds) {
            return $this->jsonResult([
                'class' => $class,
                'id' => $id,
                'relation' => $relation,
                'added' => [],
                'skipped' => count($fileIds),
            ]);
        }

        // Add files to the relation
        $added = [];
        $existing = $record->$relation()->column('ID');

        foreach ($validIds as $fileId) {
            if (in_array($fileId, $existing, true)) {
                continue;
            }
            $record->$relation()->add(File::get()->byID($fileId));
            $added[] = $fileId;
        }

        if ($record->hasExtension(Versioned::class)) {
            $record->writeToStage(Versioned::DRAFT);
        } else {
            $record->write();
        }

        return $this->jsonResult([
            'class' => $class,
            'id' => $id,
            'relation' => $relation,
            'added' => $added,
            'skipped' => count($validIds) - count($added),
        ]);
    }

    /**
     * Get many_many relations for a class using reflection.
     */
    private function getManyManyRelations(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        while ($reflection) {
            if ($reflection->hasProperty('many_many')) {
                $property = $reflection->getProperty('many_many');
                if ($property->isStatic()) {
                    $value = $property->getValue();
                    return is_array($value) ? $value : [];
                }
            }
            $reflection = $reflection->getParentClass();
        }
        return [];
    }
}
