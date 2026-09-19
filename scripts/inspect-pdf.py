import sys, pathlib, json, hashlib
sys.path.insert(0,str(pathlib.Path('.tools/python').resolve()))
import fitz
from PIL import Image,ImageOps,ImageDraw
root=pathlib.Path('.')
pdf=next(root.glob('*.pdf'))
doc=fitz.open(pdf)
text=[]
for i,p in enumerate(doc):
 text.append(f'\n=== PAGE {i+1} ===\n'+p.get_text()+'\nLINKS: '+json.dumps(p.get_links(),ensure_ascii=False,default=str))
 p.get_pixmap(matrix=fitz.Matrix(1,1)).save(f'docs/research/pdf-{i+1:02}.png')
pathlib.Path('docs/research/pdf-text.txt').write_text('\n'.join(text),encoding='utf-8')
for start in range(0,len(doc),4):
 pages=[Image.open(f'docs/research/pdf-{i+1:02}.png').convert('RGB') for i in range(start,min(start+4,len(doc)))]
 thumbs=[]
 for j,p in enumerate(pages):
  p.thumbnail((960,700)); c=Image.new('RGB',(980,740),'#eeeeee');c.paste(p,((980-p.width)//2,30));ImageDraw.Draw(c).text((10,10),f'PAGE {start+j+1}',fill='black');thumbs.append(c)
 sheet=Image.new('RGB',(1960,1480),'white')
 for j,p in enumerate(thumbs):sheet.paste(p,((j%2)*980,(j//2)*740))
 sheet.save(f'docs/research/contact-{start+1:02}.jpg')
files=[pdf,*pathlib.Path('images').glob('*')]
pathlib.Path('docs/research/original-hashes.json').write_text(json.dumps({str(p):hashlib.sha256(p.read_bytes()).hexdigest() for p in files},indent=2),encoding='utf-8')
print('Rendered',len(doc),'pages')
