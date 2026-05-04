---
title: "Pre-commit hooks : forcer le code PHP à être propre avant même d'appuyer sur Entrée"
date: 2026-05-04
description: "Rector, PHP-CS-Fixer, PHPStan en pre-commit hook : comment éviter les allers-retours avec la CI et forcer un LLM à s'autocorriger avant de comitter."

---

## Le problème : la CI comme filet de sécurité... tardif

Une CI bien configurée, c'est devenu un standard de l'industrie. **Linters**, **analyse statique**, **tests unitaires**, parfois des tests d'intégration ou de bout en bout : on empile les garde-fous pour s'assurer qu'aucune régression ne passe en production. Sur le papier, c'est parfait.

En pratique, sur les projets PHP que j'ai mis en place ces dernières années, et surtout sur des projets **fullstack** où back et front cohabitent dans le même dépôt, j'ai toujours posé le même socle. Côté back, **Rector**, **PHP-CS-Fixer** et **PHPStan** dans la CI. Côté front, **ESLint** et **Prettier**. Plus la suite de tests par-dessus. Sur un fullstack, ça fait facilement deux fois plus d'occasions de se prendre un rouge pour une raison purement mécanique. Et le souci, c'est que le feedback arrive trop tard. On pousse, on attend 3 minutes, on se prend un rouge sur une virgule manquante ou un type mal annoté, on corrige, on re-pousse. Multipliez ça par tous les développeurs de l'équipe et tous les commits de la journée : ça représente un volume de bruit considérable, et surtout ça casse le flow à chaque fois.

Un pre-commit hook déplace ce filet de sécurité au bon endroit : **avant le commit**. Si le code ne passe pas, il ne part pas. La CI reste là pour ce qui est lent ou nécessite une vraie infra (tests d'intégration, build d'image Docker, déploiements de staging), mais tout ce qui est de l'ordre du linter ou de l'analyse statique gagne à être détecté localement.

Et puis il y a un angle qu'on oublie souvent : **les sous**. Chaque minute de runner GitHub Actions, GitLab CI ou Bitbucket Pipelines, c'est de la facture qui tombe. La machine d'un dev, elle, est généralement bien plus puissante qu'un runner mutualisé (plus occupée aussi, mais ça reste largement gagnant) et elle est déjà payée. Faire tourner Rector, PHP-CS-Fixer, PHPStan ou ESLint en local, c'est autant de minutes de CI qui ne sont pas consommées pour rejouer la même chose à chaque push raté. Sur une équipe et un mois, ça finit par chiffrer.

Et accessoirement, dans un workflow avec une IA, ça change pas mal la donne (j'y reviens plus bas).

## La chaîne d'outils que je préconise

L'ordre a son importance :

1. **Rector** modernise et refactorise automatiquement (PHP 8.x, deprecations, patterns).
2. **PHP-CS-Fixer** applique le style (indentation, imports, virgules finales...).
3. **PHPStan** fait l'analyse statique en dernier, après que les deux fixers aient potentiellement modifié des fichiers.

Rector et PHP-CS-Fixer écrivent dans le code. PHPStan, lui, valide le résultat final. Si PHPStan échoue, le commit est bloqué et il faut corriger à la main (ou laisser l'IA le faire, on y vient).

## Trois façons de mettre ça en place

### L'approche brute : un hook shell

La solution la plus directe : un fichier `.git/hooks/pre-commit`. Le souci classique, c'est que `.git/` n'est pas versionné. On versionne donc le script dans le dépôt et on l'installe via un `make install`.

**`scripts/pre-commit`** :

```bash
#!/usr/bin/env bash
set -e

echo "→ Rector..."
vendor/bin/rector process --no-progress

echo "→ PHP-CS-Fixer..."
vendor/bin/php-cs-fixer fix --quiet

echo "→ Re-staging des fichiers modifiés..."
git add -u

echo "→ PHPStan..."
vendor/bin/phpstan analyse --no-progress
```

Le `git add -u` après les fixers est obligatoire. Sans lui, PHPStan tournerait sur l'ancienne version du code et le commit garderait le code non corrigé.

```makefile
install:
    composer install
    cp scripts/pre-commit .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit
```

### GrumPHP : la solution PHP pure

[GrumPHP](https://github.com/phpro/grumphp) est la librairie de référence dans l'écosystème PHP pour gérer les hooks. Elle s'installe via Composer, pose le hook automatiquement, et se configure en YAML.

```bash
composer require --dev phpro/grumphp
```

La config vit dans `grumphp.yml` à la racine :

```yaml
grumphp:
    tasks:
        rector:
            no_progress_bar: true
        phpcsfixer:
            allow_risky: true
        phpstan:
            use_grumphp_paths: false
```

L'ordre des tâches détermine l'ordre d'exécution. GrumPHP gère aussi le re-staging automatique après les fixers, donc pas besoin du `git add -u` manuel.

### Husky + lint-staged : ce que j'utilise au quotidien

Si le projet a déjà un `package.json` (asset pipeline, AssetMapper avec un peu de JS, Webpack Encore, Vite...), [Husky](https://typicode.github.io/husky/) et [lint-staged](https://github.com/lint-staged/lint-staged) sont probablement déjà là. Et c'est ce que j'utilise personnellement, parce que **lint-staged a un gros avantage** : il fait tourner les outils uniquement sur les fichiers stagés, pas sur tout le projet.

```bash
npm install --save-dev husky lint-staged
npx husky init
```

**`.husky/pre-commit`** :

```bash
npx lint-staged
```

**`package.json`** :

```json
{
    "lint-staged": {
        "*.php": [
            "vendor/bin/rector process --no-progress",
            "vendor/bin/php-cs-fixer fix --quiet",
            "vendor/bin/phpstan analyse --no-progress"
        ]
    }
}
```

Pour que tout le monde l'ait après un `npm install`, on ajoute le script `prepare` :

```json
{
    "scripts": {
        "prepare": "husky"
    }
}
```

## Le cas du développement avec une IA

C'est là que les hooks prennent toute leur valeur. Quand on développe avec un LLM (Claude Code, Cursor, Copilot...), il arrive régulièrement que **le code généré ne compile pas** ou ne respecte pas les conventions du projet.

Sans hook, le cycle ressemble à : le LLM génère, on commit, on push, la CI tombe en rouge, on revient au LLM, on re-commit... Plusieurs minutes de perdues à chaque tour, et surtout du contexte qui se dilue.

Avec un hook, le cycle est réduit : Le LLM génère, `git commit` se lance, le hook bloque, **l'IA lit l'erreur dans le terminal et se corrige sur le champ**. PHPStan en particulier crache des messages très précis (type attendu, méthode inexistante, argument manquant) qui sont exactement ce qu'il faut à un LLM pour s'auto-corriger sans intervention humaine.

En pratique, dans Claude Code, ça se résume très souvent à un seul passage : output du hook lu, code corrigé, commit accepté.

## Tests PHPUnit de conventions projet

Au-delà du style et de l'analyse statique, il y a un type de contraintes que ni PHPStan ni un linter ne savent vraiment exprimer : les **contraintes architecturales** propres au projet. Un pattern que je trouve très efficace : écrire des tests PHPUnit dédiés à ces conventions, les regrouper dans un groupe `conventions`, et les faire tourner dans le pre-commit.

```php
#[PHPUnit\Framework\Attributes\Group('conventions')]
class ProjectConventionsTest extends TestCase
{
    public function testAllEntitiesHaveTimestampableTrait(): void
    {
        $entities = /* récupère toutes les classes dans src/Entity */;

        foreach ($entities as $class) {
            $this->assertContains(
                TimestampableTrait::class,
                class_uses_recursive($class),
                "{$class} doit utiliser TimestampableTrait"
            );
        }
    }

    public function testAllRoutesHaveIsGranted(): void
    {
        $controllers = /* récupère tous les controllers */;

        foreach ($controllers as $method) {
            $attributes = $method->getAttributes(IsGranted::class);
            $this->assertNotEmpty(
                $attributes,
                "{$method->class}::{$method->name} doit avoir #[IsGranted]"
            );
        }
    }
}
```

Dans le hook, on cible uniquement ce groupe :

```bash
vendor/bin/phpunit --group conventions --no-coverage
```

C'est rapide (pas de base de données, pas de requête HTTP), ça tourne en moins d'une seconde, et ça attrape **systématiquement** les oublis. Un LLM qui crée une entité sans `TimestampableTrait` ou un controller sans `#[IsGranted]` se prend le mur immédiatement, exactement au moment où le feedback sert à quelque chose.

## Ce que le hook ne doit pas faire

Un pre-commit hook doit rester **rapide**. Si ça commence à dépasser une certaine durée, les gens finissent par faire `git commit --no-verify` et tout l'intérêt s'évapore.

À garder hors du hook :
- La suite de tests complète (à réserver à la CI ou éventuellement à un pre-push hook).
- Les tests d'intégration qui touchent la base de données.
- Les migrations Doctrine.

Concrètement, je vise **moins de 3 secondes** en moyenne, avec un seuil d'inconfort autour de **10 secondes**. Si une suite ciblée commence à dépasser ça, elle sort du hook et va dans un `make signoff` que je lance manuellement avant de pousser une grosse feature. C'est mon alias local qui reproduit la CI entière, sans en payer le coût à chaque commit.

```makefile
signoff:
    vendor/bin/rector process --no-progress
    vendor/bin/php-cs-fixer fix
    vendor/bin/phpstan analyse
    vendor/bin/phpunit --no-coverage
```

Pour gagner encore en rapidité, **lint-staged** et GrumPHP ciblent nativement les fichiers stagés. En mode hook shell brut, on peut le faire à la main :

```bash
STAGED=$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$')
[ -z "$STAGED" ] && exit 0

echo "$STAGED" | xargs vendor/bin/rector process --no-progress
echo "$STAGED" | xargs vendor/bin/php-cs-fixer fix --quiet
echo "$STAGED" | xargs vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --group conventions --no-coverage
```

## En résumé

Un pre-commit hook bien configuré (Rector, PHP-CS-Fixer, PHPStan, plus quelques tests de conventions) c'est 10 minutes d'investissement qui suppriment une catégorie entière de retours CI. Pour la validation lourde avant un push important, un `make signoff` local complète le dispositif sans alourdir le quotidien.

Et dans un workflow avec une IA, c'est encore plus rentable : le LLM reçoit un feedback immédiat, précis et actionnable, ce qui élimine la majorité des allers-retours sur des erreurs mécaniques que la machine peut très bien corriger toute seule.
