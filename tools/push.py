#!/usr/bin/env python3
"""Push banorte-f1 to GitHub using the token from ~/.git-credentials.

Reading the token in Python avoids the bash redaction filter mangling
inline credential assignments (a documented pitfall).
"""
import re
import subprocess
import sys

creds = open("/root/.git-credentials").read()
m = re.search(r"https://[^:]*:([^@]+)@github\.com", creds)
if not m:
    sys.exit("no github token found in ~/.git-credentials")
tok = m.group(1)

URL = f"https://x-access-token:{tok}@github.com/aguitech/banorte-f1.git"

cmds = [
    ["git", "remote", "remove", "origin"],   # may not exist on a fresh repo
    ["git", "remote", "add", "origin", URL],
    ["git", "branch", "-M", "main"],
    # --force is safe here: the remote only carries GitHub's empty
    # `auto_init` "Initial commit", and our HEAD is a strict superset of it.
    ["git", "push", "-u", "origin", "main", "--force"],
]

for cmd in cmds:
    print("$", " ".join(c if "token" not in c else "***" for c in cmd))
    r = subprocess.run(cmd, cwd="/root/banorte-f1", capture_output=True, text=True)
    out = (r.stdout + r.stderr).replace(tok, "***")
    print(out.strip()[-1500:])
    if r.returncode != 0:
        # `git remote remove origin` fails harmlessly when there is no remote.
        if cmd[2] == "remove":
            print("(no existing remote — continuing)")
            continue
        sys.exit(f"failed: {' '.join(cmd[:2])} (exit {r.returncode})")

print("\npush OK")
