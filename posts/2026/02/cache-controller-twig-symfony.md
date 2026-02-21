---
title: Partager le pool de cache entre controller et Twig en Symfony
date: 2026-02-21
description: Comment faire fonctionner le cache du controller Symfony et la balise {% cache %} de Twig sur le même pool, avec une seule ligne dans services.yaml.
---

# Partager le pool de cache entre controller et Twig en Symfony

Symfony propose deux endroits naturels pour mettre du cache applicatif : dans les controllers via `CacheInterface`, et dans les templates via la balise `{% cache %}` de `twig/cache-extra`. Ces deux mécanismes fonctionnent très bien séparément, mais par défaut ils n'utilisent pas le même pool de cache.

Ce cloisonnement est souvent invisible au départ, puis devient gênant dès qu'on veut une stratégie de cache cohérente sur l'ensemble de l'application. La solution tient en trois lignes de config.

## Le problème

Côté controller, on injecte `CacheInterface` pour mettre en cache le résultat d'un calcul ou d'une requête :

```php
public function index(CacheInterface $cache): Response
{
    $data = $cache->get('my_key', function (ItemInterface $item) {
        $item->expiresAfter(300);
        return $this->expensiveComputation();
    });

    return $this->render('my_template.html.twig', ['data' => $data]);
}
```

Côté Twig, `twig/cache-extra` permet de cacher des blocs de template :

```twig
{% cache 'my_block' ttl(300) %}
    {# rendu coûteux #}
    {% for item in data %}
        {{ item.name }}
    {% endfor %}
{% endcache %}
```

Ces deux systèmes sont indépendants. Le controller utilise `cache.app`, Twig utilise son propre service `twig.cache`. Deux pools séparés, deux configurations séparées, deux backends potentiellement différents.

## La solution : un alias dans `services.yaml`

Il suffit de rediriger `twig.cache` vers le même pool que celui du controller :

```yaml
# config/services.yaml
services:
    twig.cache:
        alias: cache.app
        public: true
```

C'est tout. `twig/cache-extra` utilise désormais exactement le même pool que `CacheInterface` dans vos controllers. Même backend, même configuration, même TTL par défaut si vous en avez un.

## Ce que ça change concrètement

Avant cet alias, si vous vidiez `cache.app` (via `bin/console cache:pool:clear cache.app` par exemple), le cache Twig restait intact. Même chose dans l'autre sens.

Après l'alias, les deux sont synchronisés. Vider le pool vide tout. Configurer un backend Redis vaut pour les deux. Ça simplifie aussi le monitoring : un seul pool à surveiller, une seule métrique de hit/miss.

C'est également utile quand on fait du cache warming : on peut préchauffer le cache applicatif et savoir que Twig bénéficiera du même pool sans configuration supplémentaire.

## Configurer le bon adaptateur

Pour que tout fonctionne, votre pool `cache.app` doit pointer vers l'adaptateur de votre choix. En production, Redis est souvent le bon choix :

```yaml
# config/packages/cache.yaml
framework:
    cache:
        app: cache.adapter.redis
        default_redis_provider: '%env(REDIS_URL)%'
```

En développement, l'adaptateur filesystem par défaut convient très bien — et l'alias `twig.cache` fonctionne avec lui aussi.

## Bonus : aller plus loin avec les tags

Si vous voulez une invalidation ciblée (invalider le cache d'un objet spécifique sans tout vider), vous pouvez remplacer `cache.app` par `cache.app.taggable` dans l'alias :

```yaml
services:
    twig.cache:
        alias: cache.app.taggable
        public: true
```

`cache.app.taggable` est un wrapper créé automatiquement par Symfony autour de `cache.app`, qui ajoute le support des tags. Vous pouvez alors taguer vos entrées dans le controller :

```php
$data = $cache->get('my_key', function (ItemInterface $item) {
    $item->expiresAfter(300);
    $item->tag(['product_42']);
    return $this->computeForProduct(42);
});
```

Et dans Twig :

```twig
{% cache 'my_block' ttl(300) tags(['product_42']) %}
    ...
{% endcache %}
```

Puis invalider les deux d'un seul appel, en injectant `TagAwareCacheInterface` :

```php
$cache->invalidateTags(['product_42']);
```

Les deux entrées — controller et Twig — sont invalidées simultanément, parce qu'elles partagent le même pool tag-aware.

Notez que les tags nécessitent un adaptateur qui les supporte (Redis, Memcache, Doctrine DBAL). Le filesystem ne les supporte pas nativement.

## Pour conclure

L'alias `twig.cache → cache.app` dans `services.yaml` est une de ces petites configurations qui n'a pas l'air de grand chose mais qui unifie toute votre stratégie de cache. Sans elle, vous avez deux systèmes parallèles difficiles à raisonner ensemble. Avec elle, vous avez un pool unique, une config unique, et une invalidation cohérente — avec ou sans tags.
