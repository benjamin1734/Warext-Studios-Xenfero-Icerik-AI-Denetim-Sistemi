#!/usr/bin/env python3
import json
import re
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path('upload/src/addons/Warext/AIContentInspector')
DATA = ROOT / '_data'
JS = Path('upload/js/warext/ai-content-inspector')


def fail(message: str) -> None:
    raise SystemExit(message)


addon = json.loads((ROOT / 'addon.json').read_text(encoding='utf-8'))
version = str(addon.get('version_string', '')).strip()
version_id = int(addon.get('version_id', 0))
if version != '1.0.10' or version_id != 1000400:
    fail(f'v1.0.10 sürüm kimliği bekleniyor, bulunan: {version} / {version_id}')

navigation = ET.parse(DATA / 'navigation.xml').getroot()
if navigation.findall('navigation_entry'):
    fail('AI denetimi normal public navbar navigation.xml içinde yer almamalı')

mods = (DATA / 'template_modifications.xml').read_text(encoding='utf-8')
for marker in [
    'warext_ai_moderator_tools_link',
    '<!--[XF:mod_tools_menu:bottom]-->',
    "link('warext-ai')",
    'AI İçerik Denetimi',
    'warextAiViewSimple'
]:
    if marker not in mods:
        fail('Moderator Panel entegrasyonu eksik: ' + marker)

if re.search(r'<script[^>]+thread-controls\.js', mods, flags=re.I):
    fail('Mükerrer sağ AI Analizi butonunu üreten thread-controls.js artık yüklenmemeli')

manual_js = (JS / 'manual-analysis.js').read_text(encoding='utf-8')
for marker in [
    "window.XF.ajax('post'",
    'resultMessage',
    '_xfResponseType',
    'js-warextAiManualThreadAnalyze',
    'warext-ai-manual-thread-analysis-queued'
]:
    if marker not in manual_js:
        fail('XenForo AJAX/manuel konu analiz entegrasyonu eksik: ' + marker)

thread_controller = (ROOT / 'Pub/Controller/ThreadAnalyze.php').read_text(encoding='utf-8')
for marker in [
    'force_reanalyze',
    'manual_scan',
    'queue_exception',
    'errorReference',
    'XF::logException',
    'HistoricalScan::class'
]:
    if marker not in thread_controller:
        fail('Konu analiz endpoint hata/queue koruması eksik: ' + marker)

history_job = (ROOT / 'Job/HistoricalScan.php').read_text(encoding='utf-8')
for marker in ["'force_reanalyze' => false", "'manual_scan' => false", "empty($this->data['force_reanalyze'])", "!empty($this->data['manual_scan'])"]:
    if marker not in history_job:
        fail('Manuel konu yeniden analiz desteği eksik: ' + marker)

historical = (ROOT / 'Service/HistoricalAnalyzer.php').read_text(encoding='utf-8')
if 'max(100, (int)(\\XF::options()->warextAiMinChars' in historical:
    fail('HistoricalAnalyzer içinde eski 100 karakter sabiti geri geldi')
for marker in ['bool $manualScan = false', 'max(0, min(50000', "'manual_thread_scan_unobserved'"]:
    if marker not in historical:
        fail('HistoricalAnalyzer düşük eşik/manüel tarama desteği eksik: ' + marker)

print(json.dumps({
    'status': 'ok',
    'version': version,
    'versionId': version_id,
    'publicNavbarEntryRemoved': True,
    'moderatorToolsEntryValidated': True,
    'duplicateThreadButtonRemoved': True,
    'xenforoAjaxValidated': True,
    'manualThreadReanalysisValidated': True,
    'historicalMinCharsValidated': True,
    'queueErrorReferenceValidated': True
}, ensure_ascii=False))
