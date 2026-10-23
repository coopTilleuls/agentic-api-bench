# Egress proxy for the agent container: the agent network is internal, and
# this proxy only lets through the hosts of the stack (filter-<stack>).
FROM alpine:3
ARG STACK=runtime
ENV STACK=$STACK
RUN apk add --no-cache tinyproxy
COPY tinyproxy-*.conf filter-* /etc/tinyproxy/
# --provider alibaba: the model endpoint (Model Studio, Singapore) on top of the stack's hosts.
ARG PROVIDER=anthropic
RUN if [ "$PROVIDER" = alibaba ]; then echo '^dashscope-intl\.aliyuncs\.com$' >>"/etc/tinyproxy/filter-$STACK"; fi
# Documentation host the apiplatform AGENTS.md points at (apiplatform, spec v3).
ARG DOCS=0
RUN if [ "$DOCS" = 1 ]; then echo '^api-platform\.com$' >>"/etc/tinyproxy/filter-$STACK"; fi
EXPOSE 8888
CMD ["sh", "-c", "exec tinyproxy -d -c /etc/tinyproxy/tinyproxy-$STACK.conf"]
