<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class SearchByRelationTool extends AbstractTool
{
    public function getName(): string
    {
        return 'search_by_relation';
    }

    public function getDescription(): string
    {
        return 'Search records by the value of a has_one (1-to-1) relation. '
            . 'For example, list all Vehicles whose Brand.Name matches a given value. '
            . 'The relation and value are used to look up the related record, then all '
            . 'records with that relation ID are returned.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => [
                    'type' => 'string',
                    'description' => 'The class to search (e.g. "Vehicle")',
                ],
                'relation' => [
                    'type' => 'string',
                    'description' => 'The has_one relation name (e.g. "Brand", "Category", "Type", "Flag", "Scheme")',
                ],
                'value' => [
                    'type' => 'string',
                    'description' => 'The value to match on the related record. '
                        . 'The related record is looked up by its "Name" field (if present), '
                        . 'otherwise by "Title". If multiple matches exist, the first is used.',
                ],
            ],
            'required' => ['class', 'relation', 'value'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $class = $this->requireExposableClass($arguments);
        $relation = $this->requireString($arguments, 'relation');
        $value = $this->requireString($arguments, 'value');

        // Validate the relation is a has_one
        $hasOne = $this->getHasOneRelations($class);
        if (!isset($hasOne[$relation])) {
            throw new ToolException(
                "\"$relation\" is not a has_one relation on $class. "
                    . 'Valid has_one relations: ' . implode(', ', array_keys($hasOne))
            );
        }

        $relationClass = $hasOne[$relation];

        // Look up the related record by Name or Title
        $relatedRecord = $this->findRelatedRecord($relationClass, $value);
        if (!$relatedRecord) {
            return $this->jsonResult([
                'class' => $class,
                'relation' => $relation,
                'value' => $value,
                'relatedRecord' => null,
                'count' => 0,
                'records' => [],
            ]);
        }

        // Find all records of $class that reference this related record
        $foreignIdField = $relation . 'ID';
        $records = DataObject::get($class)->filter($foreignIdField, $relatedRecord->ID);

        $results = [];
        foreach ($records as $record) {
            if (!$record->canView($actingMember)) {
                continue;
            }
            $results[] = [
                'id' => $record->ID,
                'Title' => (string) $record->Title,
            ];
        }

        return $this->jsonResult([
            'class' => $class,
            'relation' => $relation,
            'value' => $value,
            'relatedRecord' => [
                'id' => $relatedRecord->ID,
                'class' => $relationClass,
                'display' => (string) ($relatedRecord->Name ?? $relatedRecord->Title ?? ''),
            ],
            'count' => count($results),
            'records' => $results,
        ]);
    }

    /**
     * Get has_one relations for a class using reflection.
     */
    private function getHasOneRelations(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        while ($reflection) {
            if ($reflection->hasProperty('has_one')) {
                $property = $reflection->getProperty('has_one');
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
     * Find a record by Name (preferred) or Title field using exact and partial match.
     * Only tries fields that actually exist on the class.
     */
    private function findRelatedRecord(string $class, string $value): ?DataObject
    {
        $dbFields = $class::config()->get('db') ?? [];
        $fields = [];

        // Prefer Name, then Title (only if the DB field actually exists)
        if (isset($dbFields['Name'])) {
            $fields[] = 'Name';
        }
        if (isset($dbFields['Title'])) {
            $fields[] = 'Title';
        }

        foreach ($fields as $field) {
            // Try exact match first
            $record = DataObject::get($class)->filter($field, $value)->first();
            if ($record && $record->exists()) {
                return $record;
            }
            // Then partial match
            $record = DataObject::get($class)->filter($field . ':PartialMatch', $value)->first();
            if ($record && $record->exists()) {
                return $record;
            }
        }

        return null;
    }
}
