<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class UnpublishRecordTool extends AbstractTool
{
    public function getName(): string
    {
        return 'unpublish_record';
    }

    public function getDescription(): string
    {
        return 'Remove a record from the live site. The draft version is kept, so the '
            . 'record can be published again later.';
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
            throw new ToolException("$class is not versioned; there is no draft/publish workflow to use.");
        }

        if (!$record->canUnpublish($actingMember)) {
            throw new ToolException("Permission denied: this member cannot unpublish this $class record");
        }

        $record->doUnpublish();

        return $this->jsonResult([
            'class' => $class,
            'id' => $record->ID,
            'isPublished' => $record->isPublished(),
        ]);
    }
}
