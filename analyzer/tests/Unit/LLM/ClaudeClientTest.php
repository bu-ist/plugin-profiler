<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\LLM;

use PHPUnit\Framework\TestCase;
use PluginProfiler\LLM\ClaudeClient;

class ClaudeClientTest extends TestCase
{
    /**
     * Access the private parseDescriptions method via reflection for unit testing.
     *
     * @return array<string, string>
     */
    private function callParseDescriptions(string $raw): array
    {
        $client = new ClaudeClient('test-key');
        $ref    = new \ReflectionMethod($client, 'parseDescriptions');
        $ref->setAccessible(true);

        return $ref->invoke($client, $raw);
    }

    public function testParseDescriptions_WithPlainJson_ReturnsMap(): void
    {
        $raw    = '{"class_Foo": "Foo handles widgets.", "func_bar": "Bar runs on init."}';
        $result = $this->callParseDescriptions($raw);

        $this->assertSame('Foo handles widgets.', $result['class_Foo']);
        $this->assertSame('Bar runs on init.', $result['func_bar']);
    }

    public function testParseDescriptions_WithMarkdownFences_StripsAndParses(): void
    {
        $raw    = "```json\n{\"class_Foo\": \"A widget class.\"}\n```";
        $result = $this->callParseDescriptions($raw);

        $this->assertSame('A widget class.', $result['class_Foo']);
    }

    public function testParseDescriptions_WithLeadingText_ExtractsJsonObject(): void
    {
        $raw    = "Here are the descriptions:\n{\"class_Foo\": \"Does something.\"}";
        $result = $this->callParseDescriptions($raw);

        $this->assertSame('Does something.', $result['class_Foo']);
    }

    public function testParseDescriptions_WithInvalidJson_ReturnsEmpty(): void
    {
        $result = $this->callParseDescriptions('not json at all');

        $this->assertSame([], $result);
    }

    public function testParseDescriptions_FiltersNonStringValues(): void
    {
        $raw    = '{"class_Foo": "Valid.", "class_Bar": 42, "class_Baz": null}';
        $result = $this->callParseDescriptions($raw);

        $this->assertArrayHasKey('class_Foo', $result);
        $this->assertArrayNotHasKey('class_Bar', $result);
        $this->assertArrayNotHasKey('class_Baz', $result);
    }

    public function testClaudeClient_ImplementsInterface(): void
    {
        $client = new ClaudeClient('test-key');
        $this->assertInstanceOf(\PluginProfiler\LLM\LLMClientInterface::class, $client);
    }
}
