# Scripts

Development-only helpers; none of this is part of the inference service, the
packaged wheel, or the test suite.

## `grobid_exploration.py`

Historical GROBID exploration script, kept for reference and manual debugging.
It sends a PDF to GROBID's `processFulltextDocument`, requests `ref` and
`biblStruct` coordinates, and writes the raw TEI plus a JSON dump next to the
working directory.

```bash
cd inference
uv run python scripts/grobid_exploration.py path/to/paper.pdf
```

Requires a reachable GROBID instance (edit `GROBID_URL` in the script). The
production implementation lives in `src/inference/grobid/`; this script is
never imported by it.
