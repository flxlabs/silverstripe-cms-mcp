<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Security\Member;
use FLXLabs\CmsMcp\FieldIntrospector;
use FLXLabs\CmsMcp\MCP\AbstractTool;

class ListFieldsTool extends AbstractTool
{
    public function getName(): string
    {
        return 'list_fields';
    }

    public function getDescription(): string
    {
        return 'Describe the writable fields on a class: text/html/number/boolean/date fields '
            . '(editable via update_record) and File/Image relations (settable via upload_file). '
            . 'Call this before update_record so you know valid field names and types.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
            ],
            'required' => ['class'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $class = $this->requireExposableClass($arguments);
        $introspector = FieldIntrospector::singleton();

        return $this->jsonResult([
            'class' => $class,
            'editableFields' => array_values($introspector->describeEditableFields($class)),
            'fileFields' => array_values($introspector->describeFileFields($class)),
        ]);
    }
}
