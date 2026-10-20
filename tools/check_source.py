"""Validate XAML and product integration rules before the Windows build."""
from pathlib import Path
import re
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / 'Plain Craft Launcher 2'
project = ET.parse(APP / 'Plain Craft Launcher 2.vbproj')
for node in project.iter():
    if node.tag.rsplit('}', 1)[-1] in {'Page', 'ApplicationDefinition'}:
        ET.parse(APP / node.attrib['Include'].replace('\\', '/'))

def read(path):
    return (APP / path).read_text(encoding='utf-8-sig')

top = read('FormMain.xaml')
assert 'Title="71MC　"' in top
assert 'PreviewClick="LaunchMultiplayerTool"' in top
main = read('FormMain.xaml.vb')
assert 'C:\\Program Files\\mclan\\联机工具.exe' in main
assert '未找到联机工具' in main
assert 'PageLinkMain.Join' not in main
assert 'New PageLinkMain' not in main
for page in ('Pages/PageOther/PageOtherTest.xaml', 'Pages/PageOther/PageOtherTest.xaml.vb', 'Pages/PageOther/PageOtherLeft.xaml', 'Pages/PageOther/PageOtherLeft.xaml.vb'):
    value = read(page)
    for removed in ('回声洞', '今日人品', '千万别点', '新功能投票', 'ItemFeedback', 'ItemVote'):
        assert removed not in value, (page, removed)
assert 'ItemLink' not in read('Pages/PageSetup/PageSetupLeft.xaml')
secret = read('Modules/ModSecret.vb')
assert 'https://mcgame1.710080.xyz/' in secret
assert 'myqcloud.com' not in secret and 'cls.tencentcs.com' not in secret
assert 'ValidateUpdateHash(LocalPath, ExpectedSha256)' in secret
assert 'UpdateUri.AbsolutePath' in secret
assert 'assembly=Plain Craft Launcher 2' not in '\n'.join(p.read_text(encoding='utf-8-sig') for p in APP.rglob('*.xaml'))
for path in ROOT.rglob('*'):
    if not path.is_file() or any(part in {'.git', 'MeloongCore', 'bin', 'obj', 'storage'} for part in path.parts):
        continue
    if path.suffix in {'.vb', '.cs', '.php', '.py', '.yml', '.md', '.json'}:
        assert not re.search(rb'gh[pousr]_[A-Za-z0-9]{25,}', path.read_bytes()), f'GitHub credential in {path}'
print('Source integration checks passed; XAML XML is valid.')
