#!/usr/bin/env python3
from pathlib import Path

ROOT = Path('upload/src/addons/Warext/AIContentInspector/Pub/Controller')
trait = ROOT / 'JsonResponder.php'
if not trait.is_file():
    raise SystemExit('JsonResponder.php eksik')

trait_code = trait.read_text(encoding='utf-8')
for marker in ["setResponseType('json')", 'setJsonParams($params)', "view('Warext\\\\AIContentInspector:Json'"]:
    if marker not in trait_code:
        raise SystemExit('XenForo 2.3 JSON responder eksik: ' + marker)

for name in ['Report.php', 'ThreadAnalyze.php', 'ManualAnalyze.php']:
    path = ROOT / name
    if not path.is_file():
        raise SystemExit(name + ' eksik')
    code = path.read_text(encoding='utf-8')
    if 'use JsonResponder;' not in code:
        raise SystemExit(name + ' JsonResponder trait kullanmıyor')
    if '$this->asJson(' in code and 'use JsonResponder;' not in code:
        raise SystemExit(name + ' undefined asJson riski taşıyor')

print('XenForo 2.3 JSON controller regression: OK')
