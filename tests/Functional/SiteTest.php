<?php

namespace App\Tests\Functional;

use App\Content\ContentRepository;
use App\Content\Post;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vérifie le rendu du vrai contenu du site.
 */
final class SiteTest extends WebTestCase
{
    public function testHomeListsEveryPublishedPostFromNewestToOldest(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1#mes-articles');

        $expected = array_map(
            static fn (Post $post) => \sprintf('/posts/%s/%s/%s/', $post->year, $post->month, $post->slug),
            array_reverse(self::getContainer()->get(ContentRepository::class)->publishedPosts()),
        );
        self::assertSame($expected, $crawler->filter('#mes-articles + article a')->extract(['href']));
    }

    public function testAbout(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'À propos de moi');
    }

    public function testAboutWithoutTrailingSlashRedirects(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about');

        self::assertResponseRedirects('http://localhost/about/', 301);
    }

    #[DataProvider('posts')]
    public function testPost(string $year, string $month, string $slug): void
    {
        $client = self::createClient();
        $post = self::getContainer()->get(ContentRepository::class)->post($year, $month, $slug);
        $crawler = $client->request('GET', "/posts/$year/$month/$slug/");

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('title', $post->title);
        self::assertSelectorTextSame('.content > h1', $post->title);
        self::assertSame($post->description, $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame('article', $crawler->filter('meta[property="og:type"]')->attr('content'));
        self::assertSame("http://localhost/posts/$year/$month/$slug/", $crawler->filter('link[rel="canonical"]')->attr('href'));

        $jsonLd = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('BlogPosting', $jsonLd['@type']);
        self::assertSame($post->title, $jsonLd['headline']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function posts(): iterable
    {
        foreach (glob(__DIR__.'/../../content/posts/*/*/*.md') as $file) {
            [$year, $month, $name] = \array_slice(explode('/', $file), -3);
            $slug = basename($name, '.md');

            yield "$year/$month/$slug" => [$year, $month, $slug];
        }
    }

    public function testPostNavigationLinksToNeighbours(): void
    {
        $client = self::createClient();
        $posts = self::getContainer()->get(ContentRepository::class)->publishedPosts();
        [$previous, $current, $next] = \array_slice($posts, 0, 3);

        $crawler = $client->request('GET', \sprintf('/posts/%s/%s/%s/', $current->year, $current->month, $current->slug));

        self::assertStringEndsWith("/$previous->slug/", $crawler->filter('a[rel="prev"]')->attr('href'));
        self::assertStringEndsWith("/$next->slug/", $crawler->filter('a[rel="next"]')->attr('href'));
    }

    public function testUnknownPostIsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/posts/2026/01/inconnu/');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSitemapListsEveryPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/xml; charset=UTF-8');

        $sitemap = simplexml_load_string($client->getResponse()->getContent());
        self::assertNotFalse($sitemap);
        self::assertCount(2 + \count(self::getContainer()->get(ContentRepository::class)->publishedPosts()), $sitemap->url);
    }
}
