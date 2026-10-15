#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Skrip Pelatihan Model Machine Learning Analisis Sentimen (train_model.py)
Melatih model TF-IDF + Multinomial Logistic Regression & Naive Bayes menggunakan
data berlabel Bahasa Indonesia, menyimpan model ke python/models/, dan menjalankan evaluasi.
"""

import argparse
import csv
import json
import pathlib
import sys
from datetime import datetime

from ml_engine import PPKMBSentimentClassifier, CLASSES
from evaluate_model import load_dataset, evaluate_dataset
from text_preprocessor import mask_pii

PYTHON_DIR = pathlib.Path(__file__).resolve().parent
MODELS_DIR = PYTHON_DIR / "models"
DEFAULT_DATASET = PYTHON_DIR / "dataset_ppkmb_id.csv"
MODEL_PKL = MODELS_DIR / "sentiment_model.pkl"
MODEL_JSON = MODELS_DIR / "sentiment_model.json"


def train_and_evaluate(dataset_path: pathlib.Path, version_tag: str = "") -> dict:
    if not dataset_path.exists():
        return {
            "status": "error",
            "message": f"File dataset tidak ditemukan: {dataset_path}",
        }

    texts, labels = load_dataset(dataset_path)
    if len(texts) < 15:
        return {
            "status": "error",
            "message": f"Jumlah dataset terlalu sedikit ({len(texts)} baris). Minimal diperlukan 15 data berlabel.",
        }

    # Pastikan ketiga kelas terwakili
    for c in CLASSES:
        if labels.count(c) < 3:
            return {
                "status": "error",
                "message": f"Kelas '{c}' hanya memiliki {labels.count(c)} sampel. Minimal 3 sampel per kelas.",
            }

    MODELS_DIR.mkdir(parents=True, exist_ok=True)

    # Latih model pada seluruh data berlabel untuk penggunaan produksi
    clf = PPKMBSentimentClassifier(max_features=2500, ngram_range=(1, 2), c_reg=2.5)
    clf.fit(texts, labels)
    if version_tag:
        clf.model_version = version_tag
    else:
        clf.model_version = f"tfidf-logreg-nb-v1.{len(texts)}"

    clf.save(MODEL_PKL, MODEL_JSON)

    # Evaluasi performa secara jujur dengan Stratified 5-Fold Cross-Validation
    metrics = evaluate_dataset(dataset_path, k_folds=5)
    metrics["model_version"] = clf.model_version
    metrics["Vocabulary_size"] = len(clf.vocab)
    metrics["trained_at"] = clf.trained_at

    with open(MODELS_DIR / "metrics.json", "w", encoding="utf-8") as f:
        json.dump(metrics, f, indent=2, ensure_ascii=False)

    return {
        "status": "success",
        "message": "Model berhasil dilatih dan dievaluasi.",
        "model_version": clf.model_version,
        "trained_at": clf.trained_at,
        "training_samples": len(texts),
        "vocabulary_size": len(clf.vocab),
        "metrics": metrics,
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Latih Model Sentimen PPKMB Bahasa Indonesia")
    parser.add_argument("--dataset", type=str, default=str(DEFAULT_DATASET), help="Path file CSV dataset pelatihan")
    parser.add_argument("--version", type=str, default="", help="Label versi model opsional")
    args = parser.parse_args()

    result = train_and_evaluate(pathlib.Path(args.dataset), args.version)
    print(json.dumps(result, ensure_ascii=False))
    if result.get("status") != "success":
        sys.exit(1)
