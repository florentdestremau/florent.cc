<?php

namespace App\Content;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Lit les pages et articles Markdown du dossier content/.
 */
final class ContentRepository
{
    /** @var list<Post>|null */
    private ?array $posts = null;

    public function __construct(
        private readonly MarkdownParser $parser,
        #[Autowire('%kernel.project_dir%/content')]
        private readonly string $contentDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function page(string $name): Page
    {
        [$frontMatter, $html] = $this->parser->parseFile($this->contentDir.'/'.$name.'.md');

        return new Page($frontMatter['title'], $frontMatter['description'], $html);
    }

    /**
     * Articles publiés, du plus ancien au plus récent.
     *
     * @return list<Post>
     */
    public function publishedPosts(): array
    {
        return array_values(array_filter($this->allPosts(), static fn (Post $post) => !$post->draft));
    }

    /**
     * Brouillons, visibles uniquement hors production.
     *
     * @return list<Post>
     */
    public function drafts(): array
    {
        if ('prod' === $this->environment) {
            return [];
        }

        return array_values(array_filter($this->allPosts(), static fn (Post $post) => $post->draft));
    }

    public function post(string $year, string $month, string $slug): ?Post
    {
        foreach ([...$this->publishedPosts(), ...$this->drafts()] as $post) {
            if ($post->routeParameters() === ['year' => $year, 'month' => $month, 'slug' => $slug]) {
                return $post;
            }
        }

        return null;
    }

    /**
     * @return list<Post>
     */
    private function allPosts(): array
    {
        if (null !== $this->posts) {
            return $this->posts;
        }

        $posts = [];
        foreach ((new Finder())->files()->in($this->contentDir.'/posts')->path('#^\d{4}/\d{2}/#')->name('*.md') as $file) {
            [$year, $month] = explode('/', $file->getRelativePath());
            [$frontMatter, $html] = $this->parser->parseFile($file->getPathname());

            $posts[] = new Post(
                year: $year,
                month: $month,
                slug: $file->getFilenameWithoutExtension(),
                title: $frontMatter['title'],
                description: $frontMatter['description'],
                date: $this->parseDate($frontMatter['date']),
                html: $html,
                image: $frontMatter['image'] ?? null,
                imageAlt: $frontMatter['imageAlt'] ?? null,
                draft: $frontMatter['draft'] ?? false,
            );
        }

        usort($posts, static fn (Post $a, Post $b) => [$a->date, $a->slug] <=> [$b->date, $b->slug]);

        return $this->posts = $posts;
    }

    private function parseDate(int|string $date): \DateTimeImmutable
    {
        // Le parseur YAML convertit les dates nues (2026-08-08) en timestamp.
        return \is_int($date)
            ? new \DateTimeImmutable('@'.$date)
            : new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }
}
