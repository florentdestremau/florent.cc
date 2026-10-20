<?php

namespace App\Controller;

use App\Content\ContentRepository;
use App\StaticSite\PostParamsProvider;
use Bakslash\StaticSiteBundle\Attribute\Prerender;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Prerender]
final class SiteController extends AbstractController
{
    public function __construct(private readonly ContentRepository $content)
    {
    }

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->render('home.html.twig', [
            'page' => $this->content->page('index'),
            'posts' => array_reverse($this->content->publishedPosts()),
            'drafts' => array_reverse($this->content->drafts()),
        ]);
    }

    #[Route('/about/', name: 'about')]
    public function about(): Response
    {
        return $this->render('page.html.twig', [
            'page' => $this->content->page('about'),
        ]);
    }

    #[Prerender(params: PostParamsProvider::class)]
    #[Route('/posts/{year}/{month}/{slug}/', name: 'post', requirements: ['year' => '\d{4}', 'month' => '\d{2}'])]
    public function post(string $year, string $month, string $slug): Response
    {
        $post = $this->content->post($year, $month, $slug) ?? throw $this->createNotFoundException();

        $posts = $this->content->publishedPosts();
        $index = array_search($post, $posts, true);

        return $this->render('post.html.twig', [
            'post' => $post,
            'previous' => false !== $index ? $posts[$index - 1] ?? null : null,
            'next' => false !== $index ? $posts[$index + 1] ?? null : null,
        ]);
    }

    #[Route('/sitemap.xml', name: 'sitemap', format: 'xml')]
    public function sitemap(): Response
    {
        return $this->render('sitemap.xml.twig', [
            'posts' => $this->content->publishedPosts(),
        ]);
    }
}
