# Официальный образ экспортера собран FROM scratch: внутри только бинарник,
# нет ни shell, ни wget/curl — а значит, нечем выполнить healthcheck.
# Докладываем один статически собранный busybox (вариант musl не тянет за собой libc)
# и вызываем его как wget: busybox выбирает апплет по имени, под которым его запустили
FROM busybox:1.37-musl AS busybox

FROM nginx/nginx-prometheus-exporter:1.5.3
COPY --from=busybox /bin/busybox /bin/wget
