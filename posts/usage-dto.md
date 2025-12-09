---
title: De l'usage des DTO dans les formulaires
---

# De l'usage des DTO dans les formulaires

Je trouve que le débat des DTO est souvent très biaisé : on l'érige comme évidence lorsqu'on on en débat, mais on ne parle que très rarement du contexte et du coût associé en maintenance. L'argumentaire principal est de se rapprocher du DDD pour ne pas manipuler en direct les entités de l'ORM.

Prenons ce code simple généré par le `make:entity` pour  une entité basique :

```php
#[ORM\Entity()]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $body = null;
}
```

On y adjoint alors un DTO:

```php
class PostDto
{
    public function __construct(
        #[Assert\NotBlank]
        public string $title,
        #[Assert\NotBlank]
        public string $body,
    ) {
    }
}
```

Puis un service de mapping :

```php
public function fromEntity(Post $post): PostDto
{
    return new PostDto(
        $post->getTitle(),
        $post->getBody(),
    );
}

public function updateEntity(Post $post, PostDto $dto): void
{
    $post->setTitle($dto->title);
    $post->setBody($dto->body);
}
```

...son FormType associé

```php
class PostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title')
            ->add('body')
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PostDto::class,
        ]);
    }
}
```

et hop on se dit qu'on a un truc dé-cou-plé. Le hic ? On a désormais une classe qui ne sert que de passe-plat entre l'entité db et le user, qui a les règles de validation métier...et qu'il faut maintenir en plus ! On espère ainsi avoir un code plus "robuste" au changement, mais en pratique, si je renomme `Post::title`, je dois désormais changer 

- mon entité
- mon dto
- ma fonction de transfert
- mon form type

Pour les opérations de CRUD simples par entité - ce qui représente l'immense majorité des cas dans les applications que je rencontre - ça n'apporte à mon sens que très peu de valeur. Il a fallu tomber sur [un article de Martin Fowler](https://martinfowler.com/bliki/LocalDTO.html) évoquant cette sur-complexité pour me résoudre à être plus dur sur le sujet.

Comment alors protéger notre code sans dto ? En faisant des tests, pardi !

```php
class PostControllerTest extends WebTestCase
{

    public function testEdit(): void
    {
        $client = static::createClient();
        $client->request('GET', '/post/edit/1');
        $this->assertResponseIsSuccessful();
        $client->submitForm('Save', [
            'post[title]' => 'Test title',
            'post[content]' => 'Test content',
        ]);
        $this->assertResponseRedirects('/post/show/1');
    }
}
```

Pourquoi c'est suffisant ? Parce que tout casse si jamais le champ `Post::title` est modifié: j'aurai non seulement une 500 sur le GET mais aussi sur le POST. Pas besoin d'un DTO pour ça. Et je n'ai désormais que 2 fichiers à créer et maintenir, mon entité et mon formulaire. Plus qu'à déplacer les contraintes de validation sur l'entité et on est bons. Bonus ? Dans des cas simples comme ça, on peut en fait largement mettre du typage strict.

```php
#[ORM\Entity()]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $body;
}
```

Et pourtant, mentalement, cette deuxième façon de faire paraît moins "rigoureuse", alors qu'en fait elle résulte en un couplage plus souple code / base de données et une couverture de test accrue. C'est aussi une question de mentalité : on ne voit pas d'inconvénient à passer 1h de plus sur son ticket pour écrire le Dto, le couplage, le typage, les bugs pendant le développement car on a oublié de mapper un champ, etc...mais on a régulièrement "pas le temps" de faire un test fonctionnel. 

Dans le fond, comme le rappelle régulièrement DHH : la majeure partie du temps [nous sommes des "CRUD Monkeys"](https://x.com/dhh/status/1956632934615490574) et nous écrivons en base de données depuis un input utilisateur. Ne pas oublier que bien souvent, nous ne sommes que le passe-plat entre la db et l'utilisateur, autant l'assumer et réduire la surface du passe-plat.



## Les cas d'usage de Dto pertinents

Si l'on reprend la définition de fond des Dto, ce sont des objets de transfert de données. Dès lors les cas d'usages sont mis en lumière dans les scenarii "complexes". En voici quelques exemples.

### Formulaires multi-entités

Si l'on prend l'exemple d'un processus d'inscription, on va souvent vouloir mélanger plusieurs éléments qui ne rentrent pas dans l'entité de départ. Par exemple à l'inscription à un saas B2B, on va régulièrement proposer d'inviter des collègues pour former l'équipe dès l'inscription, voici à quoi ça pourrait ressembler:

```php
class RegistrationDto
{
    #[Assert\NotBlank]
    public string $name;

    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email;
    
    #[Assert\NotBlank]
    public string $plainPassword;
    
    #[Assert\NotBlank]
    public string $organisationName;
    
    /** @var array<string> */
    #[Assert\All([new Assert\NotBlank(), new Assert\Email()])]
    public array $colleagueEmails;
}
```

Là, on va mapper différemment avec le début qui irait dans `User` , un autre qui va créer l'organisation et enfin l'array d'emails qui serait utilisé pour créer des `UserInvite`. Ici on a besoin de présenter différents petits formulaires au même endroit, un Dto permet de fluidifier cela au niveau des classes et de dispatcher la logique par la suite.

### Vue partielle d'objets

Quand on commence à avoir des entités très conséquentes en taille (nombre de champs et relations), il peut devenir coûteux de récupérer l'entité entière avec un `->findBy([...])` ou un `->createQueryBuilder()`, en raisons des requêtes n+1 ou simplement de la place en mémoire que cela nécessite. On peut alors imaginer une vue allégée qui permettrait de ne récupérer que le minimum vital ET d'avoir malgré tout un typage pour la manipulation controller et vue, par exemple en Twig, en passant par une requête sql plus bas niveau. Doctrine en fait de bons [exemples sur sa documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/3.5/reference/native-sql.html#examples).

Pour notre cas ce serait par exemple pour récupérer les posts. On aurait le Dto suivant:

```php
final readonly class PostDto
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
    ) {
    }
}
```

Et il serait hydraté par un ResultSetMapping

```php
// src/Repository/PostRepository.php
/**
 * @return array<PostDto>
 */
public function findPostViewDto()
{
    $rsm = new ResultSetMapping();
    $rsm->addScalarResult('id', 'id');
    $rsm->addScalarResult('title', 'title');
    $rsm->addScalarResult('body', 'body');
    $rsm->newObjectMappings = [
        'id'    => [
            'className' => PostDto::class,
            'objIndex'  => 0,
            'argIndex'  => 0,
        ],
        'title' => [
            'className' => PostDto::class,
            'objIndex'  => 0,
            'argIndex'  => 1,
        ],
        'body'  => [
            'className' => PostDto::class,
            'objIndex'  => 0,
            'argIndex'  => 2,
        ],
    ];

    return $this->getEntityManager()
        ->createNativeQuery('SELECT id, title, body FROM post', $rsm)
        ->getResult();
}
```

Ici la charge mémoire est bien plus faible, et on peut facilement inclure ça dans une vue Twig ou dans une réponse API, avec un objet épuré et inoffensif.

### Vue aggrégée d'objets

De la même manière qu'une vue partielle, on peut rassembler à la main un `Post` avec ses `Comment` et ses `Followers` par exemple pour gagner en performance et en sécurité sur les objets renvoyés par notre code.
