# Dahu Weight Shipping — résumé technique

Document interne. C'est le seul fichier de cette extension qui mentionne
Annad : il sert à relier la version publique à sa source, à expliquer les
choix qui ne se devinent pas à la lecture, et à lister ce qui reste à
vérifier sur une vraie installation.

Version portée : `dahu-post` 2.7.0 (fichier unique, 4 050 lignes).
Version publique : 1.0.0.

---

## 1. Structure

```
dahu-weight-shipping.php        amorce : constantes, i18n, requires, liens
includes/
  grid.php                      grille au poids : défaut, format, parseur, lookup
  packages.php                  lecture d'un panier ou d'une commande en UN colis facturable
  shipping-method.php           Dahu_Weight_Shipping_Method + enregistrement
  calculators.php               registre des calculateurs (commandes manuelles)
  diagnostics.php               garde-fou « aucune méthode disponible »
  order-ajax.php                les cinq points d'entrée AJAX
  order-screen.php              panneau de poids, mise en file des assets
assets/
  css/admin-order.css           jetons d'admin partagés + écran de commande
  js/admin-order.js             l'écran de commande
uninstall.php
```

2 633 lignes de PHP, 1 273 de JS, 387 de CSS — contre 4 050 lignes dans un
seul fichier pour la version Annad.

Le PHP ne descend donc pas, et c'est délibéré : la densité de commentaires est
plus forte (chaque piège du §2 est expliqué là où il se trouve), et le fichier
d'origine portait son JS à l'intérieur. En retirant les 1 123 lignes de JS et
de CSS injectés, la version Annad fait environ 2 930 lignes de PHP, dont 667 pour le
bloc Colissimo retiré (§5.2) : à périmètre comparable, le PHP a grossi en
commentaires et en gardes.

### Le découpage et sa raison

`packages.php` est le fichier délicat, et c'est exactement pour cela qu'il est
seul. Il répond à une seule question — *quels articles cette expédition pèse,
et combien* — et il y répond identiquement au panier et en commande. Deux
implémentations de « combien pèse cette commande » divergent à la première
évolution, et le symptôme est un prix qui diffère entre le checkout du client
et l'écran du gestionnaire, sans que ni l'un ni l'autre ne puisse voir
pourquoi.

`calculators.php` existe parce que la version Annad avait **deux** chemins de
calcul : le nôtre, et une reproduction de la tarification de Colissimo. Le
second est parti (§5.2), mais le point d'extension reste.

---

## 2. Les pièges qui ont coûté du temps

Ceux-ci viennent de la version Annad, et sont conservés avec leur
justification dans le code. Les répéter ici n'est pas une redite : ce sont les
endroits où une « simplification » de bonne foi casse quelque chose
d'invisible.

### 2.1 Un poids composite de zéro n'est pas un poids (`packages.php`)

Les extensions de kit écrivent bel et bien la chaîne `"0"` dans
`_composite_weight` quand tous les composants sont réglés en « expédié
individuellement ». Une garde qui ne teste que la *présence* de la
métadonnée la franchit, la fonction retourne 0, et le repli par somme des
composants n'est **jamais** atteint : un kit entier pèse zéro. Un prix faux et
parfaitement plausible.

Un kit de poids réellement nul n'existe pas — ce sont des produits physiques.
Il n'y a donc aucun cas légitime à distinguer d'une absence.

### 2.2 Le conteneur d'un kit peut être virtuel

Il doit malgré tout être compté : c'est **lui** qui porte la classe
d'expédition du kit. Lui appliquer le filtre `needs_shipping()` habituel fait
disparaître le kit du calcul, donc ses règles de classe avec. Même piège aux
trois endroits qui lisent un colis — `build_package_contents_raw()`,
`get_package_classes()`, `get_quantity_per_class()` — et il n'est corrigé
qu'aux trois à la fois.

### 2.3 Le clone de poids

`get_grid_weight()` lit le poids via `$product->get_weight()`, c'est-à-dire le
poids *propre* du produit. Le poids effectif d'un kit vient de la ligne de
commande, information qui n'existe pas sur le produit conteneur. D'où un
**clone** du produit dont le poids est forcé — jamais enregistré, le temps du
calcul seulement.

### 2.4 Le repli ramené à UN kit

La somme des poids de composants couvre **tous** les kits de la ligne. Elle
est ajoutée à un poids **unitaire**, que le colis remultiplie ensuite par la
quantité : sans division par la quantité, N kits sont comptés N² fois.

### 2.5 Le format des champs de prix WooCommerce (`admin-order.js`)

WooCommerce attache aux champs `.wc_input_price` un assainisseur sur
`keyup change` qui **supprime** tout caractère hors `[0-9]`, `-`, `%` et le
séparateur décimal du site. Sur une boutique en virgule décimale, écrire
`8.33` puis déclencher `change` fait disparaître le point : la valeur devient
`833`, et la TVA se calcule correctement sur cette base fausse.

Ce n'est pas une confusion point/virgule des milliers. Le point est purement
effacé, ce qui multiplie le montant par 100 pour deux décimales.

D'où un **unique** point de passage pour toute écriture dans un champ de prix,
`setCost()`, où le formatage est fait une fois.

### 2.6 `window.confirm` et le `try/finally`

Le clic « Recalculer » rejoué contourne la confirmation de WooCommerce : c'est
la conséquence directe d'un clic déjà fait, et un recalcul de totaux ne
détruit rien.

Sans le `try/finally`, une exception levée par un gestionnaire WooCommerce
pendant ce clic laisserait `window.confirm` écrasé pour **tout le reste de la
page** : toute confirmation ultérieure, y compris une suppression, serait
acceptée en silence.

### 2.7 La fenêtre d'incohérence base/écran

Une ligne créée par la requête native `add_order_shipping` reste en base avec
`method_id` et `method_title` vides, alors que l'écran les affiche remplis :
les `.val()` du JS ne peignent que le DOM, et ces valeurs ne partent au
serveur qu'au « Mettre à jour ». Entre les deux, tout code qui relit la ligne
voit un état qui ne correspond pas à l'écran.

Cette fenêtre a produit deux bugs distincts dans la version Annad. Elle est
refermée en écrivant les trois champs — instance, méthode, libellé — côté
serveur dès la création.

### 2.8 Le verrou de modification manuelle doit fonctionner sans AJAX

Il faut pouvoir reconnaître une ligne gérée **dès l'ouverture de la page**,
sans attendre une requête : quelqu'un corrige un prix à la main sans jamais
toucher au bouton. La version Annad injectait pour cela une liste de
*fragments de libellé*. La version publique lit l'identifiant de méthode
exact dans le DOM (§5.4) — même immédiateté, sans l'approximation.

---

## 3. Ce qui n'est délibérément pas fait

- **Aucun tarif de repli** quand un panier se retrouve sans méthode. Un prix
  fictif masquerait une zone mal configurée tout en facturant un montant qui
  ne correspond à aucun contrat transporteur : la boutique le découvrirait sur
  la facture. Le garde-fou constate et journalise.
- **Rien d'écrit dans le champ coût** quand la méthode ne s'applique pas à la
  commande. `0 €` laisserait croire à une livraison offerte, ce qui est une
  autre affirmation.
- **Aucune TVA calculée** en commande manuelle. `add_rate()` la calcule selon
  le client courant, contexte absent ou faux en admin. Elle reste au
  « Recalculer » natif.
- **Aucun rafraîchissement automatique du poids**. Le poids ne peut pas être
  calculé côté navigateur : ni les poids unitaires ni les métadonnées de kit
  ne figurent dans le DOM de l'écran de commande. Chaque rafraîchissement est
  un aller-retour serveur ; le faire à chaque frappe rendrait l'écran
  inutilisable.

---

## 4. Désinstallation

Rien de global à supprimer : chaque réglage appartient à une instance de zone,
que WooCommerce supprime avec sa zone.

Le drapeau `_dahu_weight_shipping_manual_cost` posé sur une ligne
d'expédition dont le coût a été corrigé à la main est **conservé**. Il est
inerte sans l'extension, et une désinstallation est souvent une étape d'une
réinstallation : le supprimer annulerait en silence une décision prise ligne
par ligne, et le recalcul suivant écraserait des prix corrigés exprès.

Option `dahu_weight_shipping_remove_data_on_uninstall` pour les supprimer
quand même.

---

## 5. Portage depuis la version Annad

### 5.1 Correspondance

| Annad (`dahu-post` 2.7.0) | Public (1.0.0) |
|---|---|
| `dahu_chronopost` | `dahu_weight_shipping` |
| `Dahu_Post_Shipping_Chronopost` | `Dahu_Weight_Shipping_Method` |
| `dahu_post_default_grid` | `dahu_weight_shipping_default_grid` |
| `dahu_post_format_weight_grid` | `dahu_weight_shipping_format_grid` |
| `dahu_post_parse_weight_grid` | `dahu_weight_shipping_parse_grid` |
| `dahu_post_get_price_for_weight` | `dahu_weight_shipping_price_for_weight` |
| `dahu_post_is_composite_component` | `dahu_weight_shipping_is_kit_component` |
| `dahu_post_is_composite_container` | `dahu_weight_shipping_is_kit_container` |
| `dahu_post_get_effective_item_weight` | `dahu_weight_shipping_effective_item_weight` |
| `dahu_post_cart_item_kit_parent` | `dahu_weight_shipping_item_kit_parent` |
| `dahu_post_billable_package` | `dahu_weight_shipping_billable_package` |
| `dahu_post_with_bundle_shipping_view` | `dahu_weight_shipping_with_bundle_view` |
| `dahu_post_build_package_contents` | `dahu_weight_shipping_build_package_contents` |
| `dahu_post_collect_items_with_live_qty` | `dahu_weight_shipping_collect_items_with_live_qty` |
| `dahu_post_get_order_total_weight` | `dahu_weight_shipping_order_total_weight` |
| `dahu_post_order_has_free_shipping_coupon` | `dahu_weight_shipping_order_has_free_shipping_coupon` |
| `_dahu_post_manual_cost` | `_dahu_weight_shipping_manual_cost` |

### 5.2 Sections retirées

**Le bloc Colissimo (lignes 1169 à 1866, soit 667 lignes nettes).** Dix fonctions
qui *reproduisaient* la tarification de « Colissimo Shipping Methods for
WooCommerce » à partir de ses réglages stockés, au lieu de l'appeler — leur
`calculate_shipping()` lit le panier par `WC()->cart->get_cart()` en dur, donc
inutilisable en commande manuelle.

Ce n'était pas une API supportée par l'éditeur. Toute mise à jour de leur
extension pouvait invalider le calcul **sans aucune erreur visible** : le prix
restait plausible. Cela n'a pas sa place dans une extension publique, où
personne ne surveillerait cette dérive.

Le *point d'extension*, lui, reste : `calculators.php` et le filtre
`dahu_weight_shipping_calculators`. Un transporteur — ou une extension-pont
pour lui — déclare son propre calculateur. La règle qui ne doit pas différer
d'un transporteur à l'autre, la gratuité par coupon, est appliquée une seule
fois pour tous.

**La migration one-shot (section 11, lignes 3851 à 3934, soit 83 lignes).** Recopiait une grille
globale de la version 1.4.1 vers les instances. Il n'y a rien à migrer dans
une extension neuve.

**`dahu_post_js_literal()`.** N'existait que parce que le script était injecté
en `admin_footer` : `echo false` n'affiche rien, la ligne devient `var x = ;`,
et **tout** le script meurt. Les assets mis en file ne peuvent pas échouer
ainsi.

**`dahu_post_get_managed_label_fragments()` et sa table.** Remplacée par la
lecture de l'identifiant exact (§5.4).

**`dahu_post_get_shipping_class_id_by_slug()`.** Plus de slug codé en dur à
résoudre.

### 5.3 Dé-spécialisation : les deux classes codées en dur

C'est le cœur du portage.

La version Annad connaissait deux classes d'expédition **par leur slug**,
écrit dans le code (`DAHU_POST_CONFIGURATEUR_SLUG`,
`DAHU_POST_VELO_COMPLET_SLUG`) :

- *Vélo complet* : toujours à tarif fixe, jamais dans le calcul au poids
  (transporteur séparé).
- *Configurateur* (Kit) : dès qu'un kit est présent, **toute** la commande
  part gratuitement — aucun poids n'entre dans la grille, ni le sien ni celui
  des produits sans classe qui l'accompagnent.

Les deux sont devenues des réglages :

| Règle Annad | Réglage public |
|---|---|
| *Vélo complet* hors du calcul au poids | **Classes exclues de la grille** (`flat_rate_classes`) |
| Un *Configurateur* annule le poids de toute la commande | **Classes qui annulent la grille** (`weight_waiving_classes`) |
| Un *Configurateur* rend la commande gratuite | **Classes ouvrant droit à la gratuité** + « un seul article suffit » (réglages déjà présents en 2.7.0) |

La configuration Annad se reproduit donc intégralement :
`flat_rate_classes = {kit, vélo}`, `weight_waiving_classes = {kit}`,
`classes_free_shipping = {kit}`, `free_for_whole_cart = oui`,
`class_cost_<vélo> = 45`.

Une généralisation de ce genre se rate facilement, et le symptôme serait un
prix plausible plutôt qu'une erreur. La section 13 du banc fonctionnel
reproduit cette configuration et vérifie les prix que les règles codées en dur
produisaient — notamment le cas qui motivait le réglage : les 22 kg du vélo ne
doivent **pas** faire monter le colis voisin dans la grille (64 € et non 55 €).

`DAHU_POST_ALLOWED_COUNTRIES` (`FR`, `MC`, `AD`) est devenu le filtre
`dahu_weight_shipping_watched_countries`, vide par défaut, donc tous pays.

### 5.4 Détection d'une ligne gérée : par identifiant, plus par libellé

La version Annad reconnaissait une ligne d'expédition gérée en cherchant un
**fragment du nom du transporteur** dans le champ « Nom » de la ligne
(`« chronopost »`, `« colissimo »`). Deux dettes connues en découlaient, les
deux consignées dans son propre `RESUME_TECHNIQUE.md` :

1. Une instance renommée échappait à la détection — les titres sont du texte
   libre.
2. La recherche s'arrêtait à la **première** ligne gérée : une commande à deux
   lignes gérées n'en voyait recalculer qu'une.

Et une troisième, non consignée : la détection est textuelle, donc dépendante
de la langue de la boutique.

Vérification faite dans le template de WooCommerce
(`includes/admin/meta-boxes/views/html-order-shipping.php`) : chaque ligne
d'expédition porte un `<select class="shipping_method">` **dont la valeur est
l'identifiant de méthode**, rendu inconditionnellement — `$shipping_methods`
est chargé sans condition par la vue parente, et le `<select>` existe même
quand la commande n'est plus modifiable. Il vit dans un `div.edit` masqué
jusqu'à l'ouverture de la ligne, ce qui ne change rien : lire un champ masqué
est aussi fiable que lire un champ visible.

Les trois dettes disparaissent donc ensemble. `findManagedRows()` retourne
**toutes** les lignes gérées, et `fillShippingCosts()` les traite en série.

### 5.5 Les assets sortis du PHP

1 096 lignes de JS et 27 de CSS étaient injectées en `admin_footer`. Elles
sont devenues `assets/js/admin-order.js` (1 273 lignes) et
`assets/css/admin-order.css` (387), avec `wp_localize_script()`.

Ce n'est pas une question de propreté. Un bloc en ligne est à une
interpolation ratée d'une `SyntaxError` qui tue toutes les lignes suivantes —
y compris le bouton, créé en toute fin — et rien à l'écran ne le dit. C'est
arrivé en 2.3.3 sur un appel à une fonction WooCommerce qui n'existait pas.

Bénéfices secondaires : mise en cache par le navigateur, `?ver=` pour
l'invalidation, et `dahu_post_js_literal()` qui disparaît.

### 5.6 Internationalisation

Toutes les chaînes visibles passent par `__()` / `esc_html__()` /
`_x()`, text domain `dahu-weight-shipping`. Les chaînes du JS arrivent par
`wp_localize_script()`, traduites côté PHP : pas de `wp_set_script_translations()`
ni de fichiers JSON à produire.

Les lignes de journal restent en anglais et **non traduisibles** : un journal
technique est lu pendant un incident, souvent par quelqu'un d'autre que
l'administrateur de la boutique, et il est plus utile si sa formulation ne
dépend pas de la locale active.

Un poids affiché est formaté **côté serveur** (`number_format_i18n()`) et
transmis formaté avec le nombre brut. Le navigateur n'a pas à devenir
l'autorité sur les conventions numériques du site, et le seul séparateur dont
il dispose — celui des prix WooCommerce — est un autre réglage, qu'une
boutique peut fixer différemment.

### 5.7 Correctifs apportés au passage

Ces défauts existent dans la version Annad. Trois ont été trouvés par les
comparateurs, un par le banc.

1. **`$_POST['live_items']` non validé** (`collect_items_with_live_qty`).
   `wp_unslash()` d'une valeur POST envoyée sous forme de tableau retourne un
   tableau, et `json_decode(array)` est une `TypeError` en PHP 8. Un JSON de
   scalaires faisait de même sur `$live_item['order_item_id']`. Deux gardes
   ajoutées. **Atteignable par une requête forgée.**
2. **`preg_split()` non vérifié** (`parse_grid`). Retourne `false` en cas
   d'erreur, et le `foreach` suivant émettait un avertissement.
3. **`end($grid)['price']` non vérifié** (`price_for_weight`). Une grille
   fournie par filtre peut porter un palier mal formé.
4. **Instance de zone supprimée** (`get_method`) — le plus grave, trouvé par
   le banc. La version Annad fait
   `new Dahu_Post_Shipping_Chronopost( $instance_id )` sans vérifier que
   l'instance existe. Le constructeur accepte n'importe quel nombre ;
   `init_settings()` lit une option absente, et **tous** les réglages
   retombent sur leurs valeurs par défaut — y compris la grille tarifaire.
   Une ligne pointant vers une instance retirée de sa zone est donc facturée
   depuis une grille que personne n'a configurée, et le prix ressemble à un
   vrai prix. La version publique passe par
   `WC_Shipping_Zones::get_shipping_method()`, qui interroge la base, et
   revérifie que la méthode retournée est bien la nôtre — une ligne peut
   porter l'identifiant d'une méthode et l'instance d'une autre.

**Sortie morte supprimée** : `analyze_cart_weights()` calculait et retournait
`configurateur_unit_weight`, jamais lu par aucun appelant, avec un commentaire
expliquant l'approximation qu'il représentait. Le drapeau `has_sans_classe`
était lui aussi redondant avec le test `$grid_weight > 0`.

**Changement de comportement assumé** : les coûts fixes de classe sont
désormais facturés pour **toutes** les classes qui en ont un configuré. La
version Annad n'ajoutait que ceux des deux classes codées en dur, alors que
l'écran de réglages propose un champ par classe : un coût saisi sur une
troisième classe était ignoré en silence. À vérifier sur annad.fr (§7).

### 5.8 Méthode de vérification

**Deux comparateurs, jamais un.**

`_harness/fnskel.php` imprime le squelette de tokens d'**une** fonction
nommée, avec une table de renommage appliquée aux identifiants. Résultat sur
les quatorze fonctions reportées :

| | |
|---|---|
| Identiques au token près | 9 / 14 |
| Différentes, écarts tous vérifiés un par un | 5 / 14 |

Les neuf identiques couvrent toute la logique de kit, la partie délicate. Les
cinq écarts sont exclusivement : `0` → `0.0` sur des retours de type `float`,
les trois gardes du §5.7, l'enveloppement `__()` de deux messages d'erreur, et
un `return` anticipé remplaçant un `else`.

`_harness/markup.js` liste tous les **contrats DOM** qu'un script utilise —
sélecteurs, identifiants, classes, noms d'attributs — lus dans l'AST d'un vrai
analyseur, jamais par expression régulière. Ce second comparateur est
indispensable : le premier imprime `STR` à la place du contenu de chaque
chaîne, donc un sélecteur changé silencieusement d'un attribut à un autre le
traverse proprement, et la fonctionnalité est simplement morte dans le
navigateur sans que rien n'échoue nulle part. C'est arrivé une fois sur ce
projet.

Pour comparer un script écrit *dans* du PHP, `_harness/extract-inline.php`
reconstruit le HTML/JS brut en remplaçant chaque bloc PHP par le littéral
`null` — le résultat est du JavaScript analysable. Jamais de regex sur du PHP :
elle se désynchronise sur la première apostrophe d'un commentaire français ou
le premier `//` dans une chaîne.

Résultat : **aucun** contrat appartenant à WooCommerce n'a été perdu dans la
réécriture. Les 23 contrats communs couvrent `.add-line-item`,
`.add-order-shipping`, `.calculate-action`, `.shipping_method_name`,
`.wc-order-add-item`, `.wc-order-bulk-actions`, `#order_line_items`,
`#order_shipping_line_items`, `#post_ID`, `#woocommerce-order-items`,
`#_shipping_country`, `#_billing_country`, `input.quantity`,
`select.shipping_method`, `tr.item`, `tr.shipping`, `data-order_item_id`.
Les seuls écarts sont les noms appartenant à l'extension, renommés exprès.

Idem côté CSS : les trois sélecteurs de la feuille d'origine
(`.wc-order-add-item`, `.add-order-shipping`, et sa propre classe de garde) se
retrouvent tous.

**Harnais statiques** : `test-conformite.php`, `test-i18n.php`,
`test-i18n-assets.php` — tous `SUCCES`.

**Banc fonctionnel** : `_bench/t-weight-shipping.php`, **102 tests, 0 échec**,
idempotent sur trois exécutions consécutives, contre un vrai WordPress 7.1.3 +
WooCommerce 11.1.2 avec une vraie base. Treize sections : grille, tarification
au poids, classes requises/exclues, gratuité, coûts de classe, classes hors
grille, classes annulant la grille, pliage des composants de kit, plusieurs
kits sur une ligne, lecture d'une commande, registre des calculateurs,
gratuité par coupon, et l'équivalence Annad.

Le banc a trouvé le défaut n°4 du §5.7 — celui que l'analyse statique ne
pouvait pas voir.

### 5.9 Non porté

`DAHU_POST_MANUAL_COST_META_KEY` était une constante `const` au milieu du
fichier ; c'est un `define()` dans l'amorce, comme les autres.

La priorité `1000` du garde-fou `woocommerce_package_rates` est conservée :
après toutes les méthodes de la zone, extensions tierces comprises.

---

## 6. Retrouver le comportement Annad

À coller dans les réglages de l'instance, zone France, après avoir créé les
classes d'expédition correspondantes :

**Grille tarifaire au poids** (celle de `dahu_post_default_grid()`) :

```
0-2:18
2-4:19
4-6:22
6-9:25
9-12:27
12-16:29
16-30:55
```

**Classes exclues de la grille** : *Configurateur*, *Vélo complet*
**Classes qui annulent la grille** : *Configurateur*
**Classes ouvrant droit à la gratuité** : *Configurateur*
**Un seul article suffit** : coché
**Coût pour « Vélo complet »** : le tarif du transporteur séparé
**Coût pour « Configurateur »** : vide (ou 0)

Le masquage croisé des transporteurs (Chronopost pour un panier à kit ou à
vélo, Colissimo sinon) se configure comme en 2.7.0, des deux côtés : par les
« Classes exclues » de chaque méthode. Ce n'était déjà plus du code en 2.0.0.

Pour la tarification Colissimo en commande manuelle, il faut une
extension-pont déclarant un calculateur pour `lpc_relay`, `lpc_sign`,
`lpc_nosign` et `lpc_expert` via `dahu_weight_shipping_calculators`. Le code
retiré du §5.2 en est le point de départ — avec son avertissement : ce n'est
pas une API supportée, et une mise à jour de leur extension peut l'invalider
sans aucune erreur visible.

---

## 7. Points à vérifier sur une vraie installation

Le banc couvre la logique. Ceux-ci ne se vérifient qu'avec un navigateur sur
un vrai écran de commande, et sont les seuls endroits où je n'ai que du
raisonnement à offrir.

1. **Le bouton « Ajouter la livraison » et son sélecteur.** Le sélecteur est
   désormais une liste de boutons rendue **dans le flux**, sous la barre
   d'actions, et non un popover positionné en absolu : un popover devrait
   supposer un ancêtre positionné et aucun `overflow` masquant, qui sont des
   internes de WooCommerce et pas un contrat. À vérifier : la barre grandit
   proprement, la liste est lisible, le clic hors liste et la touche Échap la
   referment.
2. **La reconnaissance par identifiant de méthode** (§5.4). Vérifiée dans le
   template, jamais exécutée dans un navigateur. À vérifier : renommer une
   instance et constater que « Recalculer » la traite toujours.
3. **Plusieurs lignes gérées sur une commande.** Nouveau comportement : les
   deux sont recalculées, en série. À vérifier sur une commande à deux lignes.
4. **Le séparateur décimal.** Sur une boutique en virgule — annad.fr en est
   une, le banc est en point. C'est le §2.5, et c'est le défaut le plus
   coûteux de la liste : un coût multiplié par 100 avec une TVA correcte par
   dessus.
5. **Les coûts fixes sur une troisième classe** (§5.7, changement assumé). Si
   annad.fr a un coût saisi sur une classe autre que *Configurateur* et *Vélo
   complet*, il était ignoré et sera désormais facturé.
6. **Le panneau de poids sur les deux écrans de commande**, HPOS et stockage
   historique.
7. **L'interception de « Recalculer »** quand une extension tierce branche
   aussi ce bouton.
