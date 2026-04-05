# Unzip (Nextcloud app)

Adds an **Extract here** action to the Files app for archive files.

Repository: https://github.com/glimz/nextcloud-unzip

## Behavior

- Extracts the archive **into the current folder** (next to the archive file).
- If extracted files already exist, names are automatically suffixed with ` (1)`, ` (2)`, ….

## Supported formats

- Zip via PHP `zip` extension when available.
- RAR via PHP `rar` extension when available, otherwise `unrar`.
- Everything else via `7z` / `7za` (e.g. `7z`, `tar`, `gz`, `bz2`, etc.).

## Requirements

- PHP: `zip` extension recommended.
- System binaries (recommended):
  - `7z` or `7za`
  - `unrar` (for RAR if PHP `rar` extension is not installed)

## Security

The app performs basic archive preflight checks to block unsafe paths (e.g. `../` or absolute paths) and removes extracted symlinks.
