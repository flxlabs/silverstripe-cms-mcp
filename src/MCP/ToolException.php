<?php

namespace FLXLabs\CmsMcp\MCP;

/**
 * Thrown by a Tool to signal a user-facing failure. Caught by ToolRegistry::callTool()
 * and turned into an MCP tool result with isError=true, rather than a JSON-RPC error —
 * per the MCP spec, tool execution failures are reported *in* the result so the LLM can
 * see and react to them.
 */
class ToolException extends \RuntimeException {}
