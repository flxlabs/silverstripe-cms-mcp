<?php

namespace Icecaster\CmsMcp\Extensions;

use SilverStripe\ORM\DataExtension;

/**
 * Marker extension. Applying this to a DataObject's `extensions` config opts it into
 * MCP exposure without needing to edit the module's ClassExposure.allowed_classes config.
 * Presence is checked via hasExtension() — it deliberately adds no behaviour.
 */
class MCPExposableExtension extends DataExtension
{
}
