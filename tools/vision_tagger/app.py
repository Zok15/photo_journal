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
    "monkey", "ape", "elephant", "tiger", "lion", "zebra", "giraffe", "kangaroo",
]

BIRD_SPECIES_LABELS = [
    "eagle", "owl", "hawk", "duck", "goose", "swan", "pigeon", "sparrow", "crow", "seagull", "parrot",
    "chicken", "rooster", "turkey", "kingfisher", "heron", "stork", "pelican", "flamingo",
    "woodpecker", "falcon", "kite", "magpie", "raven", "cormorant",
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

FLOWER_LABELS = [
    "rose", "tulip", "lily", "sunflower", "daisy", "orchid", "lavender",
    "lotus", "cherryBlossom", "hibiscus", "peony", "poppy",
    "jasmine", "magnolia", "camellia", "marigold", "chrysanthemum", "iris",
    "violet", "petunia", "begonia", "carnation", "geranium", "hydrangea",
    "azalea", "rhododendron", "dahlia", "gladiolus", "zinnia", "aster",
    "anemone", "bluebell", "buttercup", "cosmos", "freesia", "gardenia",
    "gerbera", "hyacinth", "lilac", "morningGlory", "narcissus", "pansy",
    "primrose", "ranunculus", "snapdragon", "verbena", "wisteria", "yarrow",
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
    "flower": FLOWER_LABELS,
    "plant": FLOWER_LABELS,
}

LIVING_GATE_LABELS = {"animal", "pet", "bird", "fish", "insect"}
REFINEMENT_GATE_LABELS = {
    "landscape", "building", "architecture", "season", "timeOfDay", "daytime", "night",
    "person", "people", "clothing", "food", "flower", "plant",
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
MAX_COLOR_TAGS = int(os.getenv("VISION_TAGGER_MAX_COLOR_TAGS", "3"))
COLOR_MIN_RATIO = float(os.getenv("VISION_TAGGER_COLOR_MIN_RATIO", "0.07"))
ENABLE_MULTI_VIEW = os.getenv("VISION_TAGGER_ENABLE_MULTI_VIEW", "1").strip().lower() not in {
    "0", "false", "no", "off"
}

COLOR_HSV_BUCKETS = [
    ("red", lambda h, s, v: (h <= 0.04 or h >= 0.96) and s >= 0.20 and v >= 0.20),
    ("orange", lambda h, s, v: 0.04 < h <= 0.10 and s >= 0.22 and v >= 0.22),
    ("yellow", lambda h, s, v: 0.10 < h <= 0.17 and s >= 0.18 and v >= 0.25),
    ("green", lambda h, s, v: 0.17 < h <= 0.45 and s >= 0.16 and v >= 0.16),
    ("blue", lambda h, s, v: 0.45 < h <= 0.70 and s >= 0.16 and v >= 0.16),
    ("purple", lambda h, s, v: 0.70 < h <= 0.82 and s >= 0.18 and v >= 0.15),
    ("pink", lambda h, s, v: 0.82 < h < 0.96 and s >= 0.16 and v >= 0.25),
    ("white", lambda h, s, v: s <= 0.11 and v >= 0.86),
    ("gray", lambda h, s, v: s <= 0.13 and 0.25 <= v < 0.86),
    ("black", lambda h, s, v: v < 0.22),
    ("brown", lambda h, s, v: 0.05 < h <= 0.14 and s >= 0.24 and 0.18 <= v < 0.62),
]


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
        *FLOWER_LABELS,
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


def get_multiview_images(pil: Image.Image) -> list[Image.Image]:
    if not ENABLE_MULTI_VIEW:
        return [pil]

    width, height = pil.size
    views = [pil]
    if width < 320 or height < 320:
        return views

    crop_w = int(width * 0.70)
    crop_h = int(height * 0.70)
    left = max(0, (width - crop_w) // 2)
    top = max(0, (height - crop_h) // 2)
    views.append(pil.crop((left, top, left + crop_w, top + crop_h)))

    return views


def classify_labels(pil: Image.Image, labels, threshold: float, use_multi_view: bool = False):
    images = get_multiview_images(pil) if use_multi_view else [pil]
    best_by_label = {}

    for image in images:
        try:
            result = classifier(image, candidate_labels=labels, multi_label=True)
        except Exception:
            continue

        items = extract_items(result)
        for item in items:
            label = item.get("label")
            score = item.get("score")
            if label is None or score is None:
                continue

            normalized = normalize_tag(label)
            numeric_score = float(score)
            if normalized == "" or numeric_score < threshold:
                continue
            if normalized not in best_by_label or numeric_score > best_by_label[normalized]:
                best_by_label[normalized] = numeric_score

    return list(best_by_label.items())


def detect_color_tags(pil: Image.Image) -> list[tuple[str, float]]:
    reduced = pil.resize((64, 64))
    pixels = list(reduced.getdata())
    total = len(pixels)
    if total == 0:
        return []

    counts = {}
    for red, green, blue in pixels:
        red_norm = red / 255.0
        green_norm = green / 255.0
        blue_norm = blue / 255.0
        max_channel = max(red_norm, green_norm, blue_norm)
        min_channel = min(red_norm, green_norm, blue_norm)
        delta = max_channel - min_channel

        if delta == 0:
            hue = 0.0
        elif max_channel == red_norm:
            hue = ((green_norm - blue_norm) / delta) % 6.0
        elif max_channel == green_norm:
            hue = ((blue_norm - red_norm) / delta) + 2.0
        else:
            hue = ((red_norm - green_norm) / delta) + 4.0

        hue = (hue / 6.0) % 1.0
        saturation = 0.0 if max_channel == 0 else (delta / max_channel)
        value = max_channel

        for name, predicate in COLOR_HSV_BUCKETS:
            if predicate(hue, saturation, value):
                counts[name] = counts.get(name, 0) + 1
                break

    candidates = []
    for name, count in counts.items():
        ratio = count / total
        if ratio >= COLOR_MIN_RATIO:
            candidates.append((name, ratio))

    return sorted(candidates, key=lambda item: item[1], reverse=True)[:MAX_COLOR_TAGS]


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

    tags_with_score = classify_labels(pil, CATEGORY_LABELS, MIN_SCORE, use_multi_view=True)
    tags_with_score.extend(detect_color_tags(pil))

    # Stage 2 for animals.
    living_score = top_score(tags_with_score, LIVING_GATE_LABELS)
    if living_score >= ANIMAL_GATE:
        stage1_lookup = {label: score for label, score in tags_with_score}
        for gate_label, group_labels in REFINEMENT_GROUPS.items():
            if gate_label not in LIVING_GATE_LABELS:
                continue
            if stage1_lookup.get(gate_label, 0.0) < ANIMAL_GATE:
                continue

            group_candidates = classify_labels(pil, group_labels, MIN_SPECIES_SCORE, use_multi_view=True)
            group_candidates = filter_competitive(group_candidates, MIN_SPECIES_SCORE)
            group_candidates = top_labels(group_candidates, MAX_SPECIES_PER_GROUP)
            tags_with_score.extend(group_candidates)

        # Fallback: if only coarse "animal" is confident, also check reptiles/amphibians.
        if stage1_lookup.get("animal", 0.0) >= ANIMAL_GATE and stage1_lookup.get("bird", 0.0) < ANIMAL_GATE:
            reptile_candidates = classify_labels(
                pil, REPTILE_AMPHIBIAN_SPECIES_LABELS, MIN_SPECIES_SCORE, use_multi_view=True
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
        hinted = classify_labels(pil, hints, hint_threshold, use_multi_view=True)
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
