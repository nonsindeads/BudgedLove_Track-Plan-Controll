# Reverse Proxy (Caddy) Setup

This project is designed to run behind a central reverse proxy (recommended) that
terminates HTTPS and routes subdomains to individual stacks.

## 1) Symlink for Dockge
Dockge expects the stack folder to contain a `docker-compose.yml`.
Point it to the `compose/` directory:

```bash
ln -s /root/projects/BudgetLove_Track-Plan-Controll/compose /opt/stacks/budgetlove
```

## 2) Create a shared proxy network
```bash
docker network create proxy
```

## 3) Central proxy stack
Preferred: run the proxy directly from this repo:

```bash
cd /root/projects/BudgetLove_Track-Plan-Controll/compose
docker compose -f docker-compose.caddy.yml up -d
```

Alternative: a standalone stack (example):

```yaml
services:
  caddy:
    image: caddy:2.8-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - /root/projects/BudgetLove_Track-Plan-Controll/landing:/srv/landing:ro
      - caddy_data:/data
      - caddy_config:/config
    networks:
      - proxy

volumes:
  caddy_data:
  caddy_config:

networks:
  proxy:
    external: true
```

Create `/opt/stacks/proxy/Caddyfile`:

```caddy
{
  email admin@budgetlove.de
}

budgetlove.de {
  root * /srv/landing
  file_server
}

app.budgetlove.de {
  @ws {
    path /ws /ws/*
  }
  reverse_proxy @ws hb_ws:8081
  reverse_proxy hb_web:80
}

dockge.budgetlove.de {
  reverse_proxy dockge:5001
}

# Optional services
gitlab.budgetlove.de {
  reverse_proxy gitlab:80
}

pihole.budgetlove.de {
  reverse_proxy pihole:80
}
```

Start the proxy:
```bash
cd /opt/stacks/proxy
docker compose up -d
```

## 4) Run BudgetLove behind the proxy
Use the proxy overlay to attach to the shared network and disable local ports:

```bash
cd /root/projects/BudgetLove_Track-Plan-Controll/compose
docker compose -f docker-compose.yml -f docker-compose.proxy.yml up -d --build
```

## 5) DNS
Create `A`/`AAAA` records pointing to your server IP:
- `budgetlove.de`
- `app.budgetlove.de`
- `dockge.budgetlove.de`
- `gitlab.budgetlove.de`
- `pihole.budgetlove.de`

Ensure ports 80/443 are open on the server.
