<?php

namespace Icecaster\CmsMcp\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Icecaster\CmsMcp\MCP\AbstractTool;
use Icecaster\CmsMcp\MCP\ToolException;

class RecordStatusTool extends AbstractTool
{
    public function getName(): string
    {
        return 'get_record_status';
    }

    public function getDescription(): string
    {
        return 'Check the draft/live status of a record: whether it\'s published, has '
            . 'unpublished draft changes, or exists only on one stage.';
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

        if (!$record->hasExtension(Versioned::class)) {
            throw new ToolException("$class is not versioned; it has no draft/live distinction.");
        }

        $liveVersion = Versioned::get_versionnumber_by_stage($class, Versioned::LIVE, $record->ID);

        return $this->jsonResult([
            'class' => $class,
            'id' => $record->ID,
            'isPublished' => $record->isPublished(),
            'isModifiedOnDraft' => $record->isModifiedOnDraft(),
            'isOnDraftOnly' => $record->isOnDraftOnly(),
            'isOnLiveOnly' => $record->isOnLiveOnly(),
            'isArchived' => $record->isArchived(),
            'draftVersion' => (int) $record->Version,
            'liveVersion' => $liveVersion ?: null,
        ]);
    }
}
