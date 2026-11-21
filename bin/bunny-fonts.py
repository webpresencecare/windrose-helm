#!/usr/bin/env python3
"""Builds and checks Windrose Helm's Bunny Fonts data.

    python3 -I bin/bunny-fonts.py build    # fetches https://fonts.bunny.net/list and each family's CSS (about 1,850 requests)
    python3 -I bin/bunny-fonts.py check    # no network: checks the three files and that they agree (CI runs this)
    python3 -I bin/bunny-fonts.py reformat # rewrites both index files from the JSON index, then checks

Writes, in the plugin's data/ folder:

- bunny-fonts.json: a font collection in the shape of core's Google Fonts collection
  (https://schemas.wp.org/trunk/font-collection.json). One face per weight and style; `src` is the list of the
  family's script files (default script first, at most MAX_FILES), as fonts.bunny.net addresses. Helm rewrites them
  to its own route for each request (Bunny_Fonts::rest_response()).
- bunny-fonts-index.json: what Helm checks and prints with: per family the scripts, the faces ("400-normal"), and
  the unicode ranges that differ from the usual range of a script (`ranges` at the top).
- bunny-fonts-index.php: the same index as a PHP array, which Helm loads on pages that use a Bunny font with several
  scripts (PHP's opcode cache keeps it in memory; decoding the JSON on every page costs time). Written from the
  JSON; `check` fails when it differs.

Left out: families cut into numbered slices (Japanese, Korean, Chinese), whose faces need far more files than one
upload request takes.

Standard library only. Run it before a release when the list should be refreshed, then commit both files.
"""

import concurrent.futures
import http.client
import json
import re
import sys
import threading
import time
import urllib.parse
from collections import Counter
from datetime import date
from pathlib import Path

LIST_URL = 'https://fonts.bunny.net/list'
CSS_URL = 'https://fonts.bunny.net/css?family={id}:{variants}'
FILE_PREFIX = 'https://fonts.bunny.net/'
# Bunny answers browsers with WOFF2; any current browser identification works.
USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36'
MAX_FILES = 20  # PHP's default max_file_uploads: core uploads every file of a face in one request.
WORKERS = 3
DATA = Path(__file__).resolve().parent.parent / 'data'
COLLECTION = DATA / 'bunny-fonts.json'
INDEX = DATA / 'bunny-fonts-index.json'
INDEX_PHP = DATA / 'bunny-fonts-index.php'
CATEGORIES = {'sans-serif': 'sans-serif', 'serif': 'serif', 'display': 'system-ui', 'handwriting': 'cursive', 'monospace': 'monospace'}
FACE = re.compile(r'/\*\s*([a-z0-9-]+)\s*\*/\s*@font-face\s*\{(.*?)\}', re.S)
ID = re.compile(r'^[a-z0-9]+(?:-[a-z0-9]+)*$')


_local = threading.local()


def fetch(url, attempts=4):
    """GET over one kept-alive connection per worker (new connections to Bunny are sometimes dropped)."""
    parts = urllib.parse.urlsplit(url)
    path = parts.path + ('?' + parts.query if parts.query else '')
    for attempt in range(attempts):
        try:
            if getattr(_local, 'connection', None) is None:
                _local.connection = http.client.HTTPSConnection(parts.hostname, timeout=30)
            _local.connection.request('GET', path, headers={'User-Agent': USER_AGENT})
            response = _local.connection.getresponse()
            body = response.read()
            if response.status != 200:
                raise RuntimeError(f'{url}: HTTP {response.status}')
            return body.decode('utf-8')
        except Exception:  # noqa: BLE001 - retried on a new connection, then raised
            if getattr(_local, 'connection', None) is not None:
                _local.connection.close()
                _local.connection = None
            if attempt == attempts - 1:
                raise
            time.sleep(2 + attempt * 3)
    raise RuntimeError(url)


def prop(body, name):
    match = re.search(name + r'\s*:\s*([^;]+);', body)
    return match.group(1).strip() if match else ''


def family_faces(family_id, info):
    """Parses the family's CSS: {(weight, style): {subset: (url, range)}}."""
    variants = []
    for weight in info['weights']:
        for style in info['styles']:
            variants.append(f'{weight}i' if style == 'italic' else str(weight))
    css = fetch(CSS_URL.format(id=family_id, variants=','.join(variants)))
    faces = {}
    for subset, body in FACE.findall(css):
        weight = prop(body, 'font-weight')
        style = prop(body, 'font-style')
        urls = re.findall(r'url\((https://fonts\.bunny\.net/[^)]+\.woff2)\)', prop(body, 'src'))
        unicode_range = prop(body, 'unicode-range')
        expected = f'{FILE_PREFIX}{family_id}/files/{family_id}-{subset}-{weight}-{style}.woff2'
        if not urls or urls[0] != expected or not unicode_range:
            raise ValueError(f'{family_id}: unexpected face {subset} {weight} {style}: {urls[:1]}')
        faces.setdefault((weight, style), {})[subset] = (urls[0], unicode_range)
    return faces


def build():
    families = json.loads(fetch(LIST_URL))
    usage = Counter(s for info in families.values() for s in info['variants'])
    wanted = {k: v for k, v in sorted(families.items()) if ID.match(k) and not any(s.isdigit() for s in v['variants'])}
    print(f'{len(families)} families listed, {len(wanted)} without numbered slices', file=sys.stderr)

    parsed = {}
    with concurrent.futures.ThreadPoolExecutor(WORKERS) as pool:
        jobs = {pool.submit(family_faces, k, v): k for k, v in wanted.items()}
        for done, job in enumerate(concurrent.futures.as_completed(jobs), 1):
            parsed[jobs[job]] = job.result()
            if done % 100 == 0:
                print(f'{done} families read', file=sys.stderr)

    # The usual range of each script: the one most families use.
    seen = {}
    for faces in parsed.values():
        for scripts in faces.values():
            for subset, (_, unicode_range) in scripts.items():
                seen.setdefault(subset, Counter())[unicode_range] += 1
    standard = {subset: ranges.most_common(1)[0][0] for subset, ranges in sorted(seen.items())}

    collection, index = [], {}
    for family_id, info in wanted.items():
        faces = parsed[family_id]
        if not faces:
            print(f'skipped {family_id}: no faces', file=sys.stderr)
            continue
        default = info['defSubset']
        present = {s for scripts in faces.values() for s in scripts}
        order = sorted(present, key=lambda s: (s != default, s != 'latin-ext', -usage[s], s))[:MAX_FILES]
        ranges = {}
        face_list, face_ids = [], []
        for (weight, style), scripts in sorted(faces.items(), key=lambda item: (item[0][1] != 'normal', int(item[0][0]))):
            src = [scripts[s][0] for s in order if s in scripts]
            for s in order:
                if s in scripts and scripts[s][1] != standard[s]:
                    if ranges.get(s, scripts[s][1]) != scripts[s][1]:
                        raise ValueError(f'{family_id}: {s} has different ranges per weight')
                    ranges[s] = scripts[s][1]
            face_list.append({
                'fontFamily': info['familyName'],
                'fontStyle': style,
                'fontWeight': weight,
                'src': src[0] if len(src) == 1 else src,
            })
            face_ids.append(f'{weight}-{style}')
        category = info['category'] if info['category'] in CATEGORIES else 'sans-serif'
        collection.append({
            'font_family_settings': {
                'name': info['familyName'],
                'fontFamily': f"{info['familyName']}, {CATEGORIES[category]}",
                'slug': family_id,
                'fontFace': face_list,
            },
            'categories': [category],
        })
        entry = {'subsets': order, 'faces': face_ids}
        if ranges:
            entry['ranges'] = ranges
        index[family_id] = entry

    DATA.mkdir(exist_ok=True)
    COLLECTION.write_text(json.dumps({'$schema': 'https://schemas.wp.org/trunk/font-collection.json', 'font_families': collection}, ensure_ascii=False, separators=(',', ':')) + '\n')
    INDEX.write_text(index_json({'source': LIST_URL, 'built': date.today().isoformat(), 'ranges': standard, 'families': index}))
    INDEX_PHP.write_text(index_php(json.loads(INDEX.read_text())))
    print(f'{len(collection)} families, {sum(len(f["font_family_settings"]["fontFace"]) for f in collection)} faces', file=sys.stderr)
    check()


def index_json(data):
    """Compact JSON with one script range and one family per line (small to read on each page, readable in diffs)."""
    compact = {'ensure_ascii': False, 'separators': (',', ':'), 'sort_keys': True}
    lines = ['{', f'"built":{json.dumps(data["built"])},"source":{json.dumps(data["source"])},', '"ranges":{']
    lines.append(',\n'.join(f'{json.dumps(k)}:{json.dumps(v, **compact)}' for k, v in sorted(data['ranges'].items())))
    lines.append('},"families":{')
    lines.append(',\n'.join(f'{json.dumps(k)}:{json.dumps(v, **compact)}' for k, v in sorted(data['families'].items())))
    lines.append('}}')
    return '\n'.join(lines) + '\n'


def php_value(value):
    """A JSON value (objects, lists, strings) as PHP source."""
    if isinstance(value, dict):
        return '[' + ','.join(f'{php_value(k)}=>{php_value(v)}' for k, v in value.items()) + ']'
    if isinstance(value, list):
        return '[' + ','.join(php_value(v) for v in value) + ']'
    if isinstance(value, str):
        return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
    raise TypeError(f'unexpected value in the index: {value!r}')


def index_php(data):
    """The index as a PHP file returning an array, one script range and one family per line."""
    lines = [
        '<?php',
        '/**',
        ' * Bunny Fonts index, generated by bin/bunny-fonts.py from bunny-fonts-index.json: do not edit.',
        ' *',
        ' * @package Windrose\\Helm',
        ' */',
        '',
        "defined( 'ABSPATH' ) || exit;",
        '',
        f"return ['built'=>{php_value(data['built'])},'source'=>{php_value(data['source'])},'ranges'=>[",
    ]
    lines.append(',\n'.join(f'{php_value(k)}=>{php_value(v)}' for k, v in sorted(data['ranges'].items())))
    lines.append("],'families'=>[")
    lines.append(',\n'.join(f'{php_value(k)}=>{php_value(v)}' for k, v in sorted(data['families'].items())))
    lines.append(']];')
    return '\n'.join(lines) + '\n'


def check():
    collection = json.loads(COLLECTION.read_text())
    index = json.loads(INDEX.read_text())
    errors = []
    if not INDEX_PHP.exists() or INDEX_PHP.read_text() != index_php(index):
        errors.append('bunny-fonts-index.php differs from bunny-fonts-index.json: run reformat')
    families = index['families']
    if index.get('source') != LIST_URL or not re.match(r'^\d{4}-\d{2}-\d{2}$', index.get('built', '')):
        errors.append('index: source or built date missing')
    names = []
    for item in collection['font_families']:
        settings = item['font_family_settings']
        family_id = settings['slug']
        names.append(family_id)
        entry = families.get(family_id)
        if entry is None:
            errors.append(f'{family_id}: not in the index')
            continue
        if item.get('categories', [None])[0] not in CATEGORIES:
            errors.append(f'{family_id}: unknown category')
        faces = []
        for face in settings['fontFace']:
            face_id = f"{face['fontWeight']}-{face['fontStyle']}"
            faces.append(face_id)
            src = face['src'] if isinstance(face['src'], list) else [face['src']]
            if not 1 <= len(src) <= MAX_FILES:
                errors.append(f'{family_id} {face_id}: {len(src)} files')
            for url in src:
                prefix = f'{FILE_PREFIX}{family_id}/files/{family_id}-'
                suffix = f"-{face['fontWeight']}-{face['fontStyle']}.woff2"
                subset = url[len(prefix):-len(suffix)] if url.startswith(prefix) and url.endswith(suffix) else ''
                if subset not in entry['subsets']:
                    errors.append(f'{family_id} {face_id}: unexpected file {url}')
                elif subset not in index['ranges'] and subset not in entry.get('ranges', {}):
                    errors.append(f'{family_id}: no range for {subset}')
        if faces != entry['faces']:
            errors.append(f'{family_id}: faces differ from the index')
    if sorted(names) != sorted(families) or len(set(names)) != len(names):
        errors.append('collection and index list different families')
    for message in errors[:50]:
        print(message, file=sys.stderr)
    if errors:
        sys.exit(f'{len(errors)} problems')
    print(f'Bunny Fonts data: {len(names)} families, built {index["built"]}, consistent.')


if __name__ == '__main__':
    command = sys.argv[1] if len(sys.argv) > 1 else ''
    if command == 'build':
        build()
    elif command == 'reformat':
        INDEX.write_text(index_json(json.loads(INDEX.read_text())))
        INDEX_PHP.write_text(index_php(json.loads(INDEX.read_text())))
        check()
    elif command == 'check':
        check()
    else:
        sys.exit(__doc__)
