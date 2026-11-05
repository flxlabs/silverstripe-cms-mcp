<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class VersionHistoryTool extends AbstractTool
{
    private const MAX_LIMIT = 50;

    public function getName(): string
    {
        return 'get_version_history';
    }

    public function getDescription(): string
    {
        return 'List recent versions of a record: who edited it, when, and whether that '
            . 'version was published. Use the version number with rollback_record to revert.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
                'id' => ['type' => 'integer', 'description' => 'Record ID'],
                'limit' => ['type' => 'integer', 'description' => 'Max versions, default 10, capped at ' . self::MAX_LIMIT],
            ],
            'required' => ['class', 'id'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $record = $this->getViewableRecord($arguments, $actingMember);
        $class = get_class($record);

        if (!$record->hasExtension(Versioned::class)) {
            throw new ToolException("$class is not versioned; there is no version history to read.");
        }

        $limit = min(self::MAX_LIMIT, max(1, (int) ($arguments['limit'] ?? 10)));
        $versions = [];

        foreach ($record->Versions('', '"Version" DESC', $limit) as $version) {
            $author = $version->AuthorID ? Member::get()->byID($version->AuthorID) : null;
            $versions[] = [
                'version' => (int) $version->Version,
                'lastEdited' => $version->LastEdited,
                'wasPublished' => (bool) $version->WasPublished,
                'author' => $author ? $author->Title : null,
            ];
        }

        return $this->jsonResult(['class' => $class, 'id' => $record->ID, 'versions' => $versions]);
    }
}
