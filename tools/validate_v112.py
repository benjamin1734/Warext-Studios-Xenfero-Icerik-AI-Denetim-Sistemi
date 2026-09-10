#!/usr/bin/env python3
import json
import re
from pathlib import Path

ROOT = Path('upload/src/addons/Warext/AIContentInspector')
JS = Path('upload/js/warext/ai-content-inspector')


def fail(message):
    raise SystemExit(message)

addon = json.loads((ROOT / 'addon.json').read_text(encoding='utf-8'))
version = str(addon.get('version_string', '')).strip()
match = re.fullmatch(r'(\d+)\.(\d+)\.(\d+)', version)
if not match:
    fail('Geçerli Stable sürüm kimliği bekleniyor')
version_tuple = tuple(int(part) for part in match.groups())
if version_tuple < (1, 1, 2) or int(addon.get('version_id', 0)) < 1010200:
    fail('Mesaj-seviyesi akış doğrulaması yalnız v1.1.2 ve üstü sürümlerde çalışır')

routes = (ROOT / '_data/routes.xml').read_text(encoding='utf-8')
if 'warext-ai-thread' in routes or 'ThreadAnalyze' in routes:
    fail('Konu-seviyesi manuel analiz routeu kaldırılmalı')
if 'warext-ai-manual' not in routes:
    fail('Mesaj-seviyesi manuel analiz routeu eksik')

mods = (ROOT / '_data/template_modifications.xml').read_text(encoding='utf-8')
for marker in ['warext_ai_manual_post_action', 'Bu mesajı AI ile analiz et', 'inline-report.js', 'warext_ai_moderator_tools_link']:
    if marker not in mods:
        fail('Mesaj-seviyesi analiz/inline rapor entegrasyonu eksik: ' + marker)
for forbidden in ['warext_ai_manual_thread_action', 'Konuyu AI ile analiz et', 'data-thread-analyze-endpoint']:
    if forbidden in mods:
        fail('Konu-seviyesi manuel analiz kalıntısı var: ' + forbidden)

manual = (JS / 'manual-analysis.js').read_text(encoding='utf-8')
for marker in ['js-warextAiManualAnalyze', 'Yalnızca mesaj #', 'warext-ai-manual-analysis-complete']:
    if marker not in manual:
        fail('Mesaj manuel analiz JS akışı eksik: ' + marker)
for forbidden in ['analyzeThread(', 'js-warextAiManualThreadAnalyze', 'threadAnalyzeEndpoint']:
    if forbidden in manual:
        fail('Konu manuel analiz JS kalıntısı var: ' + forbidden)

inline = (JS / 'inline-report.js').read_text(encoding='utf-8')
for marker in ['AI Analiz Raporu', 'IntersectionObserver', 'warext-ai-batch-ready', 'warext-ai-manual-analysis-complete', 'Analiz ayrıntıları']:
    if marker not in inline:
        fail('Inline mesaj raporu eksik: ' + marker)

build = Path('tools/build_release.py').read_text(encoding='utf-8')
for marker in ['inline-report.js', 'JsonResponder.php', 'ManualAnalyze.php']:
    if marker not in build:
        fail('Paket zorunlu dosya kontrolü eksik: ' + marker)
if "'upload/src/addons/Warext/AIContentInspector/Pub/Controller/ThreadAnalyze.php'" in build:
    fail('ThreadAnalyze artık paket zorunlu dosyası olmamalı')

print(json.dumps({
    'status': 'ok',
    'version': version,
    'singlePostManualAnalysis': True,
    'threadManualAnalysisRemoved': True,
    'inlineReportValidated': True,
    'lazyDetailValidated': True
}, ensure_ascii=False))
