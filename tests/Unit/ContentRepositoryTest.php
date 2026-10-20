<?php

namespace App\Tests\Unit;

use App\Content\ContentRepository;
use App\Content\MarkdownParser;
use App\Content\Post;
use PHPUnit\Framework\TestCase;

final class ContentRepositoryTest extends TestCase
{
    private const string CONTENT_DIR = __DIR__.'/../Fixtures/content';

    public function testPublishedPostsAreSortedFromOldestToNewestWithoutDrafts(): void
    {
        $posts = $this->repository('prod')->publishedPosts();

        self::assertSame(['premier-article', 'second-article'], array_map(static fn (Post $post) => $post->slug, $posts));
    }

    public function testPostIsBuiltFromItsPathAndFrontMatter(): void
    {
        $post = $this->repository('prod')->post('2024', '03', 'second-article');

        self::assertNotNull($post);
        self::assertSame('Second article', $post->title);
        self::assertSame('Le plus récent', $post->description);
        self::assertSame('2024-03-02', $post->date->format('Y-m-d'));
        self::assertSame('/img/couverture.jpg', $post->image);
        self::assertSame('Une couverture', $post->imageAlt);
        self::assertSame(['year' => '2024', 'month' => '03', 'slug' => 'second-article'], $post->routeParameters());
    }

    public function testDraftsAreHiddenInProd(): void
    {
        $repository = $this->repository('prod');

        self::assertSame([], $repository->drafts());
        self::assertNull($repository->post('2024', '02', 'brouillon'));
    }

    public function testDraftsAreVisibleOutsideProd(): void
    {
        $repository = $this->repository('dev');

        self::assertSame(['brouillon'], array_map(static fn (Post $post) => $post->slug, $repository->drafts()));
        self::assertNotNull($repository->post('2024', '02', 'brouillon'));
    }

    public function testUnknownPost(): void
    {
        self::assertNull($this->repository('prod')->post('2024', '01', 'inconnu'));
        self::assertNull($this->repository('prod')->post('2024', '02', 'premier-article'));
    }

    public function testPage(): void
    {
        $page = $this->repository('prod')->page('about');

        self::assertSame('À propos de test', $page->title);
        self::assertSame('Description de la page à propos', $page->description);
        self::assertStringContainsString('À propos', $page->html);
    }

    private function repository(string $environment): ContentRepository
    {
        return new ContentRepository(new MarkdownParser(), self::CONTENT_DIR, $environment);
    }
}
