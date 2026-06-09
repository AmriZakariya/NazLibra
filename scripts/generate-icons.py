#!/usr/bin/env python3
"""Generate PWA icon sizes from a source image."""
import sys
from pathlib import Path

try:
    from PIL import Image
except ImportError:
    print("Pillow is not installed. Installing...")
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "Pillow", "-q"])
    from PIL import Image


def generate_icons(source_path: str) -> None:
    src = Path(source_path)
    if not src.exists():
        print(f"Source image not found: {source_path}")
        print("Please save your app icon image to that path first.")
        sys.exit(1)

    icons_dir = Path("public/icons")
    icons_dir.mkdir(parents=True, exist_ok=True)

    img = Image.open(src).convert("RGBA")

    # Generate 192x192
    size192 = img.resize((192, 192), Image.LANCZOS)
    size192.save(icons_dir / "icon-192x192.png", "PNG")
    print(f"Generated: {icons_dir / 'icon-192x192.png'}")

    # Generate 512x512
    size512 = img.resize((512, 512), Image.LANCZOS)
    size512.save(icons_dir / "icon-512x512.png", "PNG")
    print(f"Generated: {icons_dir / 'icon-512x512.png'}")

    print("\nIcons generated successfully!")
    print("Run 'php artisan cache:clear' if needed.")


if __name__ == "__main__":
    source = sys.argv[1] if len(sys.argv) > 1 else "public/icons/app-icon-source.png"
    generate_icons(source)
