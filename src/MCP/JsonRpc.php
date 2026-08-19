<?php

namespace Icecaster\CmsMcp\MCP;

/**
 * JSON-RPC 2.0 envelope helpers. See https://www.jsonrpc.org/specification
 */
class JsonRpc
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * @param string|int|null $id
     */
    public static function success($id, $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param string|int|null $id
     */
    public static function error($id, int $code, string $message, $data = null): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }
}
