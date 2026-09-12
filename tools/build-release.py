#!/usr/bin/env python3
"""Package a clean release tree (no Git history) and the isolated runtime.

Usage: python3 tools/build-release.py /absolute/output/modharbor-1.0.0-rc.3.tar.gz
No network access, Git mutation, or panel/server operation is performed.

Git history is intentionally excluded so removed assets (e.g. former bundled
Minecraft artwork) cannot reappear from historical blobs.
"""
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile

root = Path(__file__).resolve().parents[1]
if len(sys.argv) != 2:
    raise SystemExit(__doc__)
output = Path(sys.argv[1]).resolve()
if output == root or root in output.parents:
    raise SystemExit("Write the release outside the repository.")
if output.exists():
    raise SystemExit("Output already exists; choose a new filename.")


def git(*args):
    return subprocess.check_output(["git", "-C", str(root), *args])


if (root / ".git").exists():
    if git("status", "--porcelain").strip():
        raise SystemExit("Commit/checkpoint all work before packaging.")
    revision = git("rev-parse", "HEAD").decode().strip()
    tags = git("tag", "--points-at", "HEAD").decode().splitlines()
else:
    revision = "unknown"
    tags = []

if not (root / "runtime/vendor/autoload.php").is_file():
    raise SystemExit("Install the isolated locked runtime before packaging.")

files = {}
for path in sorted(root.rglob("*")):
    if not path.is_file() or path.is_symlink():
        continue
    relative = path.relative_to(root).as_posix()
    if relative == ".git" or relative.startswith(".git/"):
        continue
    if relative in {".gitignore", ".gitattributes"}:
        continue
    if relative.endswith("~") or relative.endswith(".bak"):
        continue
    if "/__pycache__/" in f"/{relative}/" or relative.endswith(".pyc"):
        continue
    mode = 0o755 if path.stat().st_mode & 0o111 else 0o644
    files[relative] = (path.read_bytes(), mode)

files["build-info.json"] = (
    (json.dumps({"commit": revision, "tags": tags}, indent=2) + "\n").encode(),
    0o644,
)

output.parent.mkdir(parents=True, exist_ok=True)
manifest = {"commit": revision, "tags": tags, "files": {}}
with tarfile.open(output, "w:gz", format=tarfile.PAX_FORMAT) as archive:
    for name, (body, mode) in sorted(files.items()):
        entry = tarfile.TarInfo("gamenest-mod-manager/" + name)
        entry.size = len(body)
        entry.mode = mode
        entry.mtime = 0
        archive.addfile(entry, io.BytesIO(body))
        manifest["files"][name] = hashlib.sha256(body).hexdigest()

digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_name(output.name + ".sha256").write_text(
    digest + "  " + output.name + "\n", encoding="utf-8"
)
output.with_name(output.name + ".manifest.json").write_text(
    json.dumps(manifest, indent=2) + "\n", encoding="utf-8"
)
print(f"Packaged {len(files)} files at {revision} (no Git history): {output}")
print("SHA256: " + digest)
