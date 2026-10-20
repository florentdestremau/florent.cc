# Florent Destremau

Site statique généré par une app Symfony avec [bakslash/static-site-bundle](https://github.com/bakslashHQ/static-site-bundle).

- `content/` : pages et articles en Markdown (frontmatter YAML), un article par fichier `content/posts/AAAA/MM/slug.md`.
  Un article avec `draft: true` n'apparaît qu'en local.
- `templates/` : templates Twig.
- `public/` : CSS, images et fichiers servis tels quels.

## Install

```
composer install
composer serve
```

Le site tourne sur http://localhost:8000.

## Build

```
composer build
```

Le site est écrit dans `build/`.

## TODO

- Merchants of complexity: k8s vs déployer soi-même
- Le marketing de PHP et Symfony
- Le positionnement bizarre des LiveComponents (sérialization): sérialisation et intégration avec les turbo-frames
- Le no build a de l'attrait, même si l'IA code bien avec tailwindcss
- Faut-il encore traduire son site ?
- Recrutement des juniors
- Trick: turbo-frame, lazy-loading, turbo-stream pour vos dropdown complexes
- Usage pg_textsearch
- Déployer en prod ne doit pas être une crainte
