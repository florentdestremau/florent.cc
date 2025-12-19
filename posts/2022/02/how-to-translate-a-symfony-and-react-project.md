---
title: How to translate a Symfony and React project.
date: 2022-02-24
description: How to maintain a single translation file for both Symfony and React using yaml-loader and i18next, with handling of pluralization and parameters.
---
# How to translate a Symfony and React project.

When starting a new web app, I tend to think that internationalization is not a first-version concern. However, as soon as your side project starts becoming a real world app, your user base grows, and when you don’t live in in an English-speaking country (such as France), you might want your application to be available in English.

Although it’s often pretty straightforward to handle internationalization in Symfony or in React on their own, handling it in both at the same time appeared to be quite the challenge for us at [Windoo](https://windoo.fr).

In this post I will talk about how we maintain a single translation file for both Symfony & React. I will not address the user-side of handling locale setting as it is not the challenge here.

Translating in a Symfony project
================================

You need to have the [translation component](https://symfony.com/doc/current/components/translation.html) set up. You probably need only to require it and use it right away if you’re using Flex (and your probably should):

```shell
composer require symfony/translation
```

If you are using twig for your templates (pages, emails…), you will need to have your translations in a file such as `messages.fr.yml` and `messages.en.yml` and use the following syntax in your templates:

```html
{% raw %}
<a href="{{ path('booking_list') }}">  
    {{ 'common.see_all'|trans }}  
</a>
{% endraw %}
```

If you want to translate a message in a controller or in service, you need to inject the `TranslatorInterface` service:

```php
public function greet(TranslatorInterface $translator): Response  
{ 
    return new Response($translator->trans('common.greet'));  
}
```

You end up with a big yaml file such as:

```yaml
common:  
   post: Post   
   greet: Hello !  
   see_all: See all  
   hide: Hide  
   home: Home  
post:  
   title: Post title
```

This way, you can use the Yaml keys in your translation. You can handle your local with Symfony, have two files, one in English, one in French, and there you go!

> Before some people comment I should be using _xliff_ format instead, I can already address the matter: I find Yaml translations files a whole lot easier to maintain and visualize on a single-dev project. The _xliff_ format is the official recommended by Symfony’s best practices, but the conversion is pretty easy if needed. More on that later in the article.

Translating in a React project
==============================

In react there a big standard lib called [react-i18next](https://react.i18next.com/). Once you set it up, you end up using it like this (there are _several_ options).

```js
import { useTranslation } from 'react-i18next';  
const { t, i18n } = useTranslation();export default () => <h1>{t('common.greet')}</h1>
```

And your translations’ initialization would look like this:

```js
import i18n from "i18next";  
import { initReactI18next } from "react-i18next";

i18next  
  .use(initReactI18next)  
  .init({  
    resources: {  
      en: {  
        common: {  
          greet: 'hello!'  
        },  
        post: {  
          title: 'Post title'  
        }  
      },  
      fr: {  
        common: {  
          greet: 'Bonjour !'  
        },  
        post: {  
          title: 'Titre d\'article'  
        }  
      }  
    }  
  });
```

So you basically need to have a JSON file that can be parsed in a Javascript object.

Using React and Symfony translations together
=============================================

Now this is all good when you work on a React SPA or a Symfony full-stack project. But when you start to share your translations across both projects, things become…messy.

For starters, the Symfony and React-i18n format are not exactly compatible. React-i18n doesn’t read xliff format, neither yaml…actually it only wants a javascript object in JSON-style as an input file. So this way, that’s also one of the reason we didn’t use the _xliff_ format for storing our translations.

In order to use the same source file for both projects at [Windoo](https://windoo.fr), we decided to script an on-the-fly conversion of the Symfony format to be injected into the React project. To do this we used a yaml loader to convert the yaml files into a javascript object.

```js
// utils/i18n.js
import **_i18n_** from 'i18next';  
import { **_initReactI18next_** } from 'react-i18next';  
import frYaml from 'js-yaml-loader!./translations/messages.fr.yml';  
import enYaml from 'js-yaml-loader!./translations/messages.en.yml';

i18n  
  .use(**_initReactI18next_**)  
  .init({  
    resources: {  
      fr: {  
        translation: frYaml,  
      },  
      en: {  
        translation: enYaml,  
      },  
    },  
    lng: (**_window_** && **_window_**.locale) || 'fr',  
    fallbackLng: 'fr',  
  });  
  
export default i18n;
```

This way , we only need to update one type of file (and I prefer the Yaml format, it’s prettier to look at and easier to search), and both projects can use the shared translations. All good to go, right ?

Beware of pluralization and parameters !
========================================

We thought we had it all figured out because the previous works for simple strings such a button labels, titles… but then it became rapidly clear that the plural formulations were not mapped the same way, and led to broken translation in front. For the same reason too the template translations with variables did not work at all.

Here is the react-i18n syntax for pluralization in the keys dictionary:

```json
{  
  "key": "item",  
  "key_plural": "items",  
  "keyWithCount": "{{count}} item",  
  "keyWithCount_plural": "{{count}} items"  
}
```

And here is the Symfony syntax for the same thing:

```yml
key: "item|items",  
keyWithCount: "%count% item|%count% items"
```

And I’m not even talking about the [full format for each language](https://symfony.com/doc/4.4/translation/message_format.html#pluralization). We only translate French and English (as of today at least), so we kept using the simple syntax.

So the _number_ of keys is not the same in React and this makes it way more difficult to transpose from Symfony-Yaml to i18n-JS. Ideally we would like to use the ICU Format for both, but I could find sufficient documentation for this. We might come back to it later in due time.

Parameter format
----------------

Before injecting the file, we updated all parameter syntax with a simple function handling all entries:

```js
{% raw %}
// replace all %param% with {{param}}   
elem = **_String_**(elem).replace(/%(\[^%\]+(?=%))%/gi, '{{$1}}');
{% endraw %}
```

Then we had to split the plural keys in Symfony

```js
const newObject = {}; // the new translation dictionary

//while looping on the keys  
if (elem.includes('|')) {  
  const plural = elem.split('|');  
  newObject[key] = plural[0];  
  newObject[`${key}_plural`] = plural[1];  
}
```

And this way, we made a full transposition of our translations on-the-fly from Symfony to React. Here a complete example in one gist:

a full i18n.js service you could import in your project

After that, you simply import your new `i18n.js` service into your React components and _voilà_ !

I will write a follow-up on how we improved this workflow with a translation service in the middle, using [Localize](https://localise.biz/).
