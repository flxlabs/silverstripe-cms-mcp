<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\ORM\DataObject;
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
            . 'record can be published again later. For Fluent-localized records the '
            . 'default locale is unpublished unless a "locale" is provided.';
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
                    'description' => 'Optional locale code (e.g. "fr_FR") to unpublish. '
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

        // Non-Fluent records have a single draft/live pair, so there is no locale to switch to.
        if (!$this->isFluentVersioned($class)) {
            if ($requestedLocale !== null) {
                throw new ToolException(
                    "$class is not a Fluent-localized class; the \"locale\" argument does not apply."
                );
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

        $locale = $this->resolveLocale($requestedLocale);

        $result = self::FLUENT_STATE::singleton()->withState(
            function ($state) use ($class, $record, $locale, $actingMember) {
                $state->setLocale($locale);

                // Load a fresh instance in the target locale, matching the CMS
                // "unpublish in this locale" action.
                /** @var DataObject $fresh */
                $fresh = DataObject::get($class)->byID($record->ID);

                if (
                    !$fresh
                    || (!$fresh->isDraftedInLocale($locale) && !$fresh->isPublishedInLocale($locale))
                ) {
                    throw new ToolException(
                        "$class record {$record->ID} does not exist in locale $locale."
                    );
                }

                if (!$fresh->canUnpublish($actingMember)) {
                    throw new ToolException(
                        "Permission denied: this member cannot unpublish this $class record in locale $locale"
                    );
                }

                $fresh->doUnpublish();

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
