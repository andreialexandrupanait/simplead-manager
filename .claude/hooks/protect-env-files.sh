#!/bin/bash
# PreToolUse hook: block modifications to .env and sensitive files

python3 -c "
import sys, json, re

try:
    d = json.load(sys.stdin)
except:
    sys.exit(0)

tool = d.get('tool_name', '')
inp = d.get('tool_input', {})
file_path = inp.get('file_path', '')
command = inp.get('command', '')

# Block Write/Edit on .env files
if tool in ('Write', 'Edit') and '.env' in file_path:
    print('Blocked: cannot modify .env files through Claude Code', file=sys.stderr)
    sys.exit(2)

# Block Bash commands that write to .env files
if tool == 'Bash' and re.search(r'(>|>>)\s*\.env|tee\s+\.env|cp\s+.*\.env', command):
    print('Blocked: cannot write to .env files through Claude Code', file=sys.stderr)
    sys.exit(2)

sys.exit(0)
"
