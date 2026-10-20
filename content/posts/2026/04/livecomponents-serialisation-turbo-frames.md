---
title: "LiveComponents Symfony : les limites d'un outil bien fait"
date: 2026-04-10
description: "Retour d'expérience sur les LiveComponents Symfony : sérialisation des entités Doctrine, soumission de formulaires et intégration Turbo Frames. Ce qui marche bien, et ce qui demande des compromis."
---

Les LiveComponents sont une incroyable solution pour faire des interfaces dynamiques à bas coût lors qu'on ne souhaite
pas importer 50MB de front-end et rester sur une stack simple, au hasard Symfony.
Les effets démo sont bluffants, on a "juste" à ajouter quelques attributs sur un TwigComponent et zou, on a du front-end
dynamique pour peu d'efforts.

Les cas d'usages permettent de faire des merveilles: édition inline, chat en ligne, live reload (avec ou sans Mercure
d'ailleurs), on est **au top** quand on voit ça et qu'on est dev-back-qui-doit-faire-du-front-sans-trop-aimer-ça.

Et on l'adopte. Et on élargit les cas d'usages. Mais on finit souvent par arriver à un point délicat quand on veut muscler un peu trop ces composants: la sérialisation.

## Sérialisation

Par exemple un code comme ceci plante:

```php
#[ORM\Entity(repositoryClass: LoanRepository::class)]
class Loan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'loans')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Book $book = null;

    #[ORM\ManyToOne(inversedBy: 'loans')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Member $member = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $loanDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $returnDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $returnedAt = null;
}
```

```php
#[ORM\Entity(repositoryClass: BookRepository::class)]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\ManyToOne(inversedBy: 'books')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Author $author = null;

    #[ORM\ManyToOne(inversedBy: 'books')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Category $category = null;

    /**
     * @var Collection<int, Loan>
     */
    #[ORM\OneToMany(targetEntity: Loan::class, mappedBy: 'book')]
    private Collection $loans;
}
```

```php
#[AsLiveComponent]
final class Loans
{
    use DefaultActionTrait;

    #[LiveProp]
    /**
     * @var Collection<Loan>
     */
    public Collection $loans;

}
```

```html
<twig:Loans :loans="loans"/>
```

avec l'erreur suivante:

```txt
An exception has been thrown during the rendering of a template ("Cannot dehydrate value typed as interface "Doctrine\Common\Collections\ArrayCollection" on component "App\Twig\Components\Loans". Change this to a concrete type that can be dehydrated. Or set the hydrateWith/dehydrateWith options in LiveProp or set "useSerializerForHydration: true" on the LiveProp to use the serializer.") in library/loans.html.twig at line 8.

```

Et c'est là que je constate les limites de ce composant: tous ces efforts pour éviter de faire du JS, créer un contrat API, ajouter une couche HTTP entre deux sources de code...pour retourner sur des problématiques d'interfaçage en php, où l'on doit sérialiser, pouvoir injecter en json dans un composant js, puis être renvoyée en HTTP et re-traduite. En fait, on a déplacé le problème.

Entendons-nous, c'est déjà plus simple. Mais la promesse que ça "juste marche" (© Nicolas Grekas) tient dans des usages simples, mais qui s'effritent vite lors qu'on veut y migrer tous ses formulaires parce que c'est stylé.

Un des grands cas d'usage sur mon projet actuel avec Spyrit c'est la gestion des collections par exemple. Le LiveCollection est très puissant en effet démo, mais dès qu'on ajoute de la gestion de fichiers...ça ne marche plus.

## Gestion des fichiers

La soumission de formulaires dans les LiveComponent n'est pas la même qu'un formulaire habituel. Notamment, les fichiers sont exclus de l'hydratation, il faut aller les chercher dans la requête. J'ai pour le coup [fait une pull request](https://github.com/symfony/ux/pull/3111) mais qui n'est toujours pas acceptée. Résultat il faut du glue code dans la méthode save() pour gérer les fichiers au lieu de pouvoir se baser sur VichUploaderBundle, la solution principalement recommandée.

## Compatibilité avec Turbo

Lorsqu'un utilise un formulaire LiveComponent, c'est toute une stack technique qui est mise en place pour gérer les interactions entre le client et le serveur. Cela implique une gestion avancée des événements, des requêtes AJAX, et des mises à jour dynamiques de html avec du morphing... mais s'exclut du fonctionnement de la suite Hotwire, sous-jacente à Symfony UX Turbo. Notamment les turbo-frames, ce qui rend la gestion de la redirection post soumission de formulaire pénible à gérer.

Pour ces deux derniers points, la solution à mon sens est d'utiliser les LiveComponent comme une vue dynamique de formulaire, mais de continuer de soumettre sur le controller de départ, avec le pattern GET + POST dans le controller.

1. On récupère l'usage des fichiers naturellement intégrés dans le formulaire
2. On récupère l'usage des Turbo Frames pour mettre à jour des parties de la page
3. C'est cohérent avec le reste des formulaires classiques de l'application


## Conclusion

Les LiveComponents rendent service, surtout quand on cadre le périmètre : peu de sérialisation complexe, peu d'objets Doctrine qui transitent, et des interactions UI bien ciblées.

Le pattern qui tient la route au quotidien, c'est de les cantonner à leur meilleur rôle : **des vues dynamiques**. Validation en temps réel, affichage conditionnel, compteurs, filtres — tout ça fonctionne très bien. Mais dès qu'un formulaire doit gérer des fichiers, ou qu'une redirection post-submit doit s'intégrer dans un Turbo Frame, le composant devient une friction plutôt qu'une aide.

Soumettre au controller classique ne signifie pas renoncer au dynamisme : on garde les LiveComponents pour tout ce qui est interaction avant soumission, et on laisse Turbo et Symfony gérer ce qu'ils font le mieux. C'est moins spectaculaire en démo, mais c'est cohérent avec le reste de l'application — et ça ne crée pas de cas particuliers à maintenir.

D'ailleurs, un des cas d'usage les plus fréquents pour justifier un LiveComponent sur un formulaire, c'est l'affichage conditionnel de champs. En pratique, avec un LLM, ça demande **10 lignes de JavaScript inline**, directement dans le template. Ça vit près du code, c'est facile à lire et à maintenir, et ça évite d'embarquer toute la mécanique de sérialisation et d'hydratation pour un simple `display: none`. Parfois **la solution la plus simple** est aussi la meilleure.

La vraie promesse des LiveComponents, c'est de faire du front sans écrire de JavaScript. Elle tient. Mais comme tout outil, elle a un périmètre naturel : ne pas en sortir, c'est souvent la décision la plus pragmatique.
