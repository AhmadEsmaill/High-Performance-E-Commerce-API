---
name: project-context
description: Laravel 13 e-commerce RESTful API for parallel programming college course 2026
metadata:
  type: project
---

College project: "High-Performance E-Commerce Backend Engine" for Parallel Programming course 2026.

**Why:** Demonstrate 3 non-functional requirements (NFRs) under high concurrency, not just CRUD features.

**Stack:** Laravel 13, MySQL, Sanctum auth, database queue driver.

**NFR #1 — Race Condition Prevention:** `DB::transaction()` + `lockForUpdate()` (pessimistic locking) in `OrderService::placeOrder()`. Proven: 3 concurrent users buying last item → only 1 succeeds, stock never goes negative.

**NFR #2 — Resource Management:** `ConcurrencyLimiter` middleware (cache-based semaphore) on `POST /api/orders` (limit=5). Built-in Laravel throttle (60 req/min) on all API routes.

**NFR #3 — Async Queues:** `GenerateInvoiceJob`, `SendOrderNotificationJob`, `NotifyLowStockJob` dispatched after order placement. Queue driver: database. Run with `php artisan queue:work`.

**How to apply:** Any new features must keep the 3 NFRs intact. Checkout endpoint stays under ConcurrencyLimiter. Any stock modification must use `lockForUpdate()` inside a transaction.
