<?php

namespace FLXLabs\CmsMcp;

use SilverStripe\Security\PermissionProvider;

class CMSMCPPermissionProvider implements PermissionProvider
{
    public const ACCESS = 'CMS_MCP_ACCESS';

    public function providePermissions()
    {
        return [
            self::ACCESS => [
                'name' => 'Access the CMS via an MCP client (AI agent)',
                'category' => 'CMS MCP',
                'help' => 'The member a token belongs to must have this permission in addition to the '
                    . 'token being valid. Every MCP tool call is further restricted by that member\'s '
                    . 'normal canView/canEdit/canPublish permissions on each record.',
            ],
        ];
    }
}
