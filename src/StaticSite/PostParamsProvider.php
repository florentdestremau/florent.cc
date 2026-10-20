<?php

namespace App\StaticSite;

use App\Content\ContentRepository;
use Bakslash\StaticSiteBundle\ParamsProvider\ParamsProviderInterface;

final readonly class PostParamsProvider implements ParamsProviderInterface
{
    public function __construct(private ContentRepository $content)
    {
    }

    public function provideParameterSets(): iterable
    {
        foreach ([...$this->content->publishedPosts(), ...$this->content->drafts()] as $post) {
            yield $post->routeParameters();
        }
    }
}
