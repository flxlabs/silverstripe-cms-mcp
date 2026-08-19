<?php

namespace Icecaster\CmsMcp\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Icecaster\CmsMcp\FieldIntrospector;
use Icecaster\CmsMcp\MCP\AbstractTool;

class GetRecordTool extends AbstractTool
{
    public function getName(): string
    {
        return 'get_record';
    }

    public function getDescription(): string
    {
        return 'Fetch the current draft field values of a single record by class and id, '
            . 'including any attached files. Reads the draft stage, which may differ from '
            . 'what is currently published — use get_record_status to check.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
                'id' => ['type' => 'integer', 'description' => 'Record ID'],
            ],
            'required' => ['class', 'id'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $record = $this->getViewableRecord($arguments, $actingMember);
        $class = get_class($record);
        $introspector = FieldIntrospector::singleton();

        $fields = [];
        foreach ($introspector->describeEditableFields($class) as $name => $meta) {
            $fields[$name] = $record->getField($name);
        }

        $files = [];
        foreach ($introspector->describeFileFields($class) as $name => $meta) {
            $file = $record->getComponent($name);
            $files[$name] = ($file && $file->exists())
                ? ['id' => $file->ID, 'title' => $file->Title, 'url' => $file->getAbsoluteURL()]
                : null;
        }

        $result = [
            'class' => $class,
            'id' => $record->ID,
            'fields' => $fields,
            'files' => $files,
        ];

        if ($record->hasExtension(Versioned::class)) {
            $result['versioning'] = [
                'isPublished' => $record->isPublished(),
                'isModifiedOnDraft' => $record->isModifiedOnDraft(),
                'draftVersion' => (int) $record->Version,
            ];
        }

        return $this->jsonResult($result);
    }
}
