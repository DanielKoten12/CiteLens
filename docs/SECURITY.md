# Security — Dafpus Cek

This document defines the security model, invariants and operational rules for the product. It is
derived from `AGENTS.md` §7/§13/§17, `docs/API_SPEC.md` §2 and the capstone proposal.

Security defects here are **data leaks or integrity failures**, not cosmetic issues. Treat every
invariant below as mandatory.

---

## 1. Assets and threat model

### 1.1 Assets

| Asset | Sensitivity |
|---|---|
| User accounts (email, password hash) | High |
| Uploaded documents (theses/drafts) | High — unpublished, personal |
| Extracted references/citations + locations | High — derivative of the document |
| Generated reports | High |
| API tokens | High |
| External API credentials (Crossref) | High |
| Inference service (GROBID/SBERT) | Internal, availability-sensitive |

### 1.2 Trust boundaries

```text
Browser (untrusted)  ──HTTPS──▶  Laravel API (trusted)  ──private net──▶  FastAPI inference
                                        │
                                        └──outbound──▶ Crossref
```

- The browser is untrusted. Never trust client-supplied ids, ownership claims or verdicts.
- The inference service is internal and has no authentication in v1; it must **not** be reachable
  from the internet.
- Crossref is external and independent; Laravel is the only component that talks to it.

### 1.3 Primary threats

| Threat | Mitigation |
|---|---|
| Cross-user data access (IDOR) | Ownership scoping (§2) |
| Existence disclosure via `403` vs `404` | Always `404` for foreign resources (§3) |
| Malicious/large uploads | PDF-only, 20 MB cap, validation (§4) |
| Credential stuffing / brute force | Rate limits (§5) |
| Secret leakage via repo | Env/config only, git-ignored `.env` (§6) |
| Internal service exposure | Private network; no public FastAPI endpoints (§7) |
| Verdict tampering | Manual review audited; candidate must belong to finding (§8) |
| Injection via document text | Treat extracted text as data, never as code/SQL |

---

## 2. Authentication and authorization

- **Scheme:** Laravel Sanctum personal access tokens, sent as `Authorization: Bearer <token>`.
- **Issuance:** on register and login. **Revocation:** on logout (current token).
- **Public endpoints:** only `POST /auth/register` and `POST /auth/login`.
- Every other endpoint requires a valid token; missing/expired/invalid → `401 UNAUTHENTICATED`.

### 2.1 Ownership invariant (MANDATORY)

> A resource is accessible **only** to the owner of its parent `researched_document`.

- If the caller does not own the parent document, return **`404 NOT_FOUND`** — never `403`, never
  a message that confirms the resource exists.
- Every query touching document-owned data (references, citations, locations, findings,
  candidates, reports, files) must be scoped through the authenticated user, e.g.:

```text
Reference → researched_document → user_id = auth()->id()
Report    → researched_document → user_id = auth()->id()
```

- Prefer route-model binding **plus** an ownership scope over post-hoc checks, so an unscoped
  query can never leak.

This applies to **every new endpoint** and is a release blocker if violated.

---

## 3. Error handling and non-disclosure

All non-2xx responses use the canonical envelope:

```json
{ "error": { "code": "...", "message": "...", "details": { } } }
```

| HTTP | Code | Notes |
|---|---|---|
| 400 | `BAD_REQUEST` | Malformed request |
| 401 | `UNAUTHENTICATED` | Do not reveal whether an account exists |
| 403 | `FORBIDDEN` | Reserved; do **not** use for foreign resources |
| 404 | `NOT_FOUND` | Unknown id **or not owned by caller** |
| 409 | `CONFLICT` | Invalid state transition |
| 413 | `PAYLOAD_TOO_LARGE` | > 20 MB |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Non-PDF |
| 422 | `VALIDATION_ERROR` | Field validation |
| 429 | `RATE_LIMITED` | Throttled |
| 500 | `SERVER_ERROR` | Never include stack traces / internal paths |
| 503 | `INFERENCE_UNAVAILABLE` | GROBID/SBERT unreachable |

Rules:

- Never echo internal exception messages, SQL, file paths or stack traces to clients.
- Login failure must not distinguish "unknown email" from "wrong password".
- Unhandled errors are logged server-side; the client gets a generic `SERVER_ERROR`.

---

## 4. Input validation

### 4.1 Upload (`POST /documents`)

| Rule | Value | Failure |
|---|---|---|
| Type | PDF (`mimes:pdf` + `mimetypes:application/pdf`) | `415 UNSUPPORTED_MEDIA_TYPE` |
| Size | ≤ 20 MB (20480 KB) | `413 PAYLOAD_TOO_LARGE` |
| Name | optional, string, ≤ 255 | `422 VALIDATION_ERROR` |

- Validate **both** extension and MIME type; do not trust the client-provided filename.
- Reject silently-unsupported input rather than coercing it.
- Uploaded files are stored on a **private** disk under a generated path; filename collisions are
  handled by `hashName()`. Never serve stored files by direct public path.

### 4.2 Other inputs

- IDs must be UUID v4; malformed ids → `404`/`422` per endpoint contract.
- Enum fields (`status`, filters) are validated against the canonical sets (`API_SPEC.md` §2.6);
  invalid values → `422`.
- Manual review `selected_candidate_id` must belong to the finding being edited.
- Citation pairing `researched_document_reference_id` must belong to the **same document**.
- Pagination: `per_page` default 15, max 100.

### 4.3 Document-derived text

Extracted text, titles, author strings and DOIs come from untrusted input. They must be:

- parameterised (never concatenated into SQL/commands),
- escaped when rendered in the SPA (Vue escapes by default — avoid `v-html` with extracted text),
- length/format-validated before persistence where the schema implies a bound.

---

## 5. Rate limiting

Implement and preserve the documented limits (`API_SPEC.md` §2.9). Relax them via
configuration/environment for development — never by editing the production contract.

| Scope | Limit |
|---|---|
| `POST /auth/login`, `POST /auth/register` | 5 / minute / IP |
| `POST /documents` | 10 / minute / user |
| `GET /documents/{id}/status` | 120 / minute / user |
| General authenticated endpoints | 60 / minute / user |

Over-limit → `429 RATE_LIMITED` with the standard envelope. Do not remove throttling to make local
development easier.

---

## 6. Secrets and configuration

- `backend/.env` is git-ignored. **Never** read it into code, docs, tests or fixtures.
- Never commit credentials, API keys, tokens or passwords to any file, including `.env.example`,
  tests and this document.
- External credentials belong in `config/services.php` + `.env.example` placeholders, e.g.
  `CROSSREF_API_KEY=<configured through environment>`.
- The frontend must never receive Crossref credentials or any server secret.
- Rotate any secret that was ever committed.

---

## 7. Internal service isolation

- `inference/` (FastAPI + GROBID + SBERT) is an **internal inference server**, reachable only by
  Laravel queue workers on a private network.
- **Gotenberg** (HTML→PDF rendering for reports) is the same kind of internal service: private
  network only, called exclusively by the report job through the `ReportRenderer` seam. The report
  HTML is generated server-side from a Blade template (escaped extracted text only) and is never
  rendered in a browser or returned to the client; only the finished PDF bytes are stored.
- Neither service may be exposed on the public internet or called from the browser.
- No public, frontend-facing FastAPI endpoints may be added unless `docs/API_SPEC.md` is
  deliberately changed.
- Inference endpoints accept only the documented internal shapes (`/health`, `/v1/extract`,
  `/v1/embeddings`) and reject other input.
- GROBID/SBERT availability failures degrade to `503 INFERENCE_UNAVAILABLE`; report rendering
  failures mark the report `failed` with a safe `error` (no raw exception). Neither may crash the
  API or leak internal errors.

---

## 8. Data protection and lifecycle

- **In transit:** HTTPS for all public traffic; private network for inference.
- **At rest:** uploaded documents and reports live on private disks (documents on
  `filesystems.default`, report PDFs on `reports.disk`); every `files` row records the disk it lives
  on and access is via time-limited signed/temporary URLs, never a public path.
- **Deletion:** hard delete with cascade (documents → references, citations, locations, findings,
  candidates, files, reports). Users may delete one document or purge their whole history.
- **Data minimisation:** store only what the schema requires; do not persist raw page text beyond
  what the pipeline needs.
- **Passwords:** hashed (Laravel default bcrypt); never logged.

---

## 9. Auditability and integrity

- Manual review sets `is_manual = true` and stamps `reviewed_by` + `reviewed_at`; never bypass
  this path to change a verdict.
- Reference confidence and reasons must be derivable from stored candidates
  (`match_reason`), so a verdict can be explained after the fact.
- Derived citation status must be computed in one place; do not allow divergent copies that could
  disagree about whether a citation is `hallucination`.

---

## 10. Logging

- Log server-side errors with enough context to debug, but **never** log passwords, tokens, full
  document text or personal data beyond what is operationally necessary.
- Do not include secrets or signed URLs in logs.
- Client-facing error messages remain generic (§3).

---

## 11. Security checklist (per change)

1. New endpoints authenticate except the two documented public ones.
2. Every document-owned query is scoped to `auth()->id()`; foreign access returns `404`.
3. Validation covers type, size, enum and ownership; failures use the canonical envelope.
4. Rate limits are applied per the table in §5.
5. No secrets committed; `.env` untouched and ignored.
6. Internal services remain private; the frontend never calls them.
7. Uploaded/extracted text is treated as untrusted data.
8. No stack traces or internal paths reach the client.
9. Deletions cascade and do not leave orphaned files or rows.
10. Manual-review audit fields are always set.
