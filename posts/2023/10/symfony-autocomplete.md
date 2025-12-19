---
title: Utiliser Symfony Autocomplete pour créer des entités à la volée dans un formulaire
date: 2023-10-23
description: Comment créer des entités directement depuis un champ autocomplete Symfony UX en utilisant Tom Select et un controller Stimulus personnalisé.
---
# Utiliser Symfony Autocomplete pour créer des entités à la volée dans un formulaire


J'ai récemment eu besoin de créer un formulaire de création d'utilisateur, où je devais remplir des attributs. Ces attributs sont des entités à part entière et devaient pouvoir être créés à la volée lors de la saisie du formulaire.

Étant déjà utilisateur du composant Aucomplete sur nos formulaires, qui utilise la librairie [Tom Select](https://tom-select.js.org/), je pensais qu'on pouvait tout naturellement utiliser la propriété `create` qui permet de saisir des nouvelles entrées au sein d'un `<select>` existant.

![Capture d'écran de l'usage de Tom Select](/img/symfony-autocomplete.png)

Malheureusement, ça n'était pas nativement supporté. La solution que j'ai trouvé était donc de faire usage d'un cas d'application particulier de l'option `create`: on peut lui fournir une fonction qui prend un callback en argument, ce qui permet de...tout faire, en somme.

```js
create: function(input,callback){
	callback({value:input,text:input});
}
```

Ainsi, ce que je voulais en somme, c'est fournir un callback de ce type:

```js
create: function (input, callback) {
    fetch('/api/attributes', {
        method: 'POST',
        body: JSON.stringify({name: input}),
    })
        .then(response => response.json())
        .then(data => callback({value: data.id, text: data.name}));
}
```

Bon, à ce stade ça fonctionne en théorie, mais je n'aime pas trop l'idée de laisser une url en clair dans mon bout de JS, et puis je n'ai pas vraiment d'endroit clair où je peux activer cette fonction. L'option "tom_select_options" dans mon formulaire ne me permet en effet pas de passer une fonction javascript.

```php
// src/Form/UserType.php

public function buildForm(FormBuilderInterface $builder, array $options): void
{
    $builder
        ->add('name')
        ->add('email')
        ->add('attributes', EntityType::class, [
            'multiple'           => true,
            'required'           => false,
            'autocomplete'       => true,
            'class'              => Attribute::class,
            'by_reference'       => false,
            'tom_select_options' => ['create' => true],
        ]);
}
```

Ma solution est donc de [suivre la doc](https://symfony.com/bundles/ux-autocomplete/current/index.html#extending-tom-select) et de créer un controller stimulus dédié.

En première version, je vais continuer de hard-coder l'url:

```js
// assets/controllers/custom-autocomplete_controller.js
import {Controller} from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.element.addEventListener('autocomplete:pre-connect', this._onPreConnect);
    }

    disconnect() {
        this.element.removeEventListener('autocomplete:pre-connect', this._onPreConnect());
    }

    _onPreConnect = (event) => {
        event.detail.options.create = function (input, callback) {
            const data = new FormData();
            data.append('attribute[name]', input);
            fetch('/attribute/new?ajax=1', {
                method: 'POST',
                body: data,
            })
                .then(response => response.json())
                .then(data => callback({value: data.id, text: data.name}));
        }
    }
}
```

```php
$builder
    ->add('attributes', EntityType::class, [
        'multiple'     => true,
        'required'     => false,
        'autocomplete' => true,
        'class'        => Attribute::class,
        'by_reference' => false,
        'attr'         => [
            'data-controller' => 'custom-autocomplete',
        ],
    ]);
```

À ce stade, j'ai ma route qui fait son travail, et j'ai bien une création d'attribut à la volée, puis je soumet mon formulaire. Dernière étape: mettre l'url en paramétrage pour garder le controller stimulus anonyme.

```php
// src/Form/UserType

'attr' => [
    'data-controller' => 'custom-autocomplete',
    'data-custom-autocomplete-url-value' => '/attribute/new',
],
```

```js
// assets/controllers/custom-autocomplete_controller.js

export default class extends Controller {
    static values = { url: String }

    // [...]

    _onPreConnect = (event) => {
        const url = this.urlValue;
        event.detail.options.create = function (input, callback) {
            const data = new FormData();
            data.append('name', input);
            fetch(url, {
                method: 'POST',
                body: data,
            })
                .then(response => response.json())
                .then(data => callback({value: data.id, text: data.name}));
        }
    }
}
```

Vous pouvez également utiliser le service UrlGenerator pour optimiser. Le controller Symfony est, lui, très banal.

```php

#[Route('/new', name: 'app_attribute_new', methods: ['GET', 'POST'])]
public function new(Request $request, EntityManagerInterface $entityManager): Response
{
    $attribute = new Attribute();
    $form = $this->createForm(AttributeType::class, $attribute);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $entityManager->persist($attribute);
        $entityManager->flush();

        return $this->json([
            'id' => $attribute->getId(),
            'name' => $attribute->getName(),
        ]);
    }

    // [...]
 }
```

Pour tester, j'ai fait un projet rapide qui regroupe toutes ces idées, avec les différentes étapes en commit, disponible ici:
[](https://github.com/florentdestremau/creatable-autocomplete)

Prochaine étape: faire une PR sur Symfony UX pour intégrer ça nativement ? 😉
