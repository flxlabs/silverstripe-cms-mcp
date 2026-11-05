<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;

class RollbackRecordTool extends AbstractTool
{
    public function getName(): string
    {
        return 'rollback_record';
    }

    public function getDescription(): string
    {
        return 'Roll the draft back to an earlier version (from get_version_history), or to '
            . '"live" to discard draft changes and match what\'s currently published. Only '
            . 'affects the draft stage — call publish_record afterwards to make it live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
                'id' => ['type' => 'integer', 'description' => 'Record ID'],
                'version' => [
                    'description' => 'A version number from get_version_history, or the string "live"',
                ],
            ],
            'required' => ['class', 'id', 'version'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $record = $this->getViewableRecord($arguments, $actingMember);
        $class = get_class($record);

        if (!$record->hasExtension(Versioned::class)) {
            throw new ToolException("$class is not versioned; there is no version history to roll back to.");
        }

        if (!$record->canEdit($actingMember)) {
            throw new ToolException("Permission denied: this member cannot edit this $class record");
        }

        $rawVersion = $arguments['version'] ?? null;
        if (is_string($rawVersion) && strtolower($rawVersion) === 'live') {
            $version = Versioned::LIVE;
        } elseif (is_numeric($rawVersion)) {
            $version = (int) $rawVersion;
        } else {
            throw new ToolException('"version" must be a version number or the string "live"');
        }

        $record->rollbackRecursive($version);

        return $this->jsonResult([
            'class' => $class,
            'id' => $record->ID,
            'rolledBackTo' => $rawVersion,
        ]);
    }
}
