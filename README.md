# icecaster/silverstripe-cms-mcp

Exposes SilverStripe CMS content to AI agents over the [Model Context Protocol](https://modelcontextprotocol.io)
(MCP): reading and editing fields, uploading files, and driving the draft/publish
versioning workflow — all permission-checked exactly as if the agent were a logged-in
CMS user.

## How it works

- A single HTTP endpoint, `POST /mcp`, implements the MCP "Streamable HTTP" transport
  (JSON-RPC 2.0 request/response, no SSE streaming — every tool call is a fast,
  synchronous operation).
- Agents authenticate with a **personal access token** (a Bearer token) issued to a
  specific SilverStripe `Member` from the CMS. Every tool call runs with that member's
  normal permissions — `canView`/`canEdit`/`canPublish`/... — on the specific record
  being touched, plus a blanket `CMS_MCP_ACCESS` permission gate.
- Only an explicit allow-list of `DataObject` classes is exposed (default: `SiteTree`
  and `File` and their subclasses). Sensitive classes (`Member`, `Group`, `Permission`,
  tokens, login attempts, ...) are always denied, even if a project widens the
  allow-list. See `_config/mcp.yml`.
- All reads/writes happen against the **draft** stage. `publish_record` /
  `unpublish_record` / `rollback_record` explicitly move content to/from Live.

## Installation

```
composer require icecaster/silverstripe-cms-mcp
vendor/bin/sake dev/build flush=1
```

## Issuing a token

1. Log into the CMS as an admin and go to **MCP Tokens** in the left menu.
2. Click **Add MCP Access Token**, enter a label and the email of the member the
   token should act as (that member needs the **"Access the CMS via an MCP client
   (AI agent)"** permission, under Security → Roles/Permissions → "CMS MCP").
3. Save. The raw token is shown once, directly under the form — copy it now, it isn't
   stored anywhere and can't be shown again. If lost, revoke it and issue a new one.

## Connecting an MCP client

Point your MCP client at:

```
https://your-site.example/mcp
Authorization: Bearer mcp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

## Exposing your own classes

Either add the class (or an ancestor) to `Icecaster\CmsMcp\ClassExposure.allowed_classes`
in YAML config, or apply the marker extension directly:

```yml
App\Model\Product:
  extensions:
    - Icecaster\CmsMcp\Extensions\MCPExposableExtension
```

Fields are auto-discovered from `$db` (Varchar/Text/HTMLText/Int/Float/Boolean/Date/
Datetime are editable via `update_record`; `has_one` relations to `File`/`Image` are
settable via `upload_file`). Hide individual fields per-class with
`Icecaster\CmsMcp\ClassExposure.denied_fields`.

## Tools

| Tool | Purpose |
|---|---|
| `list_classes` | Discover exposed classes |
| `list_fields` | Discover a class's editable + file fields |
| `search_records` | Find records by a substring match on a field |
| `get_record` | Read a record's draft field values |
| `update_record` | Write text/html/number/boolean/date fields (draft) |
| `upload_file` | Attach a base64-encoded file to a File/Image field (draft) |
| `get_record_status` | Draft vs. live status of a record |
| `get_version_history` | Recent versions: who, when, published? |
| `publish_record` | Publish draft (and owned relations) to live |
| `unpublish_record` | Remove from live, keep draft |
| `rollback_record` | Roll draft back to a version, or to "live" |

## Local development against a project

In the consuming project's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "/path/to/silverstripe-cms-mcp", "options": { "symlink": true } }
]
```

then `composer require icecaster/silverstripe-cms-mcp:dev-main`.
