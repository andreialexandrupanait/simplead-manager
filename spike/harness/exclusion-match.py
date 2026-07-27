import sys, fnmatch, os
RULES = ["wp-content/cache/**","**/wflogs/**","wp-content/ai1wm-backups/**","wp-content/updraft/**",
         "**/node_modules/**","**/.git/**","*.log","*.tmp","*.bak"]
# listing paths are relative to wp-content; prepend for rules anchored at wp-content/
def excluded(rel):
    full="wp-content/"+rel; base=os.path.basename(rel)
    for r in RULES:
        if r.startswith("**/") and r.endswith("/**"):
            seg=r[3:-3]
            if ("/"+seg+"/") in ("/"+full+"/"): return r
        elif r.endswith("/**"):
            if full.startswith(r[:-2]): return r
        elif r.startswith("*.") and fnmatch.fnmatch(base,r): return r
        elif fnmatch.fnmatch(full,r): return r
    return None
lines=[l.strip() for l in open(sys.argv[1]) if l.strip()]
inc=exc=0; excset=[]
for rel in lines:
    if excluded(rel): exc+=1; excset.append(rel)
    else: inc+=1
open(sys.argv[2],"w").write("\n".join(sorted(excset)))
print(f"included={inc} excluded={exc}")
