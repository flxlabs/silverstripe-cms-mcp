<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\FieldIntrospector;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;
use TractorCow\Fluent\State\FluentState;

class CreateTranslationTool extends AbstractTool
{
    public function getName(): string
    {
        return 'create_translation';
    }

    public function getDescription(): string
    {
        return 'Create a Fluent locale translation of an existing record in a locale where it '
            . 'does not exist yet (initialised from the record\'s current values), or update the '
            . 'existing translation in that locale. Use this when update_record with a "locale" '
            . 'fails because the translation does not exist yet — it creates the missing '
            . 'translation row (same record ID in all locales) and applies "fields" in the same '
            . 'call. Omit "fields" to simply create the translation from the record\'s current '
            . 'values. Only works on Fluent-localized classes. Writes to the draft stage only — '
            . 'call publish_record (with the same locale) to make the translation live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A Fluent-localized class returned by list_classes'],
                'id' => ['type' => 'integer', 'description' => 'Record ID (the same ID in all locales)'],
                'locale' => ['type' => 'string', 'description' => 'Target locale code (e.g. "fr_FR")'],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Optional map of field name to new value applied on the target-locale '
                        . 'copy, e.g. {"Subtitle": "..."}. Only fields from list_fields\'s editableFields.',
                ],
                'subsiteId' => [
                    'type' => 'integer',
                    'description' => 'Optional subsite ID to query within. If omitted, uses the current subsite.',
                ],
            ],
            'required' => ['class', 'id', 'locale'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $class = $this->requireExposableClass($arguments);
        $id = $this->requireInt($arguments, 'id');
        $locale = $this->requireString($arguments, 'locale');
        $subsiteId = isset($arguments['subsiteId']) ? (int) $arguments['subsiteId'] : null;

        // The class must be Fluent-localized
        $singleton = DataObject::singleton($class);
        if (!class_exists(self::FLUENT_EXTENSION::class) || !$singleton->hasExtension(self::FLUENT_EXTENSION::class)) {
            throw new ToolException(
                "$class is not a Fluent-localized class. create_translation only works on classes "
                    . 'with FluentExtension or FluentVersionedExtension.'
            );
        }

        // Validate the locale exists
        $localeModel = self::FLUENT_LOCALE::get()->filter('Locale', $locale)->first();
        if (!$localeModel) {
            $available = self::FLUENT_LOCALE::get()->column('Locale');
            throw new ToolException(
                "Locale \"$locale\" not found. Available locales: " . implode(', ', $available)
            );
        }

        return $this->withSubsite($subsiteId, function () use ($arguments, $actingMember, $class, $id, $locale) {
            // Phase 1: load the record in the current (default) locale and check permissions
            $record = $this->getViewableRecord(['class' => $class, 'id' => $id], $actingMember);

            if (!$record->canEdit($actingMember)) {
                throw new ToolException("Permission denied: this member cannot edit this $class record");
            }

            // Validate + prepare the field updates (same rules as update_record)
            $fields = $arguments['fields'] ?? [];
            if (!is_array($fields)) {
                throw new ToolException('"fields" must be an object of fieldName => value');
            }
            $editable = FieldIntrospector::singleton()->describeEditableFields($class);
            $invalid = array_diff(array_keys($fields), array_keys($editable));
            if ($invalid) {
                throw new ToolException(
                    'Not editable via create_translation: ' . implode(', ', $invalid)
                        . '. Call list_fields to see valid fields.'
                );
            }

            $translationExists = $record->existsInLocale($locale);

            // Nothing to do: translation already exists and no fields were provided
            if ($translationExists && empty($fields)) {
                return $this->jsonResult([
                    'class' => $class,
                    'id' => $id,
                    'locale' => $locale,
                    'created' => false,
                    'updated' => [],
                    'note' => "Translation already exists in $locale and no fields were provided; nothing to do.",
                ]);
            }

            // Phase 2: perform the write inside the target locale
            self::FLUENT_STATE::singleton()->withState(function ($state) use (
                $record,
                $translationExists,
                $fields,
                $editable,
                $class,
                $id,
                $locale
            ) {
                $state->setLocale($locale);

                if ($translationExists) {
                    // Load the existing translation so we update its values, not the base ones
                    $target = DataObject::get($class)->byID($id);
                    if (!$target) {
                        throw new ToolException("No $class record found with id $id in locale $locale");
                    }
                } else {
                    // Writing the record loaded in the default locale while the FluentState is in
                    // the target locale materialises the translation row (same mechanism as the
                    // CMS "copy to locale"): onBeforeWrite() detects the missing locale row and
                    // forces a change, creating the localised record for the target locale.
                    $target = $record;
                }

                foreach ($fields as $name => $value) {
                    $target->setField($name, $this->castValue($editable[$name]['type'], $value));
                }

                if ($target->hasExtension(Versioned::class)) {
                    $target->writeToStage(Versioned::DRAFT);
                } else {
                    $target->forceChange();
                    $target->write();
                }
            });

            return $this->jsonResult([
                'class' => $class,
                'id' => $id,
                'locale' => $locale,
                'created' => !$translationExists,
                'updated' => array_keys($fields),
            ]);
        });
    }

    private function castValue(string $type, $value)
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            default => (string) $value,
        };
    }
}
