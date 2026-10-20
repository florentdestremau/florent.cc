<?php

namespace App\Content;

final readonly class Post
{
    public function __construct(
        public string $year,
        public string $month,
        public string $slug,
        public string $title,
        public string $description,
        public \DateTimeImmutable $date,
        public string $html,
        public ?string $image = null,
        public ?string $imageAlt = null,
        public bool $draft = false,
    ) {
    }

    /**
     * @return array{year: string, month: string, slug: string}
     */
    public function routeParameters(): array
    {
        return ['year' => $this->year, 'month' => $this->month, 'slug' => $this->slug];
    }
}
