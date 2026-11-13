#!/usr/bin/env python3
# Uso: python3 scripts/capturas-manual/comprimir.py docs/usuario/img/<carpeta>/*.png  (necesita Pillow)
# Comprime PNG: paleta de 256 colores sin tramado (texto nítido) + optimize. Solo si queda más chico.
import sys, os
from PIL import Image
total_a = total_d = 0
for ruta in sys.argv[1:]:
    a = os.path.getsize(ruta)
    im = Image.open(ruta).convert('RGB')
    q = im.quantize(colors=256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
    tmp = ruta + '.tmp.png'
    q.save(tmp, optimize=True)
    d = os.path.getsize(tmp)
    if d < a:
        os.replace(tmp, ruta)
    else:
        os.remove(tmp); d = a
    total_a += a; total_d += d
print(f"{len(sys.argv)-1} archivos: {total_a/1e6:.1f} MB -> {total_d/1e6:.1f} MB")
