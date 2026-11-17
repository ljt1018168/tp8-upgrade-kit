#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
vendor 依赖完整性核查工具
用途：把 vendor/ 下每个第三方包与官方发布包（按 composer.lock 的 dist reference 锁定）
      逐文件 MD5 比对，证明第三方源码未被手工修改（未 hack vendor）。

用法：
    python3 verify_vendor_integrity.py [项目根目录]
    默认项目根 = 脚本所在目录的上一级

退出码：0 = 全部一致；1 = 存在差异或校验失败
"""
import json
import os
import sys
import subprocess
import hashlib
import zipfile
import tempfile
import shutil

PROJ = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else
                       os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
WORK = tempfile.mkdtemp(prefix="vendorchk_")

# 源站按优先级尝试：国内镜像 → GitHub codeload 直连
SOURCES = [
    "https://mirrors.aliyun.com/composer/dists/{name}/{ref}.zip",
    "https://codeload.github.com/{repo}/zip/{ref}",
]


def md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def collect(root):
    return {os.path.relpath(os.path.join(dp, f), root): os.path.join(dp, f)
            for dp, _, files in os.walk(root) for f in files}


def download(name, ref, dist_url, dest):
    repo = ""
    if "github.com" in dist_url:
        # https://api.github.com/repos/<owner>/<repo>/zipball/<ref>
        parts = dist_url.split("github.com/")[-1].split("/")
        if len(parts) >= 4 and parts[0] == "repos":
            repo = f"{parts[1]}/{parts[2]}"
    for tpl in SOURCES:
        if "{repo}" in tpl and not repo:
            continue
        url = tpl.format(name=name, ref=ref, repo=repo)
        r = subprocess.run(["curl", "-sSL", "--max-time", "180", "-o", dest, url],
                           capture_output=True)
        if r.returncode == 0 and zipfile.is_zipfile(dest):
            return True
    return False


def main():
    lock_path = os.path.join(PROJ, "composer.lock")
    if not os.path.exists(lock_path):
        print(f"找不到 {lock_path}")
        return 1
    lock = json.load(open(lock_path))
    pkgs = [p for p in lock["packages"] if p["name"] != "composer/composer"]
    print(f"项目: {PROJ}")
    print(f"待校验包: {len(pkgs)}\n")

    ok = bad = 0
    for p in pkgs:
        name, ver, ref = p["name"], p["version"], p["dist"]["reference"]
        zp = os.path.join(WORK, name.replace("/", "_") + ".zip")
        if not download(name, ref, p["dist"].get("url", ""), zp):
            print(f"[FAIL] {name:40s} {ver:12s} 下载失败（网络/镜像问题，非代码差异）")
            bad += 1
            continue
        exdir = os.path.join(WORK, name.replace("/", "_"))
        with zipfile.ZipFile(zp) as z:
            z.extractall(exdir)
        tops = [d for d in os.listdir(exdir) if os.path.isdir(os.path.join(exdir, d))]
        if len(tops) != 1:
            print(f"[ODD ] {name} 压缩包顶层结构异常: {tops}")
            bad += 1
            continue
        src = collect(os.path.join(exdir, tops[0]))
        dst_root = os.path.join(PROJ, "vendor", name)
        dst = collect(dst_root) if os.path.isdir(dst_root) else {}
        diff = [r for r in (set(src) & set(dst)) if md5(src[r]) != md5(dst[r])]
        added = sorted(set(dst) - set(src))
        missing = sorted(set(src) - set(dst))
        if diff or added or missing:
            bad += 1
            print(f"[DIFF] {name:40s} {ver:12s} 文件{len(src):4d} 改={len(diff)} 多={len(added)} 少={len(missing)}")
            for r in diff[:10]:
                print(f"       MOD {r}")
            for r in added[:10]:
                print(f"       ADD {r}")
            for r in missing[:10]:
                print(f"       DEL {r}")
        else:
            ok += 1
            print(f"[ OK ] {name:40s} {ver:12s} 文件{len(src):4d} 完全一致")

    print(f"\n=== 汇总：一致 {ok} / 异常 {bad} / 共 {len(pkgs)} ===")
    return 0 if bad == 0 else 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    finally:
        shutil.rmtree(WORK, ignore_errors=True)
