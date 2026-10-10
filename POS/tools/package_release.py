"""Build an application archive using an explicit runtime allowlist."""
from pathlib import Path
import hashlib
import json
import zipfile

ROOT = Path(__file__).resolve().parents[1]
DIRECTORIES = {"api", "auth", "php", "includes", "js", "css", "assets"}
ROOT_FILES = {".htaccess", "index.html", "portal.html", "apply.php", "careers.php", "supplier_partnership.php"}
EXTENSIONS = {".php", ".js", ".css", ".html", ".png", ".jpg", ".jpeg", ".webp", ".svg", ".gif", ".ico", ".woff", ".woff2", ".ttf", ".otf"}


def allowed(relative: Path) -> bool:
    if any(part in {"node_modules", "vendor", "uploads", "scratch", "tests", "tools", "database", "cache", "dist"} for part in relative.parts):
        return False
    if relative.as_posix() == "auth/hash.php" or relative.name == "config.local.php" or relative.name.startswith(".env"):
        return False
    if len(relative.parts) == 1:
        return relative.name in ROOT_FILES
    return relative.parts[0] in DIRECTORIES and (relative.name == ".htaccess" or relative.suffix.lower() in EXTENSIONS)


def build() -> None:
    output = ROOT / "dist"
    output.mkdir(exist_ok=True)
    manifest = {}
    with zipfile.ZipFile(output / "application.zip", "w", zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(ROOT.rglob("*")):
            relative = path.relative_to(ROOT)
            if path.is_file() and not path.is_symlink() and allowed(relative):
                content = path.read_bytes()
                manifest[relative.as_posix()] = hashlib.sha256(content).hexdigest()
                archive.writestr(relative.as_posix(), content)
        archive.writestr("release-manifest.json", json.dumps(manifest, indent=2))
    (output / "release-manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    print(f"Packaged {len(manifest)} runtime files. Configuration and private uploads were excluded.")


if __name__ == "__main__":
    build()
