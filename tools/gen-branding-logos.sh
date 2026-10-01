#!/usr/bin/env bash
# Génère un jeu de logos de démonstration "random" pour le plugin GLPI Branding.
# Les fichiers sont écrits dans plugins/branding/pics/ avec les noms attendus
# par Logo::find() (premier trouvé gagne, repli sur logo.*).
#
# Usage : bash tools/gen-branding-logos.sh [dossier_cible]

set -euo pipefail

DEST="${1:-plugins/branding/pics}"
mkdir -p "$DEST"

FONT="Adwaita-Sans-Bold"
TEXT="GLPI"

# --- Couleurs tirées au sort -----------------------------------------------------
h1=$(( RANDOM % 360 ))
h2=$(( (h1 + 25 + RANDOM % 60) % 360 ))
C1="hsl($h1,70%,58%)"
C2="hsl($h2,72%,42%)"
ACCENT="hsl($h2,90%,68%)"

echo "Palette aléatoire : $C1 -> $C2 (accent $ACCENT)"

TMPDIR_GEN="$( mktemp -d )"
trap 'rm -rf "$TMPDIR_GEN"' EXIT

# --- Marque ---------------------------------------------------------------------
# Carré arrondi avec dégradé + anneau d'accent, seul (sert de logo réduit/favicon).
mark() { # $1 = côté en px, $2 = fichier de sortie
  local s=$1 out=$2 r=$(( $1 / 5 ))
  # Dégradé opaque, découpé aux angles arrondis via un masque de transparence.
  local rounded="$TMPDIR_GEN/rounded-$s.png"
  magick -size "${s}x${s}" gradient:"$C1"-"$C2" \
    \( -size "${s}x${s}" xc:none -fill white \
       -draw "roundrectangle 0,0 $(( s - 1 )),$(( s - 1 )) $r,$r" \) \
    -alpha off -compose CopyOpacity -composite \
    "$rounded"
  # Anneau d'accent par-dessus.
  magick "$rounded" \
    \( -size "${s}x${s}" xc:none \
       -stroke "$ACCENT" -strokewidth "$(( s / 16 ))" -fill none \
       -draw "circle $(( s / 2 )),$(( s / 2 )) $(( s / 2 )),$(( s / 8 ))" \) \
    -compose Over -composite \
    "$out"
}

# --- Logos ----------------------------------------------------------------------
# Compose la marque + le texte, sur fond transparent.
# $1=largeur $2=hauteur $3=taille du carré de marque $4=taille police $5=couleur texte $6=fichier
compose() {
  local w=$1 h=$2 ms=$3 fs=$4 color=$5 out=$6
  local markfile="$TMPDIR_GEN/mark-$ms.png"
  local textfile="$TMPDIR_GEN/text-$fs-$color.png"
  [ -f "$markfile" ] || mark "$ms" "$markfile"
  # Le texte est rendu à part puis aligné à droite dans une zone plus large
  # que lui, ce qui laisse un espace entre la marque et le texte.
  if [ ! -f "$textfile" ]; then
    magick -background none -fill "$color" -font "$FONT" -pointsize "$fs" \
      label:"$TEXT" -gravity east -background none -extent "%[fx:w+$ms/5]x%[fx:h]" \
      "$textfile"
  fi
  magick "$markfile" "$textfile" \
    -background none -gravity center +append \
    -gravity center -background none -extent "${w}x${h}" \
    "$out"
}

echo "Génération des logos dans $DEST …"

# --- Logo principal (fond clair) + version fond sombre ----------------------------
compose 480 140 109 84 "$C2" "$DEST/logo.png"
cp "$DEST/logo.png" "$DEST/logo-dark.png"

# Version claire (menu du haut, fond sombre)
compose 480 140 109 84 "#ffffff" "$DEST/logo-light.png"

# --- Versions réduites : la marque seule, carrée ---------------------------------
mark 160 "$DEST/logo-reduced.png"
cp "$DEST/logo-reduced.png" "$DEST/logo-dark-reduced.png"
cp "$DEST/logo-reduced.png" "$DEST/logo-light-reduced.png"

# --- Page de connexion : plus grand ----------------------------------------------
compose 560 200 156 116 "$C2"      "$DEST/logo-login.png"
cp "$DEST/logo-login.png"          "$DEST/logo-dark-login.png"
compose 560 200 156 116 "#ffffff"  "$DEST/logo-light-login.png"

# --- Favicon ---------------------------------------------------------------------
magick "$DEST/logo-reduced.png" -resize 64x64 "$DEST/favicon.png"
magick -background none "$DEST/logo-reduced.png" \
  -define icon:auto-resize=64,48,32,16 "$DEST/favicon.ico"

echo
echo "Fichiers générés :"
ls -lh "$DEST" | grep -E 'logo|favicon' || true