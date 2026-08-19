<?php

namespace Icecaster\CmsMcp\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Icecaster\CmsMcp\FieldIntrospector;
use Icecaster\CmsMcp\MCP\AbstractTool;
use Icecaster\CmsMcp\MCP\ToolException;

class UpdateRecordTool extends AbstractTool
{
    public function getName(): string
    {
        return 'update_record';
    }

    public function getDescription(): string
    {
        return 'Update one or more text/html/number/boolean/date fields on a record. Only '
            . 'fields returned by list_fields\'s editableFields may be set — File/Image '
            . 'fields must go through upload_file instead. Writes to the draft stage only; '
            . 'call publish_record to make the change live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
                'id' => ['type' => 'integer', 'description' => 'Record ID'],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Map of field name to new value, e.g. {"Title": "New title"}',
                ],
            ],
            'required' => ['class', 'id', 'fields'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $record = $this->getViewableRecord($arguments, $actingMember);
        $class = get_class($record);

        if (!$record->canEdit($actingMember)) {
            throw new ToolException("Permission denied: this member cannot edit this $class record");
        }

        if (!isset($arguments['fields']) || !is_array($arguments['fields'])) {
            throw new ToolException('Missing required argument "fields" (an object of fieldName => value)');
        }

        $editable = FieldIntrospector::singleton()->describeEditableFields($class);
        $invalid = array_diff(array_keys($arguments['fields']), array_keys($editable));
        if ($invalid) {
            throw new ToolException(
                'Not editable via update_record: ' . implode(', ', $invalid)
                . '. Call list_fields to see valid fields.'
            );
        }

        foreach ($arguments['fields'] as $name => $value) {
            $record->setField($name, $this->castValue($editable[$name]['type'], $value));
        }

        $record->hasExtension(Versioned::class) ? $record->writeToStage(Versioned::DRAFT) : $record->write();

        return $this->jsonResult([
            'class' => $class,
            'id' => $record->ID,
            'updated' => array_keys($arguments['fields']),
        ]);
    }

    private function castValue(string $type, $value)
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            default => (string) $value,
        };
    }
}
