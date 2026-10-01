# Logos fournis avec le plugin (facultatif)

Le plus simple est de téléverser vos logos depuis **Configuration ▸
Plugins ▸ Branding** : ils sont alors enregistrés hors du code, dans
`files/_plugins/branding`, et un logo téléversé est toujours prioritaire
sur un fichier présent ici.

Ce dossier permet donc seulement d'**expédier un logo par défaut avec le
plugin** (utile pour un déploiement/dépôt Git). Placez-y le fichier, il
sera servi tant qu'aucun logo de ce type n'est téléversé via la page de
configuration.

Extensions acceptées : `png`, `svg`, `webp`, `jpg`, `jpeg`, `gif`
(et `ico` pour le favicon). Les fichiers placés à la racine du plugin
(juste à côté de `setup.php`) sont aussi détectés.

## Nom du fichier

| Fichier                      | Utilisé pour                                            |
| ---------------------------- | ------------------------------------------------------- |
| `logo.png` *(requis)*        | tout, si aucune variante ci-dessous n'est fournie       |
| `logo-light.png`             | logo sur fond sombre (menu du haut)                     |
| `logo-dark.png`              | logo sur fond clair                                     |
| `logo-reduced.png`           | petit logo carré (barre latérale repliée)               |
| `logo-login.png`             | logo de la page de connexion                            |
| `logo-light-reduced.png`     | idem « reduced » sur fond sombre                        |
| `logo-dark-reduced.png`      | idem « reduced » sur fond clair                         |
| `logo-light-login.png`       | page de connexion, thème sombre                         |
| `logo-dark-login.png`        | page de connexion, thème clair                          |
| `favicon.png` / `favicon.ico`| icône de l'onglet du navigateur                         |

Chaque variante est **optionnelle** : en l'absence de fichier spécifique,
le plugin retombe sur `logo-reduced`, `logo-light`, `logo-login`, puis
`logo`.

> Un logo à fond transparent est recommandé. Si votre logo a du texte
> foncé, fournissez aussi `logo-light.png` (version claire/blanche) pour
> qu'il reste lisible sur le menu sombre.
