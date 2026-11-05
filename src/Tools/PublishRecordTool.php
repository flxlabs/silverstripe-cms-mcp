<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class PublishRecordTool extends AbstractTool
{
    public function getName(): string
    {
        return 'publish_record';
    }

    public function getDescription(): string
    {
        return 'Publish the current draft of a record (and any owned relations, e.g. '
            . 'newly uploaded files) to the live site.';
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

        if (!$record->canPublish($actingMember)) {
            throw new ToolException("Permission denied: this member cannot publish this $class record");
        }

        $record->publishRecursive();

        return $this->jsonResult([
            'class' => $class,
            'id' => $record->ID,
            'isPublished' => $record->isPublished(),
        ]);
    }
}
