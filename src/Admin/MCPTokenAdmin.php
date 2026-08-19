<?php

namespace Icecaster\CmsMcp\Admin;

use SilverStripe\Admin\ModelAdmin;
use Icecaster\CmsMcp\Model\MCPAccessToken;

class MCPTokenAdmin extends ModelAdmin
{
    private static string $url_segment = 'mcp-tokens';

    private static string $menu_title = 'MCP Tokens';

    private static string $menu_icon_class = 'font-icon-key';

    private static array $managed_models = [
        MCPAccessToken::class,
    ];

    private static string $required_permission_codes = 'ADMIN';
}
