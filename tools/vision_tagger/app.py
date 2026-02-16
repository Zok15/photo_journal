from fastapi import FastAPI, File, UploadFile
from PIL import Image
from transformers import pipeline
import re

app = FastAPI(title="photo-journal-vision-tagger")

# Free local zero-shot model. First run downloads weights once.
classifier = pipeline(
    task="zero-shot-image-classification",
    model="openai/clip-vit-base-patch32",
)

# Stage 1: broad classes and global scene/color hints.
BASE_LABELS = [
    "bird", "flower", "tree", "plant", "leaf", "branch",
    "nature", "macro", "wildlife",
    "water", "sea", "lake", "river", "wetland",
    "sky", "cloud", "sunset", "sunrise",
    "forest", "woodland", "meadow", "field", "garden", "park",
    "mountain", "hill", "shore", "beach", "snow", "ice",
    "red", "orange", "yellow", "green", "blue", "purple", "pink", "white", "black", "brown",
    "gray", "golden", "turquoise",
    "winter", "spring", "summer", "autumn",
]

# Stage 2: species labels, only if coarse class is confident enough.
BIRD_SPECIES_LABELS = [
    # Bird families
    "anatidae", "laridae", "ardeidae", "accipitridae", "falconidae",
    "strigidae", "scolopacidae", "columbidae", "corvidae", "sturnidae",
    "fringillidae", "paridae", "hirundinidae", "podicipedidae", "phalacrocoracidae",
    "picidae", "sylviidae", "motacillidae",
    # Species/common bird labels
    "cormorant", "greatCormorant", "crane", "commonCrane", "sandhillCrane",
    "heron", "greyHeron", "egret",
    "seagull", "gull", "herringGull", "blackHeadedGull",
    "crow", "raven", "jackdaw", "rook", "magpie",
    "sparrow", "houseSparrow", "swallow", "swift", "starling",
    "owl", "eagle", "kite", "hawk", "falcon", "osprey",
    "duck", "mallard", "goose", "swan", "muteSwan", "grebe",
    "pigeon", "dove", "stork", "pelican", "kingfisher", "woodpecker",
]

FLOWER_SPECIES_LABELS = [
    # Flower families
    "asteraceae", "rosaceae", "liliaceae", "orchidaceae", "ranunculaceae",
    "fabaceae", "brassicaceae", "apiaceae", "caryophyllaceae", "iridaceae",
    "amaryllidaceae", "poaceae", "lamiaceae",
    # Species/common flower labels
    "flower", "wildflower",
    "rose", "tulip", "orchid", "lily", "waterLily",
    "daisy", "sunflower", "crocus", "snowdrop", "peony",
    "dandelion", "violet", "poppy", "iris", "lavender",
    "chamomile", "bellflower", "anemone", "lotus",
]

MIN_SCORE_BASE = 0.22
MIN_SCORE_SPECIES = 0.30
BIRD_GATE = 0.27
FLOWER_GATE = 0.27
MAX_TAGS = 10


def to_camel(label: str) -> str:
    value = re.sub(r"([a-z0-9])([A-Z])", r"\1 \2", str(label).strip())
    words = [part.lower() for part in re.split(r"[^A-Za-z0-9]+", value) if part]
    if not words:
        return ""

    return words[0] + "".join(w[:1].upper() + w[1:] for w in words[1:])


def build_tag_alias_map() -> dict:
    aliases = {}
    all_labels = [*BASE_LABELS, *BIRD_SPECIES_LABELS, *FLOWER_SPECIES_LABELS]

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
