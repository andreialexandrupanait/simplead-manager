# Recommended MCP Servers

MCP (Model Context Protocol) servers extend Claude Code with external tools. Configure in `~/.claude.json` under `projects."/var/www/simplead-manager".mcpServers`.

## Recommended

### Context7 — Up-to-date library docs
Provides current documentation for Laravel, Livewire, and other libraries directly in context.

```bash
# Install via Claude Code
claude mcp add context7 -- npx -y @upstash/context7-mcp@latest
```

### PostgreSQL MCP — Direct DB inspection
Query your PostgreSQL database directly from Claude Code for debugging and data exploration.

```bash
# Install
claude mcp add postgres -- npx -y @anthropic/mcp-postgres "postgresql://user:pass@localhost:5432/simplead_manager"
```

### Filesystem MCP — Scoped file access
Provides read-only access to specific directories, useful for accessing files outside the project root.

```bash
claude mcp add filesystem -- npx -y @anthropic/mcp-filesystem /var/www/simplead-manager
```

## Setup

After adding an MCP server, restart Claude Code for it to take effect. Verify with:
```bash
claude mcp list
```
