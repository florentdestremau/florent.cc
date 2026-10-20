<?php

namespace App\Twig;

use App\Content\HeadingSlugNormalizer;
use Twig\Attribute\AsTwigFilter;

final class AppExtension
{
    /**
     * Ex. : 12 février 2023.
     */
    #[AsTwigFilter('date_fr')]
    public function dateFr(\DateTimeInterface $date): string
    {
        return (new \IntlDateFormatter('fr_FR', pattern: 'd MMMM y', timezone: 'Europe/Paris'))->format($date);
    }

    /**
     * Temps de lecture estimé, à 200 mots par minute.
     */
    #[AsTwigFilter('reading_time')]
    public function readingTime(string $html): string
    {
        $words = preg_split('/\s+/u', strip_tags($html), flags: \PREG_SPLIT_NO_EMPTY);

        return max(1, (int) ceil(\count($words) / 200)).' min';
    }

    /**
     * Id d'ancre d'un titre, identique à celui des titres Markdown.
     */
    #[AsTwigFilter('heading_id')]
    public function headingId(string $text): string
    {
        return (new HeadingSlugNormalizer())->normalize($text);
    }
}
