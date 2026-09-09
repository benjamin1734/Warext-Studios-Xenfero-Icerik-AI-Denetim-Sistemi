import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADDON_ROOT = ROOT / 'upload/src/addons/Warext/AIContentInspector'
DATA = ADDON_ROOT / '_data'


def read(path: Path) -> str:
    return path.read_text(encoding='utf-8')


def write(path: Path, content: str) -> None:
    path.write_text(content, encoding='utf-8')


# 1) Version identity.
addon_path = ADDON_ROOT / 'addon.json'
addon = json.loads(read(addon_path))
if addon.get('version_string') not in {'1.0.0', '1.0.1'}:
    raise SystemExit(f"Unexpected source version: {addon.get('version_string')}")
addon['version_id'] = 1000310
addon['version_string'] = '1.0.1'
addon['description'] = (
    'Seçilen XenForo forumlarındaki içerikleri yerel dil, yapı, editör davranışı, kullanıcı yazım profili '
    've içerik benzerliği sinyalleriyle analiz eder; kesin hüküm yerine risk ve güven skoru üretir. '
    'Warext Türkçe Yazım Denetimi varsa opsiyonel entegrasyon verisini kullanır. Harici API zorunlu değildir; '
    'OpenRouter ve desteklenen doğrudan sağlayıcılar yalnız opsiyonel ikinci görüş katmanı olarak çalışır. '
    'Günlük/aylık API istek ve bütçe limitleri, provider sağlık görünümü, gerçek/tahmini maliyet ayrımı, '
    'token takibi, kullanım geçmişi temizliği ve kontrollü geçmiş içerik taraması bulunur.'
)
write(addon_path, json.dumps(addon, ensure_ascii=False, indent=2) + '\n')

# 2) XenForo option master-data compatibility.
options_path = DATA / 'options.xml'
options = read(options_path)
old_fallback = 'option_id="warextAiOpenRouterFallbackModels" edit_format="textarea" data_type="string"'
new_fallback = 'option_id="warextAiOpenRouterFallbackModels" edit_format="textbox" data_type="string"'
if old_fallback in options:
    options = options.replace(old_fallback, new_fallback, 1)
options = options.replace(
    '<edit_format_params>\\XF\\Option\\Forum::renderSelectMultiple</edit_format_params>',
    '<edit_format_params>XF\\Option\\Forum::renderSelectMultiple</edit_format_params>'
)
if 'edit_format="textarea"' in options:
    raise SystemExit('Unsupported XenForo option edit_format=textarea remains in options.xml')
if '<edit_format_params>\\XF\\Option\\Forum::renderSelectMultiple</edit_format_params>' in options:
    raise SystemExit('Forum option callback still has non-canonical leading namespace slash')
if 'option_id="warextAiOpenRouterFallbackModels" edit_format="textbox"' not in options:
    raise SystemExit('Fallback models option was not migrated to textbox')
write(options_path, options)

# 3) Canonical XenForo AdminNavigation export format.
admin_navigation = '''<?xml version="1.0" encoding="utf-8"?>
<admin_navigation>
  <admin_navigation_entry navigation_id="warextAiAdmin" display_order="95" link="options/groups/warextAi/" icon="fa-robot" admin_permission_id="" debug_only="0" development_only="0" hide_no_children="0"/>
  <admin_navigation_entry navigation_id="warextAiSettings" parent_navigation_id="warextAiAdmin" display_order="10" link="options/groups/warextAi/" icon="" admin_permission_id="" debug_only="0" development_only="0" hide_no_children="0"/>
</admin_navigation>
'''
write(DATA / 'admin_navigation.xml', admin_navigation)

# 4) Public navigation condition must use XenForo template expression syntax.
navigation_path = DATA / 'navigation.xml'
navigation = read(navigation_path)
navigation = navigation.replace(
    "$xf.visitor->hasPermission('general', 'warextAiViewSimple')",
    "$xf.visitor.hasPermission('general', 'warextAiViewSimple')"
)
write(navigation_path, navigation)

# 5) Convert historical custom phrase keys to XenForo's canonical phrase names.
phrases_path = DATA / 'phrases.xml'
phrases = read(phrases_path)
phrases = phrases.replace('title="permission_interface_warextAi"', 'title="permission_interface.warextAi"')
for permission_id in ('warextAiViewSimple', 'warextAiViewDetailed', 'warextAiReview', 'warextAiManage'):
    phrases = phrases.replace(
        f'title="permission_general_{permission_id}"',
        f'title="permission.general_{permission_id}"'
    )
phrases = phrases.replace('title="option_group_warextAi_description"', 'title="option_group_description.warextAi"')
phrases = phrases.replace('title="option_group_warextAi"', 'title="option_group.warextAi"')
phrases = re.sub(r'title="option_([A-Za-z0-9]+)_explain"', r'title="option_explain.\1"', phrases)
phrases = re.sub(r'title="option_([A-Za-z0-9]+)"', r'title="option.\1"', phrases)

extra_phrases = []
required_extra = {
    'admin_navigation.warextAiAdmin': 'Warext AI İçerik Denetimi',
    'admin_navigation.warextAiSettings': 'Ayarlar',
    'nav.warextAi': 'AI Denetimi',
}
for title, text in required_extra.items():
    if f'title="{title}"' not in phrases:
        extra_phrases.append(
            f'  <phrase title="{title}" version_id="1000310" version_string="1.0.1"><![CDATA[{text}]]></phrase>'
        )
if extra_phrases:
    phrases = phrases.replace('</phrases>', '\n' + '\n'.join(extra_phrases) + '\n</phrases>')

for forbidden in (
    'title="permission_interface_warextAi"',
    'title="permission_general_warextAiViewSimple"',
    'title="option_group_warextAi"',
    'title="option_warextAiEnabled"',
):
    if forbidden in phrases:
        raise SystemExit(f'Legacy non-XenForo phrase key remains: {forbidden}')
write(phrases_path, phrases)

# 6) Failed install retry safety. autoIncrement() already creates the PK in XenForo's schema builder.
setup_path = ADDON_ROOT / 'Setup.php'
setup = read(setup_path)
needle = "        $this->schemaManager()->createTable('xf_warext_ai_analysis', function (Create $table)\n        {\n"
replacement = needle + "            $table->checkExists(true);\n"
if '$table->checkExists(true);' not in setup.split("createReviewLogTable", 1)[0]:
    if needle not in setup:
        raise SystemExit('Analysis table install block not found')
    setup = setup.replace(needle, replacement, 1)
write(setup_path, setup)

# 7) Browser asset cache-buster.
mods_path = DATA / 'template_modifications.xml'
mods = read(mods_path).replace('?wai=1000300', '?wai=1000310')
write(mods_path, mods)

# 8) README and changelog.
readme_path = ROOT / 'README.md'
readme = read(readme_path)
readme = readme.replace('**1.0.0 Stable**', '**1.0.1 Stable**', 1)
marker = '**1.0.1 Stable**'
install_note = (
    '\n\n1.0.1, XenForo kurulumunda master-data importunu durduran geçersiz `textarea` option formatını '
    'XenForo uyumlu `textbox + rows` yapısına çevirir. ACP navigation ve phrase exportları da XenForo’nun '
    'kanonik `_data` formatına geçirildi; başarısız 1.0.0 kurulumundan kalabilecek analiz tablosunda yeniden deneme güvenliği eklendi.'
)
if marker in readme and 'master-data importunu durduran' not in readme:
    readme = readme.replace(marker, marker + install_note, 1)
write(readme_path, readme)

changelog_path = ROOT / 'CHANGELOG.md'
changelog = read(changelog_path)
section = '''## v1.0.1 Stable\n\n- XenForo kurulumunu `Exception: Please enter a valid value.` hatasıyla durduran `edit_format="textarea"` kaldırıldı; OpenRouter fallback listesi `textbox` + `rows=4` olarak tanımlandı.\n- Forum seçici callback’i XenForo’nun kanonik `XF\\Option\\Forum::renderSelectMultiple` biçimine geçirildi.\n- ACP navigation master-data dosyası gerçek XenForo `admin_navigation_entry` export şemasına dönüştürüldü.\n- Public navigation yetki koşulundaki PHP `->` sözdizimi XenForo template dot sözdizimine çevrildi.\n- Permission, option, option-group, admin-navigation ve public-navigation phrase anahtarları XenForo’nun kanonik noktalı adlandırmasına geçirildi.\n- Başarısız 1.0.0 kurulumundan kalabilecek `xf_warext_ai_analysis` tablosu için kurulum yeniden-deneme güvenliği eklendi.\n- JS cache anahtarı `1000310` olarak yükseltildi.\n- CI artık desteklenmeyen option edit formatını, hatalı ACP navigation şemasını ve legacy phrase anahtarlarını release engelleyici hata olarak denetler.\n\n'''
if '## v1.0.1 Stable' not in changelog:
    changelog = changelog.replace('# Değişiklik Günlüğü\n\n', '# Değişiklik Günlüğü\n\n' + section, 1)
write(changelog_path, changelog)

# 9) Harden CI so the installer regression cannot silently return.
validate_path = ROOT / '.github/workflows/validate.yml'
validate = read(validate_path)
validate = validate.replace("addon.get('version_string') != '1.0.0' or int(addon.get('version_id', 0)) != 1000300", "addon.get('version_string') != '1.0.1' or int(addon.get('version_id', 0)) != 1000310")
validate = validate.replace("'version':'1.0.0',", "'version':'1.0.1',")
validate = validate.replace("'versionId':1000300,", "'versionId':1000310,")
validate = validate.replace("'?wai=1000300'", "'?wai=1000310'")

options_check_anchor = "          if not required_options.issubset(option_ids):\n              raise SystemExit('Stable ayarları eksik: ' + ', '.join(sorted(required_options - option_ids)))\n"
options_check = options_check_anchor + "\n          allowed_edit_formats = {'textbox','spinbox','onoff','onofftextbox','radio','select','checkbox','template','callback','username'}\n          invalid_option_formats = {o.attrib.get('option_id'): o.attrib.get('edit_format') for o in options.findall('option') if o.attrib.get('edit_format') not in allowed_edit_formats}\n          if invalid_option_formats:\n              raise SystemExit('XenForo tarafından desteklenmeyen option edit formatı: ' + repr(invalid_option_formats))\n          forum_option = next((o for o in options.findall('option') if o.attrib.get('option_id') == 'warextAiForums'), None)\n          if forum_option is None or (forum_option.findtext('edit_format_params') or '') != r'XF\\Option\\Forum::renderSelectMultiple':\n              raise SystemExit('Forum selector callback XenForo export formatında değil')\n"
if 'invalid_option_formats' not in validate:
    if options_check_anchor not in validate:
        raise SystemExit('CI options anchor not found')
    validate = validate.replace(options_check_anchor, options_check, 1)

admin_anchor = "          admin_nav = ET.parse(data / 'admin_navigation.xml').getroot()\n          nav_ids = {n.attrib.get('id') for n in admin_nav.findall('nav')}\n          if not {'warextAiAdmin', 'warextAiSettings'}.issubset(nav_ids):\n              raise SystemExit('Ayrı ACP Warext AI navigasyonu eksik')\n"
admin_check = "          admin_nav = ET.parse(data / 'admin_navigation.xml').getroot()\n          admin_entries = admin_nav.findall('admin_navigation_entry')\n          nav_ids = {n.attrib.get('navigation_id') for n in admin_entries}\n          if not {'warextAiAdmin', 'warextAiSettings'}.issubset(nav_ids):\n              raise SystemExit('Ayrı ACP Warext AI navigasyonu eksik veya export şeması hatalı')\n          if admin_nav.findall('nav'):\n              raise SystemExit('Legacy <nav> ACP navigation yapısı kullanılamaz')\n"
if "admin_entries = admin_nav.findall('admin_navigation_entry')" not in validate:
    if admin_anchor not in validate:
        raise SystemExit('CI admin navigation anchor not found')
    validate = validate.replace(admin_anchor, admin_check, 1)

phrase_anchor = "          if not required_phrases.issubset(phrase_titles):\n              raise SystemExit('Stable phrase kayıtları eksik')\n"
phrase_check = phrase_anchor + "\n          required_master_phrases = {\n              'permission_interface.warextAi',\n              'permission.general_warextAiViewSimple', 'permission.general_warextAiViewDetailed',\n              'permission.general_warextAiReview', 'permission.general_warextAiManage',\n              'option_group.warextAi', 'option_group_description.warextAi',\n              'option.warextAiEnabled', 'option_explain.warextAiEnabled',\n              'admin_navigation.warextAiAdmin', 'admin_navigation.warextAiSettings', 'nav.warextAi'\n          }\n          if not required_master_phrases.issubset(phrase_titles):\n              raise SystemExit('XenForo kanonik phrase kayıtları eksik: ' + ', '.join(sorted(required_master_phrases - phrase_titles)))\n          if any(t.startswith(('option_', 'option_group_', 'permission_interface_', 'permission_general_')) for t in phrase_titles):\n              raise SystemExit('Legacy underscore master phrase anahtarı kaldı')\n"
if 'required_master_phrases' not in validate:
    if phrase_anchor not in validate:
        raise SystemExit('CI phrase anchor not found')
    validate = validate.replace(phrase_anchor, phrase_check, 1)

nav_check_anchor = "          modifications = (data / 'template_modifications.xml').read_text(encoding='utf-8')\n"
nav_check = "          public_navigation = (data / 'navigation.xml').read_text(encoding='utf-8')\n          if \"$xf.visitor->hasPermission\" in public_navigation or \"$xf.visitor.hasPermission('general', 'warextAiViewSimple')\" not in public_navigation:\n              raise SystemExit('Public navigation XenForo template condition sözdizimi hatalı')\n\n" + nav_check_anchor
if 'public_navigation = (data /' not in validate:
    if nav_check_anchor not in validate:
        raise SystemExit('CI navigation anchor not found')
    validate = validate.replace(nav_check_anchor, nav_check, 1)

write(validate_path, validate)

print('v1.0.1 XenForo installer/master-data fix applied successfully')
