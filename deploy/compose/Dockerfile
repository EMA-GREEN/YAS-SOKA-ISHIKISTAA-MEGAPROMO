# Binaries are cross-compiled on the host by scripts/test-all.sh with the
# timetzdata tag, so the image needs nothing beyond Alpine.
FROM alpine:3.20
RUN adduser -D -H yas
COPY bin/ /opt/yas/bin/
COPY migrations/ /opt/yas/migrations/
COPY run-stack.sh /opt/yas/run-stack.sh
USER yas
WORKDIR /opt/yas
ENTRYPOINT ["/bin/sh", "/opt/yas/run-stack.sh"]
