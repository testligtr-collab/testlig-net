#!/usr/bin/env python3
"""Read-only scope and PR-reference checks. Does not assert product correctness."""
import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

STATUSES = {"present", "partial", "planned", "review", "blocked", "decision_needed", "retired"}
ID = re.compile(r"(?:M\d{2}|X\d{2})(?:-[a-z0-9]+)+")
REQUIRED_MODULES = {f"M{i:02d}" for i in range(1, 32)}

def validate(registry, baseline, previous_registry=None, previous_baseline=None, pr_body=None):
    errors = []
    features = registry.get("features", [])
    if not isinstance(features, list) or not features:
        return ["features must be a non-empty list"]
    seen = {}
    for feature in features:
        if not isinstance(feature, dict):
            errors.append("feature must be an object")
            continue
        key = feature.get("id", "")
        if not isinstance(key, str) or not ID.fullmatch(key):
            errors.append("invalid feature id")
            continue
        if key in seen:
            errors.append(f"duplicate feature: {key}")
        seen[key] = feature
        for field in ("module", "title", "source", "acceptance"):
            if not isinstance(feature.get(field), str) or not feature[field].strip():
                errors.append(f"{key}: missing {field}")
        if feature.get("module") != key.split("-")[0]:
            errors.append(f"{key}: module mismatch")
        if feature.get("status") not in STATUSES:
            errors.append(f"{key}: invalid status")
        if not isinstance(feature.get("evidence"), list) or not feature["evidence"]:
            errors.append(f"{key}: evidence required")
        elif any(not isinstance(value, str) or not value.strip() for value in feature["evidence"]):
            errors.append(f"{key}: empty evidence")
        if not isinstance(feature.get("pr_links"), list):
            errors.append(f"{key}: pr_links must be a list")
        if not isinstance(feature.get("depends_on"), list):
            errors.append(f"{key}: depends_on must be a list")
        if feature.get("status") == "retired":
            decision = feature.get("scope_decision")
            if not isinstance(decision, dict) or any(
                not isinstance(decision.get(field), str) or not decision[field].strip()
                for field in ("date", "source", "user_decision")
            ):
                errors.append(f"{key}: explicit scope decision required")
    modules = {item.get("module") for item in seen.values()}
    required_modules = baseline.get("required_module_ids", [])
    required_ids = baseline.get("required_feature_ids", [])
    if not isinstance(required_modules, list) or not isinstance(required_ids, list):
        errors.append("baseline lists are required")
        return errors
    if any(not isinstance(value, str) for value in required_modules + required_ids):
        errors.append("baseline values must be strings")
        return errors
    if len(set(required_ids)) != len(required_ids):
        errors.append("duplicate baseline feature")
    for key in REQUIRED_MODULES | set(required_modules):
        if key not in modules:
            errors.append(f"missing module: {key}")
    for key in required_ids:
        if key not in seen:
            errors.append(f"missing baseline feature: {key}")
    if previous_registry:
        for item in previous_registry.get("features", []):
            if item["id"] not in seen:
                errors.append(f"removed existing feature: {item['id']}")
    if previous_baseline:
        for field in ("required_module_ids", "required_feature_ids"):
            for key in previous_baseline.get(field, []):
                if key not in baseline.get(field, []):
                    errors.append(f"removed baseline entry: {key}")
    for key, item in seen.items():
        for dependency in item.get("depends_on", []) if isinstance(item.get("depends_on"), list) else []:
            if not isinstance(dependency, str) or dependency not in seen or dependency == key:
                errors.append(f"{key}: invalid dependency")
    visiting, visited = set(), set()
    def walk(key):
        if key in visiting:
            errors.append(f"dependency cycle: {key}")
            return
        if key in visited:
            return
        visiting.add(key)
        dependencies = seen[key].get("depends_on", [])
        for dependency in dependencies if isinstance(dependencies, list) else []:
            if isinstance(dependency, str) and dependency in seen and dependency != key:
                walk(dependency)
        visiting.remove(key)
        visited.add(key)
    for key in seen:
        walk(key)
    active = registry.get("active_feature_ids", [])
    if not isinstance(active, list) or any(not isinstance(key, str) or key not in seen for key in active):
        errors.append("invalid active feature ids")
    if pr_body is not None:
        match = re.search(r"^Roadmap-IDs:\s*(.+)$", pr_body, re.MULTILINE)
        if not match:
            errors.append("PR body requires Roadmap-IDs: exact feature IDs")
        else:
            keys = [value.strip() for value in match.group(1).split(",")]
            if any(not ID.fullmatch(key) or key not in seen for key in keys):
                errors.append("PR references unknown or invalid roadmap feature")
    return errors

def base_json(root, sha, path):
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("invalid base SHA")
    probe = subprocess.run(["git", "-C", str(root), "cat-file", "-e", f"{sha}:{path}"], capture_output=True)
    if probe.returncode:
        return None
    result = subprocess.run(["git", "-C", str(root), "show", f"{sha}:{path}"],
                            capture_output=True, text=True, check=True)
    return json.loads(result.stdout)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--event", type=Path)
    args = parser.parse_args()
    root = args.root
    registry = json.loads((root / "docs/roadmap/features.json").read_text())
    baseline = json.loads((root / "docs/roadmap/baseline.json").read_text())
    previous_registry = previous_baseline = None
    pr_body = None
    if args.event:
        event = json.loads(args.event.read_text())
        if "pull_request" in event:
            pr_body = event["pull_request"].get("body") or ""
            sha = event["pull_request"]["base"]["sha"]
        else:
            sha = event.get("before")
        if sha and sha != "0" * 40:
            previous_registry = base_json(root, sha, "docs/roadmap/features.json")
            previous_baseline = base_json(root, sha, "docs/roadmap/baseline.json")
    errors = validate(registry, baseline, previous_registry, previous_baseline, pr_body)
    if errors:
        for error in errors:
            print(error, file=sys.stderr)
        return 1
    print(f"Roadmap coverage: {len(registry['features'])} feature records; scope and references valid.")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
