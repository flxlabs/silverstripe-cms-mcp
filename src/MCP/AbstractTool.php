<?php

namespace FLXLabs\CmsMcp\MCP;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use FLXLabs\CmsMcp\ClassExposure;
use SilverStripe\Core\Config\Configurable;

abstract class AbstractTool implements Tool
{
    use Configurable;
    // Lazy-load subsite classes only if the module is installed
    private const SUBSITE_MODEL = 'SilverStripe\\Subsites\\Model\\Subsite';
    private const SUBSITE_STATE = 'SilverStripe\\Subsites\\State\\SubsiteState';

    // Lazy-load fluent classes only if the module is installed
    private const FLUENT_EXTENSION = 'TractorCow\\Fluent\\Extension\\FluentExtension';
    private const FLUENT_LOCALE = 'TractorCow\\Fluent\\Model\\Locale';
    private const FLUENT_STATE = 'TractorCow\\Fluent\\State\\FluentState';
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

    /**
     * Execute a callback optionally within a specific Fluent locale.
     * If $locale is provided and the class uses FluentVersionedExtension,
     * switches the FluentState to that locale before calling $callback.
     *
     * @param string   $class    The DataObject class
     * @param string|null $locale Locale code (e.g. "fr_FR"), or null for current locale
     * @param callable $callback The operation to run
     * @return mixed Result of callback
     */
    protected function withLocale(string $class, ?string $locale, callable $callback)
    {
        // If no locale specified, just run the callback
        if ($locale === null) {
            return $callback();
        }

        // If Fluent module is not installed, just run the callback
        if (!class_exists(self::FLUENT_EXTENSION, true)) {
            return $callback();
        }

        $singleton = DataObject::singleton($class);

        // If the class doesn't use Fluent, just run the callback
        if (!$singleton->hasExtension(self::FLUENT_EXTENSION)) {
            return $callback();
        }

        // Validate the locale exists
        $existingLocale = self::FLUENT_LOCALE::get()->filter('Locale', $locale)->first();
        if (!$existingLocale) {
            $available = self::FLUENT_LOCALE::get()->column('Locale');
            throw new ToolException(
                "Locale \"$locale\" not found. Available locales: " . implode(', ', $available)
            );
        }

        // Switch to the requested locale and run the callback
        return self::FLUENT_STATE::singleton()->withState(function ($state) use ($locale, $callback) {
            $state->setLocale($locale);
            return $callback();
        });
    }

    /**
     * Execute a callback optionally within a specific Subsite context.
     * If $subsiteId is provided, switches the SubsiteState to that subsite before calling $callback.
     *
     * @param int|null $subsiteId Subsite ID (0 = main site), or null for current subsite
     * @param callable $callback The operation to run
     * @return mixed Result of callback
     */
    protected function withSubsite(?int $subsiteId, callable $callback)
    {
        // If no subsite specified, just run the callback
        if ($subsiteId === null) {
            return $callback();
        }

        // If Subsites module is not installed, just run the callback
        if (!class_exists(self::SUBSITE_MODEL, true)) {
            return $callback();
        }

        // Validate the subsite exists
        $subsite = self::SUBSITE_MODEL::get()->byID($subsiteId);
        if (!$subsite) {
            $available = self::SUBSITE_MODEL::get()->column('ID');
            throw new ToolException(
                "Subsite ID $subsiteId not found. Available IDs: " . implode(', ', $available)
            );
        }

        // Switch to the requested subsite and run the callback
        return self::SUBSITE_STATE::singleton()->withState(function ($state) use ($subsiteId, $callback) {
            $state->setSubsiteId($subsiteId);
            return $callback();
        });
    }
}
