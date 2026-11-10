# API authentication

The API uses Laravel Sanctum personal access tokens, sent as a Bearer header:

```bash
curl https://ai-product-studio.test/api/v1/me \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Accept: application/json"
```

## Create a token

1. Sign in and open **Settings**.
2. Under **API Access**, enter a name, tick the permissions the client needs, and optionally pick an expiration date.
3. Choose **Generate Token** and copy the value. It is shown once.

Only a SHA-256 hash is stored, so a lost token can't be recovered. Revoke it and create a new one.

## Revoke a token

Choose **Revoke** next to it under **API Access**. It stops working immediately.

## Abilities

Give each client only what it needs.

| Ability | Allows |
| --- | --- |
| `products:read` | List and read products |
| `products:write` | Create, update and delete products, and manage external references |
| `content:read` | Read content, read approved content, run SEO analysis |
| `content:write` | Create and replace hand-written content |
| `ai:generate` | Start AI generation and product analysis. This uses your AI quota |
| `ai:read` | Read generation status and results |
| `exports:read` | Create, check and download exports |

A token without the required ability gets:

```json
{
  "message": "This token doesn't have the permission this request needs.",
  "code": "insufficient_ability",
  "required_abilities": ["products:write"]
}
```

## Ownership

Abilities decide what a token may do. Ownership decides what it may do it to. A token only reaches its owner's products, generations and exports, and anything else answers `404`, so the API doesn't reveal which IDs exist.

## Failure responses

| Situation | Response |
| --- | --- |
| No token, or a wrong one | `401 unauthenticated` |
| Revoked or expired token | `401 unauthenticated` |
| A browser session and no token | `401 unauthenticated` |
| Too many failed attempts from one address | `429 rate_limited` |
| Plain HTTP where HTTPS is required | `403 https_required` |

## Handling tokens safely

- Keep tokens in server-side configuration or a secret manager, never in frontend code or a repository.
- New tokens start with `aps_` after their ID, which makes accidental commits easier to find.
- Use an expiration date for temporary integrations.
- Use a separate token per application, so one can be revoked alone.

Expired tokens stay listed for 7 days, then the scheduled `api:prune` command removes them.
