#!/usr/bin/env bash
# 全站链接回归一键脚本：扫描 → 并发请求 → 生成报告
#
# 用法:
#   bash docs/regress/regress.sh                # 默认 page+ajax+route
#   bash docs/regress/regress.sh --concurrency=8 --timeout=120
set -euo pipefail

cd "$(dirname "$0")/../.."   # 项目根目录

CONCURRENCY=6
TIMEOUT=60
KINDS=page,ajax,route
EXTRA=()

for arg in "$@"; do
    case "$arg" in
        --concurrency=*) CONCURRENCY="${arg#*=}" ;;
        --timeout=*)     TIMEOUT="${arg#*=}" ;;
        --kinds=*)       KINDS="${arg#*=}" ;;
        *)               EXTRA+=("$arg") ;;
    esac
done

echo "==> 1/3 扫描链接"
php docs/regress/discover.php

echo "==> 2/3 执行回归（并发 ${CONCURRENCY}，超时 ${TIMEOUT}s）"
php docs/regress/run.php --kinds="$KINDS" --concurrency="$CONCURRENCY" --timeout="$TIMEOUT" "${EXTRA[@]:-}"

echo "==> 3/3 生成报告"
php docs/regress/report.php

echo "完成。报告: docs/regress/reports/regression-report.md"
