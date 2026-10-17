# syntax=docker/dockerfile:1.7

FROM node:22-bookworm-slim AS build
WORKDIR /app

COPY package.json ./
RUN npm install --ignore-scripts --no-audit --no-fund

COPY tsconfig.json ./
COPY src ./src
COPY test ./test

RUN npm run typecheck \
 && npm test \
 && npm run build \
 && npm prune --omit=dev

FROM node:22-bookworm-slim AS runtime
ENV NODE_ENV=production \
    MCP_BIND_HOST=0.0.0.0 \
    MCP_PORT=8787
WORKDIR /app

RUN groupadd --system --gid 10001 mcp \
 && useradd --system --uid 10001 --gid mcp --home-dir /app --shell /usr/sbin/nologin mcp

COPY --from=build --chown=mcp:mcp /app/package.json ./package.json
COPY --from=build --chown=mcp:mcp /app/node_modules ./node_modules
COPY --from=build --chown=mcp:mcp /app/dist/src ./dist/src

USER mcp
EXPOSE 8787

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD node -e "const p=process.env.MCP_PORT||'8787';fetch('http://127.0.0.1:'+p+'/healthz').then(r=>{if(!r.ok)process.exit(1)}).catch(()=>process.exit(1))"

CMD ["node", "dist/src/http.js"]
