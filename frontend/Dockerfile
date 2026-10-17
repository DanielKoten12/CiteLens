# syntax=docker/dockerfile:1
#
# Vue 3 SPA (CiteLens). The `dev` target serves Vite with HMR and proxies `/api`
# to the Laravel edge; the `prod` target builds the bundle (`vue-tsc -b && vite
# build`) and serves it with nginx, which also reverse-proxies `/api/`.
#
# Build context is the repository root (see compose.yaml), so paths below are
# repository-relative.

# ---------------------------------------------------------------------------
# Development: Vite dev server, sources arrive via bind mount in compose.yaml.
# ---------------------------------------------------------------------------
FROM node:26-alpine AS dev

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./

EXPOSE 5173

CMD ["npm", "run", "dev"]

# ---------------------------------------------------------------------------
# Build: static production bundle.
# ---------------------------------------------------------------------------
FROM node:26-alpine AS build

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./

# Same-origin API base URL by default; nginx (prod target) proxies /api to Laravel.
ARG VITE_API_BASE_URL=/api/v1
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL

RUN npm run build

# ---------------------------------------------------------------------------
# Production: nginx serving the bundle + /api reverse proxy.
# ---------------------------------------------------------------------------
FROM nginx:1.29-alpine AS prod

COPY docker/nginx/frontend.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
