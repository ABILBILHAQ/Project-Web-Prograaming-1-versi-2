#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Engine Machine Learning Analisis Sentimen Bahasa Indonesia untuk Evaluasi PPKMB
Mendukung:
1. TF-IDF (Unigram + Bigram, Sublinear TF, Smooth IDF) + Supervised Multinomial Logistic Regression
   & Multinomial Naive Bayes (Kompatibel dengan scikit-learn maupun NumPy/SciPy murni).
2. Penyimpanan dan pemuatan model terlatih (.pkl dan .json).
3. Fallback Rule-Based Lexicon yang transparan apabila file model ML belum tersedia
   (tidak pernah mengklaim sebagai AI apabila sedang berjalan dalam mode fallback).
4. Arsitektur modular yang siap diperluas ke IndoBERT / HuggingFace Transformers.
"""

import json
import math
import pickle
import pathlib
from datetime import datetime
from typing import List, Dict, Any, Tuple, Optional

import numpy as np
from scipy.optimize import minimize

from text_preprocessor import preprocess_text, mask_pii, check_text_validity

# Cek ketersediaan scikit-learn (jika dijalankan di Laragon Windows setelah pip install scikit-learn)
try:
    from sklearn.feature_extraction.text import TfidfVectorizer
    from sklearn.linear_model import LogisticRegression
    HAS_SKLEARN = True
except ImportError:
    HAS_SKLEARN = False

CLASSES = ["positif", "negatif", "netral"]

# Kamus Fallback Sederhana (HANYA digunakan bila model ML .pkl/.json belum dilatih/dihapus)
FALLBACK_POSITIVE_WORDS = {
    "bermanfaat", "membantu", "bagus", "baik", "ramah", "nyaman", "jelas",
    "interaktif", "puas", "seru", "edukatif", "inspirasi", "menginspirasi",
    "motivasi", "sabar", "sigap", "tepat", "tertib", "lancar", "sukses",
    "bersih", "enak", "layak", "apresiasi", "komunikatif", "sopan", "senang",
    "bangga", "meriah", "kompak", "profesional", "higienis", "stabil",
    "tidak_membosankan", "tidak_terlambat", "tidak_ada_perpeloncoan"
}

FALLBACK_NEGATIVE_WORDS = {
    "padat", "kurang", "kelelahan", "lelah", "mati", "berdengung", "membentak",
    "terlambat", "dingin", "keras", "monoton", "panas", "pengap", "kacau",
    "sedikit", "habis", "kecewa", "kotor", "arogan", "kasar", "basi", "rusak",
    "semrawut", "membingungkan", "bingung", "tegang", "pingsan", "lambat",
    "asam", "tidak_jelas", "tidak_terdengar", "tidak_berfungsi", "kurang_ramah",
    "kurang_kompak", "kurang_efektif", "kurang_nyaman", "kurang_layak",
    "tidak_nyaman", "kurang_baik", "tidak_ramah", "buruk"
}


class PPKMBSentimentClassifier:
    """
    Model Klasifikasi Sentimen Terbimbing (Supervised ML):
    Menggabungkan ekstraksi fitur TF-IDF (Unigram & Bigram) dengan
    L2-Regularized Multinomial Logistic Regression + Multinomial Naive Bayes (Soft Voting).
    """

    def __init__(self, max_features: int = 2500, ngram_range: Tuple[int, int] = (1, 2), c_reg: float = 2.5):
        self.max_features = max_features
        self.ngram_range = ngram_range
        self.c_reg = c_reg
        self.classes = CLASSES
        self.vocab: Dict[str, int] = {}
        self.idf: np.ndarray = np.array([])
        self.weights: np.ndarray = np.array([])  # shape: (n_features, n_classes)
        self.bias: np.ndarray = np.array([])     # shape: (n_classes,)
        self.nb_log_prior: np.ndarray = np.array([])
        self.nb_feature_log_prob: np.ndarray = np.array([])
        self.model_version: str = "tfidf-logreg-nb-v1.0"
        self.trained_at: Optional[str] = None
        self.training_samples: int = 0
        self.is_trained: bool = False

    def _extract_ngrams(self, text: str) -> List[str]:
        tokens = text.split()
        features: List[str] = []
        min_n, max_n = self.ngram_range
        for n in range(min_n, max_n + 1):
            if n == 1:
                features.extend(tokens)
            else:
                for i in range(len(tokens) - n + 1):
                    features.append(" ".join(tokens[i : i + n]))
        return features

    def _fit_tfidf(self, cleaned_texts: List[str]) -> np.ndarray:
        n_docs = len(cleaned_texts)
        df_counts: Dict[str, int] = {}
        term_freqs: List[Dict[str, int]] = []

        for doc in cleaned_texts:
            ngrams = self._extract_ngrams(doc)
            tf: Dict[str, int] = {}
            for ng in ngrams:
                tf[ng] = tf.get(ng, 0) + 1
            term_freqs.append(tf)
            for ng in set(ngrams):
                df_counts[ng] = df_counts.get(ng, 0) + 1

        # Urutkan berdasarkan document frequency dan leksikografis agar deterministik
        sorted_terms = sorted(df_counts.items(), key=lambda x: (-x[1], x[0]))[: self.max_features]
        self.vocab = {term: idx for idx, (term, _) in enumerate(sorted_terms)}
        n_feat = len(self.vocab)

        # Smooth IDF sebagaimana scikit-learn: log((1 + N) / (1 + df)) + 1
        idf_list = [math.log((1.0 + n_docs) / (1.0 + df_counts[term])) + 1.0 for term, _ in sorted_terms]
        self.idf = np.array(idf_list, dtype=np.float64)

        X = np.zeros((n_docs, n_feat), dtype=np.float64)
        for doc_idx, tf in enumerate(term_freqs):
            for term, count in tf.items():
                if term in self.vocab:
                    feat_idx = self.vocab[term]
                    # Sublinear TF: 1 + log(tf)
                    X[doc_idx, feat_idx] = (1.0 + math.log(count)) * self.idf[feat_idx]

        # Normalisasi L2 baris demi baris
        norms = np.linalg.norm(X, axis=1, keepdims=True)
        norms[norms == 0] = 1.0
        X = X / norms
        return X

    def _transform_tfidf(self, cleaned_texts: List[str]) -> np.ndarray:
        n_docs = len(cleaned_texts)
        n_feat = len(self.vocab)
        X = np.zeros((n_docs, n_feat), dtype=np.float64)
        for doc_idx, doc in enumerate(cleaned_texts):
            ngrams = self._extract_ngrams(doc)
            tf: Dict[str, int] = {}
            for ng in ngrams:
                tf[ng] = tf.get(ng, 0) + 1
            for term, count in tf.items():
                if term in self.vocab:
                    feat_idx = self.vocab[term]
                    X[doc_idx, feat_idx] = (1.0 + math.log(count)) * self.idf[feat_idx]
        norms = np.linalg.norm(X, axis=1, keepdims=True)
        norms[norms == 0] = 1.0
        X = X / norms
        return X

    @staticmethod
    def _softmax(logits: np.ndarray) -> np.ndarray:
        shifted = logits - np.max(logits, axis=1, keepdims=True)
        exp_scores = np.exp(shifted)
        return exp_scores / np.sum(exp_scores, axis=1, keepdims=True)

    def fit(self, raw_texts: List[str], labels: List[str]) -> "PPKMBSentimentClassifier":
        cleaned_texts = [preprocess_text(t) for t in raw_texts]
        X = self._fit_tfidf(cleaned_texts)
        n_samples, n_features = X.shape
        n_classes = len(self.classes)

        class_to_idx = {c: i for i, c in enumerate(self.classes)}
        y_idx = np.array([class_to_idx[lbl.strip().lower()] for lbl in labels], dtype=np.int64)
        Y_onehot = np.zeros((n_samples, n_classes), dtype=np.float64)
        Y_onehot[np.arange(n_samples), y_idx] = 1.0

        # 1. Latih Multinomial Naive Bayes dengan Laplace Smoothing (alpha=0.5)
        alpha = 0.5
        class_counts = np.bincount(y_idx, minlength=n_classes).astype(np.float64)
        self.nb_log_prior = np.log((class_counts + alpha) / (n_samples + alpha * n_classes))
        feature_counts = np.zeros((n_classes, n_features), dtype=np.float64)
        for c in range(n_classes):
            feature_counts[c] = np.sum(X[y_idx == c], axis=0) + alpha
        self.nb_feature_log_prob = np.log(feature_counts / np.sum(feature_counts, axis=1, keepdims=True))

        # 2. Latih L2-Regularized Multinomial Logistic Regression via L-BFGS-B
        reg_lambda = 1.0 / (self.c_reg * n_samples)

        def loss_and_grad(params: np.ndarray) -> Tuple[float, np.ndarray]:
            W = params[: n_features * n_classes].reshape((n_features, n_classes))
            b = params[n_features * n_classes :]
            logits = X @ W + b
            probs = self._softmax(logits)
            # Cross-entropy loss + L2 penalty
            eps = 1e-12
            ce_loss = -np.sum(Y_onehot * np.log(probs + eps)) / n_samples
            l2_loss = 0.5 * reg_lambda * np.sum(W * W)
            total_loss = ce_loss + l2_loss

            diff = (probs - Y_onehot) / n_samples
            grad_W = (X.T @ diff) + reg_lambda * W
            grad_b = np.sum(diff, axis=0)
            grad = np.concatenate([grad_W.ravel(), grad_b])
            return float(total_loss), grad

        init_params = np.zeros(n_features * n_classes + n_classes, dtype=np.float64)
        res = minimize(
            loss_and_grad,
            init_params,
            method="L-BFGS-B",
            jac=True,
            options={"maxiter": 400, "ftol": 1e-9},
        )
        opt_params = res.x
        self.weights = opt_params[: n_features * n_classes].reshape((n_features, n_classes))
        self.bias = opt_params[n_features * n_classes :]

        self.training_samples = n_samples
        self.trained_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        self.model_version = "tfidf-logreg-nb-v1.0" + ("-sklearn-ready" if HAS_SKLEARN else "")
        self.is_trained = True
        return self

    def predict_proba_cleaned(self, cleaned_texts: List[str]) -> np.ndarray:
        X = self._transform_tfidf(cleaned_texts)
        # Probabilitas dari Multinomial Logistic Regression
        logits_lr = X @ self.weights + self.bias
        probs_lr = self._softmax(logits_lr)

        # Probabilitas dari Multinomial Naive Bayes
        logits_nb = (X @ self.nb_feature_log_prob.T) + self.nb_log_prior
        probs_nb = self._softmax(logits_nb)

        # Soft-voting ensemble (70% Logistic Regression + 30% Multinomial NB)
        probs = 0.70 * probs_lr + 0.30 * probs_nb
        return probs

    def predict_single(self, raw_text: str, critique_text: str = "") -> Dict[str, Any]:
        combined_raw = " ".join([str(raw_text or "").strip(), str(critique_text or "").strip()]).strip()
        validity = check_text_validity(combined_raw)
        masked_text = mask_pii(combined_raw)
        cleaned = preprocess_text(combined_raw)

        if not validity["is_valid"] or not cleaned:
            return {
                "label": "netral",
                "confidence_score": 0.0,
                "probabilities": {"positif": 0.0, "negatif": 0.0, "netral": 1.0},
                "status": validity["reason"],
                "status_message": validity["message"],
                "is_ml": False,
                "method": "rule_validation",
                "model_version": self.model_version if self.is_trained else "none",
                "cleaned_text": cleaned,
                "masked_text": masked_text,
                "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            }

        if not self.is_trained:
            return fallback_lexicon_predict(combined_raw, cleaned, masked_text)

        probs = self.predict_proba_cleaned([cleaned])[0]
        # Jika seluruh fitur di luar kosakata (vektor nol), tandai sebagai keyakinan rendah
        X_vec = self._transform_tfidf([cleaned])[0]
        if np.linalg.norm(X_vec) == 0:
            return {
                "label": "netral",
                "confidence_score": 0.3400,
                "probabilities": {"positif": 0.33, "negatif": 0.33, "netral": 0.34},
                "status": "low_confidence",
                "status_message": "Tidak ditemukan kata kunci yang dikenali dalam kosakata model. Perlu tinjauan manual.",
                "is_ml": True,
                "method": "ml_tfidf_logreg_nb",
                "model_version": self.model_version,
                "cleaned_text": cleaned,
                "masked_text": masked_text,
                "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            }

        best_idx = int(np.argmax(probs))
        best_label = self.classes[best_idx]
        confidence = float(round(probs[best_idx], 4))
        prob_dict = {self.classes[i]: float(round(probs[i], 4)) for i in range(len(self.classes))}

        status = "analyzed"
        status_msg = "Berhasil dianalisis menggunakan model Machine Learning TF-IDF + Klasifikasi Probabilistik."
        if confidence < 0.52:
            status = "low_confidence"
            status_msg = "Skor keyakinan prediksi rendah (< 52%). Disarankan verifikasi manual oleh panitia."

        return {
            "label": best_label,
            "confidence_score": confidence,
            "probabilities": prob_dict,
            "status": status,
            "status_message": status_msg,
            "is_ml": True,
            "method": "ml_tfidf_logreg_nb",
            "model_version": self.model_version,
            "cleaned_text": cleaned,
            "masked_text": masked_text,
            "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        }

    def save(self, pkl_path: pathlib.Path, json_path: pathlib.Path) -> None:
        state = {
            "max_features": self.max_features,
            "ngram_range": list(self.ngram_range),
            "c_reg": self.c_reg,
            "classes": self.classes,
            "vocab": self.vocab,
            "idf": self.idf.tolist(),
            "weights": self.weights.tolist(),
            "bias": self.bias.tolist(),
            "nb_log_prior": self.nb_log_prior.tolist(),
            "nb_feature_log_prob": self.nb_feature_log_prob.tolist(),
            "model_version": self.model_version,
            "trained_at": self.trained_at,
            "training_samples": self.training_samples,
            "is_trained": self.is_trained,
        }
        with open(pkl_path, "wb") as f:
            pickle.dump(state, f)
        with open(json_path, "w", encoding="utf-8") as f:
            json.dump(state, f, ensure_ascii=False)

    @classmethod
    def load(cls, pkl_path: pathlib.Path, json_path: Optional[pathlib.Path] = None) -> "PPKMBSentimentClassifier":
        state = None
        if pkl_path.exists():
            try:
                with open(pkl_path, "rb") as f:
                    state = pickle.load(f)
            except Exception:
                state = None
        if state is None and json_path and json_path.exists():
            with open(json_path, "r", encoding="utf-8") as f:
                state = json.load(f)
        if state is None:
            return cls()

        inst = cls(
            max_features=state.get("max_features", 2500),
            ngram_range=tuple(state.get("ngram_range", [1, 2])),
            c_reg=state.get("c_reg", 2.5),
        )
        inst.classes = state["classes"]
        inst.vocab = state["vocab"]
        inst.idf = np.array(state["idf"], dtype=np.float64)
        inst.weights = np.array(state["weights"], dtype=np.float64)
        inst.bias = np.array(state["bias"], dtype=np.float64)
        inst.nb_log_prior = np.array(state["nb_log_prior"], dtype=np.float64)
        inst.nb_feature_log_prob = np.array(state["nb_feature_log_prob"], dtype=np.float64)
        inst.model_version = state.get("model_version", "tfidf-logreg-nb-v1.0")
        inst.trained_at = state.get("trained_at")
        inst.training_samples = state.get("training_samples", 0)
        inst.is_trained = state.get("is_trained", True)
        return inst


def fallback_lexicon_predict(raw_text: str, cleaned: str, masked_text: str) -> Dict[str, Any]:
    """
    Mekanisme Fallback Transparan apabila file model Machine Learning belum tersedia.
    Secara jujur memberi label method='fallback_lexicon' dan is_ml=False.
    """
    tokens = cleaned.split()
    pos_hits = sum(1 for t in tokens if t in FALLBACK_POSITIVE_WORDS)
    neg_hits = sum(1 for t in tokens if t in FALLBACK_NEGATIVE_WORDS)

    if pos_hits > neg_hits:
        label = "positif"
        conf = min(0.85, 0.50 + 0.10 * (pos_hits - neg_hits))
    elif neg_hits > pos_hits:
        label = "negatif"
        conf = min(0.85, 0.50 + 0.10 * (neg_hits - pos_hits))
    else:
        label = "netral"
        conf = 0.50

    return {
        "label": label,
        "confidence_score": round(conf, 4),
        "probabilities": {
            "positif": round(conf if label == "positif" else (1 - conf) / 2, 4),
            "negatif": round(conf if label == "negatif" else (1 - conf) / 2, 4),
            "netral": round(conf if label == "netral" else (1 - conf) / 2, 4),
        },
        "status": "fallback_rule",
        "status_message": "PERHATIAN: Model Machine Learning belum tersedia. Hasil ini menggunakan Fallback Pencocokan Kata Sederhana (Bukan AI/ML).",
        "is_ml": False,
        "method": "fallback_lexicon",
        "model_version": "fallback-lexicon-non-ml",
        "cleaned_text": cleaned,
        "masked_text": masked_text,
        "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
    }
