# Spike harness convenience targets. All isolated under compose project sam_spike.
COMPOSE = docker compose -p sam_spike -f docker-compose.spike.yml

.PHONY: spike-up spike-down spike-ps spike-logs wp-install mc-ls smoke

spike-up:
	$(COMPOSE) up -d

spike-down:
	$(COMPOSE) down -v

spike-ps:
	$(COMPOSE) ps

spike-logs:
	$(COMPOSE) logs --tail=50

# Download wp-cli into the (ephemeral) WP container /tmp and install WordPress.
wp-install:
	$(COMPOSE) exec -T spike-wp bash -lc '\
		command -v wp >/dev/null 2>&1 || { \
		  curl -sSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
		  chmod +x /usr/local/bin/wp; }; \
		wp core install --url=http://localhost:18080 --title=SpikeWP \
		  --admin_user=admin --admin_password=admin --admin_email=admin@example.com \
		  --skip-email --allow-root; \
		wp plugin activate simplead-manager-connector --allow-root || true; \
		wp core version --allow-root'

mc-ls:
	$(COMPOSE) run --rm --entrypoint sh spike-minio-mc -c '\
		mc alias set spike http://spike-minio:9000 spikeadmin spikeadmin123 >/dev/null && \
		mc ls --recursive spike/backups'

smoke:
	$(COMPOSE) ps
	@echo "--- minio ---"; curl -sf http://127.0.0.1:19000/minio/health/ready && echo OK || echo FAIL
	@echo "--- wp ---"; curl -sf -o /dev/null -w '%{http_code}\n' http://127.0.0.1:18080/ || true
