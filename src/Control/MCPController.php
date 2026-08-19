<?php

namespace Icecaster\CmsMcp\Control;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use Icecaster\CmsMcp\CMSMCPPermissionProvider;
use Icecaster\CmsMcp\MCP\JsonRpc;
use Icecaster\CmsMcp\MCP\ToolRegistry;
use Icecaster\CmsMcp\Model\MCPAccessToken;
use Throwable;

/**
 * MCP "Streamable HTTP" transport endpoint, mounted at /mcp.
 *
 * Implements the JSON-response variant of the transport (a single JSON-RPC request per
 * POST, answered with a single JSON-RPC response) rather than the optional SSE streaming
 * variant — every tool call here is a fast, synchronous DB operation, so there's nothing
 * that benefits from a long-lived stream.
 *
 * Every request is authenticated independently via a Bearer token (Authorization header),
 * resolved to a Member by MCPAccessToken::authenticate(). That Member must hold the
 * CMS_MCP_ACCESS permission, and every tool call is additionally permission-checked
 * against that same Member's normal canView/canEdit/canPublish rules per record.
 */
class MCPController extends Controller
{
    private static array $allowed_actions = ['index'];

    private static string $protocol_version = '2025-06-18';

    public function index(HTTPRequest $request): HTTPResponse
    {
        return match ($request->httpMethod()) {
            'POST' => $this->handlePost($request),
            'DELETE' => $this->jsonResponse(204, ''),
            default => $this->jsonResponse(405, '', ['Allow' => 'POST, DELETE']),
        };
    }

    private function handlePost(HTTPRequest $request): HTTPResponse
    {
        $member = $this->authenticate($request);
        if (!$member) {
            return $this->authError(401, 'invalid_token', 'Missing or invalid bearer token');
        }

        if (!Permission::checkMember($member, CMSMCPPermissionProvider::ACCESS)) {
            return $this->authError(403, 'insufficient_scope', 'Member lacks the CMS_MCP_ACCESS permission');
        }

        $payload = json_decode($request->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            return $this->rpcResponse(200, JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Invalid JSON'));
        }

        $id = $payload['id'] ?? null;
        $method = $payload['method'] ?? null;
        $params = $payload['params'] ?? [];

        if (!is_string($method) || $method === '') {
            return $this->rpcResponse(200, JsonRpc::error($id, JsonRpc::INVALID_REQUEST, 'Missing "method"'));
        }

        // JSON-RPC notifications (no "id") get no response body per spec.
        $isNotification = !array_key_exists('id', $payload);

        try {
            // All tools read/write the draft stage by default, matching what an editor sees
            // in the CMS — publish/unpublish/rollback tools explicitly target Live themselves
            // regardless of this ambient reading mode.
            $result = Versioned::withVersionedMode(function () use ($method, $params, $member) {
                Versioned::set_stage(Versioned::DRAFT);
                return $this->dispatch($method, is_array($params) ? $params : [], $member);
            });
        } catch (UnknownMethodException $e) {
            if ($isNotification) {
                return $this->jsonResponse(202, '');
            }
            return $this->rpcResponse(200, JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, $e->getMessage()));
        } catch (Throwable $e) {
            if ($isNotification) {
                return $this->jsonResponse(202, '');
            }
            return $this->rpcResponse(200, JsonRpc::error($id, JsonRpc::INTERNAL_ERROR, $e->getMessage()));
        }

        if ($isNotification) {
            return $this->jsonResponse(202, '');
        }

        $response = $this->rpcResponse(200, JsonRpc::success($id, $result));

        if ($method === 'initialize') {
            // Echoed back for spec compatibility. This server is stateless — every request
            // is independently authenticated via the bearer token — so the session id is
            // not otherwise validated or persisted server-side.
            $response->addHeader('Mcp-Session-Id', bin2hex(random_bytes(16)));
        }

        return $response;
    }

    /**
     * @throws UnknownMethodException
     */
    private function dispatch(string $method, array $params, Member $member): array
    {
        $registry = ToolRegistry::singleton();

        switch ($method) {
            case 'initialize':
                return [
                    'protocolVersion' => static::config()->get('protocol_version'),
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => [
                        'name' => 'silverstripe-cms-mcp',
                        'version' => '1.0.0',
                    ],
                ];

            case 'ping':
                return [];

            case 'tools/list':
                return ['tools' => $registry->getToolDefinitions()];

            case 'tools/call':
                $name = $params['name'] ?? null;
                if (!is_string($name) || $name === '') {
                    throw new UnknownMethodException('tools/call requires a "name" parameter');
                }
                if (!$registry->hasTool($name)) {
                    throw new UnknownMethodException("Unknown tool: $name");
                }
                $arguments = $params['arguments'] ?? [];
                return $registry->callTool($name, is_array($arguments) ? $arguments : [], $member);

            case 'resources/list':
                return ['resources' => []];

            case 'prompts/list':
                return ['prompts' => []];

            default:
                throw new UnknownMethodException("Method not found: $method");
        }
    }

    private function authenticate(HTTPRequest $request): ?Member
    {
        $header = $request->getHeader('Authorization') ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return MCPAccessToken::authenticate($matches[1]);
    }

    private function rpcResponse(int $statusCode, array $body): HTTPResponse
    {
        return $this->jsonResponse($statusCode, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    private function jsonResponse(int $statusCode, string $body, array $headers = []): HTTPResponse
    {
        $response = HTTPResponse::create($body, $statusCode);
        $response->addHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $response->addHeader($name, $value);
        }
        return $response;
    }

    private function authError(int $statusCode, string $error, string $description): HTTPResponse
    {
        $response = $this->jsonResponse($statusCode, json_encode([
            'error' => $error,
            'error_description' => $description,
        ]));
        if ($statusCode === 401) {
            $response->addHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
        }
        return $response;
    }
}
