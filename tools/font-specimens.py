#!/usr/bin/env python3
"""Aperçus des polices libres pour l'écran Polices et le panneau de l'éditeur de site.

Titre : lorem ipsum ponctué, en contours SVG (graisse 700 si la famille la couvre).
Paragraphe : lorem ipsum en WebP, pour les familles de texte des duos seulement.
Noir sur fond transparent, affichées sur le fond clair de l'administration et de l'éditeur.
Titre en SVG sous SVG_MAX octets, sinon en WebP (police pixelisée, empattements détaillés).

Chaque fichier source doit reproduire l'empreinte SHA-256 fixée dans Fonts.php ; aucun fichier
de police n'est copié dans le dépôt, seulement les images qui en sont tirées.

Usage : python3 tools/font-specimens.py <dossier> [<dossier> ...]
  Un dossier contient <slug>.woff2 ou <slug>/<slug>.woff2 (archive Proton Fluffless/polices,
  services/font-bank/local, dossier temporaire des fichiers Fontshare). Une famille sans fichier
  est signalée et garde son aperçu actuel.
Prérequis : fontTools, brotli, Pillow avec WebP.
"""

import hashlib
import io
import re
import sys
from pathlib import Path

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
FONTS_PHP = ROOT / 'packages/fluffless-suite/src/Modules/Fonts.php'
OUT = ROOT / 'packages/fluffless-suite/assets/specimens'

NBSP, THIN = chr(0xA0), chr(0x202F)  # Points de code : la source reste en ASCII visible.
TITLE = f'«{NBSP}Lorem ipsum{NBSP}: dolor sit amet{THIN}!{NBSP}»'
PARAGRAPH = ('Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor '
             'incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud '
             'exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.')
WIDTH, SIZE, LEADING = 1120, 32, 1.5  # 560 px CSS à densité 2, texte de 16 px.
GRID = 100  # Unités par cadratin des tracés : assez fin pour un titre de 30 px.
SVG_MAX = 8000  # Au-delà (police pixelisée, par exemple), le titre passe en WebP.
TITLE_SIZE = 56  # Titre WebP : 28 px CSS à densité 2.


def catalogue():
    """Familles (nom, graisse, empreinte) et familles de texte des duos, lues dans Fonts.php."""
    php = FONTS_PHP.read_text(encoding='utf-8')
    families = {
        m[1]: {'name': m[2], 'weight': m[3], 'sha256': m[4]}
        for m in re.finditer(r"'([a-z0-9-]+)'\s*=>\s*array\(\s*'name'\s*=>\s*'([^']+)'.*?'weight'\s*=>\s*'([^']+)'.*?'sha256'\s*=>\s*'([0-9a-f]{64})'", php, re.S)
    }
    block = php[php.index('const PAIRS = array('):]
    block = block[:block.index('\n\t);')]
    bodies = {b for _, b in re.findall(r"array\( '([a-z0-9-]+)', '([a-z0-9-]+)' \)", block) if b != 'system'}
    return families, bodies


def source(slug, dirs):
    for d in dirs:
        for p in (d / f'{slug}.woff2', d / slug / f'{slug}.woff2'):
            if p.is_file():
                return p
    return None


def instance(data, weight_range, target):
    """Police à la graisse voulue (bornée à la plage de la famille)."""
    font = TTFont(io.BytesIO(data))
    if 'fvar' in font:
        axes = {a.axisTag: a for a in font['fvar'].axes}
        if 'wght' in axes:
            low, high = axes['wght'].minValue, axes['wght'].maxValue
            font = instantiateVariableFont(font, {'wght': min(max(target, low), high)})
        else:
            font = instantiateVariableFont(font, {})
    return font


def svg(font):
    """Titre en un seul tracé, coordonnées entières sur une grille de GRID unités par cadratin."""
    cmap, glyphs, hmtx = font.getBestCmap(), font.getGlyphSet(), font['hmtx']
    scale = GRID / font['head'].unitsPerEm
    space = hmtx[cmap[32]][0]
    pen = SVGPathPen(glyphs, ntos=lambda v: str(round(v)))
    x = 0
    for ch in TITLE:
        code = ord(ch)
        if code == 0x202F or (code not in cmap and ch.isspace()):
            x += round(space * (0.5 if code == 0x202F else 1))
            continue
        name = cmap.get(code) or cmap.get(32)
        glyphs[name].draw(TransformPen(pen, (scale, 0, 0, -scale, x * scale, 0)))
        x += hmtx[name][0]
    asc, desc = round(font['hhea'].ascent * scale), round(font['hhea'].descent * scale)
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 {-asc} {round(x * scale)} {asc - desc}">'
            f'<path d="{pen.getCommands()}"/></svg>\n')


def face(font, size):
    buf = io.BytesIO()
    font.flavor = None
    font.save(buf)
    return ImageFont.truetype(io.BytesIO(buf.getvalue()), size)


def encode(image):
    out = io.BytesIO()
    image.convert('RGBA').save(out, 'WEBP', quality=70, method=6)
    return out.getvalue()


def title_webp(font):
    """Titre en WebP, noir sur transparent, pour les tracés trop lourds."""
    f = face(font, TITLE_SIZE)
    # Les familles n'ont pas l'espace fine : morceaux posés à la main, espace fine = demi-espace.
    # Sans glyphe U+00A0 (Roundo), Pillow dessinerait un glyphe de remplacement : espace simple.
    title = TITLE if ord(NBSP) in font.getBestCmap() else TITLE.replace(NBSP, ' ')
    parts = title.split(THIN)
    gap = f.getlength(' ') / 2
    width = round(sum(f.getlength(part) for part in parts) + gap * (len(parts) - 1))
    _, top, _, bottom = f.getbbox(title.replace(THIN, ' '))
    image = Image.new('LA', (width + TITLE_SIZE // 4, bottom - top), (0, 0))
    draw, x = ImageDraw.Draw(image), 0.0
    for part in parts:
        draw.text((x, -top), part, font=f, fill=(0, 255))
        x += f.getlength(part) + gap
    return encode(image.crop(image.getbbox()))


def webp(font):
    """Paragraphe coupé en lignes sous WIDTH, noir sur transparent."""
    face_ = face(font, SIZE)
    lines, line = [], ''
    for word in PARAGRAPH.split():
        test = f'{line} {word}'.strip()
        if face_.getlength(test) > WIDTH and line:
            lines.append(line)
            line = word
        else:
            line = test
    lines.append(line)
    step = round(SIZE * LEADING)
    image = Image.new('LA', (WIDTH, step * len(lines) + SIZE // 2), (0, 0))  # Jambages de la dernière ligne.
    draw = ImageDraw.Draw(image)
    for i, text in enumerate(lines):
        draw.text((0, i * step + (step - SIZE) // 2), text, font=face_, fill=(0, 255))
    return encode(image)


def main(argv):
    if not argv:
        sys.exit(__doc__)
    dirs = [Path(a).expanduser() for a in argv]
    families, bodies = catalogue()
    OUT.mkdir(exist_ok=True)
    missing, total = [], 0
    for slug, fam in families.items():
        path = source(slug, dirs)
        if path is None:
            missing.append(slug)
            continue
        data = path.read_bytes()
        if hashlib.sha256(data).hexdigest() != fam['sha256']:
            sys.exit(f'ÉCHEC : {path} ne reproduit pas l’empreinte de {slug} fixée dans Fonts.php.')
        bold = instance(data, fam['weight'], 700)
        title, ext = svg(bold).encode(), 'svg'
        if len(title) > SVG_MAX:
            title, ext = title_webp(bold), 'webp'
        for old in OUT.glob(f'{slug}-title.*'):
            old.unlink()
        (OUT / f'{slug}-title.{ext}').write_bytes(title)
        total += len(title)
        size = f'{slug} : titre {ext.upper()} {len(title) / 1000:.1f} ko'
        if slug in bodies:
            para = webp(instance(data, fam['weight'], 400))
            (OUT / f'{slug}-text.webp').write_bytes(para)
            total += len(para)
            size += f', paragraphe {len(para) / 1000:.1f} ko'
        print(size)
    print(f'Total : {total / 1000:.1f} ko dans {OUT.relative_to(ROOT)}.')
    if missing:
        print('Sans fichier source (aperçu inchangé) : ' + ', '.join(missing))


if __name__ == '__main__':
    main(sys.argv[1:])
