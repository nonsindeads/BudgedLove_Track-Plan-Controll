# BudgetLove Documentation

This directory contains the maintained project documentation. The GitHub wiki should mirror the same structure, but the repository docs remain the canonical, reviewable source.

## Start Here

- [Developer and deployment setup](guides/developer-setup.md)
- [1.0 roadmap](product/roadmap-1.0.md)
- [Domain model](product/domain-model.md)
- [Cloud mode with Nextcloud and SQLite](cloud/nextcloud-cloud-mode.md)
- [AI integrations and Custom GPT](api/ai-integrations.md)
- [Release QA checklist](qa/release-1.0-checklist.md)

## Product

- [Domain model](product/domain-model.md)
- [Period calculation](product/periods.md)
- [1.0 roadmap](product/roadmap-1.0.md)
- [Wishlist and saving goals](product/wishlist-saving-goals.md)
- [Saving goal storage types](product/saving-goals-storage-types.md)

## Setup And Operations

- [Developer and deployment setup](guides/developer-setup.md)
- [Environment variables](guides/environment.md)
- [Cron setup](guides/cron.md)
- [Reverse proxy setup](guides/proxy.md)
- [Infrastructure split](ops/infra-split.md)

## Cloud Mode

- [Nextcloud cloud mode](cloud/nextcloud-cloud-mode.md)
- [Cloud drives and SQLite plan](cloud/cloud-drives-sqlite-plan.md)
- [Runtime hardening checklist](cloud/runtime-hardening-checklist.md)

## API, GPT And MCP

- [AI integrations overview](api/ai-integrations.md)
- [Custom GPT Actions setup](api/custom-gpt-actions.md)
- [CustomGPT testing guide](api/customgpt-testing-guide.md)
- [MCP testing](api/mcp-testing.md)
- [Public API roadmap](api/public-api-roadmap.md)
- OpenAPI schemas:
  - [Main OpenAPI](api/openapi.yaml)
  - [CustomGPT complete schema](api/customgpt-openapi.yaml)
  - [Daily booking actions](api/customgpt-booking-actions.yaml)
  - [Planning actions](api/customgpt-planning-actions.yaml)
  - [Public OAuth actions draft](api/customgpt-public-oauth-actions.yaml)

## QA

- [Release 1.0 checklist](qa/release-1.0-checklist.md)
- [API split bookings and saving goals checks](qa/api-splits-saving-goals.md)

## Archive

Historical working notes are kept under [archive](archive/). They are not the current source of truth.

## GitHub Wiki Publishing

The intended wiki target is:

`git@github.com:nonsindeads/BudgedLove_Track-Plan-Controll.wiki.git`

If GitHub reports `Repository not found`, initialize or enable the wiki once in the GitHub UI, then push the prepared wiki export:

```bash
git clone git@github.com:nonsindeads/BudgedLove_Track-Plan-Controll.wiki.git /tmp/budgetlove-wiki
cp docs/wiki-export/*.md /tmp/budgetlove-wiki/
cd /tmp/budgetlove-wiki
git add .
git commit -m "docs: publish BudgetLove wiki"
git push origin master
```
