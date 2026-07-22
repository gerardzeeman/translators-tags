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

COPY . .

CMD ["python", "main.py"]
