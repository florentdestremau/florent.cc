---
layout: "layout.njk"
title: Florent Destremau
description: Je suis Florent Destremau, CTO Dev PHP/JS freelance.
---

# Bonjour !

<article>

Passionné par **PHP**, **Symfony**, **React** et **Hotwire**, j’accompagne les projets web avec une approche **pragmatique et orientée business**. Fort de **12 ans d’expérience** dont 10 à co-construire [Windoo](https://windoo.fr), je transforme les enjeux métiers en solutions techniques **performantes et scalables**.

**Mon expertise** :
Allier **vision produit**, **leadership technique** et **exécution hands-on** pour livrer des applications à fort impact utilisateur et organisationnel.

</article>

# Mes articles

<article>

{% for post in collections.posts reversed %}
<em>{{ post.date | dateFr }}:</em>
<br>
<a href="{{ post.url }}">{{ post.data.title }}</a>
{% endfor %}

</article>

{% if collections.drafts.length > 0 %}
# Brouillons (local uniquement)

<article>

{% for post in collections.drafts reversed %}
<em>{{ post.date | dateFr }}:</em>
<br>
<a href="{{ post.url }}">{{ post.data.title }}</a> 🚧
{% endfor %}

</article>
{% endif %}
