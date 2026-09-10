import hashlib
import json
import re
import shutil
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE_UPLOAD = ROOT / 'upload'
ADDON_REL = Path('src/addons/Warext/AIContentInspector')
ADDON_JSON = SOURCE_UPLOAD / ADDON_REL / 'addon.json'

if not SOURCE_UPLOAD.is_dir() or not ADDON_JSON.is_file():
    raise SystemExit('XenForo kaynak dizini veya addon.json bulunamadı.')

addon = json.loads(ADDON_JSON.read_text(encoding='utf-8'))
version = str(addon.get('version_string') or '').strip()
version_id = int(addon.get('version_id') or 0)
if not version or version_id <= 0:
    raise SystemExit('Geçerli add-on sürümü bulunamadı.')

safe_version = re.sub(r'[^0-9A-Za-z._-]+', '-', version).strip('-')
package_name = f'Warext-XenForo-Icerik-AI-Denetim-Sistemi-V{safe_version}.zip'
output = ROOT / package_name

required = {
    'upload/src/addons/Warext/AIContentInspector/addon.json',
    'upload/src/addons/Warext/AIContentInspector/Setup.php',
    'upload/src/addons/Warext/AIContentInspector/Admin/Controller/HighRisk.php',
    'upload/src/addons/Warext/AIContentInspector/Job/ExternalVerify.php',
    'upload/src/addons/Warext/AIContentInspector/Job/HistoricalScan.php',
    'upload/src/addons/Warext/AIContentInspector/Cron/UsagePrune.php',
    'upload/src/addons/Warext/AIContentInspector/Provider/ProviderInterface.php',
    'upload/src/addons/Warext/AIContentInspector/Provider/LocalProvider.php',
    'upload/src/addons/Warext/AIContentInspector/Provider/OpenRouterProvider.php',
    'upload/src/addons/Warext/AIContentInspector/Provider/AbstractJsonProvider.php',
    'upload/src/addons/Warext/AIContentInspector/Provider/OpenAICompatibleProvider.php',
    'upload/src/addons/Warext/AIContentInspector/Provider/Registry.php',
    'upload/src/addons/Warext/AIContentInspector/Service/Analyzer.php',
    'upload/src/addons/Warext/AIContentInspector/Service/UserProfile.php',
    'upload/src/addons/Warext/AIContentInspector/Service/Similarity.php',
    'upload/src/addons/Warext/AIContentInspector/Service/ExternalVerifier.php',
    'upload/src/addons/Warext/AIContentInspector/Service/HistoricalAnalyzer.php',
    'upload/src/addons/Warext/AIContentInspector/Service/UsageTracker.php',
    'upload/src/addons/Warext/AIContentInspector/Pub/Controller/JsonResponder.php',
    'upload/src/addons/Warext/AIContentInspector/Pub/Controller/Report.php',
    'upload/src/addons/Warext/AIContentInspector/Pub/Controller/ThreadAnalyze.php',
    'upload/src/addons/Warext/AIContentInspector/Option/Forum.php',
    'upload/src/addons/Warext/AIContentInspector/Pub/Controller/ManualAnalyze.php',
    'upload/src/addons/Warext/AIContentInspector/XF/Entity/Post.php',
    'upload/src/addons/Warext/AIContentInspector/_data/admin_navigation.xml',
    'upload/src/addons/Warext/AIContentInspector/_data/cron_entries.xml',
    'upload/src/addons/Warext/AIContentInspector/_data/options.xml',
    'upload/src/addons/Warext/AIContentInspector/_data/option_groups.xml',
    'upload/src/addons/Warext/AIContentInspector/_data/permissions.xml',
    'upload/src/addons/Warext/AIContentInspector/_data/phrases.xml',
    'upload/src/addons/Warext/AIContentInspector/_data/routes.xml',
    'upload/src/addons/Warext/AIContentInspector/_data/template_modifications.xml',
    'upload/src/addons/Warext/AIContentInspector/_data/templates.xml',
    'upload/js/warext/ai-content-inspector/tracker.js',
    'upload/js/warext/ai-content-inspector/report-ui.js',
    'upload/js/warext/ai-content-inspector/thread-report.js',
    'upload/js/warext/ai-content-inspector/thread-controls.js',
    'upload/js/warext/ai-content-inspector/manual-analysis.js'
}

with tempfile.TemporaryDirectory(prefix='warext-ai-release-') as temp_dir:
    stage = Path(temp_dir)
    stage_upload = stage / 'upload'
    shutil.copytree(SOURCE_UPLOAD, stage_upload)

    hashes_path = stage_upload / ADDON_REL / 'hashes.json'
    hashes = {}
    for path in sorted(stage_upload.rglob('*')):
        if not path.is_file() or path == hashes_path:
            continue
        hashes[path.relative_to(stage_upload).as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
    hashes_path.write_text(json.dumps(hashes, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')

    readme = ROOT / 'README.md'
    if readme.is_file():
        shutil.copy2(readme, stage / 'README.md')

    changelog = ROOT / 'CHANGELOG.md'
    if changelog.is_file():
        shutil.copy2(changelog, stage / 'CHANGELOG.md')

    if output.exists():
        output.unlink()

    fixed = (2026, 9, 9, 0, 0, 0)
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(stage.rglob('*')):
            if not path.is_file():
                continue
            arcname = path.relative_to(stage).as_posix()
            info = zipfile.ZipInfo(arcname, fixed)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

with zipfile.ZipFile(output, 'r') as archive:
    bad = archive.testzip()
    if bad:
        raise SystemExit(f'ZIP bütünlük testi başarısız: {bad}')
    names = set(archive.namelist())
    missing = sorted(required - names)
    if missing:
        raise SystemExit('Kurulum ZIP zorunlu dosyaları eksik: ' + ', '.join(missing))
    hashes_name = 'upload/src/addons/Warext/AIContentInspector/hashes.json'
    if hashes_name not in names:
        raise SystemExit('Paket hashes.json içermiyor.')

    packaged_addon = json.loads(archive.read('upload/src/addons/Warext/AIContentInspector/addon.json').decode('utf-8'))
    if packaged_addon.get('version_string') != version or int(packaged_addon.get('version_id') or 0) != version_id:
        raise SystemExit('Paket içindeki addon.json sürümü kaynakla eşleşmiyor.')

    packaged_hashes = json.loads(archive.read(hashes_name).decode('utf-8'))
    expected_hashes = {}
    for name in sorted(names):
        if not name.startswith('upload/') or name.endswith('/') or name == hashes_name:
            continue
        expected_hashes[name[len('upload/'):]] = hashlib.sha256(archive.read(name)).hexdigest()
    if packaged_hashes != expected_hashes:
        raise SystemExit('Paket hashes.json içeriği ZIP ile eşleşmiyor.')

print(json.dumps({
    'status': 'ok',
    'version': version,
    'version_id': version_id,
    'package': package_name,
    'size': output.stat().st_size,
    'sha256': hashlib.sha256(output.read_bytes()).hexdigest()
}, ensure_ascii=False, separators=(',', ':')))
(ROOT / '.release-package').write_text(package_name + '\n', encoding='utf-8')
