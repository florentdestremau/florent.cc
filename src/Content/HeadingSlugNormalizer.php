<?php

namespace App\Content;

use League\CommonMark\Normalizer\TextNormalizerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Génère les id des titres comme le faisait l'IdAttributePlugin d'Eleventy, pour garder les ancres existantes.
 */
final class HeadingSlugNormalizer implements TextNormalizerInterface
{
    private AsciiSlugger $slugger;

    public function __construct()
    {
        $this->slugger = new AsciiSlugger('fr');
    }

    public function normalize(string $text, array $context = []): string
    {
        return $this->slugger->slug($text)->lower()->toString();
    }
}
