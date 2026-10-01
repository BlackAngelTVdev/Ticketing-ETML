# GLPI Branding

Plugin GLPI qui remplace **tous les logos GLPI** (menu du haut, barre
latérale repliée, page de connexion, thème sombre et icône de l'onglet du
navigateur) et corrige quelques éléments d'interface.

Tout se règle depuis **Configuration ▸ Plugins ▸ Branding** : aucun
fichier de code n'est à modifier.

## Installation

1. Copiez le dossier `branding` dans `glpi/plugins/` (il doit être nommé
   exactement `branding`, sans tiret).
2. Installez et activez le plugin :
   * UI : *Configuration ▸ Plugins* → **Branding** → *Installer* → *Activer*,
   * CLI :
     ```bash
     php bin/console plugin:install branding
     php bin/console plugin:activate branding
     ```
3. Ouvrez **Configuration ▸ Plugins ▸ Branding** (bouton *Configuration*)
   et téléversez vos logos.

## Configuration

La page de configuration (`/plugins/branding/front/config.php`, réservée
aux profils ayant le droit de modifier la configuration) permet de :

* **téléverser un logo par emplacement** — un simple aperçu, la source
  actuelle et les boutons *Téléverser* / *Supprimer* sont affichés pour
  chaque emplacement. Formats acceptés : `png`, `svg`, `webp`, `jpg`,
  `jpeg`, `gif` (et `ico` pour le favicon), 2 Mo maximum ;
* **afficher ou masquer le bouton « Afficher sur la carte »** des listes
  de résultats (masqué par défaut, voir plus bas).

Les logos téléversés sont enregistrés dans
`files/_plugins/branding` (hors du code du plugin). Un logo encore fourni
avec le plugin (`pics/logo.png`, voir `pics/README.md`) sert de secours et
reste utilisé tant qu'aucun logo n'a été téléversé pour cet emplacement.

## Comment ça marche

GLPI 11 affiche ses logos via des variables CSS déclarées dans
`css/includes/_base.scss` :

```scss
:root {
    --glpi-logo: var(--glpi-logo-light);
    --glpi-logo-light-reduced: url("../pics/logos/logo-G-100-white.png");
    --glpi-logo-dark-login: url("../pics/logos/logo-GLPI-250-black.png");
    // ...
}

.page            .glpi-logo { background: var(--glpi-logo) no-repeat; }
.page-anonymous  .glpi-logo { content: var(--glpi-logo-dark-login); }
```

Le plugin ne touche à aucun fichier de GLPI : il redéfinit ces variables
via `css/branding.css`, chargé **après** le cœur et les thèmes. Chaque
valeur pointe vers `front/logo.php`, un petit endpoint qui sert le fichier
correspondant (téléversé ou fourni avec le plugin, avec cache HTTP géré
par `ETag`). Le favicon, lui, ne pouvant pas être changé en CSS, est géré
par `js/branding.js`.

Hooks utilisés (voir `setup.php`) :

* `config_page` → lien *Configuration* dans la liste des plugins ;
* `add_css` / `add_css_anonymous_page` → surcharge des variables et
  tweaks d'interface ;
* `add_javascript` / `add_javascript_anonymous_page` → favicon ;
* `Firewall::addPluginStrategyForLegacyScripts()` → `front/logo.php`
  accessible aux visiteurs non connectés (nécessaire pour la page de
  connexion).

## Emplacements des logos

Chaque emplacement est facultatif et retombe sur `logo` tant qu'il n'est
pas personnalisé :

| Emplacement                     | Variable GLPI surchargée   |
| ------------------------------- | -------------------------- |
| Logo principal (partout)        | `--glpi-logo`              |
| Logo sur fond sombre            | `--glpi-logo-light`        |
| Logo sur fond clair             | `--glpi-logo-dark`         |
| Logo réduit (barre latérale)    | `--glpi-logo-reduced`      |
| Logo réduit fond sombre         | `--glpi-logo-light-reduced`|
| Logo réduit fond clair          | `--glpi-logo-dark-reduced` |
| Logo page de connexion          | `--glpi-logo-dark-login`   |
| Logo connexion thème sombre     | `--glpi-logo-light-login`  |
| Logo connexion thème clair      | `--glpi-logo-dark-login`   |
| Favicon (onglet)                | *(lien `<link rel=icon>`)* |

> Un logo à fond transparent est recommandé. Si votre logo a du texte
> foncé, personnalisez aussi « fond sombre » (version claire/blanche) pour
> qu'il reste lisible sur le menu sombre.

## Tweaks d'interface

En plus des logos, le plugin corrige des éléments d'interface inadaptés au
ticketing. Le seul tweak actuel est réglable depuis la page de
configuration :

* **Bouton « Afficher sur la carte » masqué par défaut** (issue #3). GLPI
  affiche un sélecteur tableau/carte au-dessus de toute liste dont
  l'élément possède une localisation — les tickets le sont toujours —,
  alors qu'il n'apporte rien à une instance de ticketing et encombre la
  barre d'outils. Seul le bouton carte est masqué : le bouton « Afficher
  sous forme de tableau » et la vue tableau restent disponibles. La case
  *Afficher le bouton « Afficher sur la carte »* permet de le rétablir.

## Fichiers

```
plugins/branding/
├── setup.php          métadonnées + hooks
├── src/Logo.php       résolution et stockage des logos
├── src/Settings.php   réglages (Config GLPI)
├── front/config.php   page de configuration
├── front/logo.php     sert le logo demandé (public, mise en cache)
├── css/tweaks.css     corrections d'interface
├── css/branding.css    surcharge des variables --glpi-logo-*
├── js/branding.js     remplacement du favicon
├── pics/              logos fournis avec le plugin (facultatif)
└── README.md
```
