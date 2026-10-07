"""Prepare an offline SQL + flat private-file package from the original cart sources.

Requires Pillow. Never modifies cart, existing databases, or an existing package.
"""
import argparse
import csv
import hashlib
import json
import re
import shutil
import uuid
import zipfile
from pathlib import Path
from PIL import Image
from build_business_cards_manifest import build, normalize


def json_text(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'))


def sql_value(value):
    if value is None:
        return 'NULL'
    if isinstance(value, int):
        return str(value)
    return 'CONVERT(0x' + str(value).encode('utf-8').hex() + ' USING utf8mb4)'


def insert(table, values):
    return 'INSERT INTO ' + table + ' (' + ','.join(values) + ') VALUES (' + ','.join(sql_value(v) for v in values.values()) + ');'


def clean(value):
    return value.strip() or None if isinstance(value, str) else value


def normalized(value):
    return normalize(value).lower() if value is not None else None


def point_normalized(kind, value):
    value = normalized(value)
    if not value:
        return None
    if kind == 'email':
        return value if re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', value) and value.isascii() else None
    if kind == 'website':
        return re.sub(r'^https?://', '', value.rstrip('/'))
    if kind in ('phone', 'mobile', 'fax'):
        if not re.fullmatch(r'\+?[0-9 ()-]+\+?', value) or re.search(r'-\d{1,2}$', value):
            return None
        digits = re.sub(r'\D', '', value)
        return digits if 7 <= len(digits) <= 15 else None
    return value if kind == 'messenger' else None


def prepared_image(source_root, record, storage):
    relative = record['image_relative_path']
    if not relative:
        return None
    source = (source_root / relative).resolve()
    if not source.is_relative_to(source_root.resolve()):
        raise ValueError('Source image escapes cart')
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    if digest != record['image_sha256'] or source.stat().st_size > 10485760:
        raise ValueError('Source hash/size failed')
    name = uuid.uuid4().hex
    with Image.open(source) as image:
        width, height = image.size
        mime = {'JPEG': 'image/jpeg', 'PNG': 'image/png'}.get(image.format)
        if not mime or width * height > 40000000:
            raise ValueError('Invalid image format/dimensions')
        image.load()
    original_name = name + ('.png' if mime == 'image/png' else '.jpg')
    shutil.copyfile(source, storage / original_name)
    return {'original_filename': source.name, 'stored_filename': original_name,
            'mime_type': mime,
            'size_bytes': source.stat().st_size, 'width': width, 'height': height,
            'sha256': digest}


def make_sql(records, package_hash, package_id, schema):
    tables = {'cards': 'emcore_business_cards', 'points': 'emcore_business_card_contact_points',
              'locations': 'emcore_business_card_locations', 'files': 'emcore_business_card_files',
              'sources': 'emcore_business_card_source_records'}
    temporary = {key: 'bc_pkg_' + key for key in tables}
    procedure = 'emcore_bc_import_' + package_id[:12]
    lines = ["-- Set the ACTIVE ProcessMaker UID below; import into the workspace database.",
             "-- Requires existing authorization/audit foundation (migrations 001, 002).",
             "SET NAMES utf8mb4;",
             "SET @emcore_bc_import_actor = 'REPLACE_WITH_ACTIVE_USER_UID';",
             schema, 'DELIMITER $$', 'DROP PROCEDURE IF EXISTS ' + procedure + '$$',
             'CREATE PROCEDURE ' + procedure + '() SQL SECURITY INVOKER', 'BEGIN',
             'DECLARE actor CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DECLARE import_batch BIGINT UNSIGNED; DECLARE acquired INT DEFAULT 0;',
             'DECLARE added INT DEFAULT 0; DECLARE lock_result INT;',
             'DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK;',
             "IF acquired=1 THEN DO RELEASE_LOCK('emcore_business_cards_import'); END IF;",
             *['DROP TEMPORARY TABLE IF EXISTS ' + t + ';' for t in [*temporary.values(), 'bc_pkg_map']],
             'RESIGNAL; END;',
             'IF @emcore_bc_import_actor IS NULL OR CHAR_LENGTH(@emcore_bc_import_actor)<>32 THEN',
             "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Set emcore_bc_import_actor to an active ProcessMaker UID'; END IF;",
             'SET actor=@emcore_bc_import_actor;',
             "IF NOT EXISTS(SELECT 1 FROM USERS WHERE BINARY USR_UID=BINARY actor AND USR_STATUS='ACTIVE') THEN",
             "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Import actor is not an active ProcessMaker user'; END IF;",
             "IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='emcore_business_card_files' AND column_name IN ('thumbnail_filename','thumbnail_sha256') AND is_nullable='NO') THEN",
             "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Apply migration 015 before CALL: legacy thumbnail columns must be nullable'; END IF;",
             "SELECT GET_LOCK('emcore_business_cards_import',0) INTO lock_result;",
             "IF lock_result IS NULL OR lock_result<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Another business-card import is running'; END IF;",
             'SET acquired=1;', 'START TRANSACTION;',
             *['CREATE TEMPORARY TABLE ' + temporary[k] + ' LIKE ' + v + ';' for k, v in tables.items()],
             'CREATE TEMPORARY TABLE bc_pkg_map (source_index BIGINT UNSIGNED PRIMARY KEY, card_id BIGINT UNSIGNED NOT NULL);']
    card_columns = None
    point_index = location_index = file_index = 0
    for i, record in enumerate(records, 1):
        card = record['card']
        core = {k: clean(card.get(k)) for k in ['contact_name', 'organization_name', 'job_title', 'business_country_code', 'source_category', 'related_unit', 'activity', 'notes', 'review_status']}
        core.update(contact_key=normalized(core['contact_name']), organization_key=normalized(core['organization_name']),
                    record_origin='imported', create_request_id=hashlib.sha256((package_id + record['source_key']).encode()).hexdigest()[:32],
                    create_payload_hash=record['content_hash'])
        card_columns = list(core)
        lines.append(insert(temporary['cards'], dict(id=i, **core, created_by_usr_uid='package-source', updated_by_usr_uid='package-source')))
        for order, point in enumerate(card['contact_points']):
            point_index += 1
            lines.append(insert(temporary['points'], dict(id=point_index, card_id=i, kind=point['kind'], label=clean(point.get('label')), raw_value=clean(point['raw_value']), normalized_value=point_normalized(point['kind'], clean(point['raw_value'])), sort_order=order)))
        for order, location in enumerate(card['locations']):
            location_index += 1
            values = {k: clean(location.get(k)) for k in ['label', 'country_code', 'region_name', 'city', 'address', 'postal_code', 'latitude', 'longitude', 'accuracy', 'source_note']}
            values.update(source_urls=json_text(location.get('source_urls', [])), sort_order=order)
            lines.append(insert(temporary['locations'], dict(id=location_index, card_id=i, **values)))
        if record['prepared_image']:
            file_index += 1
            lines.append(insert(temporary['files'], dict(id=file_index, card_id=i, **record['prepared_image'], request_id=hashlib.sha256((package_id + ':image:' + record['source_key']).encode()).hexdigest()[:32], payload_hash=record['content_hash'], uploaded_by_usr_uid='package-source')))
        lines.append(insert(temporary['sources'], dict(id=i, card_id=i, batch_id=0, source_key=record['source_key'], legacy_source_id=record['legacy_source_id'], raw_text=record['raw_text'], extracted_data=json_text(record), image_relative_path=record['image_relative_path'], content_hash=record['content_hash'])))
    lines += [
        'IF EXISTS(SELECT 1 FROM bc_pkg_sources p JOIN emcore_business_card_source_records s ON s.source_key=p.source_key WHERE s.content_hash<>p.content_hash) THEN',
        "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Source content differs; review required, no cards have been changed'; END IF;",
        'INSERT INTO emcore_business_cards (' + ','.join(card_columns) + ',created_by_usr_uid,updated_by_usr_uid)',
        'SELECT ' + ','.join('c.' + k for k in card_columns) + ',actor,actor FROM bc_pkg_cards c JOIN bc_pkg_sources s ON s.card_id=c.id',
        'LEFT JOIN emcore_business_card_source_records old ON old.source_key=s.source_key WHERE old.id IS NULL;',
        'INSERT INTO bc_pkg_map SELECT p.id,c.id FROM bc_pkg_sources p JOIN bc_pkg_cards t ON t.id=p.card_id',
        'JOIN emcore_business_cards c ON c.created_by_usr_uid=actor AND c.create_request_id=t.create_request_id',
        'LEFT JOIN emcore_business_card_source_records old ON old.source_key=p.source_key WHERE old.id IS NULL;',
        'SELECT COUNT(*) INTO added FROM bc_pkg_map;',
        "INSERT INTO emcore_business_card_import_batches(source_hash,actor_usr_uid,imported_count,skipped_count,completed_at) VALUES('" + package_hash + "',actor,added,443-added,NOW());",
        'SET import_batch=LAST_INSERT_ID();']
    child_columns = {
        'points': ['kind', 'label', 'raw_value', 'normalized_value', 'sort_order'],
        'locations': ['label', 'country_code', 'region_name', 'city', 'address', 'postal_code', 'latitude', 'longitude', 'accuracy', 'source_note', 'source_urls', 'sort_order'],
        'files': ['original_filename', 'stored_filename', 'mime_type', 'size_bytes', 'width', 'height', 'sha256', 'request_id', 'payload_hash']}
    for kind, columns in child_columns.items():
        actor_column = ',uploaded_by_usr_uid' if kind == 'files' else ''
        actor_value = ',actor' if kind == 'files' else ''
        lines += ['INSERT INTO ' + tables[kind] + ' (card_id,' + ','.join(columns) + actor_column + ')',
                  'SELECT m.card_id,' + ','.join('p.' + c for c in columns) + actor_value + ' FROM ' + temporary[kind] + ' p JOIN bc_pkg_map m ON m.source_index=p.card_id;']
    columns = ['source_key', 'legacy_source_id', 'raw_text', 'extracted_data', 'image_relative_path', 'content_hash']
    lines += ['INSERT INTO emcore_business_card_source_records(card_id,batch_id,' + ','.join(columns) + ')',
              'SELECT m.card_id,import_batch,' + ','.join('p.' + c for c in columns) + ' FROM bc_pkg_sources p JOIN bc_pkg_map m ON m.source_index=p.id;',
              'INSERT INTO emcore_audit_log(request_id,actor_usr_uid,module_key,action,entity_type,entity_id,after_data,metadata)',
              "SELECT REPLACE(UUID(),'-',''),actor,'business_cards','create','business_card',m.card_id,",
              "JSON_SET(JSON_EXTRACT(p.extracted_data,'$.card'),'$.id',m.card_id,'$.record_origin','imported','$.lock_version',1,'$.image',JSON_EXTRACT(p.extracted_data,'$.prepared_image')),",
              "JSON_OBJECT('operation','prepared_sql_import','batch_id',import_batch,'source_key',p.source_key,'package_hash','" + package_hash + "') FROM bc_pkg_sources p JOIN bc_pkg_map m ON m.source_index=p.id;",
              'COMMIT;', *['DROP TEMPORARY TABLE ' + t + ';' for t in [*temporary.values(), 'bc_pkg_map']],
              "DO RELEASE_LOCK('emcore_business_cards_import'); SET acquired=0;",
              "SELECT added AS imported_cards,443-added AS skipped_cards,'" + package_id + "' AS package_id;",
              'END$$', 'DELIMITER ;', 'CALL ' + procedure + '();', 'DROP PROCEDURE ' + procedure + ';']
    return '\n'.join(lines) + '\n'


def write_review_csv(path, records, country_names):
    with path.open('w', encoding='utf-8-sig', newline='') as f:
        headers = ['source_key', 'contact_name', 'organization_name', 'job_title', 'business_country_code', 'business_country_name_fa', 'source_category', 'related_unit', 'activity', 'review_status', 'phones', 'emails', 'websites', 'other_contact_points', 'primary_city', 'primary_location_country_code', 'addresses', 'coordinates', 'locations_json', 'has_image', 'attachment_filename', 'attachment_relative_path', 'original_source_path', 'sha256', 'issues', 'notes']
        writer = csv.DictWriter(f, fieldnames=headers)
        writer.writeheader()
        for record in records:
            card = record['card']; image = record['prepared_image'] or {}
            primary = card['locations'][0] if card['locations'] else {}
            row = {k: card.get(k) for k in headers if k in card}
            row.update(source_key=record['source_key'], business_country_name_fa=country_names.get(card['business_country_code']), primary_city=primary.get('city'), primary_location_country_code=primary.get('country_code'), addresses=' | '.join(l['address'] for l in card['locations'] if l.get('address')), coordinates=' | '.join(str(l['latitude']) + ', ' + str(l['longitude']) for l in card['locations'] if l.get('latitude') is not None), locations_json=json_text(card['locations']), has_image=int(bool(image)), attachment_filename=image.get('stored_filename'), attachment_relative_path=('business_cards_storage/' + image['stored_filename']) if image else None, original_source_path=record['image_relative_path'], sha256=image.get('sha256'), issues='; '.join(record['issues']))
            for column, kinds in [('phones', ['phone', 'mobile', 'fax']), ('emails', ['email']), ('websites', ['website']), ('other_contact_points', ['other', 'messenger'])]:
                row[column] = ' | '.join(p['raw_value'] for p in card['contact_points'] if p['kind'] in kinds)
            # CSV is a human review export, never the database import contract.
            # Keep spreadsheet applications from executing source text as formulas.
            for key, value in row.items():
                if isinstance(value, str) and value.lstrip().startswith(('=', '+', '-', '@')):
                    row[key] = "'" + value
            writer.writerow(row)


def finalize_package(output, repo):
    checksum_lines = []
    storage = output / 'business_cards_storage'
    for file in sorted(storage.iterdir()):
        checksum_lines.append(hashlib.sha256(file.read_bytes()).hexdigest() + '  ' + storage.name + '/' + file.name)
    for name in ['IMPORT.sql', 'manifest.json', 'business_cards_table.csv']:
        checksum_lines.append(hashlib.sha256((output / name).read_bytes()).hexdigest() + '  ' + name)
    (output / 'SHA256SUMS.txt').write_text('\n'.join(checksum_lines) + '\n', encoding='utf-8')
    readme = (repo / 'docs/BUSINESS_CARDS_PREPARED_IMPORT.md').read_text(encoding='utf-8')
    (output / 'README_FA.md').write_text(readme, encoding='utf-8')
    with zipfile.ZipFile(output.with_suffix('.zip'), 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=1) as archive:
        for file in sorted(output.rglob('*')):
            if file.is_file():
                archive.write(file, file.relative_to(output.parent).as_posix())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-root', type=Path, default=Path(__file__).resolve().parents[1] / 'cart')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    output = args.output.resolve()
    if output.exists() or output.with_suffix('.zip').exists():
        raise ValueError('Choose a new package directory; existing output is never overwritten')
    manifest = build(args.source_root)
    output.mkdir(parents=True)
    storage = output / 'business_cards_storage'
    storage.mkdir()
    package_id = uuid.uuid4().hex
    for record in manifest['records']:
        record['prepared_image'] = prepared_image(args.source_root, record, storage)
    package_hash = hashlib.sha256(json_text(manifest).encode()).hexdigest()
    manifest.update(package_id=package_id, package_hash=package_hash, storage_directory=storage.name)
    (output / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding='utf-8')
    repo = Path(__file__).resolve().parents[1]
    schema = (repo / 'database/migrations/014_emcore_business_cards.sql').read_text(encoding='utf-8') + '\n' + (repo / 'database/migrations/015_emcore_business_cards_original_only.sql').read_text(encoding='utf-8')
    (output / 'IMPORT.sql').write_text(make_sql(manifest['records'], package_hash, package_id, schema), encoding='utf-8')
    country_names = dict(re.findall(r"\('([A-Z]{2})','([^']*)',", schema))
    write_review_csv(output / 'business_cards_table.csv', manifest['records'], country_names)
    finalize_package(output, repo)
    print(json_text({'package': str(output), 'archive': str(output.with_suffix('.zip')), 'cards': 443, 'originals': 304, 'without_image': 139, 'storage_files': len(list(storage.iterdir())), 'package_hash': package_hash}))


if __name__ == '__main__':
    main()
