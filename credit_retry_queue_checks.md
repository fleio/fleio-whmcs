# Credit retry queue checks

SQL queries (MySQL) for inspecting the `fleio_credit_retry_queue` table.
All queries are read-only unless marked otherwise.

## Jobs whose service no longer exists

These are skipped by the cron with the message "no longer exists" in the activity log.

```sql
SELECT q.id, q.invoice_id, q.service_id, q.status, q.attempts, q.next_attempt_at
FROM fleio_credit_retry_queue q
LEFT JOIN tblhosting h ON h.id = q.service_id
WHERE h.id IS NULL;
```

## Jobs that failed permanently

These reached the maximum number of attempts and are no longer retried.

```sql
SELECT id, invoice_id, service_id, attempts, last_error, updated_at
FROM fleio_credit_retry_queue
WHERE status = 'failed_permanently';
```

## Overview by status

```sql
SELECT status, COUNT(*) AS jobs
FROM fleio_credit_retry_queue
GROUP BY status;
```

## Re-queue a job (modifies data)

Puts a job back in the queue so the next cron run picks it up. Replace `<job_id>`.

```sql
UPDATE fleio_credit_retry_queue
SET status = 'pending', next_attempt_at = NOW(), updated_at = NOW()
WHERE id = <job_id>;
```

## Delete jobs whose service no longer exists (modifies data)

Removes the jobs listed by "Jobs whose service no longer exists". Run that query first to review what will be deleted.

```sql
DELETE q
FROM fleio_credit_retry_queue q
LEFT JOIN tblhosting h ON h.id = q.service_id
WHERE h.id IS NULL;
```
