#!/usr/bin/env python3
import copy
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("guard", ROOT / ".github/scripts/check-roadmap.py")
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

class ScopeGuardTest(unittest.TestCase):
    def setUp(self):
        self.registry = json.loads((ROOT / "docs/roadmap/features.json").read_text())
        self.baseline = json.loads((ROOT / "docs/roadmap/baseline.json").read_text())
    def test_current_registry_valid(self):
        self.assertEqual([], guard.validate(self.registry, self.baseline))
    def test_removing_record_fails(self):
        self.registry["features"].pop()
        self.assertTrue(guard.validate(self.registry, self.baseline))
    def test_removing_record_and_baseline_fails_against_previous(self):
        old_registry, old_baseline = copy.deepcopy(self.registry), copy.deepcopy(self.baseline)
        removed = self.registry["features"].pop()["id"]
        self.baseline["required_feature_ids"].remove(removed)
        self.assertTrue(guard.validate(self.registry, self.baseline, old_registry, old_baseline))
    def test_duplicate_id_fails(self):
        self.registry["features"].append(self.registry["features"][0])
        self.assertTrue(guard.validate(self.registry, self.baseline))
    def test_missing_evidence_fails(self):
        self.registry["features"][0]["evidence"] = []
        self.assertTrue(guard.validate(self.registry, self.baseline))
    def test_unknown_dependency_fails(self):
        self.registry["features"][0]["depends_on"] = ["M99-missing"]
        self.assertTrue(guard.validate(self.registry, self.baseline))
    def test_dependency_cycle_fails(self):
        first, second = self.registry["features"][:2]
        first["depends_on"], second["depends_on"] = [second["id"]], [first["id"]]
        self.assertTrue(guard.validate(self.registry, self.baseline))
    def test_retired_without_decision_fails(self):
        self.registry["features"][0]["status"] = "retired"
        self.assertTrue(guard.validate(self.registry, self.baseline))
    def test_retired_kept_with_decision_valid(self):
        item = self.registry["features"][0]
        item["status"] = "retired"
        item["scope_decision"] = {"date": "2026-10-10", "source": "test-only", "user_decision": "test-only"}
        self.assertEqual([], guard.validate(self.registry, self.baseline))
    def test_pr_must_reference_real_feature(self):
        self.assertTrue(guard.validate(self.registry, self.baseline, pr_body="No feature listed"))
        self.assertTrue(guard.validate(self.registry, self.baseline, pr_body="Roadmap-IDs: M99-missing"))
        self.assertEqual([], guard.validate(self.registry, self.baseline,
                         pr_body="Roadmap-IDs: M18-cloud-storage, M12-institution-authoring"))

if __name__ == "__main__":
    unittest.main()
