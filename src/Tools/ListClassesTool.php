<?php

namespace FLXLabs\CmsMcp\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\MCP\AbstractTool;

class ListClassesTool extends AbstractTool
{
    public function getName(): string
    {
        return 'list_classes';
    }

    public function getDescription(): string
    {
        return 'List the DataObject classes exposed via MCP on this site. Call this first to '
            . 'discover what content types exist before using other tools, which all take a '
            . '"class" argument that must be one of these.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $classes = [];

        foreach ($this->classExposure()->getExposableClasses() as $class) {
            $singleton = DataObject::singleton($class);
            $classes[] = [
                'class' => $class,
                'singularName' => $singleton->i18n_singular_name(),
                'pluralName' => $singleton->i18n_plural_name(),
                'versioned' => $singleton->hasExtension(Versioned::class),
            ];
        }

        return $this->jsonResult(['classes' => $classes]);
    }
}
