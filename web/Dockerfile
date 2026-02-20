FROM node:20-alpine AS build

WORKDIR /build

COPY package.json package-lock.json* ./
RUN npm install --ignore-scripts

COPY index.html ./
COPY js/ js/

FROM nginx:alpine AS runtime

COPY --from=build /build/index.html /usr/share/nginx/html/index.html
COPY --from=build /build/js/ /usr/share/nginx/html/js/
COPY nginx.conf /etc/nginx/conf.d/default.conf
