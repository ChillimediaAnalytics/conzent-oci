#!/usr/bin/env python3
"""Build reproducible GPL source ZIP. Does not contact WordPress or any server."""
from pathlib import Path
import hashlib,json,zipfile
root=Path(__file__).resolve().parent
source=root/'chilli-consent'
manifest={}
with zipfile.ZipFile(root/'chilli-consent.zip','w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as output:
    for path in sorted(source.rglob('*')):
        if not path.is_file(): continue
        name='chilli-consent/'+path.relative_to(source).as_posix()
        data=path.read_bytes()
        info=zipfile.ZipInfo(name,(2026,10,19,0,0,0));info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED
        output.writestr(info,data)
        manifest[name]=hashlib.sha256(data).hexdigest()
zip_hash=hashlib.sha256((root/'chilli-consent.zip').read_bytes()).hexdigest()
(root/'package-manifest.json').write_text(json.dumps({'version':'2.2.4.1','zip_sha256':zip_hash,'files':manifest},indent=2)+'\n')
print('chilli-consent.zip SHA-256:',zip_hash)
