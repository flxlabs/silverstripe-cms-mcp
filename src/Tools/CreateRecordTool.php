<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\FieldIntrospector;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class CreateRecordTool extends AbstractTool
{
    public function getName(): string
    {
        return 'create_record';
    }

    public function getDescription(): string
    {
        return 'Create a new database record of any MCP-exposed class. '
            . 'Pass the class name (from list_classes) and a map of field values. '
            . 'If the user does not specify a class and the available classes have subclasses '
            . '(e.g. SiteTree has Page, NewsHolder, NewsPage), ask the user which class to use. '
            . 'For CMS pages, use "Page" (not "SiteTree") to ensure the record gets the '
            . 'correct Page class name with page sections support. '
            . 'Writes to the draft stage only — call publish_record to make the change live. '
            . 'For Fluent-localized classes, pass "locale" to create in a specific locale.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => [
                    'type' => 'string',
                    'description' => 'A class returned by list_classes. For CMS pages, use "Page" (not "SiteTree") '
                        . 'to ensure correct Page class name with page sections support.',
                ],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Map of field name to value (e.g. {"Title": "My Record"})',
                ],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Optional locale code for Fluent-localized classes (e.g. "fr_FR"). '
                        . 'If omitted, uses the current locale.',
                ],
                'subsiteId' => [
                    'type' => 'integer',
                    'description' => 'Optional subsite ID to create within. If omitted, uses the current subsite.',
                ],
            ],
            'required' => ['class', 'fields'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $class = $this->requireExposableClass($arguments);
        $locale = $arguments['locale'] ?? null;
        $subsiteId = isset($arguments['subsiteId']) ? (int) $arguments['subsiteId'] : null;

        // Auto-resolve SiteTree to Page for CMS page creation
        if ($class === 'SilverStripe\\CMS\\Model\\SiteTree') {
            $class = 'Page';
        }

        return $this->withSubsite($subsiteId, function () use ($arguments, $actingMember, $class, $locale) {
            return $this->withLocale($class, $locale, function () use ($arguments, $actingMember, $class) {
                if (!singleton($class)->canCreate($actingMember)) {
                    throw new ToolException("Permission denied: cannot create $class");
                }

                if (!isset($arguments['fields']) || !is_array($arguments['fields'])) {
                    throw new ToolException('Missing required argument "fields" (an object of fieldName => value)');
                }

                $editable = FieldIntrospector::singleton()->describeEditableFields($class);
                $invalid = array_diff(array_keys($arguments['fields']), array_keys($editable));
                if ($invalid) {
                    throw new ToolException(
                        'Not editable: ' . implode(', ', $invalid)
                            . '. Call list_fields to see valid fields.'
                    );
                }

                $record = $class::create();

                foreach ($arguments['fields'] as $name => $value) {
                    $record->setField($name, $this->castValue($editable[$name]['type'], $value));
                }

                $record->writeToStage(Versioned::DRAFT);

                return $this->jsonResult([
                    'class' => $class,
                    'id' => $record->ID,
                    'fields' => array_keys($arguments['fields']),
                ]);
            });
        });
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
