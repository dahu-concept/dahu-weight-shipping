# Dahu Weight Shipping

Une méthode de livraison WooCommerce au poids, configurable par zone, avec des
règles par classe de livraison et des lignes de livraison en un clic sur les
commandes saisies à la main.

Sous licence MIT. Nécessite WordPress 6.0, PHP 7.4 et WooCommerce 7.0.

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

## À quoi ça sert

Un contrat transporteur est le plus souvent un tableau : jusqu'à deux kilos,
tant ; jusqu'à cinq, tant. Cette extension vous laisse coller ce tableau dans un
champ et tarife chaque panier à partir de lui. Le tableau appartient à la zone de
livraison : un second pays avec un second contrat est une seconde instance avec
son propre tableau, et non une règle conditionnelle.

## La grille

Une tranche par ligne, `min-max:prix`, les poids dans l'unité de la boutique et
les prix hors taxes :

```
0-2:10
2-5:15
5-10:20
10-30:35
```

Le minimum est **exclu** et le maximum **inclus** : `0-2:10` couvre tout ce qui
dépasse 0 et va jusqu'à 2. Au-delà de la dernière tranche, c'est son prix qui
s'applique.

Les lignes mal formées sont signalées à l'écran plutôt que sautées en silence,
et une grille dont aucune ligne n'est valable n'est pas enregistrée : la
précédente est conservée, parce qu'une grille vide tarife tous les poids à zéro
et se lit comme une livraison offerte.

## Des règles en réglages, pas en code

Par instance de zone :

| Réglage | Effet |
|---|---|
| N'afficher que si le panier contient | La méthode n'apparaît que pour les paniers contenant une de ces classes |
| Masquer si le panier contient | La méthode n'apparaît jamais pour les paniers contenant une de ces classes |
| Exclues de la grille au poids | Le poids de ces produits n'entre jamais dans la grille ; ils ne sont facturés que par leur coût fixe |
| Annulent la grille au poids | Un seul tel produit dans le panier et AUCUN poids n'entre dans la grille, ni le sien ni celui des autres |
| Donnent la livraison offerte | Gratuit pour toute la commande, ou seulement pour une commande qui n'est composée que de ça |
| Coût fixe par classe | Facturé à l'unité, en plus de la grille |

## Commandes manuelles

Un bouton **Ajouter la livraison** sur l'écran de commande liste les méthodes
disponibles pour la destination de cette commande, nomme la zone d'où elles
viennent, et signale lesquelles cette extension sait tarifer. En choisir une
crée la ligne, la rattache à cette instance de zone précise et la tarife, en une
seule requête.

Le prix vient du même `calculate_shipping()` qu'appelle la commande en ligne, sur
la même instance — pas d'une seconde copie des règles de tarification. Une
correction faite à la main désactive définitivement la tarification automatique
pour cette ligne.

Un panneau latéral affiche le poids qui entre dans la grille, issu du même code,
pour que le chiffre montré et le chiffre facturé ne puissent pas diverger.

## Kits et lots

Un kit vendu comme un seul produit est un seul colis. Les composants sont
rattachés à leur kit des deux côtés — panier et commande — pour que le kit soit
pesé et facturé une fois. Reconnus par la méta que chaque extension publie :
Dahu Composite (`dahu_kit_parent`), WooCommerce Composite Products
(`composite_parent`, `_composite_*`) et WooCommerce Product Bundles (par son
propre filtre `get_product_from_item`).

## Étendre

### Tarifer un autre transporteur sur les commandes manuelles

```php
add_filter( 'dahu_weight_shipping_calculators', function ( $calculators ) {
	$calculators['mon_transporteur'] = function ( $order, $instance_id, $items_with_qty ) {
		// Renvoyer le coût hors taxes, plus les lignes qui l'expliquent,
		// ou un WP_Error de code 'manual' ou 'no_rate'.
		return array(
			'cost'    => 12.5,
			'details' => array( 'forfait de mon transporteur' ),
		);
	};
	return $calculators;
} );
```

La gratuité par code promo est appliquée au résultat de **tous** les
calculateurs, en un seul endroit, pour qu'une même commande ne puisse pas sortir
offerte sur une méthode et payante sur une autre.

### Autres points d'extension

| Hook | Type | Rôle |
|---|---|---|
| `dahu_weight_shipping_cost` | filtre | Dernier mot sur un coût, après grille, coûts par classe et gratuité. Pour un supplément que rien ici ne modélise |
| `dahu_weight_shipping_default_grid` | filtre | La grille proposée sur une NOUVELLE instance ; jamais lue par une instance configurée |
| `dahu_weight_shipping_calculators` | filtre | Identifiant de méthode => appelable, pour les commandes manuelles |
| `dahu_weight_shipping_watched_countries` | filtre | Pays que surveille la garde « aucune méthode disponible ». Vide, le défaut, signifie tous |
| `dahu_weight_shipping_no_rate_available` | action | Se déclenche quand un panier se retrouve sans aucune méthode de livraison |

## Limites assumées

* Pas de dimensions, pas de poids volumétrique, pas de nombre de colis.
* Pas d'API transporteur, pas d'étiquettes, pas de suivi.
* Pas de découpage d'un panier en plusieurs colis.
* Un panier resté sans méthode de livraison est **consigné**, jamais masqué par
  un prix inventé.

## Données

Rien à l'échelle du site. Chaque réglage appartient à une instance de zone
WooCommerce, que WooCommerce supprime avec sa zone. La seule chose écrite
ailleurs est un marqueur sur une ligne de livraison dont le coût a été corrigé à
la main, et la désinstallation le conserve — le supprimer annulerait en silence
cette décision, et le recalcul suivant écraserait un prix corrigé exprès.
Définissez l'option `dahu_weight_shipping_remove_data_on_uninstall` pour retirer
ces marqueurs aussi.

## Licence

MIT. Voir `LICENSE`.
