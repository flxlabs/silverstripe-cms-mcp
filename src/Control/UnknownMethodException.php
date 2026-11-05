<?php

namespace FLXLabs\CmsMcp\Control;

/**
 * Thrown when a JSON-RPC "method" isn't recognised, or tools/call names an unknown tool.
 * Caught in MCPController and turned into a JSON-RPC -32601 error.
 */
class UnknownMethodException extends \RuntimeException {}
