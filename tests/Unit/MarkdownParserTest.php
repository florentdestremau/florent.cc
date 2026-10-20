<?php

namespace App\Tests\Unit;

use App\Content\MarkdownParser;
use PHPUnit\Framework\TestCase;

final class MarkdownParserTest extends TestCase
{
    private const string POST = __DIR__.'/../Fixtures/content/posts/2024/01/premier-article.md';

    public function testFrontMatter(): void
    {
        [$frontMatter] = (new MarkdownParser())->parseFile(self::POST);

        self::assertSame('Premier article', $frontMatter['title']);
        self::assertSame('Le plus ancien', $frontMatter['description']);
    }

    public function testHeadingsGetUniqueIdsWithoutPermalink(): void
    {
        [, $html] = (new MarkdownParser())->parseFile(self::POST);

        self::assertStringContainsString('<h2 id="un-titre-accentue-ca-marche">', $html);
        self::assertStringContainsString('<h2 id="un-titre-accentue-ca-marche-1">', $html);
        self::assertStringNotContainsString('heading-permalink', $html);
    }

    public function testCodeBlocksAreHighlightedAndKeepTwigSyntax(): void
    {
        [, $html] = (new MarkdownParser())->parseFile(self::POST);

        self::assertStringContainsString('<pre data-lang="twig"', $html);
        self::assertStringContainsString('{{', $html);
        self::assertStringContainsString('home', $html);
    }
}
