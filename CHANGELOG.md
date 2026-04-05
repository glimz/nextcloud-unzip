# Changelog

## 1.0.0

- Initial release.
- Files v4 action integration (“Extract here”).
- CSRF-safe extraction request.
- Server-side archive preflight safety checks.

## 1.0.1

- Support extracting archives from non-local storages (e.g. external mounts) by extracting to a temp dir then importing into the target folder.
- Show server JSON error messages in the UI on HTTP 400 responses.

## 1.0.2

- Extract directly into the current folder (instead of creating a subfolder).
- Return extracted counts (files/folders) to the UI.

## 1.0.3

- Refresh Files view after extracting (fallback to full page reload on Files v4).

## 1.0.4

- Skip macOS metadata (`__MACOSX`, `._*`, `.DS_Store`) during import.
- Include skipped count in the UI.
- Improve server response for “nothing extracted” cases.

## 1.0.5

- Extract into a new folder named after the archive (with “(1)” suffix on conflicts), matching the behavior of the classic `extract` app.
