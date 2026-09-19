import urllib.request,pathlib,json,sys
sys.path.insert(0,str(pathlib.Path('.tools/python').resolve()))
from PIL import Image
base='https://pianissimo-production.de/wp-content/uploads/'
assets={
 'abszission':'2025/03/Abszission-Cover-600x601.png',
 'au-milieu':'2025/03/Gros-v1_LCIL_Au-milieu_Album-Cover_mitMPS-2.jpeg',
 'dough':'2025/03/Doughwithahole—v1_Cover-FINAL-600x600.jpg',
 'kinnhakenhank':'2025/03/Kinnhakenhank-Website-Edit_01_00_13_07-900x377.jpg',
 'kinnhakenhank-cover':'2025/03/FINAL_KHH_OST_Cover-600x600.jpeg',
 'kinnhakenhank-still':'2025/03/Kinnhakenhank-Website-Edit_01_00_58_01-900x377.jpg',
 'karussell':'2025/03/Karussell-Laurels-600x300.jpg',
 'leaving-earth':'2025/03/FinalPost-600x600.png',
 'lillys-lullaby':'2025/11/Lillys-Lullaby-Cover-FINAL-1-600x600.png',
 'orchestra':'2025/03/250212-Babelsberg-Einspielung-4-Sterne-21-600x400.jpeg',
 'studio':'2025/03/SamplesUntermSofa_3Sterne-44-1290x860.jpg'
}
dest=pathlib.Path('frontend/public/media');dest.mkdir(parents=True,exist_ok=True)
cache=pathlib.Path('content/imported');cache.mkdir(parents=True,exist_ok=True)
records=[]
for key,path in assets.items():
 url=base+urllib.parse.quote(path,safe='/')
 original=cache/(key+pathlib.Path(path).suffix)
 urllib.request.urlretrieve(url,original)
 im=Image.open(original).convert('RGB'); im.thumbnail((1600,1600));im.save(dest/(key+'.webp'),quality=88)
 small=im.copy();small.thumbnail((480,480));small.save(dest/(key+'-480.webp'),quality=82)
 records.append({'id':key,'source':base+path,'width':im.width,'height':im.height})
im=Image.open('images/Sebastian.png').convert('RGB');im.thumbnail((1100,1400));im.save(dest/'sebastian-about.webp',quality=88)
sm=im.copy();sm.thumbnail((480,600));sm.save(dest/'sebastian-about-480.webp',quality=84)
records.append({'id':'sebastian-about','source':'user:images/Sebastian.png','width':im.width,'height':im.height})
pathlib.Path('content/imported-assets.json').write_text(json.dumps(records,indent=2,ensure_ascii=False),encoding='utf-8')
print('Saved',len(records),'selected assets and derivatives')
