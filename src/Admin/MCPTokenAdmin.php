<?php

namespace FLXLabs\CmsMcp\Admin;

use SilverStripe\Admin\ModelAdmin;
use FLXLabs\CmsMcp\Model\MCPAccessToken;

class MCPTokenAdmin extends ModelAdmin
{
    private static string $url_segment = 'mcp-tokens';

    private static string $menu_title = 'MCP Tokens';

    private static string $menu_icon_class = 'font-icon-lock';

    private static array $managed_models = [
        MCPAccessToken::class,
    ];

    private static string $required_permission_codes = 'ADMIN';
}
