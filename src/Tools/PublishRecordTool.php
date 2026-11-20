<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\ORM\DataObject;
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
            . 'newly uploaded files) to the live site. For Fluent-localized records the '
            . 'default locale is published unless a "locale" is provided.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
                'id' => ['type' => 'integer', 'description' => 'Record ID'],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Optional locale code (e.g. "fr_FR") to publish. '
                        . 'Defaults to the default Fluent locale.',
                ],
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

        $requestedLocale = isset($arguments['locale']) ? $this->requireString($arguments, 'locale') : null;

        // Non-Fluent records (and Versioned classes that only have the non-versioned Fluent
        // extension) have a single draft/live pair, so there is no locale to switch to.
        if (!$this->isFluentVersioned($class)) {
            if ($requestedLocale !== null) {
                throw new ToolException(
                    "$class is not a Fluent-localized class; the \"locale\" argument does not apply."
                );
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

        $locale = $this->resolveLocale($requestedLocale);

        $result = self::FLUENT_STATE::singleton()->withState(
            function ($state) use ($class, $record, $locale, $actingMember) {
                $state->setLocale($locale);

                // Load a fresh instance in the target locale, as the CMS "publish in this
                // locale" action does. The instance loaded in another locale carries that
                // locale's field values and must not be used to publish this one.
                /** @var DataObject $fresh */
                $fresh = DataObject::get($class)->byID($record->ID);

                if (!$fresh || !$fresh->isDraftedInLocale($locale)) {
                    throw new ToolException(
                        "$class record {$record->ID} does not exist in locale $locale. "
                            . 'Call create_translation first.'
                    );
                }

                if (!$fresh->canPublish($actingMember)) {
                    throw new ToolException(
                        "Permission denied: this member cannot publish this $class record in locale $locale"
                    );
                }

                $fresh->publishRecursive();

                return [
                    'locale' => $locale,
                    'isPublished' => $fresh->isPublished(),
                ];
            }
        );

        return $this->jsonResult([
            'class' => $class,
            'id' => $record->ID,
            'locale' => $result['locale'],
            'isPublished' => $result['isPublished'],
        ]);
    }
}
