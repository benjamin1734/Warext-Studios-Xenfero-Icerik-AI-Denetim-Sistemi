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


def main() -> None:
    addon = json.loads((ROOT / 'addon.json').read_text(encoding='utf-8'))
    version = str(addon.get('version_string', '')).strip()
    version_id = int(addon.get('version_id', 0))
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        fail(f'Geçersiz Stable sürüm kimliği: {version!r}')
    if version_id <= 0:
        fail('version_id geçersiz')
    if int(addon.get('require', {}).get('XF', [0])[0]) < 2030070:
        fail('XenForo 2.3+ gereksinimi eksik')
    if 'Warext/TurkishSpellCheck' in json.dumps(addon, ensure_ascii=False):
        fail('Writing Checker zorunlu bağımlılık olamaz')

    parsed = {path.name: ET.parse(path).getroot() for path in sorted(DATA.glob('*.xml'))}

    allowed_formats = {'textbox','spinbox','onoff','onofftextbox','radio','select','checkbox','template','callback','username'}
    option_nodes = parsed['options.xml'].findall('option')
    if len(option_nodes) < 20:
        fail(f'Warext AI seçenek master-data eksik: yalnız {len(option_nodes)} option bulundu')
    invalid = {o.attrib.get('option_id'): o.attrib.get('edit_format') for o in option_nodes if o.attrib.get('edit_format') not in allowed_formats}
    if invalid:
        fail('Geçersiz XenForo option edit formatı: ' + repr(invalid))

    required_option_ids = {
        'warextAiEnabled','warextAiMinChars','warextAiForums','warextAiExternalProvider',
        'warextAiOpenRouterKey','warextAiOpenRouterModel','warextAiDailyRequestLimit',
        'warextAiMonthlyRequestLimit','warextAiHistoryBatchSize'
    }
    actual_ids = {o.attrib.get('option_id') for o in option_nodes}
    missing = sorted(required_option_ids - actual_ids)
    if missing:
        fail('Zorunlu Warext AI seçenekleri eksik: ' + ', '.join(missing))
    for option in option_nodes:
        relations = option.findall('relation')
        if not relations or not any(r.attrib.get('group_id') == 'warextAi' for r in relations):
            fail(f"Option warextAi grubuna bağlı değil: {option.attrib.get('option_id')}")

    fallback = next((o for o in option_nodes if o.attrib.get('option_id') == 'warextAiOpenRouterFallbackModels'), None)
    if fallback is None or fallback.attrib.get('edit_format') != 'textbox' or 'rows=4' not in (fallback.findtext('edit_format_params') or ''):
        fail('OpenRouter fallback listesi textbox + rows=4 olmalı')
    min_chars = next((o for o in option_nodes if o.attrib.get('option_id') == 'warextAiMinChars'), None)
    min_params = (min_chars.findtext('edit_format_params') or '') if min_chars is not None else ''
    if min_chars is None or 'min=0' not in min_params or 'max=50000' not in min_params:
        fail('Minimum analiz karakteri 0-50000 aralığında serbest olmalı')
    forum_option = next((o for o in option_nodes if o.attrib.get('option_id') == 'warextAiForums'), None)
    if forum_option is None or (forum_option.findtext('edit_format_params') or '') != r'Warext\AIContentInspector\Option\Forum::renderCheckboxList':
        fail('Forum selector özel checkbox callback biçiminde değil')

    forum_renderer = ROOT / 'Option/Forum.php'
    if not forum_renderer.exists():
        fail('Forum checkbox option renderer eksik')
    forum_code = forum_renderer.read_text(encoding='utf-8')
    for marker in ['renderCheckboxList','getCheckboxRow',"node_type_id !== 'Forum'","implode(' › ', $path)"]:
        if marker not in forum_code:
            fail('Forum checkbox option renderer eksik: ' + marker)

    option_groups = parsed['option_groups.xml']
    if option_groups.findall('option_group'):
        fail('option_groups.xml legacy <option_group> etiketi içeriyor')
    groups = option_groups.findall('group')
    warext_group = next((g for g in groups if g.attrib.get('group_id') == 'warextAi'), None)
    if warext_group is None:
        fail('Warext AI option group <group> öğesiyle tanımlı değil')
    if int(warext_group.attrib.get('display_order', '0')) <= 0:
        fail('Warext AI option group display_order geçersiz')

    admin_nav = parsed['admin_navigation.xml']
    nav = {n.attrib.get('navigation_id'): n for n in admin_nav.findall('admin_navigation_entry')}
    if not {'warextAiAdmin','warextAiSettings'}.issubset(nav):
        fail('ACP navigation export şeması hatalı')
    if int(nav['warextAiAdmin'].attrib.get('display_order','0')) < 1000:
        fail('Warext AI ACP kategorisi core menülerin üstüne taşınmamalı')
    if nav['warextAiSettings'].attrib.get('parent_navigation_id') != 'warextAiAdmin':
        fail('Warext AI ayarlar girdisi kendi ACP kategorisinin altında olmalı')
    if nav['warextAiSettings'].attrib.get('link') != 'add-ons/Warext-AIContentInspector/options':
        fail('Ayarlar bağlantısı add-on options route kullanmalı')
    if nav['warextAiAdmin'].attrib.get('link') != 'warext-ai-high-risk/':
        fail('Warext AI ACP ana kategorisi yüksek risk görünümüne gitmeli')

    permissions = {p.attrib.get('permission_id') for p in parsed['permissions.xml'].findall('permission')}
    if not {'warextAiViewSimple','warextAiViewDetailed','warextAiReview','warextAiManage'}.issubset(permissions):
        fail('Yetki tanımları eksik')

    phrases = {p.attrib.get('title') for p in parsed['phrases.xml'].findall('phrase')}
    for title in ['permission_interface.warextAi','option_group.warextAi','admin_navigation.warextAiAdmin','admin_navigation.warextAiSettings','nav.warextAi']:
        if title not in phrases:
            fail('Kanonik XenForo phrase eksik: ' + title)

    templates = parsed['templates.xml'].findall('template')
    center = next((t for t in templates if t.attrib.get('type') == 'public' and t.attrib.get('title') == 'warext_ai_center'), None)
    if center is None:
        fail('public:warext_ai_center şablonu eksik')
    for template in templates:
        item_version = int(template.attrib.get('version_id','0'))
        if item_version <= 0 or item_version > version_id:
            fail(f"Template version_id geçersiz: {template.attrib.get('title')} -> {item_version}")
        body = template.text or ''
        for pattern in [r'\{\$[^{}\n]*\?:[^{}\n]*\}', r'\{\$[^{}\n]*\?[^{}\n]*:[^{}\n]*\}']:
            match = re.search(pattern, body)
            if match:
                fail(f"{template.attrib.get('title')} içinde geçersiz ifadeli {{$...}} kullanımı: {match.group(0)}")

    center_body = center.text or ''
    if '<xf:select name="state" value="{$filters.state}"' not in center_body:
        fail('Durum filtresi kanonik xf:select yapısında değil')
    try:
        ET.fromstring('<root xmlns:xf="urn:xenforo">' + center_body + '</root>')
    except ET.ParseError as exc:
        fail('warext_ai_center markup dengesi bozuk: ' + str(exc))

    modifications = (DATA / 'template_modifications.xml').read_text(encoding='utf-8')
    cache_key = f'?wai={version_id}'
    if cache_key not in modifications:
        fail(f'{version} JS cache anahtarı eksik: {cache_key}')
    for marker in ['warext_ai_moderator_tools_link','warext_ai_manual_post_action','actionBar-action--menuItem','js-warextAiManualAnalyze',"link('warext-ai-manual/run'",'manual-analysis.js','inline-report.js']:
        if marker not in modifications:
            fail('Mesaj-seviyesi moderasyon/rapor entegrasyonu eksik: ' + marker)
    for forbidden in ['warext_ai_manual_thread_action','js-warextAiManualThreadAnalyze',"link('warext-ai-thread/run'",'data-thread-analyze-endpoint','Konuyu AI ile analiz et']:
        if forbidden in modifications:
            fail('Konu-seviyesi manuel analiz kalıntısı var: ' + forbidden)

    routes = parsed['routes.xml'].findall('route')
    route_map = {(r.attrib.get('route_type'), r.attrib.get('route_prefix')): r.attrib.get('controller') for r in routes}
    if route_map.get(('public','warext-ai')) != r'Warext\AIContentInspector:Report':
        fail('Ana Warext AI public route eksik')
    if ('public','warext-ai-thread') in route_map:
        fail('Konu-seviyesi manuel analiz public route kaldırılmalı')
    if route_map.get(('public','warext-ai-manual')) != r'Warext\AIContentInspector:ManualAnalyze':
        fail('Manuel mesaj analiz public route eksik')
    if route_map.get(('admin','warext-ai-high-risk')) != r'Warext\AIContentInspector:HighRisk':
        fail('Yüksek riskli konular ACP route eksik')

    setup = (ROOT / 'Setup.php').read_text(encoding='utf-8')
    for marker in ['installStep1','installStep2','ensureOptionGroup',"'xf_option_group'","'group_id' => 'warextAi'",'uninstallStep1','xf_warext_ai_analysis','xf_warext_ai_review_log','xf_warext_ai_usage']:
        if marker not in setup:
            fail('Install/upgrade self-heal zinciri eksik: ' + marker)
    if '$this->ensureOptionGroup();' not in setup.split('public function installStep2',1)[0]:
        fail('Temiz kurulumda option group installStep1 içinde doğrulanmalı')

    high_risk = ROOT / 'Admin/Controller/HighRisk.php'
    if not high_risk.exists():
        fail('Yüksek riskli konular ACP controller eksik')
    for marker in ["router('public')","buildLink('warext-ai'","'risk_min' => 70"]:
        if marker not in high_risk.read_text(encoding='utf-8'):
            fail('Yüksek riskli konular ACP controller eksik: ' + marker)

    manual_controller = ROOT / 'Pub/Controller/ManualAnalyze.php'
    json_responder = ROOT / 'Pub/Controller/JsonResponder.php'
    manual_ui = JS / 'manual-analysis.js'
    inline_ui = JS / 'inline-report.js'
    if not manual_controller.exists() or not json_responder.exists() or not manual_ui.exists() or not inline_ui.exists():
        fail('Manuel mesaj analiz/JSON/inline rapor dosyaları eksik')
    manual_code = manual_controller.read_text(encoding='utf-8')
    for marker in ['use JsonResponder;','warextAiReview','warextAiManage','assertPostOnly','warextRunAiAnalysis(true, true)','insufficient_text','asJson(']:
        if marker not in manual_code:
            fail('Manuel analiz controller eksik: ' + marker)
    responder_code = json_responder.read_text(encoding='utf-8')
    for marker in ['trait JsonResponder','protected function asJson','setResponseType(\'json\')','setJsonParams']:
        if marker not in responder_code:
            fail('XenForo JSON responder eksik: ' + marker)
    manual_js = manual_ui.read_text(encoding='utf-8')
    for marker in ['js-warextAiManualAnalyze','_xfToken','warext-ai-manual-analysis-complete','Yalnızca mesaj #']:
        if marker not in manual_js:
            fail('Manuel analiz tarayıcı entegrasyonu eksik: ' + marker)
    for forbidden in ['analyzeThread(','js-warextAiManualThreadAnalyze','threadAnalyzeEndpoint']:
        if forbidden in manual_js:
            fail('Manuel analiz JS içinde konu-seviyesi kalıntı var: ' + forbidden)
    inline_code = inline_ui.read_text(encoding='utf-8')
    for marker in ['AI Analiz Raporu','IntersectionObserver','warext-ai-batch-ready','warext-ai-manual-analysis-complete','Analiz ayrıntıları']:
        if marker not in inline_code:
            fail('Inline mesaj raporu eksik: ' + marker)

    post = (ROOT / 'XF/Entity/Post.php').read_text(encoding='utf-8')
    for marker in ['Registry','UserProfile','Similarity','enqueueUnique','ExternalVerify','existingHash','hash_equals','public function warextRunAiAnalysis(bool $force = false, bool $manual = false): array','$minChars = max(0, min(50000','manual_analysis']:
        if marker not in post:
            fail('Post analiz zinciri eksik: ' + marker)

    provider = ROOT / 'Provider'
    for name in ['ProviderInterface.php','LocalProvider.php','Registry.php','OpenRouterProvider.php','AbstractJsonProvider.php','OpenAICompatibleProvider.php','OpenAIProvider.php','GeminiProvider.php','DeepSeekProvider.php','AnthropicProvider.php']:
        if not (provider / name).exists():
            fail('Provider mimarisi eksik: ' + name)

    print(json.dumps({
        'status':'ok','version':version,'versionId':version_id,
        'installerMasterDataValidated':True,'optionRelationsValidated':True,
        'messageManualAnalysisValidated':True,'threadManualAnalysisRemoved':True,
        'inlineReportValidated':True,'jsonResponderValidated':True,
        'adminNavigationOrderProtected':True,'externalOptional':True
    }, ensure_ascii=False))


if __name__ == '__main__':
    main()
