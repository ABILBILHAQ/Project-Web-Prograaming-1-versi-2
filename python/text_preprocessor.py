#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Modul Preprocessing Teks Bahasa Indonesia & PII Masking
Sistem Evaluasi Kegiatan Mahasiswa terhadap PPKMB Berbasis Analisis Sentimen
"""

import re
from typing import Dict, Any, List

# Kamus Normalisasi Singkatan & Slang Bahasa Indonesia (Konteks Kampus / PPKMB)
SLANG_DICT: Dict[str, str] = {
    "yg": "yang",
    "gak": "tidak",
    "ga": "tidak",
    "nggak": "tidak",
    "ngga": "tidak",
    "gk": "tidak",
    "tdk": "tidak",
    "tak": "tidak",
    "enggak": "tidak",
    "kagak": "tidak",
    "krn": "karena",
    "karna": "karena",
    "dgn": "dengan",
    "dg": "dengan",
    "utk": "untuk",
    "buat": "untuk",
    "bgt": "sangat",
    "banget": "sangat",
    "bener": "benar",
    "bnr": "benar",
    "sdh": "sudah",
    "udah": "sudah",
    "udh": "sudah",
    "blm": "belum",
    "blom": "belum",
    "tp": "tetapi",
    "tpi": "tetapi",
    "tapi": "tetapi",
    "klo": "jika",
    "kalo": "jika",
    "kalau": "jika",
    "dlm": "dalam",
    "dr": "dari",
    "pd": "pada",
    "spt": "seperti",
    "kayak": "seperti",
    "kyk": "seperti",
    "jg": "juga",
    "jga": "juga",
    "aja": "saja",
    "aj": "saja",
    "doang": "saja",
    "bs": "bisa",
    "jd": "jadi",
    "jdi": "jadi",
    "sm": "sama",
    "maba": "mahasiswa baru",
    "narsum": "pemateri",
    "pematerinya": "pemateri",
    "materinya": "materi",
    "panitianya": "panitia",
    "fasilitasnya": "fasilitas",
    "konsumsinya": "konsumsi",
    "jadwalnya": "jadwal",
    "acaranya": "acara",
    "kegiatannya": "kegiatan",
    "mantul": "sangat bagus",
    "mantap": "bagus",
    "keren": "bagus",
    "kece": "bagus",
    "oke": "baik",
    "ok": "baik",
    "sip": "baik",
    "helpful": "membantu",
    "suportif": "membantu",
    "zonk": "buruk",
    "parah": "buruk",
    "ancur": "kacau",
    "hancur": "kacau",
    "molor": "terlambat",
    "ngaret": "terlambat",
    "telat": "terlambat",
    "gerah": "panas",
    "pengap": "panas",
    "basi": "rusak",
    "semrawut": "kacau",
    "arogan": "kasar",
    "bentak": "membentak",
    "membentak2": "membentak",
}

NEGATION_WORDS = {"tidak", "bukan", "kurang", "belum", "jangan", "tanpa"}

STOPWORDS_ID = {
    "dan", "di", "ke", "dari", "ini", "itu", "atau", "pada", "untuk",
    "dengan", "oleh", "sebagai", "dalam", "para", "tersebut", "kami",
    "kita", "saya", "aku", "mereka", "dia", "ia", "nya", "yang", "jika", "maka"
}

PROTECTED_WORDS = {
    "nyaman", "ramah", "panas", "keras", "jelas", "puas", "lelah",
    "kecewa", "bagus", "buruk", "cepat", "lambat", "tepat", "padat",
    "materi", "panitia", "pemateri", "fasilitas", "konsumsi", "jadwal",
    "kegiatan", "acara", "kampus", "mahasiswa", "baru", "sangat",
    "kurang", "tidak", "belum", "bukan", "jangan", "tanpa", "bersih",
    "kotor", "dingin", "hangat", "segar", "terlambat", "manfaat",
    "bermanfaat", "bantu", "membantu", "kacau", "tertib", "lancar",
    "sukses", "apresiasi", "motivasi", "inspirasi", "bosan", "kantuk",
    "antusias", "komunikatif", "interaktif", "profesional", "sopan",
    "terlalu", "selama", "pukul", "meliputi", "terdiri", "bertempat",
    "dibagi", "mulai", "hingga", "sampai"
}

POSITIVE_ANCHORS = {
    "bermanfaat", "membantu", "ramah", "nyaman", "jelas", "interaktif",
    "puas", "seru", "edukatif", "menginspirasi", "motivasi", "sabar",
    "sigap", "tertib", "lancar", "sukses", "bersih", "enak", "layak",
    "apresiasi", "komunikatif", "sopan", "senang", "bangga", "meriah",
    "kompak", "profesional", "higienis", "stabil", "bagus", "baik",
    "antusias", "hangat", "segar", "teratur", "memadai", "berguna",
    "tidak_membosankan", "tidak_terlambat", "tidak_terburu", "tidak_ada_perpeloncoan"
}

NEGATIVE_ANCHORS = {
    "terlalu", "padat", "kurang", "kelelahan", "lelah", "mati", "berdengung",
    "membentak", "terlambat", "monoton", "panas", "kacau", "sedikit",
    "habis", "kecewa", "kotor", "kasar", "rusak", "membingungkan", "bingung",
    "tegang", "pingsan", "lambat", "asam", "sulit", "sempit", "berubah",
    "mepet", "mengantuk", "memberatkan", "mahal", "bertele", "sia",
    "tidak_jelas", "tidak_terdengar", "tidak_berfungsi", "tidak_terbaca",
    "tidak_sebanding", "tidak_menggunakan", "tidak_nyaman", "tidak_dibagikan",
    "tidak_layak", "kurang_ramah", "kurang_kompak", "kurang_efektif",
    "kurang_menghargai", "kurang_sigap", "kurang_terorganisir", "kurang_relevan",
    "kurang_interaktif", "kurang_layak", "kurang_nyaman", "kurang_menyentuh",
    "kurang_segar", "kurang_jelas", "kurang_mampu", "buruk"
}

NEUTRAL_ANCHORS = {
    "dilaksanakan", "dilaksana", "selama", "pukul", "wib", "bertempat",
    "meliputi", "dibagi", "terdiri", "diwajibkan", "berlangsung", "diadakan",
    "berasal", "mencakup", "menggunakan", "bertugas", "bergiliran", "luring",
    "terdapat", "dijadwalkan", "terletak", "diisi", "ditutup", "menampung",
    "mengangkat", "menjelaskan", "dipandu", "diarahkan", "ditandai", "berjaga",
    "ditempatkan", "disampaikan", "diunggah", "memaparkan", "disesuaikan"
}


def mask_pii(text: str) -> str:
    """
    Menyamarkan atau menghapus Informasi Identitas Pribadi (PII) dari komentar mahasiswa
    sebelum disimpan ke log analisis atau digunakan untuk pelatihan model ML.
    """
    if not text:
        return ""
    masked = str(text)
    masked = re.sub(r"[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+", "[EMAIL_DISEMBUNYIKAN]", masked)
    masked = re.sub(r"(\+62|62|0)8[1-9][0-9]{6,11}\b", "[TELP_DISEMBUNYIKAN]", masked)
    masked = re.sub(r"\b\d{8,16}\b", "[NIM_DISEMBUNYIKAN]", masked)
    masked = re.sub(
        r"\b(nama\s+saya|perkenalkan\s+saya)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,2})",
        r"\1 [NAMA_DISEMBUNYIKAN]",
        masked,
        flags=re.IGNORECASE,
    )
    return masked


def light_stem_word(word: str) -> str:
    """Stemmer ringan Bahasa Indonesia dengan proteksi kata dasar penting."""
    if len(word) <= 4 or word in PROTECTED_WORDS or word in POSITIVE_ANCHORS or word in NEGATIVE_ANCHORS or word in NEUTRAL_ANCHORS or "_" in word:
        return word
    w = word
    for suf in ("lah", "kah", "nya", "pun"):
        if w.endswith(suf) and len(w) - len(suf) >= 4:
            w = w[: -len(suf)]
            break
    if w in PROTECTED_WORDS or w in POSITIVE_ANCHORS or w in NEGATIVE_ANCHORS or w in NEUTRAL_ANCHORS:
        return w
    return w


def preprocess_text(text: str, remove_stopwords: bool = True) -> str:
    """
    Tahapan Preprocessing Teks Bahasa Indonesia:
    1. Penyamaran PII (Privasi Mahasiswa)
    2. Case folding (huruf kecil)
    3. Pembersihan URL, tag HTML, & tanda baca
    4. Normalisasi huruf berulang ('bagussss' -> 'bagus')
    5. Normalisasi singkatan & slang Bahasa Indonesia
    6. Penanganan negasi ('tidak bagus' -> 'tidak_bagus' + tag polaritas)
    7. Ekstraksi fitur indikator polaritas & deskriptif Bahasa Indonesia
    """
    if not text or not isinstance(text, str):
        return ""

    cleaned = mask_pii(text)
    cleaned = re.sub(r"\[[A-Z]+_DISEMBUNYIKAN\]", " ", cleaned)
    cleaned = cleaned.lower()
    cleaned = re.sub(r"https?://\S+|www\.\S+", " ", cleaned)
    cleaned = re.sub(r"<[^>]+>", " ", cleaned)
    cleaned = re.sub(r"([a-z])\1{2,}", r"\1", cleaned)
    cleaned = re.sub(r"[^a-z\s]", " ", cleaned)
    cleaned = re.sub(r"\s+", " ", cleaned).strip()

    if not cleaned:
        return ""

    raw_tokens = cleaned.split()
    norm_tokens: List[str] = []
    for tok in raw_tokens:
        replacement = SLANG_DICT.get(tok, tok)
        norm_tokens.extend(replacement.split())

    neg_handled_tokens: List[str] = []
    skip_next = False
    for i, tok in enumerate(norm_tokens):
        if skip_next:
            skip_next = False
            continue
        if tok in NEGATION_WORDS and i + 1 < len(norm_tokens):
            next_tok = light_stem_word(norm_tokens[i + 1])
            if next_tok == "ada" and i + 2 < len(norm_tokens):
                third_tok = light_stem_word(norm_tokens[i + 2])
                compound = f"{tok}_ada_{third_tok}"
                neg_handled_tokens.append(compound)
            else:
                compound = f"{tok}_{next_tok}"
                neg_handled_tokens.append(compound)
                # Pertahankan juga kata negasi sebagai sinyal umum kecuali negasi positif
                if compound not in ("tidak_membosankan", "tidak_terlambat", "tidak_terburu", "tidak_berdesakan"):
                    neg_handled_tokens.append(tok)
            skip_next = True
        else:
            stemmed = light_stem_word(tok)
            if remove_stopwords and stemmed in STOPWORDS_ID:
                continue
            if len(stemmed) >= 2:
                neg_handled_tokens.append(stemmed)

    # Tambahkan fitur sinyal semantik Bahasa Indonesia untuk memperkuat generalisasi TF-IDF pada variasi kalimat
    pos_count = sum(1 for t in neg_handled_tokens if t in POSITIVE_ANCHORS)
    neg_count = sum(1 for t in neg_handled_tokens if t in NEGATIVE_ANCHORS)
    neu_count = sum(1 for t in neg_handled_tokens if t in NEUTRAL_ANCHORS)

    enriched = list(neg_handled_tokens)
    if pos_count > 0 and neg_count == 0:
        enriched.extend(["_indikator_positif_"] * min(2, pos_count))
    if neg_count > 0:
        enriched.extend(["_indikator_negatif_"] * min(2, neg_count))
    if neu_count > 0 and pos_count == 0 and neg_count == 0:
        enriched.extend(["_indikator_netral_"] * min(2, neu_count))

    return " ".join(enriched)


def check_text_validity(raw_text: str) -> Dict[str, Any]:
    """
    Memeriksa apakah komentar kosong atau terlalu pendek untuk dianalisis dengan yakin.
    """
    if raw_text is None:
        return {"is_valid": False, "reason": "empty", "message": "Komentar kosong."}
    stripped = str(raw_text).strip()
    if len(stripped) == 0 or stripped in ("-", ".", "tidak ada", "cukup", "pass", "nil"):
        return {
            "is_valid": False,
            "reason": "empty",
            "message": "Komentar kosong atau hanya berisi tanda strip.",
        }
    words = [w for w in re.findall(r"[a-zA-Z]{2,}", stripped)]
    if len(stripped) < 10 or len(words) < 3:
        return {
            "is_valid": False,
            "reason": "too_short",
            "message": f"Teks terlalu pendek ({len(words)} kata). Minimal 3 kata bermakna diperlukan untuk analisis sentimen otomatis yang akurat.",
        }
    return {"is_valid": True, "reason": "valid", "message": "Teks valid untuk dianalisis."}
