# How com_media really calls the adapter

Facts about Joomla's com_media that the adapter depends on, none of them documented in `AdapterInterface`:

- **`getFile()` runs before most operations.** `ApiModel::delete()` and the move/copy flow call the adapter's
  `getFile()` first, and `ApiController` calls `getFile()` on whatever `move()`/`copy()` return. A 404 from
  `getFile()` blocks the operation before the adapter's own method runs; a wrong return path turns a successful
  move into a 404.
- **Folders created outside Joomla have no `folder/` placeholder.** `statPath()` recognises them by listing one key
  under the prefix. Keep every folder operation working without the placeholder.
- **Exception code = HTTP status.** `ApiController` maps `FileNotFoundException` → 404, `FileExistsException` → 409,
  `InvalidPathException` → 400, any other exception → its code if > 0, else 500. The message is shown verbatim to
  every com_media user (hence `safeException()`).
- **The search term is filtered with `getCmd()`** (`A–Z a–z 0–9 . _ -` only). `*`, `?`, `[ ]`, `/`, `\` never reach
  `search()` over HTTP, so test those cases as unit tests, not E2E.
- **GET on a path that does not exist returns 200 with an empty listing** (`getFiles()` treats it as an empty folder),
  not 404. Use DELETE of a missing file to exercise a 404.
- **Upload policy, name safety and path checks are the adapter's job**, not com_media's; see
  [core-adapter-parity.md](core-adapter-parity.md).

**Why:** each of these caused a wrong fix or a wrong test during the 2026-09 audit and known-issue fixes (#3, #6, #10,
M1, L1).

**How to apply:** before changing an adapter method or writing an E2E test for it, trace the com_media call path
(`administrator/components/com_media/src/Controller/ApiController.php` and `Model/ApiModel.php` in a Joomla site).
