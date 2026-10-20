<?php

namespace App\Tests\Unit;

use App\Twig\AppExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AppExtensionTest extends TestCase
{
    public function testDateFr(): void
    {
        self::assertSame('12 février 2023', (new AppExtension())->dateFr(new \DateTimeImmutable('2023-02-12')));
    }

    #[DataProvider('readingTimes')]
    public function testReadingTime(int $words, string $expected): void
    {
        $html = '<p>'.str_repeat('mot ', $words).'</p>';

        self::assertSame($expected, (new AppExtension())->readingTime($html));
    }

    /**
     * @return iterable<array{int, string}>
     */
    public static function readingTimes(): iterable
    {
        yield 'contenu vide' => [0, '1 min'];
        yield 'exactement 200 mots' => [200, '1 min'];
        yield 'arrondi au supérieur' => [201, '2 min'];
    }

    public function testReadingTimeIgnoresTags(): void
    {
        self::assertSame('1 min', (new AppExtension())->readingTime(str_repeat('<span class="hl-keyword">x</span>', 200)));
    }

    #[DataProvider('headingIds')]
    public function testHeadingId(string $title, string $expected): void
    {
        self::assertSame($expected, (new AppExtension())->headingId($title));
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function headingIds(): iterable
    {
        // Ancres produites par Eleventy, à conserver pour ne pas casser les liens existants.
        yield ['Mes articles', 'mes-articles'];
        yield ['How to translate a Symfony and React project.', 'how-to-translate-a-symfony-and-react-project'];
        yield ["Le worktree, c'est génial jusqu'à ce qu'on veuille le lancer", 'le-worktree-c-est-genial-jusqu-a-ce-qu-on-veuille-le-lancer'];
        yield ['Étape 1 bis : Mise pour figer les versions par projet', 'etape-1-bis-mise-pour-figer-les-versions-par-projet'];
        yield ['Ce que ça change au quotidien', 'ce-que-ca-change-au-quotidien'];
    }
}
