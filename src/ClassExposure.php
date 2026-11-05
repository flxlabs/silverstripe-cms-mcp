<?php

namespace FLXLabs\CmsMcp;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use FLXLabs\CmsMcp\Extensions\MCPExposableExtension;

/**
 * Decides which DataObject classes may be read/written through MCP tools.
 *
 * A class is exposable if it (or an ancestor) is in `allowed_classes`, or it has the
 * MCPExposableExtension applied, PROVIDED it (or no ancestor) is in `denied_classes`.
 * Deny always wins over allow, so project code can't accidentally expose Member etc.
 * by widening allowed_classes carelessly.
 */
class ClassExposure
{
    use Injectable;
    use Configurable;

    private static array $allowed_classes = [];

    private static array $denied_classes = [];

    private static array $global_denied_fields = [];

    private static array $denied_fields = [];

    public function isExposable(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, DataObject::class)) {
            return false;
        }

        if ((new \ReflectionClass($class))->isAbstract()) {
            return false;
        }

        if ($this->matchesAncestor($class, (array) $this->config()->get('denied_classes'))) {
            return false;
        }

        if ($this->matchesAncestor($class, (array) $this->config()->get('allowed_classes'))) {
            return true;
        }

        $instance = DataObject::singleton($class);
        return (bool) $instance->hasExtension(MCPExposableExtension::class);
    }

    /**
     * @return string[] All concrete DataObject subclasses currently exposable via MCP.
     */
    public function getExposableClasses(): array
    {
        $classes = [];
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            if ($this->isExposable($class)) {
                $classes[] = $class;
            }
        }
        sort($classes);
        return $classes;
    }

    public function isFieldExposable(string $class, string $field): bool
    {
        $denied = array_merge(
            (array) $this->config()->get('global_denied_fields'),
            (array) ($this->config()->get('denied_fields')[$class] ?? [])
        );

        return !in_array($field, $denied, true);
    }

    private function matchesAncestor(string $class, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($class === $candidate || is_subclass_of($class, $candidate)) {
                return true;
            }
        }
        return false;
    }
}
