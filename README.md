# AIDesk Chat Center

Independent omnichannel customer support platform for automotive dealer websites, designed for later integration with CRM V2.

## Planned stack
- Laravel, MySQL, Redis, Laravel Reverb
- React, Inertia, TypeScript
- shadcn/ui and ReUI
- Pest and Playwright

## Core scope
- Multi-tenant organizations, showrooms and sites
- Per-site messenger widget builder with versioned draft/published settings
- Secure visitor sessions (name and phone before starting a chat)
- Unified operator inbox, routing, roles and audit
- Extensible channel adapters (Avito, Auto.ru, Telegram and others, subject to API access)
- Versioned integration contract for CRM V2

## Implementation rules
- No production credentials or personal customer data in Git.
- Develop features on branches with pull requests and automated tests.
- The Jivo audit is a functional reference, not a source-code specification.
- AIDesk is a visual reference; use independently implemented and properly licensed components.

Status: project initialization. No application code has been implemented yet.
