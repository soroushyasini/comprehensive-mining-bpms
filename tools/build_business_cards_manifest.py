"""Offline, lossless extraction of the two cart Markdown sources (no OCR/network).

The manifest is private operational data, not a SQL migration. Ambiguous fields
remain in notes/raw_text and are marked for review; source documents are untouched.
"""
import argparse
import csv
import hashlib
import json
import re
from collections import defaultdict
from pathlib import Path

COUNTRIES = {
    'آلمان': 'DE', 'اتریش': 'AT', 'ازبکستان': 'UZ', 'استرلیا': 'AU', 'استرالیا': 'AU',
    'افغانستان': 'AF', 'اوگاندا': 'UG', 'ایتالیا': 'IT', 'بسنی و هرزگوین': 'BA',
    'بلاروس': 'BY', 'بلغارستان': 'BG', 'تانزانیا': 'TZ', 'تایوان': 'TW',
    'ترکیه_ازمیر_استانبول': 'TR', 'ترکیه': 'TR', 'چین': 'CN', 'ایران': 'IR',
    'تبریز': 'IR', 'تهران': 'IR', 'مشهد': 'IR', 'دبی': 'AE', 'امارات متحده عربی': 'AE',
    'رمانی': 'RO', 'روسیه': 'RU', 'ژاپن': 'JP', 'سریلانکا': 'LK', 'سنگاپور': 'SG',
    'عمان': 'OM', 'فیلیپین': 'PH', 'قرقیزستان': 'KG', 'قزاقستان': 'KZ',
    'کلمبو': 'LK', 'کنیا': 'KE', 'کوالالامپور': 'MY', 'کوبا': 'CU', 'لهستان': 'PL',
    'مالزی': 'MY', 'مصر': 'EG', 'مغولستان': 'MN', 'ویتنام': 'VN', 'هند': 'IN',
    'پاکستان': 'PK', 'عربستان سعودی': 'SA', 'قطر': 'QA',
}
# Explicit locality tokens only; coordinates and telephone prefixes never infer a country.
CITIES = {
    'تهران': ('تهران','IR'), 'tehran': ('تهران','IR'), 'مشهد': ('مشهد','IR'),
    'mashhad': ('مشهد','IR'), 'تبریز': ('تبریز','IR'), 'tabriz': ('تبریز','IR'),
    'tashkent': ('Tashkent','UZ'), 'ташкент': ('Tashkent','UZ'), 'کابل': ('Kabul','AF'),
    'kabul': ('Kabul','AF'), 'nairobi': ('Nairobi','KE'), 'shanghai': ('Shanghai','CN'),
    'istanbul': ('Istanbul','TR'), 'İstanbul': ('Istanbul','TR'), 'izmir': ('Izmir','TR'),
    'moscow': ('Moscow','RU'), 'omsk': ('Omsk','RU'), 'дубай': ('Dubai','AE'),
    'dubai': ('Dubai','AE'), 'دبی': ('Dubai','AE'), 'seoul': ('Seoul','KR'),
    'kuala lumpur': ('Kuala Lumpur','MY'), 'colombo': ('Colombo','LK'),
    'wien': ('Wien','AT'), 'vienna': ('Wien','AT'), 'kiel': ('Kiel','DE'),
    'erftstadt': ('Erftstadt','DE'), 'krefeld': ('Krefeld','DE'),
    'new delhi': ('New Delhi','IN'), 'mumbai': ('Mumbai','IN'),
}

def normalize(value):
    return re.sub(r'\s+', ' ', value.translate(str.maketrans('يك۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','یک01234567890123456789'))).strip()

def fields(block):
    result = []
    for line in block.splitlines():
        if not line.startswith('- '):
            continue
        line = line[2:].replace('**','')
        if line.startswith('مختصات جغرافیایی'):
            continue
        # Keep multilingual unlabelled text intact. Split only explicit inline labels.
        for item in re.split(r'[؛;]\s*(?=(?:نام|سمت|شرکت|نهاد|نشانی|وب‌سایت|ایمیل|همراه|تلفن|فکس)[^:؛;]{0,35}:)',line):
            match = re.match(r'([^:]+):\s*(.*)',item)
            if match:
                result.append((match[1].strip(), match[2].strip()))
    return result

def country_from_text(text):
    code = re.search(r'\(([A-Z]{2})\)',text)
    if code:
        return code.group(1)
    low = text.casefold()
    for token,(city,country) in CITIES.items():
        if re.search(r'(?<!\w)'+re.escape(token.casefold())+r'(?!\w)',low):
            return country
    explicit = {'germany':'DE','austria':'AT','iran':'IR','turkey':'TR','türkiye':'TR',
                'china':'CN','russia':'RU','uzbekistan':'UZ','kenya':'KE','sri lanka':'LK',
                'malaysia':'MY','india':'IN','korea':'KR','poland':'PL','afghanistan':'AF',
                'tanzania':'TZ','vietnam':'VN','japan':'JP','oman':'OM','singapore':'SG'}
    for token,code in explicit.items():
        if re.search(r'(?<!\w)'+re.escape(token)+r'(?!\w)',low):
            return code
    for token,code in COUNTRIES.items():
        if re.search(r'(?<!\w)'+re.escape(token)+r'(?!\w)',text):
            return code
    return None

def locality(text):
    low=text.casefold()
    for token,(city,country) in CITIES.items():
        if re.search(r'(?<!\w)'+re.escape(token.casefold())+r'(?!\w)',low):
            return city,country
    return None,country_from_text(text)

def point_values(label,value):
    points=[]
    # Email and website extraction does not repair typos or split telephone ranges.
    email_pattern=r'[A-Za-z0-9.!#$%&\x27*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+'
    if any(token in label for token in ['ایمیل','وب','سایت']):
        for match in re.finditer(email_pattern,value):
            points.append({'kind':'email','label':label,'raw_value':match.group(0)})
        remaining=re.sub(email_pattern,' ',value)
        for match in re.finditer(r'(?:(?:https?://|www\.)[^\s؛,،]+|(?<![\w@])(?:[\w-]+\.)+[a-zA-Z]{2,}(?:/[^\s؛,،]*)?)',remaining):
            points.append({'kind':'website','label':label,'raw_value':match.group(0).rstrip('/.,؛')})
        # The complete source cell always remains available, even when partially parsed.
        points.append({'kind':'other','label':label+' (متن اصلی)','raw_value':value})
    elif any(token in label for token in ['تلفن','همراه','فکس','فاکس','تلگرام','اجتماعی','واتس']):
        kind='fax' if 'فکس' in label or 'فاکس' in label else 'mobile' if 'همراه' in label else 'messenger' if any(x in label+value for x in ['تلگرام','اجتماعی','@','واتس']) else 'phone'
        for val in re.split(r'[؛;]\s*',value):
            if val.strip(): points.append({'kind':kind,'label':label,'raw_value':val.strip()})
    return points

def extract(block,source_file,root,csv_rows):
    heading=block.splitlines()[0]
    image_match=re.search(r'^تصویر:\s*\[[^\]]+\]\((?:<([^>]+)>|([^\r\n]+))\)\s*$',block,re.M)
    image=(image_match.group(1) or image_match.group(2)) if image_match else None
    legacy_match=re.search(r'ردیف جدول\s+(\d+)',heading)
    legacy_id=legacy_match.group(1) if legacy_match else None
    source_key='legacy-card:'+legacy_id if legacy_id else 'card-image:'+image if image else source_file+':'+heading
    parts=image.split('/') if image else []
    category=parts[1] if parts and parts[0] in ['business_cards_images','داخلی'] else parts[0] if parts else None
    card={'contact_name':None,'organization_name':None,'job_title':None,
          'business_country_code':COUNTRIES.get(category),'source_category':category,
          'related_unit':None,'activity':None,'notes':None,'review_status':'needs_review',
          'contact_points':[],'locations':[]}
    addresses=[]
    for label,value in fields(block):
        if not value: continue
        if label in ['نام','نام مخاطب','نام فارسی','نام شخص','نام خانوادگی درج‌شده']:
            card['contact_name']=card['contact_name'] or value
        elif label in ['شرکت','نهاد','نام شرکت / نهاد','کسب‌وکار','شرکت/نشان','نهاد/گروه','نام تجاری/برند','نام تجاری','نام خدمت/برند','برند/شرکت']:
            card['organization_name']=card['organization_name'] or value
        elif label=='سمت': card['job_title']=value
        elif label=='کشور': card['business_country_code']=country_from_text(value)
        elif label=='واحد مرتبط': card['related_unit']=value
        elif 'نشانی' in label or label in ['دفتر مرکزی','دفتر ایران','دفتر تهران']:
            addresses.append((label,value))
        card['contact_points'].extend(point_values(label,value))
    # Reviewed layouts in the supplied transcription. Language names are labels,
    # not instructions. Preserve the other language in notes and source text.
    language=dict(fields(block))
    if not card['contact_name'] and not card['organization_name']:
        value=language.get('انگلیسی') or language.get('اسپانیایی') or language.get('روسی')
        if value:
            pieces=[x.strip().rstrip('.') for x in re.split('[;؛]',value) if x.strip()]
            person_first=image in ['روسیه/3.jpg','روسیه/5.jpg','روسیه/16.jpg','روسیه/17.jpg','روسیه/18.jpg','قرقیزستان/2.jpg']
            if len(pieces)>=3:
                if person_first:
                    card['contact_name'],card['job_title'],card['organization_name']=pieces[0],pieces[1],'; '.join(pieces[2:])
                elif image=='روسیه/23.jpg':
                    card['organization_name']='; '.join(pieces[:2]);card['contact_name']=pieces[2];card['job_title']='; '.join(pieces[3:])
                else:
                    card['organization_name'],card['contact_name'],card['job_title']=pieces[0],pieces[1],'; '.join(pieces[2:])
            elif len(pieces)==2:
                if image=='روسیه/19.jpg':
                    card['contact_name'],card['job_title']=pieces;card['organization_name']=language.get('روسی')
                else: card['organization_name'],card['contact_name']=pieces
    if language.get('انگلیسی (نام)'):
        card['contact_name']=language['انگلیسی (نام)']
    if language.get('انگلیسی (نام شرکت)'):
        card['organization_name']=language['انگلیسی (نام شرکت)']
    if not card['organization_name']:
        group=re.search(r'متعلق به (.+?) است',block)
        if group: card['organization_name']=group.group(1)
    # Multilingual semicolon cells are retained as notes unless explicitly labelled.
    # Legacy section headings independently preserve names when the body omits them.
    if legacy_id and ' — ' in heading:
        pieces=heading.split(' — ')
        if len(pieces)>=2 and not re.search(r'\.jpg$',pieces[1],re.I):
            card['contact_name']=card['contact_name'] or pieces[1]
            if len(pieces)>=3: card['organization_name']=card['organization_name'] or ' — '.join(pieces[2:])
    card['notes']='\n'.join(line[2:] for line in block.splitlines() if line.startswith('- ') and not line.startswith('- مختصات'))
    for label,address in addresses:
        city,country=locality(address)
        card['locations'].append({'label':label,'address':address,'city':city,'country_code':country,
                                 'latitude':None,'longitude':None,'accuracy':'unknown','source_note':None,'source_urls':[]})
    geo_line=next((line for line in block.splitlines() if line.startswith('- مختصات جغرافیایی')),None)
    if geo_line and 'mlat=' in geo_line:
        links=list(re.finditer(r'\[نقشه\]\((https://www\.openstreetmap\.org/[^)]+)\)',geo_line))
        # One location per source coordinate link. Exact duplicate links are ignored.
        seen=set()
        for i,link in enumerate(links):
            lat=re.search(r'mlat=(-?\d+(?:\.\d+)?)',link.group(1)).group(1)
            lon=re.search(r'mlon=(-?\d+(?:\.\d+)?)',link.group(1)).group(1)
            if (lat,lon) in seen: continue
            seen.add((lat,lon))
            note=geo_line[links[i-1].end() if i else 0:link.start()]
            accuracy='address' if any(x in note for x in ['ساختمان','نقطهٔ نشانی','نقطهٔ کارخانه']) else 'street' if 'خیابان/محله' in note else 'city'
            if i==0 and card['locations']:
                loc=card['locations'][0]
            else:
                city,country=locality(note)
                loc={'label':'موقعیت منبع' if i==0 else 'موقعیت تکمیلی','address':None,'city':city,'country_code':country}
                card['locations'].append(loc)
            if not loc.get('country_code'):
                loc['city'],loc['country_code']=locality(note)
            loc.update({'latitude':lat,'longitude':lon,'accuracy':accuracy,'source_note':geo_line[2:],
                        'source_urls':re.findall(r'\[[^\]]+\]\((https?://[^)]+)\)',geo_line)})
    image_hash=None
    if image:
        path=(root/image).resolve()
        if not path.is_relative_to(root.resolve()) or not path.is_file():
            raise ValueError('Missing/out-of-root image: '+image)
        image_hash=hashlib.sha256(path.read_bytes()).hexdigest()
    issues=[]
    if not card['contact_name'] and not card['organization_name']: issues.append('unstructured_identity')
    if 'تصویر ترکیبی' in block or 'تصویر شامل سه کارت' in block: issues.append('composite_image')
    if not card['business_country_code']: issues.append('unknown_business_country')
    if not any(x.get('latitude') is not None for x in card['locations']): issues.append('unknown_coordinates')
    return {'source_key':source_key,'source_file':source_file,'legacy_source_id':legacy_id,
            'raw_text':block,'image_relative_path':image,'image_sha256':image_hash,
            'content_hash':hashlib.sha256((block+'\n'+(image_hash or '')).encode()).hexdigest(),
            'export_lineage':csv_rows.get(legacy_id), 'issues':issues,'card':card}

def similarity_report(records):
    """Exact normalized hints only; warnings never remove or merge source entries."""
    groups=defaultdict(set)
    translation=str.maketrans('يك۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','یک01234567890123456789')
    def normalized(value):
        return re.sub(r'\s+',' ',(value or '').translate(translation)).strip().lower()
    for record in records:
        card=record['card'];key=record['source_key']
        if record['image_sha256']: groups[('image_hash',record['image_sha256'])].add(key)
        name,org=normalized(card['contact_name']),normalized(card['organization_name'])
        if name and org: groups[('name_and_organization',name+'\n'+org)].add(key)
        for point in card['contact_points']:
            value=normalized(point['raw_value']);kind=point['kind']
            if kind in ('phone','mobile'):
                if not re.fullmatch(r'\+?[0-9 ()-]+\+?',value) or re.search(r'-\d{1,2}$',value): continue
                value=re.sub(r'\D','',value)
                if not 7<=len(value)<=15: continue
                kind='phone'
            elif kind=='email':
                if not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+',value): continue
            else: continue
            groups[(kind,value)].add(key)
    return [{'reason':kind,'source_keys':sorted(keys)} for (kind,value),keys in groups.items() if len(keys)>1]

def build(root):
    export=root/'business_cards_images/export_report.csv'
    with export.open(encoding='utf-8-sig',newline='') as f:
        csv_rows={row['id']:row for row in csv.DictReader(f)}
    records=[]
    sources={}
    for filename in ['کارت‌های_ویزیت.md','کارت‌های_ویزیت_بدون_عکس.md']:
        raw=(root/filename).read_bytes(); sources[filename]=hashlib.sha256(raw).hexdigest()
        text=raw.decode('utf-8-sig')
        for block in re.split(r'(?m)(?=^## \d+\.)',text):
            if re.match(r'^## \d+\.',block): records.append(extract(block,filename,root,csv_rows))
    keys=[r['source_key'] for r in records]
    if len(keys)!=len(set(keys)): raise ValueError('Duplicate source keys')
    similarities=similarity_report(records)
    summary={'records':len(records),'images':sum(bool(r['image_relative_path']) for r in records),
             'without_image':sum(not r['image_relative_path'] for r in records),
             'cards_with_coordinates':sum(any(l.get('latitude') is not None for l in r['card']['locations']) for r in records),
             'coordinate_points':sum(sum(l.get('latitude') is not None for l in r['card']['locations']) for r in records),
             'unstructured_identity':sum('unstructured_identity' in r['issues'] for r in records),
             'similarity_groups':len(similarities),
             'issue_counts':dict((issue,sum(issue in r['issues'] for r in records)) for issue in sorted({i for r in records for i in r['issues']}))}
    if (summary['records'],summary['images'],summary['without_image'],summary['cards_with_coordinates'])!=(443,304,139,295):
        raise ValueError('Source acceptance counts changed: '+str(summary))
    return {'format_version':1,'sources':sources,'summary':summary,'similarities':similarities,'records':records}

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-root',type=Path,default=Path(__file__).resolve().parents[1]/'cart')
    parser.add_argument('--output',type=Path,help='Private manifest path (for example dataset/business_cards_manifest.json)')
    args=parser.parse_args(); manifest=build(args.source_root)
    if args.output:
        args.output.parent.mkdir(parents=True,exist_ok=True)
        args.output.write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps(manifest['summary'],ensure_ascii=False))
