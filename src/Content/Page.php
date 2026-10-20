<?php

namespace App\Content;

final readonly class Page
{
    public function __construct(
        public string $title,
        public string $description,
        public string $html,
    ) {
    }
}
