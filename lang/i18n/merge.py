"""Merge lang/i18n/out/{locale}/chunk*.json into lang/{locale}.json.

Arabic also keeps the old dictionary's entries (lang/i18n/ar-existing.json)
the extractor no longer finds, so no text that was translated becomes
French again.
"""
import json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
LANG = os.path.dirname(HERE)
PH = re.compile(r'\{\d+\}|:[a-z_]+\b|_[A-Z]+_|%[sd]')

source = json.load(open(os.path.join(HERE, 'source.json'), encoding='utf-8'))

for locale in sys.argv[1:]:
    merged = {}
    folder = os.path.join(HERE, 'out', locale)
    for name in sorted(os.listdir(folder)):
        # zz-fixes.json, corrections by hand, sorts last and wins.
        if (name.startswith('chunk') or name.startswith('zz-')) and name.endswith('.json'):
            merged.update(json.load(open(os.path.join(folder, name), encoding='utf-8')))
    if locale == 'ar':
        for k, v in json.load(open(os.path.join(HERE, 'ar-existing.json'), encoding='utf-8')).items():
            merged.setdefault(k, v)
    missing = [k for k in source if not merged.get(k, '').strip()]
    broken = [k for k, v in merged.items() if sorted(PH.findall(k)) != sorted(PH.findall(v))]
    out = {k: merged[k] for k in sorted(merged)}
    with open(os.path.join(LANG, f'{locale}.json'), 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=4)
        f.write('\n')
    print(f'{locale}: {len(out)} entries, {len(missing)} missing, {len(broken)} placeholder mismatches')
