# BudgetLove Public API – Roadmap & Public Release Plan

---

## 📋 Current State (v0.29.0)

**Scope:** Private/Single-Household CustomGPT API
- ✅ Transactions (CRUD)
- ✅ Metadata (Read)
- ✅ Payees & Tags (CRUD)
- ✅ Planned Payments (Read)
- ✅ Open Cases (Read)
- ✅ Analytics (Read: summaries, duplicates)
- ✅ Recurring Rules (Read)

**Authentication:** Fixed API token per household (Bearer token)

**Docs:** OpenAPI 0.29.0, custom GPT actions ready

---

## 🚀 Phase 4: Write Operations (Medium Priority)

These extend existing endpoints with create/update/delete for planning & recurring objects.

### 4.1 Planned Payments (CRUD)
**Endpoint:** `POST /api/planned_payments.php`, `PATCH`, `DELETE`

```
Scope: Allow GPT to create & manage financial plans
- POST: createBudgetLovePlannedPayment (amount, date, status, priority)
- PATCH: updateBudgetLovePlannedPayment
- DELETE: resolveBudgetLovePlannedPayment (mark as resolved/cancelled)
```

**Why:** GPT can now help plan expenses, adjust forecasts, mark paid bills.

**Effort:** Medium – add to schema, implement PHP logic, add test prompts

### 4.2 Recurring Rules (CRUD)
**Endpoint:** `POST /api/recurring_rules.php`, `PATCH`, `DELETE`

```
Scope: Create & modify recurring transaction schedules
- POST: createBudgetLoveRecurringRule (name, kind, schedule)
- PATCH: updateBudgetLoveRecurringRule
- DELETE: deactivateRecurringRule
```

**Why:** GPT can set up salary income, rent, subscriptions directly.

**Effort:** Medium-High – schedule parsing complexity (weekly/monthly/yearly logic)

### 4.3 Open Cases (CRUD)
**Endpoint:** `POST /api/open_cases.php`, `PATCH`, `DELETE`

```
Scope: Track & manage outstanding items
- POST: createBudgetLoveOpenCase (title, contact, notes)
- PATCH: updateBudgetLoveOpenCase
- DELETE: resolveOpenCase
```

**Why:** GPT can create follow-up reminders, claims, refund tracking.

**Effort:** Low – straightforward CRUD

---

## 🏦 Phase 5: Budgets & Goals (Advanced Analytics)

New analytical endpoints for financial planning.

### 5.1 Budget Summary
**Endpoint:** `GET /api/budgets.php`

```
Response: List all budgets with current vs. budgeted amounts
- budget_id, category_id, period, budgeted_amount, spent_amount, remaining, status
- Filter by: category, period, status (on-track, exceeded, unbudgeted)
```

**Why:** GPT can warn "You've spent 85% of your grocery budget this month"

**Effort:** Low – mostly SQL queries on existing data

### 5.2 Spending Trends
**Endpoint:** `GET /api/analytics.php?endpoint=trends`

```
Response: Historical spending patterns by category/payee
- category, avg_monthly, min, max, trend (up/down), forecast_next_month
- Period: last 3, 6, 12 months
```

**Why:** Identify spending increases, recommend budget adjustments, detect anomalies.

**Effort:** Medium – requires historical aggregation, trend analysis

### 5.3 Savings Tracker
**Endpoint:** `GET /api/analytics.php?endpoint=savings`

```
Response: Savings rate, goals, progress
- total_income, total_expense, net_savings, savings_rate_pct, goal_remaining
```

**Why:** Help users track financial goals, celebrate progress, adjust plans.

**Effort:** Low – derived from existing transaction data

---

## 🔒 Phase 6: Public API Requirements

### 6.1 Multi-Tenant Infrastructure

**Current (Private):**
```
Token → Single Household → All Data
```

**Target (Public):**
```
User Account → OAuth Token → Household Selection → Scoped Data Access
```

**Implementation:**

1. **User Accounts & OAuth** (Required)
   - `/api/auth/register` – Create user account
   - `/api/auth/login` – OAuth 2.0 flow (Google, GitHub, or in-app)
   - `/api/auth/refresh` – Refresh JWT tokens
   - JWT tokens (short-lived) + refresh tokens (long-lived)

2. **Token Management** (Required)
   - `/api/tokens` – User can create/revoke API tokens
   - Token scopes: `transactions:read`, `transactions:write`, `analytics:read`, etc.
   - Rate limiting per token: 100 req/min default

3. **Household Isolation** (Critical)
   - Every API call enforces `token.user_id → household_id` validation
   - User can belong to multiple households (family sharing)
   - JWT payload includes `household_id` claim
   - Database queries always filter by `WHERE household_id = ?`

4. **Audit Logging** (Recommended)
   - `/api/audit_logs` – Log all API calls (read/write)
   - Store: timestamp, user_id, household_id, operation, changes
   - 90-day retention for compliance

### 6.2 API Security Hardening

1. **Rate Limiting**
   ```
   - Authenticated: 100 req/min per token
   - Unauthenticated: 10 req/min per IP
   - Burst: 20 req/10sec (prevent hammering)
   - Backoff: 429 with Retry-After header
   ```

2. **CORS & HTTPS**
   ```
   - HTTPS only (no HTTP)
   - CORS: whitelist trusted origins
   - Secure cookies: SameSite=Strict, HttpOnly
   ```

3. **Input Validation**
   ```
   - All numeric inputs: min/max bounds
   - All string inputs: max length, character whitelist
   - Date inputs: must be valid ISO 8601
   - Amounts: ≤ 999,999.99 EUR (prevent overflow)
   ```

4. **Data Exposure Prevention**
   ```
   - Mask sensitive fields in error messages
   - Never leak IDs of other households
   - Paginate large results (default 50, max 1000)
   - Soft-delete (archived) instead of hard-delete
   ```

---

## 🎯 Priority & Timeline Recommendation

### ✅ Phase 0: Live (Current – v0.29.0)
**Status:** Ready for private/single-household testing
- Single fixed API token per household
- All read operations working
- Transaction CRUD working
- GPT testing prompt ready

**Action:** Run CUSTOMGPT_TESTING_GUIDE.md tests, gather feedback

---

### 📅 Phase 1: Private Polish (1–2 weeks)
**Decision:** Ship as private CustomGPT first to validate demand

**Action Items:**
- ✅ Run GPT testing guide → fix any bugs
- ⏳ Add Phase 4.1 (Planned Payments CRUD) – high-value feature
- ⏳ Improve error messages & edge case handling
- ⏳ Documentation: Add API rate limits, error codes, examples

**Deliverable:** Stable v0.30.0 for closed beta

---

### 📅 Phase 2: Public Prep (2–4 weeks)
**Decision:** Prepare infrastructure for public launch

**Action Items:**
- 🔓 Implement OAuth (Google/GitHub or in-app)
- 🔓 Build user account system
- 🔓 JWT token generation & validation
- 🔓 Household isolation logic (critical security review)
- 🔓 Rate limiting middleware
- 🔓 Audit logging
- 🔓 API documentation site (swagger.budgetlove.de)

**Deliverable:** Security review + public beta (limited invite)

**Dependencies:**
- User table schema (email, password_hash, oauth_providers)
- JWT library integration
- Rate limiting library (Redis)
- Audit log table

---

### 📅 Phase 3: Public Launch (Ongoing)
**Decision:** Open API to registered users with OAuth

**Action Items:**
- 📢 Announce public API with pricing/tiers
- 📢 Publish SDKs (Python, JavaScript, etc.)
- 📢 Launch API marketplace / ecosystem
- 📢 Community feedback loop & roadmap

**Tiers (Example):**
- **Free:** 100 req/month, read-only
- **Pro:** 10k req/month, write access (€4.99/mo)
- **Enterprise:** Unlimited, webhooks, SLA (contact sales)

---

## 🛠️ Technical Optimizations (All Phases)

### Performance
1. **Database Indexing**
   ```sql
   CREATE INDEX idx_transactions_household_date ON transactions(household_id, booking_date);
   CREATE INDEX idx_planned_household_status ON planned_payments(household_id, status);
   CREATE INDEX idx_recurring_household_active ON recurring_rules(household_id, is_active);
   ```

2. **Query Optimization**
   - Avoid N+1 queries (batch joins for payee/category names)
   - Use SELECT only needed columns
   - Pagination: always limit to 1000 max

3. **Caching**
   ```
   - Redis cache for metadata (categories, accounts, payees, tags)
   - TTL: 1 hour (metadata changes rarely)
   - Invalidate on write operations
   ```

4. **Response Compression**
   ```
   - Enable gzip compression for all endpoints
   - Typical: 70% size reduction for JSON
   ```

### Reliability
1. **Error Handling**
   - All 4xx/5xx errors return standard format:
     ```json
     {"error": "readable message", "code": "ERROR_CODE", "timestamp": "2026-05-15T10:30:00Z"}
     ```
   - Include request ID for debugging

2. **Monitoring & Alerts**
   - Track error rate (alert if >5% 4xx/5xx)
   - Track response times (alert if p95 > 500ms)
   - Track API token usage (alert on quota exceeded)

3. **Backwards Compatibility**
   - API version in URL (v1, v2) once public
   - Deprecation notice 6 months before breaking changes
   - Support 2 major versions simultaneously

### Documentation
1. **OpenAPI / Swagger**
   - Auto-generate from schema
   - Include examples for each endpoint
   - Interactive "Try it out" button

2. **Developer Portal**
   - Getting started guide
   - Authentication flows
   - Code samples (cURL, Python, JS)
   - Common use cases & recipes

3. **Changelog**
   - Version-by-version changes
   - Breaking changes clearly marked
   - Migration guides

---

## 🔐 Security Checklist for Public API

- [ ] OAuth 2.0 implemented (Google, GitHub, or in-app)
- [ ] JWT tokens with household_id claim
- [ ] All endpoints validate household isolation
- [ ] Rate limiting active (100 req/min authenticated)
- [ ] HTTPS enforced (no HTTP)
- [ ] CORS configured with trusted origins only
- [ ] Input validation on all endpoints
- [ ] SQL injection prevention (prepared statements)
- [ ] XSS prevention (sanitize error messages)
- [ ] CSRF protection (if cookies used)
- [ ] Audit logging for all write operations
- [ ] Secrets not logged (passwords, tokens)
- [ ] API keys rotatable
- [ ] Data encryption at rest (if PII stored)
- [ ] Penetration test completed
- [ ] GDPR/Privacy policy updated
- [ ] Terms of Service for API usage
- [ ] SLA & uptime guarantees documented

---

## 📊 Success Metrics (Post-Launch)

- API uptime ≥ 99.9%
- Response time p95 < 500ms
- Error rate < 1%
- Adoption: 100+ API token users within 6 months
- NPS score from API users ≥ 50
- Community SDK contributions (Python, JS, Ruby, etc.)

---

## Summary: Recommendation for BudgetLove

### 🎯 **Immediate (This Week)**
1. ✅ Test v0.29.0 with CUSTOMGPT_TESTING_GUIDE.md
2. ✅ Commit test guide to repo
3. ⏳ Fix any bugs found in testing

### 🚀 **Short-term (1–2 Weeks) – "Private MVP"**
1. Add Planned Payments CRUD (high-value feature)
2. Improve error handling & validation
3. Ship v0.30.0 as closed beta

### 🔓 **Medium-term (2–4 Weeks) – "Public Prep"**
1. Implement OAuth & user accounts
2. Add JWT token system with household isolation
3. Security audit & penetration test
4. Publish API docs

### 📢 **Long-term (Month 2+) – "Public API"**
1. Launch public API with free tier
2. Announce in docs/marketing
3. Community feedback loop
4. Roadmap: Phase 4–5 endpoints based on demand

---

**Bottom Line:** BudgetLove has a **strong private API foundation** (v0.29.0). Before going public, **mandatory:** OAuth + household isolation (✅ in this roadmap). This prevents data leaks and scales to thousands of users safely.
