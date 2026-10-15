#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Skrip Evaluasi Performa Model Machine Learning (evaluate_model.py)
Menghitung metrik aktual (Accuracy, Precision, Recall, F1-Score, dan Confusion Matrix)
menggunakan Stratified 5-Fold Cross-Validation dan 80/20 Train-Test Split pada dataset berlabel.
"""

import csv
import json
import pathlib
from datetime import datetime
from typing import List, Dict, Any, Tuple
import numpy as np

from ml_engine import PPKMBSentimentClassifier, CLASSES
from text_preprocessor import mask_pii

PYTHON_DIR = pathlib.Path(__file__).resolve().parent
MODELS_DIR = PYTHON_DIR / "models"
DEFAULT_DATASET = PYTHON_DIR / "dataset_ppkmb_id.csv"
METRICS_JSON = MODELS_DIR / "metrics.json"


def load_dataset(csv_path: pathlib.Path) -> Tuple[List[str], List[str]]:
    texts: List[str] = []
    labels: List[str] = []
    with open(csv_path, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            txt = mask_pii(row.get("text", "").strip())
            lbl = row.get("label", "").strip().lower()
            if txt and lbl in CLASSES:
                texts.append(txt)
                labels.append(lbl)
    return texts, labels


def stratified_kfold_indices(labels: List[str], k: int = 5, seed: int = 42) -> List[Tuple[List[int], List[int]]]:
    rng = np.random.default_rng(seed)
    class_indices: Dict[str, List[int]] = {c: [] for c in CLASSES}
    for idx, lbl in enumerate(labels):
        class_indices[lbl].append(idx)

    folds: List[List[int]] = [[] for _ in range(k)]
    for c in CLASSES:
        idxs = np.array(class_indices[c], dtype=int)
        rng.shuffle(idxs)
        for i, idx_val in enumerate(idxs):
            folds[i % k].append(int(idx_val))

    splits: List[Tuple[List[int], List[int]]] = []
    for fold_idx in range(k):
        test_idx = sorted(folds[fold_idx])
        train_idx = sorted([i for f_i, f_list in enumerate(folds) if f_i != fold_idx for i in f_list])
        splits.append((train_idx, test_idx))
    return splits


def compute_classification_metrics(y_true: List[str], y_pred: List[str]) -> Dict[str, Any]:
    n_total = len(y_true)
    class_to_idx = {c: i for i, c in enumerate(CLASSES)}
    cm = np.zeros((len(CLASSES), len(CLASSES)), dtype=int)

    for yt, yp in zip(y_true, y_pred):
        cm[class_to_idx[yt], class_to_idx[yp]] += 1

    correct = int(np.trace(cm))
    accuracy = round(correct / n_total, 4) if n_total > 0 else 0.0

    per_class: Dict[str, Dict[str, float]] = {}
    precisions: List[float] = []
    recalls: List[float] = []
    f1s: List[float] = []

    for i, c in enumerate(CLASSES):
        tp = int(cm[i, i])
        fp = int(np.sum(cm[:, i]) - tp)
        fn = int(np.sum(cm[i, :]) - tp)
        support = int(np.sum(cm[i, :]))

        prec = tp / (tp + fp) if (tp + fp) > 0 else 0.0
        rec = tp / (tp + fn) if (tp + fn) > 0 else 0.0
        f1 = (2 * prec * rec) / (prec + rec) if (prec + rec) > 0 else 0.0

        precisions.append(prec)
        recalls.append(rec)
        f1s.append(f1)

        per_class[c] = {
            "precision": round(prec, 4),
            "recall": round(rec, 4),
            "f1_score": round(f1, 4),
            "support": support,
            "tp": tp,
            "fp": fp,
            "fn": fn,
        }

    return {
        "accuracy": accuracy,
        "precision_macro": round(float(np.mean(precisions)), 4),
        "recall_macro": round(float(np.mean(recalls)), 4),
        "f1_macro": round(float(np.mean(f1s)), 4),
        "per_class": per_class,
        "confusion_matrix": {
            "labels": CLASSES,
            "matrix": cm.tolist(),
        },
        "total_evaluated": n_total,
        "correct_predictions": correct,
    }


def evaluate_dataset(csv_path: pathlib.Path = DEFAULT_DATASET, k_folds: int = 5) -> Dict[str, Any]:
    texts, labels = load_dataset(csv_path)
    splits = stratified_kfold_indices(labels, k=k_folds, seed=42)

    oof_true: List[str] = []
    oof_pred: List[str] = []

    # 1. Stratified 5-Fold Cross Validation
    for train_idx, test_idx in splits:
        train_texts = [texts[i] for i in train_idx]
        train_labels = [labels[i] for i in train_idx]
        test_texts = [texts[i] for i in test_idx]
        test_labels = [labels[i] for i in test_idx]

        clf = PPKMBSentimentClassifier()
        clf.fit(train_texts, train_labels)

        for txt, true_lbl in zip(test_texts, test_labels):
            pred = clf.predict_single(txt)
            oof_true.append(true_lbl)
            oof_pred.append(pred["label"])

    cv_metrics = compute_classification_metrics(oof_true, oof_pred)

    # 2. Evaluasi Hold-out Test Set (80% Train, 20% Test - Fold 1)
    tr_idx, te_idx = splits[0]
    clf_holdout = PPKMBSentimentClassifier()
    clf_holdout.fit([texts[i] for i in tr_idx], [labels[i] for i in tr_idx])
    ho_true = [labels[i] for i in te_idx]
    ho_pred = [clf_holdout.predict_single(texts[i])["label"] for i in te_idx]
    holdout_metrics = compute_classification_metrics(ho_true, ho_pred)

    result = {
        "status": "success",
        "model_version": "tfidf-logreg-nb-v1.0",
        "evaluation_method": f"Stratified {k_folds}-Fold Cross-Validation & 80/20 Hold-Out Split",
        "dataset_size": len(texts),
        "class_distribution": {c: labels.count(c) for c in CLASSES},
        "accuracy": cv_metrics["accuracy"],
        "precision": cv_metrics["precision_macro"],
        "recall": cv_metrics["recall_macro"],
        "f1_score": cv_metrics["f1_macro"],
        "per_class": cv_metrics["per_class"],
        "confusion_matrix": cv_metrics["confusion_matrix"],
        "holdout_80_20": holdout_metrics,
        "evaluated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
    }

    MODELS_DIR.mkdir(parents=True, exist_ok=True)
    with open(METRICS_JSON, "w", encoding="utf-8") as f:
        json.dump(result, f, indent=2, ensure_ascii=False)

    return result


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="Evaluasi Model Sentimen PPKMB")
    parser.add_argument("--dataset", type=str, default=str(DEFAULT_DATASET), help="Path file CSV dataset berlabel")
    args = parser.parse_args()

    res = evaluate_dataset(pathlib.Path(args.dataset))
    print(json.dumps(res, ensure_ascii=False))
