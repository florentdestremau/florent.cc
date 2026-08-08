---
title: "Isoler complètement ses worktrees Git : ports, base et sessions"
date: 2026-08-08
image: /img/worktrees-git-isoles.jpg
imageAlt: "Illustration isométrique : trois environnements de développement miniatures sous cloche de verre, greffés sur les branches d'un même arbre étiqueté master, chacun relié par un câble de couleur à une plaque de port numérotée"
description: "Faire travailler plusieurs agents en parallèle, c'est autant de stacks complètes à faire tourner. Ports décalés par hash de branche, base clonée par copie de volume Docker et sessions indépendantes : la recette pour des worktrees vraiment autonomes."

---

## Le worktree, c'est génial jusqu'à ce qu'on veuille le lancer

`git worktree` existe depuis 2015 et est resté longtemps confidentiel. Ce qui l'a remis au goût du jour, c'est [l'arrivée des agents de code](/posts/2026/06/inference-a-la-carte-modeles-ia-open-source/) : dès qu'on veut faire tourner Claude Code sur une refonte pendant qu'on continue à bosser sur `master`, il faut bien deux répertoires de travail distincts.

Le principe est simple : au lieu de jongler avec `git stash` et `git checkout` sur un seul dossier, on demande à Git de matérialiser une branche dans un **autre répertoire**, qui partage le même `.git`. On se retrouve avec deux (ou dix) copies du projet sur le disque, chacune sur sa branche, sans duplication de l'historique.

```bash
git worktree add ../saas-refonte-facturation feature/refonte-facturation
```

Pour **explorer une codebase**, comparer deux implémentations ou laisser un agent tenter une migration dans son coin, ça marche très bien. Mais « explorer » et « faire tourner » sont deux choses très différentes. Dès qu'il faut démarrer un serveur web, une base PostgreSQL, un Redis, un worker Messenger et une CI locale, on se prend le mur : **tous les worktrees veulent le port 5432**. Et le 6379. Et le 8000. Docker Compose refuse de démarrer, ou pire, démarre en écrasant les conteneurs du voisin parce que le nom de projet Compose est le même.

Voilà comment j'ai fini par rendre chaque worktree complètement autonome sur mon projet principal, un gros Symfony avec Postgres, Redis, Gotenberg et un serveur de mails.

Les exemples qui suivent sont donc en PHP, mais **le problème n'a rien de spécifique à Symfony** : un projet Rails, Django, Laravel ou Node avec sa base et son Redis se prend exactement le même mur. Les quatre étapes se transposent telles quelles : décaler les ports depuis le nom de branche, cloner le volume de données, isoler les cookies, tout scripter derrière une commande unique. Seul l'outillage change, avec un `Rakefile` ou les scripts d'un `package.json` à la place de Castor. Mise, lui, ne change même pas : il gère Ruby, Python ou Go aussi bien que PHP.

## Castor plutôt qu'un Makefile

Avant d'entrer dans le vif, un mot sur l'outil qui porte tout ça. J'ai longtemps utilisé un `Makefile` comme point d'entrée unique de mes projets : `make start`, `make test`, `make fixtures`. C'est universel, c'est installé partout, ça marche.

Mais dès qu'on dépasse une dizaine de cibles, `make` montre ses limites. Les tabulations obligatoires, le `.PHONY` qu'on oublie, les variables shell qu'il faut échapper deux fois, l'impossibilité de faire une condition lisible, et surtout : aucune logique un peu sérieuse n'y est écrivable sans basculer sur des scripts bash à côté.

[**Castor**](https://castor.jolicode.com/) résout exactement ça. C'est un task runner développé par [JoliCode](https://jolicode.com), où **les tâches sont des fonctions PHP** décorées par un attribut :

```php
use Castor\Attribute\AsTask;
use function Castor\run;

#[AsTask(name: 'phpstan', description: 'Analyse statique PHPStan')]
function phpstan(): void
{
    run('./vendor/bin/phpstan analyse');
}
```

On installe le binaire (ou un `.phar` versionné dans le repo), on écrit un `castor.php` à la racine, et `castor` sans argument liste toutes les tâches avec leur description. On récupère au passage l'autocomplétion shell, les arguments typés, et surtout : **du vrai PHP**. Des conditions, des boucles, de la manipulation de fichiers, du `preg_replace`, de l'appel de fonction à fonction. Pour un projet PHP, c'est aussi une barrière d'entrée nulle pour l'équipe : tout le monde sait lire le fichier.

Concrètement, ça me permet d'avoir **un seul point d'entrée qui ne demande aucune réflexion** : `castor start`. Que le dossier soit un clone tout neuf, un worktree créé il y a trente secondes ou la stack principale déjà installée, la commande est la même. Elle installe les dépendances si le `vendor/` manque, génère les fichiers de config manquants, démarre Docker, le serveur, les workers. Idempotente. C'est ce genre de logique conditionnelle qui devient franchement pénible à écrire en `make`, et qui est triviale ici :

```php
/** Installe les dépendances manquantes (utile sur un worktree fraîchement créé). */
function ensure_deps(string $root): void
{
    if (!is_file($root . '/vendor/autoload.php')) {
        io()->text('Installation des dépendances PHP (composer install)…');
        run('composer install --no-interaction --no-progress');
    }
    if (!is_dir($root . '/node_modules')) {
        io()->text('Installation des dépendances JS (npm install)…');
        run('npm install');
    }
}
```

Tout ce qui suit vit dans ce `castor.php`.

## Étape 1 : sortir PHP de Docker

Premier réflexe pour alléger la stack : **réduire le nombre de conteneurs**. Et le premier à dégager, c'est le conteneur PHP.

Ce n'est pas un choix idéologique, c'est un choix d'ergonomie. Je veux pouvoir taper `bin/console` directement, sans préfixe `docker compose exec php`. Je veux que mon IDE trouve l'interpréteur sans configuration de mapping de chemins. Et surtout, je veux que **mon agent IA puisse invoquer PHP librement** : un LLM qui doit se souvenir de préfixer chaque commande d'un `docker compose exec -T php` va se tromper une fois sur trois, et le débogage devient pénible pour rien.

La solution, c'est le [**Symfony CLI**](https://symfony.com/download) pour le serveur local :

```bash
symfony server:start -d
```

Au-delà du confort, c'est aussi la meilleure intégration disponible avec Docker : le CLI **détecte automatiquement les conteneurs Compose** du projet et expose leurs coordonnées en variables d'environnement (`DATABASE_URL`, etc.). Il fournit du **HTTPS local** avec un certificat de confiance, un proxy avec des sous-domaines `.wip`, et il sait lancer des process annexes attachés au serveur :

```php
run('symfony local:run -d npm run start');
run('symfony local:run -d --watch=config,src,templates,vendor bin/console messenger:consume async -vv');
```

Le `--watch` est précieux : le consumer Messenger redémarre tout seul quand on touche au code, ce qui évite les heures perdues à débugger un handler qui tourne sur du code obsolète.

Docker reste dans l'équation, mais **uniquement pour les services** : Postgres, Redis, Gotenberg, le serveur SMTP de test. Ces conteneurs-là, on ne les ouvre jamais. Ce qui n'a rien d'une position de principe contre Docker : [en production, je déploie justement dans un conteneur unique avec FrankenPHP](/posts/2026/04/once-et-frankenphp-pour-shiper-vite/). C'est en local que l'arbitrage penche dans l'autre sens.

## Étape 1 bis : mise, pour figer les versions par projet

Sortir PHP de Docker soulève immédiatement une objection légitime : et si mes projets n'ont pas la même version de PHP ? Ou de Node ? C'est précisément la raison pour laquelle beaucoup de gens gardent le conteneur PHP.

La réponse, c'est [**mise**](https://mise.jdx.dev/) (ex-rtx). C'est un gestionnaire de versions polyglotte : il lit un fichier de config à la racine du projet, installe les versions demandées, et **active automatiquement les bons binaires quand on entre dans le dossier**. `php`, `node`, `composer` : chaque projet a les siens.

```toml
[tools]
node = "24"
php = "8.4"

[env]
PHP_CONFIGURE_OPTIONS = "--with-openssl --with-curl --with-zlib --with-readline --with-gettext --with-bz2 --with-pgsql --with-sodium --with-xsl"
```

Le `PHP_CONFIGURE_OPTIONS` mérite une explication. Le plugin PHP de mise compile PHP depuis les sources et n'active par défaut qu'un jeu d'extensions standard. Les extensions **core** dont on a besoin en plus (ici `bz2`, `pgsql`, `sodium`, `xsl`) doivent être ajoutées aux options de compilation. Attention, cette variable **remplace** le jeu d'options par défaut, d'où la liste complète.

Les extensions **PECL**, elles, ne sont pas gérées du tout par le plugin. Pour Redis, il faut passer derrière, et autant automatiser ça aussi dans le `castor.php` :

```php
function ensure_php_redis(): void
{
    $modules = strtolower(capture('mise exec -- php -m', onFailure: ''));
    if (str_contains($modules, 'redis')) {
        return;
    }

    io()->text('Installation de l\'extension PECL redis (PHP mise)…');
    run('mise exec -- pecl install redis', context()->withAllowFailure());

    $scanDir = trim(capture("mise exec -- php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;'", onFailure: ''));
    if ('' !== $scanDir) {
        fs()->mkdir($scanDir);
        file_put_contents($scanDir . '/redis.ini', "extension=redis.so\n");
    }
}
```

Une réserve sur ce bout de code : `pecl` est en voie de remplacement par [**PIE**](https://github.com/php/pie) (_PHP Installer for Extensions_), le nouvel installateur officiel porté par la PHP Foundation, qui s'appuie sur les métadonnées Composer plutôt que sur le vieux canal PECL. Je n'ai pas encore exploré comment il se comporte avec un PHP compilé par mise, notamment sur la détection du bon `php-config` et l'écriture dans le `conf.d`. Si quelqu'un a déjà fait tourner les deux ensemble, je suis preneur du retour.

Le seul vrai inconvénient : la **première** compilation de PHP prend plusieurs minutes. Mais c'est un coût unique par version. Une fois PHP 8.4 compilé, tout nouveau worktree qui déclare `php = "8.4"` démarre en **0 seconde**, puisque mise réutilise l'installation existante. `mise install` est instantané, et c'est parfaitement acceptable.

## Étape 2 : décaler tous les ports à partir du nom de branche

On arrive au cœur du problème. Chaque worktree a besoin de son Postgres, de son Redis, de son Gotenberg. Et j'insiste sur le fait que ces services doivent être **exposés sur l'hôte** : je veux pouvoir me connecter à la base avec `psql` ou un client graphique, pas seulement depuis un conteneur. C'est aussi ce qui permet de brancher un **serveur MCP** dessus : mes agents interrogent le schéma et les données directement via une chaîne de connexion, et là encore, chacun doit taper sur la base de son propre worktree. Un MCP pointé sur le 5432 de `master` pendant qu'on travaille sur une branche avec des migrations en cours, c'est la garantie de raisonnements faux.

Comment trouver un port libre ? On pourrait scanner, incrémenter, tenir un registre. J'ai testé, c'est fragile : le port change entre deux démarrages, les fichiers de config se désynchronisent, et le jour où deux worktrees démarrent en même temps c'est la course critique.

L'approche que j'ai retenue, inspirée de mes discussions avec [Bastien Jaillot](https://bastnic.fr/) (JoliCode), est **déterministe** : on hache le nom de la branche, et on en tire un décalage entier fixe. Même branche, même offset, à jamais. Aucun registre à maintenir, aucune collision entre deux instances de la même branche puisqu'il n'y en a qu'une.

```php
$branch = capture('git rev-parse --abbrev-ref HEAD');
// Décalage déterministe par branche (5..404) : évite 5432/5433 (Postgres) et 6379/6380 (Redis).
$offset = (crc32($branch) % 400) + 5;

return [
    'project'     => basename($root),
    'branch'      => $branch,
    'offset'      => $offset,
    'postgres'    => 5432 + $offset,
    'redis'       => 6379 + $offset,
    'gotenberg'   => 3000 + $offset,
    'mailer_smtp' => 1025 + $offset,
    'mailer_web'  => 8025 + $offset,
];
```

Deux détails comptent ici :

- L'offset commence à **5** et pas à 0, pour ne jamais retomber sur les ports canoniques (5432, 5433) déjà pris par la stack principale.
- Les ports de base sont **espacés de plus de 400**, l'amplitude de l'offset. Sans ça, le Postgres d'une branche pourrait tomber pile sur le Redis d'une autre.

Le repérage du worktree lui-même se fait en comparant la racine du checkout courant au répertoire `.git` commun :

```php
$root      = capture('git rev-parse --show-toplevel');
$commonDir = capture('git rev-parse --git-common-dir');
$mainRoot  = \dirname($commonDir);

if (realpath($root) === realpath($mainRoot)) {
    return null; // repo principal, aucune isolation nécessaire
}
```

Le clone principal reste donc sur `master`, avec ses ports historiques et son comportement inchangé. **Seuls les worktrees sont décalés.** C'est important : je ne veux pas casser les habitudes de l'équipe pour une fonctionnalité que je suis seul à utiliser.

### Écrire les fichiers non versionnés

Une fois l'offset calculé, on génère deux choses. D'abord un `docker-compose.override.yaml`, que Compose charge **automatiquement** en plus du fichier de base :

```yaml
services:
    db:
        ports: !override
            - 5537:5432
    redis:
        ports: !override
            - 6484:6379
```

Le tag `!override` est indispensable. Sans lui, Compose **fusionne** les listes de ports, et le 5432 d'origine resterait exposé, avec collision immédiate à la clé sur la stack principale.

Ensuite, un `.env.local` qui aligne tous les DSN sur ces nouveaux ports :

```php
upsert_env($ctx['root'] . '/.env.local', [
    'DATABASE_URL'  => "postgresql://postgres:password@127.0.0.1:{$ctx['postgres']}/postgres?serverVersion=16&charset=utf8",
    'REDIS_URL'     => "redis://127.0.0.1:{$ctx['redis']}",
    'LOCK_DSN'      => "redis://127.0.0.1:{$ctx['redis']}",
    'GOTENBERG_URL' => "http://127.0.0.1:{$ctx['gotenberg']}",
    'MAILER_DSN'    => "smtp://127.0.0.1:{$ctx['mailer_smtp']}",
]);
```

`upsert_env()` écrit un **bloc balisé** plutôt que d'écraser le fichier :

```
# >>> castor worktree (généré, ne pas committer) >>>
DATABASE_URL=postgresql://…
# <<< castor worktree <<<
```

Un `preg_replace` sur ce bloc permet de régénérer la config sans jamais toucher aux variables que le développeur a ajoutées à la main à côté. Sur un fichier que l'on réécrit à chaque `castor start`, c'est ce qui fait la différence entre un outil qu'on garde et un outil qu'on désactive au bout de deux jours.

Même traitement pour `.env.test.local`, qui pointe vers une base `app-test` sur **le Postgres du worktree**. C'est ce qui rend possible de lancer la suite de tests complète dans un worktree pendant que la stack principale tourne.

Et bien sûr, on applique le même décalage au serveur web pour qu'il sorte au bon endroit :

```php
run("symfony server:start -d --port=" . (8000 + $ctx['offset']));
```

## Étape 3 : la donnée, en copiant le volume Docker

Un environnement isolé qui démarre sur une base vide ne sert à rien. Pour déboguer, pour reproduire un bug, pour [faire tourner la CI en local](/posts/2026/05/pre-commit-hooks-php/), il faut de la **donnée réaliste** : des fixtures chargées, un dump de préprod, un jeu de données anonymisé.

Le réflexe naturel, c'est de rejouer le chemin classique : `doctrine:migrations:migrate` puis `doctrine:fixtures:load`, ou un `pg_restore` d'un dump. Sur un projet mature, comptez plusieurs minutes. Répété à chaque nouveau worktree, ça devient rédhibitoire.

Or cette donnée, je l'ai déjà : elle est dans le volume Docker de ma stack principale, celle qui reste sur `master`. Le plus rapide n'est donc pas de la reconstruire, c'est de la **copier**. Et copier un volume Docker, c'est un `cp -a` dans un conteneur Alpine jetable :

```php
function seed_db_volume(array $ctx): void
{
    $src    = $ctx['source'] . '_database_data';
    $dst    = $ctx['project'] . '_database_data';
    $srcCtn = $ctx['source'] . '-db-1';

    if (!run("docker volume inspect {$src}", probe())->isSuccessful()) {
        io()->note("Seed DB ignoré : volume source « {$src} » introuvable.");
        return;
    }
    if (run("docker run --rm -v {$dst}:/d alpine test -f /d/PG_VERSION", probe())->isSuccessful()) {
        io()->note("Seed DB ignoré : « {$dst} » déjà initialisé.");
        return;
    }

    $wasRunning = 'true' === trim(capture("docker inspect -f '{{.State.Running}}' {$srcCtn}", onFailure: ''));
    if ($wasRunning) {
        run("docker stop {$srcCtn}", context()->withQuiet());
    }

    run(
        "docker run --rm -v {$src}:/from:ro -v {$dst}:/to alpine "
        . "sh -c 'rm -rf /to/* /to/..?* /to/.[!.]* 2>/dev/null; cp -a /from/. /to/'",
    );

    if ($wasRunning) {
        run("docker start {$srcCtn}", context()->withQuiet());
    }
}
```

Trois précautions valent le détour :

1. **On arrête le conteneur source** le temps de la copie. Copier les fichiers d'un PostgreSQL en train d'écrire produit un répertoire de données incohérent : on récupérerait au mieux une base qui rejoue son WAL au démarrage, au pire une base corrompue. On le redémarre juste après, et uniquement s'il tournait avant.
2. **C'est idempotent** : la présence d'un fichier `PG_VERSION` dans le volume cible signale une base déjà initialisée, on ne réécrase rien. `castor start` peut donc être relancé cent fois sans risque.
3. **C'est désactivable** via `WORKTREE_SEED_DB=0`, pour le cas où on veut justement partir d'une base vierge.

En local, l'opération prend **moins de dix secondes** sur une base de plusieurs gigaoctets, là où un `pg_dump` / `pg_restore` en prendrait plusieurs minutes. Et le résultat est un clone bit à bit : mêmes séquences, mêmes index, mêmes statistiques. Les conditions exactes de `master`, immédiatement.

## Étape 4 : l'URL et le piège des sessions

Ma première tentative visait des URLs élégantes : `projet.nom-de-branche.localhost`, servies en HTTPS par le proxy du Symfony CLI. Sur le papier, c'est joli. En pratique, ça ne marche pas : **tout le monde se bouscule sur les ports 80 et 443**. Le proxy ne peut router que vers une seule stack à la fois, et l'ensemble devient un point de contention central, soit exactement ce qu'on cherchait à éviter.

J'ai donc bifurqué vers le plus simple : chaque worktree écoute sur son propre port, `http://127.0.0.1:8412`. Moins joli, mais parfaitement isolé et sans aucune infrastructure partagée.

Sauf qu'un problème beaucoup plus vicieux est apparu : **je me faisais déconnecter en permanence**. Je me connectais sur le worktree A, je passais sur le worktree B, je me reconnectais, et en revenant sur A j'étais déloggé.

La cause est une subtilité de la spécification des cookies : **le port ne fait pas partie de l'origine d'un cookie**. Contrairement à la _same-origin policy_ de JavaScript, qui distingue bien `127.0.0.1:8000` de `127.0.0.1:8412`, les cookies ne considèrent que le domaine et le chemin. Résultat : tous mes serveurs locaux se partagent le même espace de cookies sur `127.0.0.1`, et chaque nouvelle session écrase la précédente.

La solution est directe : rendre le **nom** du cookie unique par worktree. Symfony permet de le configurer entièrement par variable d'environnement.

```yaml
# config/packages/framework.yaml
framework:
    session:
        name: "%env(SESSION_COOKIE_NAME)%"
```

```yaml
# config/packages/security.yaml
    remember_me:
        name: "%env(REMEMBER_ME_COOKIE_NAME)%"
```

Et dans le `.env.local` généré :

```php
$suffix = preg_replace('/[^A-Za-z0-9]+/', '_', $ctx['project']);

upsert_env($ctx['root'] . '/.env.local', [
    // …
    'SESSION_COOKIE_NAME'     => "APPSESSID_{$suffix}",
    'REMEMBER_ME_COOKIE_NAME' => "REMEMBERME_{$suffix}",
]);
```

Ne pas oublier le cookie **remember-me** : sans lui, la session PHP est bien isolée mais le cookie de reconnexion automatique reste partagé, et on se retrouve reconnecté sous la mauvaise identité au premier rechargement. Si le projet a d'autres cookies persistants (2FA « appareil de confiance », bannière de consentement, préférences), le même traitement s'applique.

## Le résultat : `castor start`, `castor destroy`

Bout à bout, la tâche `start` détecte le contexte et fait le nécessaire :

```php
#[AsTask(name: 'start', description: 'Démarre la stack, isole automatiquement les worktrees')]
function start(): void
{
    ensure_tools();                      // mise install + extensions PECL

    $ctx = worktree_context();

    if (null !== $ctx) {
        io()->section("Worktree isolé : {$ctx['project']} (branche {$ctx['branch']}, offset +{$ctx['offset']})");
        write_worktree_files($ctx);      // override Compose + .env.local + .env.test.local
        seed_db_volume($ctx);            // clone du volume Postgres depuis master
        ensure_deps($ctx['root']);       // composer install / npm install si besoin
    }

    run('docker compose up -d');
    run("symfony server:start -d --port=" . (8000 + ($ctx['offset'] ?? 0)));
    run('symfony local:run -d npm run start');
    run('symfony local:run -d --watch=config,src,templates,vendor bin/console messenger:consume async -vv');
}
```

Le cycle de vie complet d'une branche tient en trois commandes :

```bash
git worktree add ../saas-refonte feature/refonte
cd ../saas-refonte && castor start
# … travail, tests, migrations, upgrades …
castor destroy
```

`destroy` arrête la stack et supprime les volumes du worktree, avec une confirmation explicite si on l'invoque depuis le repo principal, pour ne pas y toucher par inadvertance :

```php
#[AsTask(name: 'destroy', description: 'Arrête la stack puis supprime les volumes Docker')]
function destroy(): void
{
    $ctx = worktree_context();

    if (null === $ctx && !io()->confirm('Repo principal détecté : supprimer les volumes Docker (base dev incluse) ?', false)) {
        io()->note('Annulé.');
        return;
    }

    stop();
    run('docker compose down -v --remove-orphans');
}
```

Ensuite, un `git worktree prune` nettoie les références Git une fois le dossier supprimé.

## Ce que ça change au quotidien

L'intérêt de tout ce dispositif, ce n'est pas le confort, c'est le **coût du retour en arrière**.

Quand un environnement complet se crée en moins d'une minute et se détruit en une commande, on arrête de se poser la question « est-ce que je peux me permettre de tenter ça ? ». Une migration Doctrine hasardeuse, une montée de version majeure d'une dépendance, un refactoring qui touche cinquante fichiers, un rebuild complet du front : on tente, dans un worktree, avec une vraie base de données et une stack complète. Si ça marche, on ouvre la PR. Si ça ne marche pas, `castor destroy` et il n'en reste rien : la stack principale n'a jamais bougé.

C'est aussi ce qui rend le **travail en parallèle avec des agents IA** réellement praticable. Un agent qui tourne dans son propre worktree peut lancer les tests, migrer la base, casser des choses : il est dans un bac à sable qui a exactement les mêmes conditions que `master`, mais dont l'explosion n'a aucune conséquence. Et je peux en faire tourner trois en même temps sans qu'ils se disputent le port 5432.

Reste la question de savoir ce que font ces trois agents pendant qu'ils tournent. Là-dessus, toujours grâce à Bastien, j'ai découvert **[herdr](https://herdr.dev)** : un multiplexeur de terminaux pensé pour les agents de code, qui organise le travail en workspaces, onglets et panes, détecte l'identité et l'état de chaque agent, et sait ouvrir un workspace directement adossé à un worktree Git. La complémentarité est assez évidente : ce que je décris ici isole les environnements, herdr donne la vue d'ensemble sur ce qui tourne dedans. Ça mérite sans doute un article à part entière.
