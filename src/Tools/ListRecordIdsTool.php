<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class ListRecordIdsTool extends AbstractTool
{
    public function getName(): string
    {
        return 'list_record_ids';
    }

    public function getDescription(): string
    {
        return 'List all IDs and Titles of a DataObject. Returns every record the acting member can view, '
            . 'including draft and live versions. For Fluent-localized classes, pass "locale" '
            . 'to filter results to a specific locale. Pass "subsiteId" to filter to a specific subsite.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => [
                    'type' => 'string',
                    'description' => 'DataObject, e.g. Page or PageElement',
                ],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Optional locale code for Fluent-localized classes (e.g. "fr_FR"). '
                        . 'If omitted, uses the current locale.',
                ],
                'subsiteId' => [
                    'type' => 'integer',
                    'description' => 'Optional subsite ID to query within. If omitted, uses the current subsite.',
                ],
            ],
            'required' => ['class'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $elementClass = $this->requireExposableClass($arguments);
        $locale = $arguments['locale'] ?? null;
        $subsiteId = isset($arguments['subsiteId']) ? (int) $arguments['subsiteId'] : null;

        return $this->withSubsite($subsiteId, function () use ($arguments, $actingMember, $elementClass, $locale) {
            return $this->withLocale($elementClass, $locale, function () use ($arguments, $actingMember, $elementClass) {
                $list = DataObject::get($elementClass);
                $ids = [];

                foreach ($list as $record) {
                    if (!$record->canView($actingMember)) {
                        continue;
                    }
                    $ids[] = ['id' => $record->ID, 'Title' => (string) $record->Title];
                }

                return $this->jsonResult([
                    'class' => $elementClass,
                    'count' => count($ids),
                    'ids' => $ids,
                ]);
            });
        });
    }
}
