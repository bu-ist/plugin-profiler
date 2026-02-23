FROM node:20-alpine
RUN apk add --no-cache docker-cli docker-cli-compose
WORKDIR /app
COPY server.mjs .
CMD ["node", "server.mjs"]
