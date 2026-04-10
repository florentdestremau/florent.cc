---
title: "De l'idée à la production en quelques minutes : FrankenPHP + Once pour déployer Symfony"
date: 2026-04-09
description: "Comment déployer une application Symfony en production en quelques minutes avec FrankenPHP et Once : un conteneur unique, SSL automatique, et un pipeline CI simple pour suivre le rythme du vibe-coding."

---

## L'ère du vibe-coding ou le besoin compulsif de shipper du code

Même si le terme est un peu décrié aujourd'hui, le fait de développer avec une **assistance IA** s'impose de plus en plus. Sur des gros projets existants, le gain de performance n'est pas toujours flagrant car il y a beaucoup de passif historique. En revanche, **pour** des **nouveaux projets**, il n'y a pas de doute que ça permet d'aboutir à des versions plus ou moins minimalistes d'applications web en quelques heures/jours d'efforts à peine.

Du coup, si le code s'écrit de plus en plus vite, l'**infrastructure doit suivre**. Sinon, le goulot d'étranglement se déplace juste d'un cran. Autant il existe aujourd'hui beaucoup d'outils pour faire du déploiement en continu sur des projets existants, autant maintenant la problématique devient de pouvoir déployer un **nouveau projet** de manière automatique le plus vite possible.

Et pour ça, on passe souvent par quelques étapes obligatoires : DNS, mise à disposition d'un VPS, repo GitHub, échange de clés SSH, provisioning... Ça marche bien, et une grosse partie peut être automatisée. On peut utiliser des solutions _entreprise_ pour ce genre de choses. Mais si le but est vraiment de faire plein de projets divers qu'on va laisser tourner au cas où, tout mettre sur la même machine paraît quand même **plus économique** et plus pragmatique : en somme, avoir sa grosse sandbox en ligne.

## Once : l'outil de déploiement self-hosted de 37signals

[37signals](https://37signals.com), les créateurs de [Basecamp](https://basecamp.com/), Hey et Ruby on Rails, ont une longue histoire de publication open source d'outils qu'ils utilisent eux-mêmes. Leur dernier en date : **[Once](https://github.com/basecamp/once)**, un outil de déploiement d'application en auto-hébergé. Once repose sur Docker et [Kamal](https://kamal-deploy.org) pour orchestrer les conteneurs. Le principe : un serveur, autant d'applications que l'on veut, zéro gestion manuelle du routing ou des certificats. Chaque application doit être une image Docker et ensuite once s'occupe du reverse proxy, du SSL et des redémarrages propres.

L'installation sur un VPS est rapide :

```bash
curl https://get.once.com | sh
```

Le script télécharge le binaire adapté à la plateforme (Linux principalement, n'essayez pas sur Windows...), installe le service en arrière-plan, et Docker si nécessaire. 

## Le wildcard DNS : n'importe quelle app disponible instantanément

L'astuce qui rend Once vraiment fluide : pointer un sous-domaine wildcard vers le serveur.

```
*.example.com → IP du VPS
```

Une fois cette entrée DNS en place, chaque nouvelle application qu'on déploie via Once est accessible immédiatement sur `mon-app.example.com`, **sans toucher au DNS** ni configurer un reverse proxy. Once détecte le domaine à partir du nom de l'application, provisionne le certificat Let's Encrypt automatiquement, et c'est prêt.

Résultat concret : on déploie une nouvelle idée en quelques minutes depuis le premier `git push`.

## PHP dans ce contexte : adopter Docker

Je l'admets, j'étais moi-même un peu récalcitrant à l'idée de dockeriser mes applications PHP. Le `symfony serve` local fonctionne très bien, et ajouter Docker semblait souvent ajouter une couche de complexité sans bénéfice immédiat.

Ce qui a changé, c'est l'IA. Générer et déboguer un Dockerfile est devenu trivial. Le vrai apport, c'est de comprendre ce qu'on fait — et là, **FrankenPHP** change la donne.

## FrankenPHP : un conteneur pour tout

[FrankenPHP](https://frankenphp.dev) est un runtime PHP qui embarque PHP directement dans [Caddy](https://caddyserver.com). Caddy gère le routing HTTP, le SSL, et intègre nativement un hub Mercure pour le temps réel. **Un seul conteneur** remplace ce qui était habituellement trois services séparés : Nginx (ou Apache), PHP-FPM, et un hub Mercure.

### Le Dockerfile Symfony

```dockerfile
FROM composer:2 AS composer

FROM dunglas/frankenphp:1-php8.4-alpine AS base

WORKDIR /app

# Extensions système requises
RUN apk add --no-cache acl && \
    install-php-extensions \
        intl \
        opcache \
        pdo_sqlite \
        zip

COPY --link frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY --link frankenphp/conf.d/app.ini $PHP_INI_DIR/conf.d/app.ini

# ─── Build ──────────────────────────────────────────────────────────────
FROM base AS builder

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV APP_ENV=prod APP_DEBUG=0 APP_SECRET=buildsecret

COPY --link composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress \
    && rm -rf ~/.composer/cache

COPY --link . .

RUN composer dump-autoload --optimize --no-dev && \
    php bin/console importmap:install && \
    php bin/console asset-map:compile && \
    php bin/console cache:warmup --env=prod

# ─── Image finale ───────────────────────────────────────────────────────
FROM base AS final

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    DATABASE_URL="sqlite:////storage/data_prod.db"

# Utilisateur non-root + répertoires Caddy/FrankenPHP
RUN addgroup -S -g 1000 php && adduser -S -u 1000 -G php php && \
    mkdir -p /storage /data/caddy /config/caddy && \
    chown -R 1000:1000 /storage /data /config

USER 1000:1000

# Données SQLite sur un volume persistant
VOLUME /storage

COPY --chown=1000:1000 --from=builder --link /app /app

ENTRYPOINT ["/app/frankenphp/docker-entrypoint.sh"]

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
```

Il s'agit d'un build multi-stage standard. La seule différence avec un Dockerfile PHP classique : l'image de base est `dunglas/frankenphp`. Tout le reste est du Symfony habituel.

L'entrypoint gère les migrations automatiquement au démarrage :

```bash
#!/bin/sh
php bin/console doctrine:migrations:migrate --no-interaction
exec "$@"
```

### Le Caddyfile

```caddyfile
{
    frankenphp
    order php_server before file_server
}

:80 {
    root * /app/public

    mercure {
        publisher_jwt {env.MERCURE_JWT_SECRET} HS256
        subscriber_jwt {env.MERCURE_JWT_SECRET} HS256
        anonymous
        subscriptions
    }

    @mercure path /.well-known/mercure*
    handle @mercure {}

    php_server
    encode zstd br gzip
}
```

Si l'application n'utilise pas Mercure, le bloc `mercure { }` disparaît. Pour une application Symfony classique, le Caddyfile se réduit à quelques lignes.

L'image Docker est prête. Reste à l'envoyer sur le registry GitHub et à configurer Once pour surveiller les mises à jour.

## Le pipeline complet : push → production

Une fois le serveur configuré avec Once et le wildcard DNS en place, le workflow ressemble à ça :

1. GitHub Actions build l'image Docker à chaque merge sur `master`
2. L'image est poussée sur le registry **GitHub**
3. Once détecte la nouvelle image et redéploie au moins une fois par jour, ou bien on peut forcer l'update

```yaml
name: Docker Build & Push

on:
  push:
    branches: [ master ]

env:
  REGISTRY: ghcr.io
  IMAGE_NAME: ${{ github.repository }}

jobs:
  build-and-push:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Log in to Registry
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY }}
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build and push
        uses: docker/build-push-action@v6
        with:
          context: .
          push: true
          tags: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}:master
```

Il y a des variables d'environnement à configurer dans Once :

```
APP_SECRET=<random>
MERCURE_JWT_SECRET=<clé de 256 bits minimum>
MERCURE_PUBLIC_URL=https://mon-projet.example.com/.well-known/mercure
```

Un volume `/storage` persiste la base SQLite entre les redéploiements. Pour des projets qui n'ont pas besoin d'une base de données externe, c'est suffisant et en local c'est trivial à utiliser, notamment pour un agent IA à travers un **MCP**.


## Ce que cette stack rend possible

En combinant Once sur un VPS wildcard, FrankenPHP, et un pipeline CI simple, le **coût de déploiement** d'une nouvelle idée en Symfony devient **négligeable**. On crée un repo, on configure deux secrets GitHub, on `git push` — et l'application est en production avec HTTPS, migrations appliquées, et Mercure disponible si besoin — le tout générable en quelques minutes par un LLM.

De mon côté, j'ai créé un skill MCP pour Claude localement que je compte pouvoir publier dans les semaines à venir. J'ai aussi essayé de copier une app PHP en Rails, et il faut avouer qu'ils ont des valeurs par défaut sur le Dockerfile, la CI GitHub, etc. qui donnent envie d'avoir un équivalent standardisé dans l'écosystème Symfony.
