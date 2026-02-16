from fastapi import FastAPI, File, UploadFile
from PIL import Image
from transformers import pipeline

app = FastAPI(title="photo-journal-vision-tagger")

# Free local zero-shot model. First run downloads weights once.
classifier = pipeline(
    task="zero-shot-image-classification",
    model="openai/clip-vit-base-patch32",
)

# Stage 1: broad classes and global scene/color hints.
BASE_LABELS = [
    "bird", "flower", "nature", "macro", "water", "sky", "forest", "meadow", "garden",
    "red", "orange", "yellow", "green", "blue", "purple", "pink", "white", "black", "brown",
    "winter", "spring", "summer", "autumn",
]

# Stage 2: species labels, only if coarse class is confident enough.
BIRD_SPECIES_LABELS = [
    "cormorant", "great cormorant", "crane", "common crane", "grey heron",
    "seagull", "herring gull", "black-headed gull", "crow", "raven",
    "jackdaw", "rook", "sparrow", "house sparrow", "swallow", "owl",
    "duck", "mallard", "swan", "mute swan", "eagle", "kite", "hawk",
    "pigeon", "dove", "stork",
]

FLOWER_SPECIES_LABELS = [
    "flower", "rose", "tulip", "orchid", "lily", "daisy", "sunflower",
    "crocus", "snowdrop", "peony", "dandelion", "violet", "poppy",
]

MIN_SCORE_BASE = 0.22
MIN_SCORE_SPECIES = 0.30
BIRD_GATE = 0.27
FLOWER_GATE = 0.27
MAX_TAGS = 10


def normalize_tag(label: str) -> str:
    return str(label).strip().lower().replace(" ", "-")


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


def top_score(candidates, tag: str) -> float:
    for label, score in candidates:
        if label == tag:
            return score
    return 0.0


@app.get("/health")
def health() -> dict:
    return {"ok": True}


@app.post("/tag")
async def tag_image(image: UploadFile = File(...)) -> dict:
    try:
        pil = Image.open(image.file).convert("RGB")
    except Exception:
        return {"tags": []}

    tags_with_score = []

    # Stage 1
    base = classify_labels(pil, BASE_LABELS, MIN_SCORE_BASE)
    tags_with_score.extend(base)

    # Stage 2 for birds
    bird_score = top_score(base, "bird")
    if bird_score >= BIRD_GATE:
        bird_species = classify_labels(pil, BIRD_SPECIES_LABELS, MIN_SCORE_SPECIES)
        tags_with_score.extend(bird_species)

    # Stage 2 for flowers
    flower_score = top_score(base, "flower")
    if flower_score >= FLOWER_GATE:
        flower_species = classify_labels(pil, FLOWER_SPECIES_LABELS, MIN_SCORE_SPECIES)
        tags_with_score.extend(flower_species)

    # Deduplicate by max confidence per tag.
    best = {}
    for label, score in tags_with_score:
        if label not in best or score > best[label]:
            best[label] = score

    # Sort by confidence and cap count.
    ordered = sorted(best.items(), key=lambda item: item[1], reverse=True)
    tags = [label for label, _ in ordered[:MAX_TAGS]]

    return {"tags": tags}
