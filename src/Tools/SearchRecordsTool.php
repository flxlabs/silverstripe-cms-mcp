<?php

namespace Icecaster\CmsMcp\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Icecaster\CmsMcp\MCP\AbstractTool;
use Icecaster\CmsMcp\MCP\ToolException;

class SearchRecordsTool extends AbstractTool
{
    private const MAX_LIMIT = 100;

    public function getName(): string
    {
        return 'search_records';
    }

    public function getDescription(): string
    {
        return 'Search/list records of a class. Optionally filter by a case-insensitive '
            . 'substring match against a specific field (e.g. Title). Results only include '
            . 'records the acting member can view, and only their id/title/summary fields — '
            . 'call get_record for full field values.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
                'query' => ['type' => 'string', 'description' => 'Optional substring to search for'],
                'field' => [
                    'type' => 'string',
                    'description' => 'Field to search "query" against. Defaults to Title.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results, default 20, capped at ' . self::MAX_LIMIT,
                ],
            ],
            'required' => ['class'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $class = $this->requireExposableClass($arguments);
        $field = is_string($arguments['field'] ?? null) ? $arguments['field'] : 'Title';
        $query = is_string($arguments['query'] ?? null) ? $arguments['query'] : null;
        $limit = min(self::MAX_LIMIT, max(1, (int) ($arguments['limit'] ?? 20)));

        if (!$this->classExposure()->isFieldExposable($class, $field)
            || !DataObject::getSchema()->fieldSpec($class, $field)
        ) {
            throw new ToolException("\"$field\" is not a searchable field on $class");
        }

        $list = DataObject::get($class);
        if ($query !== null && $query !== '') {
            $list = $list->filter("{$field}:PartialMatch", $query);
        }

        $versioned = DataObject::singleton($class)->hasExtension(Versioned::class);
        $results = [];

        foreach ($list->limit($limit * 4) as $record) {
            if (count($results) >= $limit) {
                break;
            }
            if (!$record->canView($actingMember)) {
                continue;
            }

            $row = [
                'id' => $record->ID,
                'title' => (string) ($record->hasField('Title') ? $record->Title : $record->getTitle()),
            ];
            if ($versioned) {
                $row['isPublished'] = $record->isPublished();
                $row['isModifiedOnDraft'] = $record->isModifiedOnDraft();
            }
            $results[] = $row;
        }

        return $this->jsonResult(['class' => $class, 'results' => $results]);
    }
}
