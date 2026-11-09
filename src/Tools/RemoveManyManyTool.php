<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class RemoveManyManyTool extends AbstractTool
{
    public function getName(): string
    {
        return 'remove_many_many';
    }

    public function getDescription(): string
    {
        return 'Remove records from a many_many or belongs_many_many relation on any MCP-exposed class. '
            . 'Pass the class name (from list_classes), record ID, relation name (e.g. "Features", '
            . '"Images", "CategoryPages"), and an array of IDs to remove. '
            . 'Writes to the draft stage only — call publish_record to make changes live.';
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
                    'description' => 'Name of the many_many or belongs_many_many relation, '
                        . 'e.g. "Features", "Images", "CategoryPages"',
                ],
                'ids' => [
                    'type' => 'array',
                    'description' => 'Array of IDs to remove from the relation',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['class', 'id', 'relation', 'ids'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $class = $this->requireExposableClass($arguments);
        $id = $this->requireInt($arguments, 'id');
        $relation = $this->requireString($arguments, 'relation');
        $ids = $arguments['ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            throw new ToolException('"ids" must be a non-empty array of integers');
        }

        // Validate the record
        $record = DataObject::get($class)->byID($id);
        if (!$record) {
            throw new ToolException("No $class record found with id $id");
        }

        if (!$record->canEdit($actingMember)) {
            throw new ToolException("Permission denied: cannot edit this $class record");
        }

        // Validate the relation is a many_many or belongs_many_many
        $manyMany = $this->getManyManyRelations($class);
        $belongsManyMany = $this->getBelongsManyManyRelations($class);
        $allRelations = array_merge($manyMany, $belongsManyMany);

        if (!isset($allRelations[$relation])) {
            $type = isset($manyMany[$relation]) ? 'many_many'
                : (isset($belongsManyMany[$relation]) ? 'belongs_many_many' : 'unknown');
            throw new ToolException(
                "\"$relation\" is not a $type relation on $class. "
                    . 'Valid many_many relations: ' . implode(', ', array_keys($manyMany))
                    . '. Valid belongs_many_many relations: ' . implode(', ', array_keys($belongsManyMany))
            );
        }

        // Remove IDs from the relation
        $removed = [];
        $skipped = [];
        $relationList = $record->$relation();

        foreach ($ids as $itemId) {
            $itemId = (int) $itemId;
            $item = $relationList->dataClass::get()->byID($itemId);
            if (!$item || !$item->exists()) {
                $skipped[] = $itemId;
                continue;
            }
            $relationList->remove($item);
            $removed[] = $itemId;
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
            'removed' => $removed,
            'skipped' => $skipped,
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

    /**
     * Get belongs_many_many relations for a class using reflection.
     */
    private function getBelongsManyManyRelations(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        while ($reflection) {
            if ($reflection->hasProperty('belongs_many_many')) {
                $property = $reflection->getProperty('belongs_many_many');
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
