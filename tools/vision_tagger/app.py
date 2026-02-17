from typing import Optional

from fastapi import FastAPI, File, Form, UploadFile
from PIL import Image
from transformers import pipeline
import json
import os
import re

app = FastAPI(title="photo-journal-vision-tagger")

try:
    import torch
    DEVICE = 0 if torch.cuda.is_available() else -1
except Exception:
    DEVICE = -1

classifier = pipeline(
    task="zero-shot-image-classification",
    model="openai/clip-vit-base-patch32",
    device=DEVICE,
)

# Stage 1: broad categories.
CATEGORY_LABELS = [
    # Living entities
    "animal", "bird", "fish", "insect", "pet",
    "person", "people", "portrait", "crowd",
    "clothing", "food", "season", "timeOfDay",
    "plant", "flower", "tree", "grass",
    # Environments and scenes
    "nature", "landscape", "forest", "mountain", "field", "park", "garden", "desert",
    "beach", "sea", "lake", "river", "snow", "sky", "cloud",
    "city", "street", "road", "building", "architecture", "bridge", "indoorScene", "outdoorScene",
    "indoor", "outdoor", "daytime", "night",
    # Object families
    "vehicle", "bicycle", "motorcycle", "car", "bus", "train", "airplane", "boat",
    "food", "drink", "furniture", "electronics", "computer", "phone", "camera",
    "book", "bag", "toy", "sign", "text", "document",
    # Visual style/meta
    "macro", "closeup",
    # Colors
    "red", "orange", "yellow", "green", "blue", "purple", "pink", "white", "black", "brown", "gray",
]

# Stage 2: species-level labels by zoological group.
MAMMAL_SPECIES_LABELS = [
    "dog", "cat", "horse", "cow", "sheep", "goat", "pig",
    "deer", "fox", "wolf", "bear", "rabbit", "squirrel", "hedgehog", "raccoon", "otter",
    "dolphin", "whale", "seal",
]

BIRD_SPECIES_LABELS = [
    "eagle", "owl", "hawk", "duck", "goose", "swan", "pigeon", "sparrow", "crow", "seagull", "parrot",
    "chicken", "rooster", "turkey",
]

FISH_SPECIES_LABELS = [
    "salmon", "trout", "carp", "catfish", "goldfish", "shark", "ray",
]

INSECT_SPECIES_LABELS = [
    "butterfly", "bee", "dragonfly", "ant", "beetle", "grasshopper", "mosquito",
]

REPTILE_AMPHIBIAN_SPECIES_LABELS = [
    "lizard", "snake", "turtle", "frog", "toad", "crocodile",
]

LANDSCAPE_LABELS = [
    "mountain", "forest", "beach", "desert", "river", "lake", "waterfall",
    "countryside", "cityscape", "seascape", "snowyLandscape", "sunsetSky",
]

BUILDING_LABELS = [
    "house", "apartmentBuilding", "skyscraper", "officeBuilding", "bridge",
    "church", "castle", "temple", "tower", "interior", "exterior",
]

SEASON_LABELS = [
    "spring", "summer", "autumn", "winter",
]

TIME_OF_DAY_LABELS = [
    "dawn", "morning", "afternoon", "evening", "night", "sunrise", "sunset",
]

PEOPLE_LABELS = [
    "man", "woman", "boy", "girl", "baby", "child", "teenager", "adult", "elderlyPerson",
]

CLOTHING_LABELS = [
    "coat", "jacket", "dress", "shirt", "tShirt", "sweater", "jeans", "shorts",
    "skirt", "suit", "uniform", "hat", "cap", "scarf", "glasses", "boots", "sneakers",
]

FOOD_LABELS = [
    "fruit", "vegetable", "meat", "fishDish", "salad", "soup", "bread",
    "pasta", "pizza", "burger", "cake", "dessert", "coffee", "tea",
]

REFINEMENT_GROUPS = {
    "bird": BIRD_SPECIES_LABELS,
    "fish": FISH_SPECIES_LABELS,
    "insect": INSECT_SPECIES_LABELS,
    "animal": MAMMAL_SPECIES_LABELS,
    "pet": MAMMAL_SPECIES_LABELS,
    "landscape": LANDSCAPE_LABELS,
    "building": BUILDING_LABELS,
    "architecture": BUILDING_LABELS,
    "season": SEASON_LABELS,
    "timeOfDay": TIME_OF_DAY_LABELS,
    "daytime": TIME_OF_DAY_LABELS,
    "night": TIME_OF_DAY_LABELS,
    "person": PEOPLE_LABELS,
    "people": PEOPLE_LABELS,
    "clothing": CLOTHING_LABELS,
    "food": FOOD_LABELS,
}

LIVING_GATE_LABELS = {"animal", "pet", "bird", "fish", "insect"}
REFINEMENT_GATE_LABELS = {
    "landscape", "building", "architecture", "season", "timeOfDay", "daytime", "night",
    "person", "people", "clothing", "food",
}

MIN_SCORE = float(os.getenv("VISION_TAGGER_MIN_CONFIDENCE", "0.20"))
MIN_SPECIES_SCORE = float(os.getenv("VISION_TAGGER_SPECIES_MIN_CONFIDENCE", "0.28"))
ANIMAL_GATE = float(os.getenv("VISION_TAGGER_ANIMAL_GATE", "0.24"))
REFINEMENT_GATE = float(os.getenv("VISION_TAGGER_REFINEMENT_GATE", str(ANIMAL_GATE)))
SPECIES_RELATIVE_GATE = float(os.getenv("VISION_TAGGER_SPECIES_RELATIVE_GATE", "0.72"))
MAX_SPECIES_PER_GROUP = int(os.getenv("VISION_TAGGER_MAX_SPECIES_PER_GROUP", "3"))
HINT_SCORE_BOOST = float(os.getenv("VISION_TAGGER_HINT_BOOST", "0.06"))
MAX_HINTS = int(os.getenv("VISION_TAGGER_MAX_HINTS", "20"))
MAX_TAGS = int(os.getenv("VISION_TAGGER_MAX_TAGS", "10"))


def to_camel(label: str) -> str:
    value = re.sub(r"([a-z0-9])([A-Z])", r"\1 \2", str(label).strip())
    words = [part.lower() for part in re.split(r"[^A-Za-z0-9]+", value) if part]
    if not words:
        return ""

    return words[0] + "".join(w[:1].upper() + w[1:] for w in words[1:])


def build_tag_alias_map() -> dict:
    aliases = {}
    all_labels = [
        *CATEGORY_LABELS,
        *MAMMAL_SPECIES_LABELS,
        *BIRD_SPECIES_LABELS,
        *FISH_SPECIES_LABELS,
        *INSECT_SPECIES_LABELS,
        *REPTILE_AMPHIBIAN_SPECIES_LABELS,
        *LANDSCAPE_LABELS,
        *BUILDING_LABELS,
        *SEASON_LABELS,
        *TIME_OF_DAY_LABELS,
        *PEOPLE_LABELS,
        *CLOTHING_LABELS,
        *FOOD_LABELS,
    ]

    for label in all_labels:
        key = re.sub(r"[^A-Za-z0-9]+", "", str(label)).lower()
        canonical = to_camel(label)
        if key and canonical:
            aliases[key] = canonical

    return aliases


TAG_ALIAS_MAP = build_tag_alias_map()


def normalize_tag(label: str) -> str:
    collapsed = re.sub(r"[^A-Za-z0-9]+", "", str(label)).lower()
    if collapsed in TAG_ALIAS_MAP:
        return TAG_ALIAS_MAP[collapsed]

    return to_camel(label)


def extract_items(result):
    """Normalize transformers output to list[dict(label, score)]."""
    if isinstance(result, dict):
        labels = result.get("labels", [])
        scores = result.get("scores", [])
        return [{"label": l, "score": s} for l, s in zip(labels, scores)]

    if isinstance(result, list):
        return [item for item in result if isinstance(item, dict)]

    return []


def classify_labels(pil: Image.Image, labels, threshold: float):
    try:
        result = classifier(pil, candidate_labels=labels, multi_label=True)
    except Exception:
        return []

    items = extract_items(result)
    picked = []

    for item in items:
        label = item.get("label")
        score = item.get("score")
        if label is None or score is None:
            continue
        if float(score) < threshold:
            continue

        picked.append((normalize_tag(label), float(score)))

    return picked


def filter_competitive(candidates, base_threshold: float):
    if not candidates:
        return []

    best_score = max(score for _, score in candidates)
    dynamic_threshold = max(base_threshold, best_score * SPECIES_RELATIVE_GATE)

    return [(label, score) for label, score in candidates if score >= dynamic_threshold]


def top_labels(candidates, limit: int):
    ordered = sorted(candidates, key=lambda item: item[1], reverse=True)
    if limit <= 0:
        return ordered
    return ordered[:limit]


def top_score(candidates, labels) -> float:
    lookup = set(labels) if not isinstance(labels, str) else {labels}
    best = 0.0

    for label, score in candidates:
        if label in lookup and score > best:
            best = score

    return best


def parse_tag_hints(raw: Optional[str]) -> list[str]:
    if raw is None:
        return []

    text = str(raw).strip()
    if text == "":
        return []

    parsed = None
    try:
        parsed = json.loads(text)
    except Exception:
        parsed = None

    if isinstance(parsed, list):
        values = parsed
    else:
        values = [part.strip() for part in text.split(",")]

    hints = []
    for value in values:
        if not isinstance(value, str):
            continue
        normalized = normalize_tag(value)
        if normalized == "":
            continue
        hints.append(normalized)

    deduped = []
    seen = set()
    for hint in hints:
        if hint in seen:
            continue
        seen.add(hint)
        deduped.append(hint)
        if len(deduped) >= MAX_HINTS:
            break

    return deduped


@app.get("/health")
def health() -> dict:
    return {"ok": True, "device": DEVICE}


@app.post("/tag")
async def tag_image(
    image: UploadFile = File(...),
    tag_hints: Optional[str] = Form(default=None),
) -> dict:
    try:
        pil = Image.open(image.file).convert("RGB")
    except Exception:
        return {"tags": []}

    tags_with_score = classify_labels(pil, CATEGORY_LABELS, MIN_SCORE)

    # Stage 2 for animals.
    living_score = top_score(tags_with_score, LIVING_GATE_LABELS)
    if living_score >= ANIMAL_GATE:
        stage1_lookup = {label: score for label, score in tags_with_score}
        for gate_label, group_labels in REFINEMENT_GROUPS.items():
            if gate_label not in LIVING_GATE_LABELS:
                continue
            if stage1_lookup.get(gate_label, 0.0) < ANIMAL_GATE:
                continue

            group_candidates = classify_labels(pil, group_labels, MIN_SPECIES_SCORE)
            group_candidates = filter_competitive(group_candidates, MIN_SPECIES_SCORE)
            group_candidates = top_labels(group_candidates, MAX_SPECIES_PER_GROUP)
            tags_with_score.extend(group_candidates)

        # Fallback: if only coarse "animal" is confident, also check reptiles/amphibians.
        if stage1_lookup.get("animal", 0.0) >= ANIMAL_GATE and stage1_lookup.get("bird", 0.0) < ANIMAL_GATE:
            reptile_candidates = classify_labels(
                pil, REPTILE_AMPHIBIAN_SPECIES_LABELS, MIN_SPECIES_SCORE
            )
            reptile_candidates = filter_competitive(reptile_candidates, MIN_SPECIES_SCORE)
            reptile_candidates = top_labels(reptile_candidates, MAX_SPECIES_PER_GROUP)
            tags_with_score.extend(reptile_candidates)

    # Stage 2 for non-animal domains (landscape/building/people/clothing/food/season/time).
    stage1_lookup = {label: score for label, score in tags_with_score}
    for gate_label, group_labels in REFINEMENT_GROUPS.items():
        if gate_label not in REFINEMENT_GATE_LABELS:
            continue
        if stage1_lookup.get(gate_label, 0.0) < REFINEMENT_GATE:
            continue

        group_candidates = classify_labels(pil, group_labels, MIN_SPECIES_SCORE)
        group_candidates = filter_competitive(group_candidates, MIN_SPECIES_SCORE)
        group_candidates = top_labels(group_candidates, MAX_SPECIES_PER_GROUP)
        tags_with_score.extend(group_candidates)

    # Optional hint rerank: gently prefer already existing tags when they are present.
    hints = parse_tag_hints(tag_hints)
    if hints:
        hint_threshold = max(MIN_SCORE * 0.7, 0.12)
        hinted = classify_labels(pil, hints, hint_threshold)
        boosted = [(label, min(1.0, score + HINT_SCORE_BOOST)) for label, score in hinted]
        tags_with_score.extend(boosted)

    # Deduplicate by max confidence per tag.
    best = {}
    for label, score in tags_with_score:
        if label not in best or score > best[label]:
            best[label] = score

    # Sort by confidence and cap count.
    ordered = sorted(best.items(), key=lambda item: item[1], reverse=True)
    tags = [label for label, _ in ordered[:MAX_TAGS]]

    return {"tags": tags}
