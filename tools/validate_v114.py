#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path('upload/src/addons/Warext/AIContentInspector')
JS = Path('upload/js/warext/ai-content-inspector')


def fail(message: str) -> None:
    raise SystemExit(message)

addon = json.loads((ROOT / 'addon.json').read_text(encoding='utf-8'))
version = tuple(int(part) for part in str(addon.get('version_string', '0.0.0')).split('.'))
if version < (1, 1, 4):
    fail('v1.1.4 veya üstü sürüm bekleniyor')

for path in [
    ROOT / 'Service/LocalCalibration.php',
    ROOT / 'Service/ExternalScoreFusion.php',
    ROOT / 'Service/RiskClassifier.php'
]:
    if not path.exists():
        fail('v1.1.4 servis dosyası eksik: ' + str(path))

local_provider = (ROOT / 'Provider/LocalProvider.php').read_text(encoding='utf-8')
for marker in ['LocalCalibration', 'LocalCalibration::apply', 'engine_version']:
    if marker not in local_provider:
        fail('LocalProvider kalibrasyon zinciri eksik: ' + marker)

manual = (ROOT / 'Pub/Controller/ManualAnalyze.php').read_text(encoding='utf-8')
for marker in ['ExternalVerifier', "'force_external' => true", "'manual' => true", 'analysisStage', 'rawLocalRisk', 'calibratedLocalRisk', 'externalRisk']:
    if marker not in manual:
        fail('Manuel derin analiz zinciri eksik: ' + marker)

external = (ROOT / 'Service/ExternalVerifier.php').read_text(encoding='utf-8')
for marker in ['force_external', 'ExternalScoreFusion::combine', 'local_external_disagreement', 'classifyWithConfidence']:
    if marker not in external:
        fail('Harici skor füzyon zinciri eksik: ' + marker)

manual_js = (JS / 'manual-analysis.js').read_text(encoding='utf-8')
for marker in ['pollFinalReport', 'externalPending', 'detailEndpoint', 'Harici ikinci görüş tamamlandı']:
    if marker not in manual_js:
        fail('Harici sonuç otomatik yenileme akışı eksik: ' + marker)

inline = (JS / 'inline-report.js').read_text(encoding='utf-8')
for marker in ['Ham yerel skor', 'Kalibre yerel skor', 'Harici sağlayıcı skoru', 'Nihai skor', 'engine_version', 'local_external_disagreement']:
    if marker not in inline:
        fail('Inline skor zinciri görünümü eksik: ' + marker)

build = Path('tools/build_release.py').read_text(encoding='utf-8')
for marker in ['LocalCalibration.php', 'ExternalScoreFusion.php']:
    if marker not in build:
        fail('Yeni kalibrasyon servisi ZIP zorunlu listesinde değil: ' + marker)

print(json.dumps({
    'status': 'ok',
    'version': '.'.join(map(str, version)),
    'localCalibration': True,
    'forcedManualExternal': True,
    'externalFusion': True,
    'inlineScoreTrace': True,
    'externalAutoRefresh': True
}, ensure_ascii=False))
