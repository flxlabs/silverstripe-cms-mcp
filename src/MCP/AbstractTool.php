<?php

namespace FLXLabs\CmsMcp\MCP;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use FLXLabs\CmsMcp\ClassExposure;
use SilverStripe\Core\Config\Configurable;

abstract class AbstractTool implements Tool
{
    use Configurable;

    protected function classExposure(): ClassExposure
    {
        return ClassExposure::singleton();
    }

    protected function textResult(string $text): array
    {
        return [['type' => 'text', 'text' => $text]];
    }

    protected function jsonResult($data): array
    {
        return [['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]];
    }

    protected function requireString(array $arguments, string $key): string
    {
        if (!isset($arguments[$key]) || !is_string($arguments[$key]) || $arguments[$key] === '') {
            throw new ToolException("Missing required argument \"$key\"");
        }
        return $arguments[$key];
    }

    protected function requireInt(array $arguments, string $key): int
    {
        if (!isset($arguments[$key]) || !is_numeric($arguments[$key])) {
            throw new ToolException("Missing required argument \"$key\"");
        }
        return (int) $arguments[$key];
    }

    /**
     * Resolve {class, id} to a record, enforcing class exposure + canView.
     */
    protected function getViewableRecord(array $arguments, Member $member): DataObject
    {
        $class = $this->requireExposableClass($arguments);
        $id = $this->requireInt($arguments, 'id');

        $record = DataObject::get($class)->byID($id);
        if (!$record) {
            throw new ToolException("No $class record found with id $id");
        }

        if (!$record->canView($member)) {
            throw new ToolException("Permission denied: this member cannot view this $class record");
        }

        return $record;
    }

    protected function requireExposableClass(array $arguments): string
    {
        $class = $this->requireString($arguments, 'class');

        if (!$this->classExposure()->isExposable($class)) {
            throw new ToolException(
                "Class \"$class\" is not exposed via MCP. Call list_classes to see what's available."
            );
        }

        return $class;
    }
}
