# Test-only browser image, never included in the runtime image or published.
FROM mcr.microsoft.com/playwright:v1.56.1-noble
WORKDIR /probe
RUN npm install --no-save --ignore-scripts --no-audit --no-fund playwright@1.56.1
COPY probe.cjs /probe/probe.cjs
USER pwuser
ENTRYPOINT ["node", "/probe/probe.cjs"]
