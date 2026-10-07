# ZAP mit den zusätzlichen Beta-/Alpha-Regelpaketen und OAST.
# Bauen:  docker build -t gastrobook-zap:deep security/zap
#
# Die Beta/Alpha-Regeln bringen die Breite (mehr Schwachstellenklassen); die
# Scan-Stärke bleibt im Plan bei "high", weil "insane" vor allem die
# Varianten-Menge aufbläht und ZAPs eingebettete Session-DB sprengt.
FROM ghcr.io/zaproxy/zaproxy:2.17.0
USER zap
RUN /zap/zap.sh -cmd -silent \
      -addoninstall ascanrulesBeta -addoninstall ascanrulesAlpha \
      -addoninstall pscanrulesBeta -addoninstall pscanrulesAlpha \
      -addoninstall oast || true
