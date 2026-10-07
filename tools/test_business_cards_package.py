"""Verify the prepared package in a NEW disposable schema, without touching old fixtures.

Requires the business-card Docker fixture database and PHP image already available.
Creates a dedicated schema/container and retains them for inspection; refuses reuse.
"""
import argparse
import csv
import hashlib
import json
import re
import subprocess
import time
import urllib.request
import urllib.error
import http.cookiejar
from pathlib import Path

DATABASE = 'emcore_business_cards_package_fixture'
CONTAINER = 'emcore-bc-package-api'
ACTOR = '00000000000000000000000000000001'
checked = 0


def check(condition, message):
    global checked
    if not condition:
        raise AssertionError(message)
    checked += 1


def docker(*args, data=None, fail=True):
    return subprocess.run(['docker', *args], input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=fail)


def mysql(statement, database=DATABASE, fail=True):
    return docker('exec', '-i', 'emcore-bc-test-db', 'mysql', '--default-character-set=utf8mb4', '-N', '-uroot', '-pbc-fixture-only', database, data=statement.encode('utf-8'), fail=fail)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package', type=Path)
    args = parser.parse_args()
    package = args.package.resolve()
    manifest = json.loads((package / 'manifest.json').read_text(encoding='utf-8'))
    for line in (package / 'SHA256SUMS.txt').read_text().splitlines():
        digest, relative = line.split('  ', 1)
        file = (package / relative).resolve()
        check(file.is_relative_to(package), 'Checksum path confined to package')
        check(hashlib.sha256(file.read_bytes()).hexdigest() == digest, 'Package checksum')
    records = manifest['records']
    check(len(records) == 443, '443 package source records')
    with (package / 'business_cards_table.csv').open(encoding='utf-8-sig', newline='') as f:
        rows = list(csv.DictReader(f))
    check(len(rows) == 443, '443 consolidated CSV rows')
    check(sum(row['has_image'] == '1' for row in rows) == 304, '304 CSV attachments')
    check(all(not row['attachment_filename'] for row in rows if row['has_image'] == '0'), '139 empty attachment references')
    check(len(set(r['prepared_image']['stored_filename'] for r in records if r['prepared_image'])) == 304, 'Unique flattened original names')
    check(all(re.fullmatch(r'[a-f0-9]{32}\.jpg', r['prepared_image']['stored_filename']) for r in records if r['prepared_image']), 'API-compatible file names')
    exists = mysql("SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='" + DATABASE + "';", database='information_schema').stdout.strip()
    if exists != b'0':
        raise RuntimeError('Dedicated package fixture already exists; inspect it rather than overwriting')
    names = docker('ps', '-a', '--format', '{{.Names}}').stdout.decode().splitlines()
    if CONTAINER in names:
        raise RuntimeError('Dedicated package API already exists; inspect it first')
    mysql('CREATE DATABASE ' + DATABASE + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', database='information_schema')
    repo = Path(__file__).resolve().parents[1]
    docker('run', '-d', '--name', CONTAINER, '--network', 'emcore-bc-test-net', '-p', '127.0.0.1:33384:8080',
           '--mount', f'type=bind,source={repo},target=/workspace,readonly',
           '--mount', f'type=bind,source={package / "business_cards_storage"},target=/private/business_cards_storage',
           '-e', 'EMCORE_BC_FIXTURE=1', '-e', f'EMCORE_DB_DSN=mysql:host=emcore-bc-test-db;dbname={DATABASE};charset=utf8mb4',
           '-e', 'EMCORE_DB_USER=root', '-e', 'EMCORE_DB_PASSWORD=bc-fixture-only',
           '-e', 'EMCORE_BUSINESS_CARDS_STORAGE_ROOT=/private/business_cards_storage', 'emcore-business-cards-php-test:local')
    docker('exec', CONTAINER, 'php', 'tools/fixtures/business_cards/setup.php')
    mysql('ALTER TABLE emcore_business_cards AUTO_INCREMENT=1000;')
    sql = (package / 'IMPORT.sql').read_text(encoding='utf-8')
    invalid = mysql(sql, fail=False)
    check(invalid.returncode != 0 and b'active ProcessMaker UID' in invalid.stderr, 'Invalid actor blocked')
    check(mysql('SELECT COUNT(*) FROM emcore_business_cards;').stdout.strip() == b'0', 'Invalid actor creates no cards')
    sql = sql.replace('REPLACE_WITH_ACTIVE_USER_UID', ACTOR)
    mysql('UPDATE fixture_flags SET fail_audit=1;')
    failed = mysql(sql, fail=False)
    check(failed.returncode != 0 and b'Fixture audit failure' in failed.stderr, 'Forced audit failure detected')
    check(mysql('SELECT COUNT(*) FROM emcore_business_cards; SELECT COUNT(*) FROM emcore_business_card_source_records; SELECT COUNT(*) FROM emcore_business_card_files;').stdout.strip() == b'0\n0\n0', 'Failed import rolls back all seed data')
    mysql('UPDATE fixture_flags SET fail_audit=0;')
    imported = mysql(sql)
    check(imported.stdout.startswith(b'443\t0\t'), 'SQL imports 443 new records')
    counts = mysql("SELECT COUNT(*) FROM emcore_business_cards; SELECT COUNT(*) FROM emcore_business_card_files WHERE current_card_id IS NOT NULL; SELECT COUNT(*) FROM emcore_business_card_source_records; SELECT COUNT(DISTINCT card_id) FROM emcore_business_card_locations WHERE latitude IS NOT NULL; SELECT COUNT(*) FROM emcore_business_card_locations WHERE latitude IS NOT NULL; SELECT COUNT(*) FROM emcore_audit_log WHERE module_key='business_cards';").stdout.strip()
    check(counts == b'443\n304\n443\n295\n297\n443', 'SQL count acceptance and audits')
    first_card = int(mysql('SELECT MIN(id) FROM emcore_business_cards;').stdout.strip())
    check(first_card >= 1000, 'IDs dynamically assigned in target database')
    mysql("UPDATE emcore_business_cards SET notes='PACKAGE USER EDIT',lock_version=lock_version+1 WHERE id=" + str(first_card) + ';')
    replay = mysql(sql)
    check(replay.stdout.startswith(b'0\t443\t'), 'SQL replay creates zero records')
    check(mysql('SELECT notes,lock_version FROM emcore_business_cards WHERE id=' + str(first_card) + ';').stdout.strip() == b'PACKAGE USER EDIT\t2', 'SQL replay preserves user edits')
    source_hash = mysql('SELECT content_hash FROM emcore_business_card_source_records WHERE card_id=' + str(first_card) + ';').stdout.strip().decode()
    mysql("UPDATE emcore_business_card_source_records SET content_hash=REPEAT('0',64) WHERE card_id=" + str(first_card) + ';')
    changed = mysql(sql, fail=False)
    check(changed.returncode != 0 and b'Source content differs' in changed.stderr, 'Changed source blocks SQL import')
    check(mysql('SELECT notes,lock_version FROM emcore_business_cards WHERE id=' + str(first_card) + ';').stdout.strip() == b'PACKAGE USER EDIT\t2', 'Changed source never overwrites user data')
    mysql("UPDATE emcore_business_card_source_records SET content_hash='" + source_hash + "' WHERE card_id=" + str(first_card) + ';')
    # Failed CALL deliberately leaves its definition for diagnostics; next import
    # replaces only that same package-owned procedure and removes it on success.
    mysql(sql)
    procedure = 'emcore_bc_import_' + manifest['package_id'][:12]
    check(mysql("SELECT COUNT(*) FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA='" + DATABASE + "' AND ROUTINE_NAME='" + procedure + "';").stdout.strip() == b'0', 'Temporary import procedure removed')
    # Reuse source/hash verifier; it intentionally ignores user-editable card notes.
    private_manifest = package.relative_to(repo).as_posix() + '/manifest.json'
    result = docker('exec', CONTAINER, 'php', 'tools/fixtures/business_cards/verify_sources.php', private_manifest)
    check(b'Source fidelity passed' in result.stdout, 'All source texts, originals and coordinates match')
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def api(action, **fields):
        from urllib.parse import urlencode
        request = urllib.request.Request('http://127.0.0.1:33384/emcore_api/emcore_business_cards.php', data=urlencode(dict(action=action, **fields)).encode(), headers={'X-BC-Fixture-Actor': 'admin'})
        return opener.open(request, timeout=30)
    for attempt in range(30):
        try:
            lookup = json.load(api('lookups'))
            break
        except (OSError, urllib.error.URLError):
            time.sleep(0.5)
    check(lookup['data']['storage_ready'], 'Prepared server folder is ready')
    linked = {row.split('\t')[0]: int(row.split('\t')[1]) for row in mysql('SELECT source_key,card_id FROM emcore_business_card_source_records;').stdout.decode().splitlines()}
    for record in records:
        image = record['prepared_image']
        if not image:
            continue
        response = api('preview_image', id=linked[record['source_key']])
        check(hashlib.sha256(response.read()).hexdigest() == image['sha256'], 'Authorized API reads the correctly linked original')
    original_card = next(r for r in records if r['prepared_image'])
    check(api('download_image', id=linked[original_card['source_key']]).status == 200, 'Prepared original downloads')
    check(mysql('SELECT COUNT(*) FROM emcore_business_card_download_log;').stdout.strip() == b'1', 'Prepared original download is logged')
    check(len(list((package / 'business_cards_storage').iterdir())) == 304, 'Only 304 originals in storage')
    check(mysql('SELECT COUNT(*) FROM emcore_business_card_files WHERE thumbnail_filename IS NOT NULL OR thumbnail_sha256 IS NOT NULL;').stdout.strip() == b'0', 'New records have no thumbnail metadata')
    print(f'Prepared package passed: {checked} checks; SQL rollback/replay, shifted IDs, 443 source records, 304 originals through API, no thumbnails.')


if __name__ == '__main__':
    main()
