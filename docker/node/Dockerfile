# syntax=docker/dockerfile:1
###############################################################################
# SchoolFlow frontend image (Next.js 15 / Node 22)
###############################################################################

FROM node:22-alpine AS base
RUN apk add --no-cache libc6-compat
WORKDIR /app

# ---------------------------------------------------------------- development
FROM base AS development
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install
COPY frontend/ ./
EXPOSE 3000
CMD ["sh", "-c", "npm install --no-audit --no-fund && npm run dev"]

# --------------------------------------------------------------------- build
FROM base AS builder
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci
COPY frontend/ ./
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build

# ---------------------------------------------------------------- production
FROM base AS production
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1
RUN addgroup -g 1001 -S nodejs && adduser -S nextjs -u 1001
COPY --from=builder /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static
USER nextjs
EXPOSE 3000
CMD ["node", "server.js"]
