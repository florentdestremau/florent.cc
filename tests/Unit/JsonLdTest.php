<?php

namespace App\Tests\Unit;

use App\Content\Post;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class JsonLdTest extends KernelTestCase
{
    public function testTitleCannotCloseTheJsonLdScript(): void
    {
        self::bootKernel();
        $request = Request::create('/posts/2024/01/test/');
        $request->attributes->set('_route', 'post');
        $request->attributes->set('_route_params', ['year' => '2024', 'month' => '01', 'slug' => 'test']);
        self::getContainer()->get(RequestStack::class)->push($request);

        $html = self::getContainer()->get(Environment::class)->render('post.html.twig', [
            'post' => new Post('2024', '01', 'test', '</script><script>alert(1)</script>', 'Description', new \DateTimeImmutable('2024-01-01'), '<p>Contenu</p>'),
            'previous' => null,
            'next' => null,
        ]);

        self::assertStringNotContainsString('<script>alert(1)', $html);
    }
}
