<?php

namespace Icecaster\CmsMcp\MCP;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;

class ToolRegistry
{
    use Injectable;

    /** @var Tool[] Populated via YAML injector config (mcp.yml). */
    public array $Tools = [];

    public function getToolDefinitions(): array
    {
        $definitions = [];
        foreach ($this->Tools as $tool) {
            $definitions[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }
        return $definitions;
    }

    public function hasTool(string $name): bool
    {
        return $this->findTool($name) !== null;
    }

    /**
     * @return array{content: array, isError: bool}
     */
    public function callTool(string $name, array $arguments, Member $actingMember): array
    {
        $tool = $this->findTool($name);
        if (!$tool) {
            throw new InvalidArgumentException("Unknown tool: $name");
        }

        try {
            return [
                'content' => $tool->call($arguments, $actingMember),
                'isError' => false,
            ];
        } catch (ToolException $e) {
            return [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    private function findTool(string $name): ?Tool
    {
        foreach ($this->Tools as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }
        return null;
    }
}
