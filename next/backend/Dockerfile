FROM golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -trimpath -o /tahsin-next ./cmd/server

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /tahsin-next /tahsin-next
ENV TAHSIN_NEXT_ENABLED=0 TAHSIN_NEXT_LISTEN=0.0.0.0:8080
EXPOSE 8080
ENTRYPOINT ["/tahsin-next"]
