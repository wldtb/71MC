"""Package complete source including the pinned MeloongCore submodule."""
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'dist'
OUT.mkdir(exist_ok=True)

def tracked(directory):
    values = subprocess.check_output(['git', '-C', str(directory), 'ls-files', '-z']).decode().split('\0')
    return [directory / value for value in values if value and (directory / value).is_file()]

files = tracked(ROOT) + tracked(ROOT / 'MeloongCore')
with zipfile.ZipFile(OUT / '71MC-source.zip', 'w', zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
    for file in sorted(set(files)):
        archive.write(file, Path('71MC') / file.relative_to(ROOT))
with zipfile.ZipFile(OUT / '71MC-php-server.zip', 'w', zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
    for file in sorted(files):
        if file.is_relative_to(ROOT / 'server'):
            archive.write(file, Path('71MC-php-server') / file.relative_to(ROOT / 'server'))
print('Source packages:', OUT / '71MC-source.zip', OUT / '71MC-php-server.zip')
