"""Build a private, content-addressed archive manifest. Requires PyYAML."""
import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tempfile
from collections import Counter, defaultdict
from pathlib import Path, PurePosixPath

import rarfile
import yaml

MAX_FILE = 256 * 1024 * 1024
MAX_TEXT = 8 * 1024 * 1024
FORMATS = {".pdf", ".doc", ".docx", ".xls", ".xlsx", ".jpg", ".jpeg", ".png", ".bmp"}


def relative(value):
    if not isinstance(value, str) or "\\" in value or ":" in value:
        raise ValueError("invalid relative path")
    path = PurePosixPath(value)
    if path.is_absolute() or not path.parts or ".." in path.parts:
        raise ValueError("invalid relative path")
    return path


def inside(root, name):
    path = root.joinpath(*relative(name).parts).resolve(strict=True)
    if not path.is_relative_to(root) or not path.is_file():
        raise ValueError("source outside archive")
    return path


def digest(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def store(path, folder, sha):
    if folder is None:
        return
    dest = folder / sha
    if dest.exists():
        if dest.is_symlink() or digest(dest) != sha:
            raise ValueError("staged object does not match its hash")
        return
    with tempfile.NamedTemporaryFile(dir=folder, delete=False) as temp:
        name = Path(temp.name)
        with path.open("rb") as source:
            shutil.copyfileobj(source, temp)
    try:
        if digest(name) != sha:
            raise ValueError("source changed while copying")
        name.replace(dest)
    finally:
        name.unlink(missing_ok=True)


def read_meta(path):
    text = path.read_text(encoding="utf-8-sig")
    match = re.match(r"\A---\n(.*?)\n---\n", text, re.S)
    if not match:
        raise ValueError("missing document metadata")
    data = yaml.safe_load(match[1])
    if not isinstance(data, dict) or not re.fullmatch(r"DOC-(?:ARC-)?[A-F0-9]{12}", data.get("doc_id", "")):
        raise ValueError("invalid document ID")
    return data


def rar_inventory(archive, unrar, work, known_hashes):
    # Decode names with rarfile, then stream each checked member to a path we choose.
    rarfile.UNRAR_TOOL = unrar
    members = {}
    with rarfile.RarFile(archive) as container:
        entries = container.infolist()
        names = [entry.filename for entry in entries]
        if len(entries) > 256 or len(names) != len(set(names)):
            raise ValueError("archive inventory exceeds bounds or contains duplicate paths")
        if sum(entry.file_size for entry in entries) > 512 * 1024 * 1024:
            raise ValueError("archive extraction exceeds limits")
        for entry in entries:
            name = entry.filename
            relative(name)
            if name.startswith("-") or any(ord(char) < 32 for char in name):
                raise ValueError("unsafe archive member name")
            if entry.isdir():
                continue
            if not entry.is_file() or entry.file_size > MAX_FILE:
                raise ValueError("unsupported archive member")
        folder = Path(tempfile.mkdtemp(dir=work))
        for index, entry in enumerate(entries):
            if entry.isdir():
                continue
            destination = folder / str(index)
            with container.open(entry) as stream, destination.open("wb") as output:
                while chunk := stream.read(1024 * 1024):
                    if output.tell() + len(chunk) > entry.file_size:
                        raise ValueError("archive member larger than declared")
                    output.write(chunk)
            if destination.stat().st_size != entry.file_size:
                raise ValueError("incomplete archive member")
            sha = digest(destination)
            members[entry.filename] = (destination, sha, known_hashes.get(sha, []))
    return members


def document_status(name, role):
    suffix = PurePosixPath(name).suffix.lower()
    if suffix == ".db" or PurePosixPath(name).name.startswith("~$") or "سیستمی" in role:
        return "quarantine"
    if suffix == ".rar":
        return "container"
    if suffix not in FORMATS or "الگو" in role or "آرشیو اسناد" in role:
        return "reference"
    return "ready"


def build(source, staging, tar):
    digital = (source / "دیجیتال‌شده").resolve(strict=True)
    if not digital.is_relative_to(source):
        raise ValueError("digital folder outside archive")
    md_files = sorted(digital.rglob("*.md"))
    items, pending, by_hash = [], [], defaultdict(list)
    seen = set()
    with tempfile.TemporaryDirectory() as tmp:
        work = Path(tmp)
        for md in md_files:
            if not md.resolve().is_relative_to(digital) or md.stat().st_size > MAX_TEXT:
                raise ValueError(f"invalid digital record: {md.name}")
            data = read_meta(md)
            doc_id = data["doc_id"]
            if doc_id in seen:
                raise ValueError(f"duplicate document ID: {doc_id}")
            seen.add(doc_id)
            pending.append((md, data))
            if data.get("source_relative_path") and data.get("source_sha256"):
                by_hash[data["source_sha256"]].append(doc_id)
        archive_members = {}
        for md, data in pending:
            name = data.get("source_relative_path")
            archive = data.get("source_archive")
            member = data.get("archive_member_path")
            role = str(data.get("document_role", ""))
            bundle_title = PurePosixPath(archive or name or "").parent.name
            if not bundle_title:
                bundle_title = PurePosixPath(archive or name or md.name).stem
            row = {
                "doc_id": data["doc_id"], "bundle_id": data.get("bundle_id"),
                "bundle_title": bundle_title, "relative_path": name or f"{archive}!/{member}",
                "filename": PurePosixPath(member or name or md.name).name, "metadata": data,
                "sha256": data.get("source_sha256")
            }
            try:
                if archive:
                    relative(member)
                    if archive not in archive_members:
                        original = inside(source, archive)
                        if digest(original) != data.get("source_archive_sha256"):
                            raise ValueError("archive hash mismatch")
                        archive_members[archive] = rar_inventory(original, tar, work, by_hash)
                    path, _, _ = archive_members[archive][member]
                else:
                    path = inside(source, name)
                if path.stat().st_size > MAX_FILE:
                    raise ValueError("file exceeds preparation size limit")
                if digest(path) != row["sha256"] or path.stat().st_size != data.get("source_size_bytes"):
                    raise ValueError("source size or hash mismatch")
                row["status"] = document_status(member or name, role)
                if row["status"] == "ready" and not re.fullmatch(r"BND-[A-F0-9]{12}", row["bundle_id"] or ""):
                    row["status"] = "ungrouped"
                row["text_hash"] = digest(md)
                store(path, staging, row["sha256"])
                store(md, staging, row["text_hash"])
            except (OSError, ValueError, KeyError, subprocess.SubprocessError, rarfile.Error) as error:
                row["status"] = "blocked"
                row["error"] = f"{type(error).__name__}: {error}" if isinstance(error, ValueError) else type(error).__name__
            row["same_hash_doc_ids"] = sorted(key for key in by_hash[row["sha256"]] if key != row["doc_id"])
            items.append(row)
        for row in items:
            if row["relative_path"] in archive_members:
                row["archive_members"] = [
                    {"path": name, "sha256": sha, "same_hash_doc_ids": sorted(ids),
                     "doc_ids": [candidate["doc_id"] for candidate in items if
                                 candidate["metadata"].get("source_archive") == row["relative_path"] and
                                 candidate["metadata"].get("archive_member_path") == name]}
                    for name, (_, sha, ids) in archive_members[row["relative_path"]].items()
                ]
        actual = {p.relative_to(source).as_posix() for p in source.rglob("*") if p.is_file()
                  and not p.is_relative_to(digital) and p.name != "نمایه_مدیریت_قراردادها.md"}
        indexed = {item["relative_path"] for item in items if not item["metadata"].get("source_archive")}
        coverage = {"original_files": len(actual), "digital_records": len(items),
                    "unindexed": sorted(actual - indexed), "missing": sorted(indexed - actual)}
        for name in coverage["unindexed"]:
            items.append({"doc_id":"DOC-EXTRA-"+hashlib.sha256(name.encode()).hexdigest()[:20].upper(),
                          "relative_path":name,"filename":PurePosixPath(name).name,
                          "status":"blocked","error":"source has no digital record","metadata":{}})
        return {"schema_version": 1, "coverage": coverage, "items": items}


def main():
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("archive", type=Path)
    cli.add_argument("--storage-root", type=Path, help="private server-compatible staging destination")
    cli.add_argument("--unrar", default="unrar", help="path to the UnRAR executable")
    args = cli.parse_args()
    source = args.archive.resolve(strict=True)
    staging = None
    if args.storage_root:
        storage = args.storage_root.resolve(strict=True)
        if storage.is_relative_to(source) or source.is_relative_to(storage) or storage.is_relative_to(Path(__file__).resolve().parents[1]):
            cli.error("use a private destination separate from the archive and repository")
        staging = storage / "staging"
        staging.mkdir(exist_ok=True)
        if staging.is_symlink():
            cli.error("staging may not be a symlink")
    manifest = build(source, staging, args.unrar)
    raw = json.dumps(manifest, ensure_ascii=False, sort_keys=True, separators=(",", ":"), default=str).encode()
    sha = hashlib.sha256(raw).hexdigest()
    if staging:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "manifest"
            path.write_bytes(raw)
            store(path, staging, sha)
    counts = Counter(row["status"] for row in manifest["items"])
    print(json.dumps({"manifest_hash": sha, "items": len(manifest["items"]), "counts": counts,
                      "blocked": [{"doc_id":row["doc_id"],"reason":row["error"]} for row in manifest["items"] if row["status"]=="blocked"],
                      "coverage": manifest["coverage"],"staged": staging is not None}, ensure_ascii=False))
    return 2 if counts["blocked"] else 0


if __name__ == "__main__":
    sys.exit(main())
