FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# LatinCy Latin language model. Defaults to the large model (recommended for
# production); override with --build-arg LATINCY_MODEL=la_core_web_sm for a
# faster ~50MB alternative during testing.
#
# The wheel version changes over time (currently 3.9.6) and older docs
# reference a stale "-any-py3-none-any.whl" filename that 404s, so resolve
# the live filename from the HuggingFace API at build time instead of
# hardcoding it.
ARG LATINCY_MODEL=la_core_web_lg
RUN wheel_file=$(python -c "import json, urllib.request; data = json.load(urllib.request.urlopen('https://huggingface.co/api/models/latincy/${LATINCY_MODEL}')); print([s['rfilename'] for s in data['siblings'] if s['rfilename'].endswith('.whl')][0])") && \
    pip install --no-cache-dir \
    "https://huggingface.co/latincy/${LATINCY_MODEL}/resolve/main/${wheel_file}"
ENV LATINCY_MODEL=${LATINCY_MODEL}

# OdyCy Ancient Greek model, for scripts/tokenize_greek_quotes.py -- Calvin
# and the confession authors occasionally quote Greek within the Latin
# text (e.g. Institutio, NGB art. 10), and LatinCy (a Latin-only model)
# can't lemmatize that: it just passes the surface form through mostly
# unchanged, so an inflected form like "υποστασεως" ends up as its own
# "lemma" instead of resolving to the dictionary form "υπόστασις". OdyCy
# is the Greek-language counterpart, same install pattern as LatinCy
# above. Small (non-transformer) variant -- no torch/CUDA dependency, and
# this only ever needs to run on dev anyway (see docs/data-sync.md).
# This model's own wheel filename literally has "-any-" where the version
# normally goes (grc_odycy_joint_sm-any-py3-none-any.whl), which recent
# pip rejects as an invalid PEP 440 version -- unlike LatinCy's properly
# versioned wheels above. Download it and rename with its real version
# (from its own meta.json) before installing from the local file.
RUN wheel_file=$(python -c "import json, urllib.request; data = json.load(urllib.request.urlopen('https://huggingface.co/api/models/chcaa/grc_odycy_joint_sm')); print([s['rfilename'] for s in data['siblings'] if s['rfilename'].endswith('.whl')][0])") && \
    version=$(python -c "import json, urllib.request; print(json.load(urllib.request.urlopen('https://huggingface.co/chcaa/grc_odycy_joint_sm/resolve/main/meta.json'))['version'])") && \
    python -c "import urllib.request; urllib.request.urlretrieve('https://huggingface.co/chcaa/grc_odycy_joint_sm/resolve/main/${wheel_file}', '/tmp/grc_odycy_joint_sm-${version}-py3-none-any.whl')" && \
    pip install --no-cache-dir "/tmp/grc_odycy_joint_sm-${version}-py3-none-any.whl" && \
    rm "/tmp/grc_odycy_joint_sm-${version}-py3-none-any.whl"

COPY . .

CMD ["python", "main.py"]
