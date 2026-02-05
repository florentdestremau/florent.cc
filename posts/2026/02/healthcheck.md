---
title: L'intérêt d'un endpoint de healthcheck
date: 2026-02-05
description: Implémentez un endpoint de healthcheck en Symfony pour sécuriser vos déploiements. Guide pratique avec exemples de commande console et controller HTTP, validation du schéma Doctrine, et intégration dans vos workflows CI/CD et monitoring.
---

# L'intérêt d'un endpoint de healthcheck

Une nouvelle habitude bonne à prendre sur vos projets est d’avoir un controller ou bien une commande de healthcheck. C’est léger, sans dépendance et rapide à mettre en place.

## En commande

```php
<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function sprintf;

#[AsCommand(
    name: 'app:healthcheck',
    description: 'Basic check if app is running.',
)]
class HealthCheckCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $time = microtime(true);
        $now = $this->entityManager->getConnection()->executeQuery('select now()')->fetchOne();

        $validator = new SchemaValidator($this->entityManager);

        // Validate ORM metadata (mapping issues)
        $mappingErrors = $validator->validateMapping();

        if (!empty($mappingErrors)) {
            $io->error('Schema validation failed');

            return Command::FAILURE;
        }

        $responseTime = (int) ((microtime(true) - $time) * 1000);

        $io->success(sprintf('Ok @ %sUTC in %dms', $now, $responseTime));

        return Command::SUCCESS;
    }
}
```

L’usage est assez simple :
```shell
❯ bin/console app:healthcheck
                                                                                           
 [OK] Ok @ 2026-02-04 22:24:07.035493+00UTC in 50ms                                        
                                                                                            
```

On peut ajouter ici quelques autres endpoints comme l’accès au cache si on en a. Ensuite les applications sont nombreuses, en voici deux simples

### Premier usage: sécuriser le déploiement blue/green

Si vous utilisez un déploiement roulant comme https://deployer.org/, vous pouvez faire un pre check avant de basculer, et vous éviter quelques pages blanches à l’occasion.

```php
// deploy.php

task(
    'deploy:health-check',
    fn () => run('cd {{release_path}} && bin/console app:healthcheck'),
);
before('deploy:symlink', 'deploy:health-check');
```

### Deuxième usage : monitoring tout bête

En version très DIY (et non exhaustive, vous pouvez ping avec un crontab votre propre appli).

```shell
# crontab
* * * * * bin/console app:health-check && send_uptime_signal
```

## En controller

Côté HTTP, on peut reproduire la même chose, mais sans la validation du schéma pour ne pas trop impacter la perf.

```php
<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use function sprintf;

final class HealthCheckController
{
    #[Route('/healthcheck', name: 'app_healthcheck')]
    public function __invoke(EntityManagerInterface $entityManager): Response
    {
        $time = microtime(true);
        $now = $entityManager->getConnection()->executeQuery('select now()')->fetchOne();
        $responseTime = (int) ((microtime(true) - $time) * 1000);

        return new Response(sprintf('Ok @ %sUTC in %dms', $now, $responseTime));
    }
}
```

Même principe, simple et efficace, sans le schema pour être plus léger. Ajoutez-y un filtrage IP si besoin selon votre stratégie de gestion des risques.

## Pour conclure

Bien sûr, il existe des solutions plus robustes dans l’écosystème Symfony comme le [LiipMonitorBundle](https://github.com/liip/LiipMonitorBundle) ou [Symfony healthcheck bundle](https://github.com/MacPaw/symfony-health-check-bundle) ou des healthchecks natifs via le composant `Webhook` de Symfony, mais cette approche "maison" a le mérite d’être instantanée à mettre en place et sans aucune dépendance.

C’est le genre de petit script qui transforme une mise en prod stressante en une simple formalité validée automatiquement. C’est un premier pas essentiel vers une culture DevOps saine : l’application est capable de s’autodiagnostiquer, ne serait-ce que sommairement. Alors, pourquoi s’en priver ? Ça ne dispense bien sûr pas d’avoir une CI en amont, mais c’est un petit check simple qui sécurise en aval.
