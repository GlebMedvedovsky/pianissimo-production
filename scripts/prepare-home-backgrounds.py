"""Make web derivatives only. Never overwrite source images or bake in UI."""
import hashlib
import json
import pathlib
import sys

root = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(root / '.tools/python'))
from PIL import Image, ImageOps

def source(stem):
    for suffix in ('.jpg', '.png'):
        candidate = root / 'images/source' / (stem + suffix)
        if candidate.is_file():
            return candidate
    raise FileNotFoundError(f'Expected images/source/{stem}.jpg or .png')

selections = [
    ('home-hero-poster', source('home-hero-poster'),
     'Piano, film camera and a warm evening view from a studio'),
    ('split-menu-background', source('split-menu-background'),
     'A piano interior on the left and a cinematic outdoor scene on the right'),
]
manifest_path = root / 'content/manifest.json'
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
records = []
for asset_id, original, alt in selections:
    checksum = hashlib.sha256(original.read_bytes()).hexdigest()
    image = ImageOps.exif_transpose(Image.open(original)).convert('RGB')
    image.thumbnail((1920, 1920))
    derivative = root / 'frontend/public/media' / (asset_id + '.webp')
    image.save(derivative, quality=90, method=6)
    small = image.copy()
    small.thumbnail((480, 480))
    small.save(derivative.with_name(asset_id + '-480.webp'), quality=85, method=6)
    asset = dict(id=asset_id, type='image', path='media/' + derivative.name,
                 width=image.width, height=image.height, alt=alt,
                 crop=dict(x=50, y=50), origin='User-provided working background',
                 source='user:' + original.relative_to(root).as_posix(), state='temporary')
    manifest['assets'] = [a for a in manifest['assets'] if a['id'] != asset_id] + [asset]
    records.append(dict(source=original.relative_to(root).as_posix(), sha256=checksum,
                        derivative=asset['path'], width=image.width, height=image.height))
    assert hashlib.sha256(original.read_bytes()).hexdigest() == checksum

for slot, asset_id in [('hero', 'home-hero-poster'), ('composerMenu', 'split-menu-background'),
                       ('directingMenu', 'split-menu-background')]:
    manifest['site']['mediaSlots'][slot] = dict(image=asset_id, video=None, poster=asset_id)
manifest_path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
(root / 'content/home-background-assets.json').write_text(
    json.dumps(records, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
print(json.dumps(records, indent=2, ensure_ascii=False))
