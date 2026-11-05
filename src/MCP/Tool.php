<?php

namespace FLXLabs\CmsMcp\MCP;

use SilverStripe\Security\Member;

/**
 * A single MCP tool. Implementations should be stateless services (registered via the
 * ToolRegistry.Tools injector property) — all request-specific state comes in through
 * call()'s arguments and the authenticated Member.
 */
interface Tool
{
    /** Machine name used in tools/call, e.g. "get_record". */
    public function getName(): string;

    /** Shown to the LLM to help it decide when/how to use this tool. */
    public function getDescription(): string;

    /** JSON Schema (object) describing the arguments accepted by call(). */
    public function getInputSchema(): array;

    /**
     * @param array $arguments Decoded JSON arguments, already validated against getInputSchema()
     *                         by the caller only for basic JSON-RPC shape — tools must still
     *                         validate their own business rules.
     * @param Member $actingMember The member the calling token belongs to. Every permission
     *                              check (canView/canEdit/canPublish/...) must be run against
     *                              this member, never against Security::getCurrentUser().
     * @return array MCP "content" array, e.g. [['type' => 'text', 'text' => '...']]
     * @throws ToolException For any user-facing failure (not found, permission denied,
     *                        invalid arguments). The message is returned to the LLM as
     *                        an error result — do not include secrets in it.
     */
    public function call(array $arguments, Member $actingMember): array;
}
