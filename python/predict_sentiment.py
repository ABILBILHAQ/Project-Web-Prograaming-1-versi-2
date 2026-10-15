#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Skrip Prediksi Sentimen Komentar Evaluasi PPKMB (predict_sentiment.py)
Mendukung input argumen CLI maupun JSON via STDIN (tunggal maupun batch).
"""

import argparse
import json
import pathlib
import sys

from ml_engine import PPKMBSentimentClassifier

PYTHON_DIR = pathlib.Path(__file__).resolve().parent
MODELS_DIR = PYTHON_DIR / "models"
MODEL_PKL = MODELS_DIR / "sentiment_model.pkl"
MODEL_JSON = MODELS_DIR / "sentiment_model.json"


def main() -> None:
    parser = argparse.ArgumentParser(description="Prediksi Sentimen Komentar Mahasiswa PPKMB")
    parser.add_argument("--text", type=str, default=None, help="Komentar pengalaman mahasiswa")
    parser.add_argument("--critique", type=str, default="", help="Kritik dan saran tambahan")
    parser.add_argument("--stdin", action="store_true", help="Baca input JSON dari STDIN")
    args = parser.parse_args()

    clf = PPKMBSentimentClassifier.load(MODEL_PKL, MODEL_JSON)

    if args.stdin or (args.text is None and not sys.stdin.isatty()):
        raw_input = sys.stdin.read().strip()
        if not raw_input:
            print(json.dumps({"status": "error", "message": "Input STDIN kosong."}, ensure_ascii=False))
            sys.exit(1)
        try:
            payload = json.loads(raw_input)
        except json.JSONDecodeError as e:
            print(json.dumps({"status": "error", "message": f"Format JSON tidak valid: {e}"}, ensure_ascii=False))
            sys.exit(1)

        # Mode batch jika terdapat key "items"
        if isinstance(payload, dict) and "items" in payload and isinstance(payload["items"], list):
            results = []
            for item in payload["items"]:
                eval_id = item.get("evaluation_id")
                txt = item.get("comment", item.get("text", ""))
                crit = item.get("critique", "")
                pred = clf.predict_single(txt, crit)
                pred["evaluation_id"] = eval_id
                results.append(pred)
            print(
                json.dumps(
                    {
                        "status": "success",
                        "count": len(results),
                        "is_ml": clf.is_trained,
                        "model_version": clf.model_version if clf.is_trained else "fallback-lexicon-non-ml",
                        "results": results,
                    },
                    ensure_ascii=False,
                )
            )
            return

        # Mode tunggal dari JSON
        txt = payload.get("comment", payload.get("text", ""))
        crit = payload.get("critique", "")
        res = clf.predict_single(txt, crit)
        print(json.dumps(res, ensure_ascii=False))
        return

    # Mode argumen CLI
    res = clf.predict_single(args.text or "", args.critique or "")
    print(json.dumps(res, ensure_ascii=False))


if __name__ == "__main__":
    main()
