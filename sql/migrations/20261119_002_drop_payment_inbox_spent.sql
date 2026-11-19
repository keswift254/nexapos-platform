-- payment_inbox_spent (20261119_001) kept the codes the shop phone paid out, so an M-PESA Jumbe alert for such a code was
-- never taken as a payment received. That was wrong: only a phone the till nominated gets a Jumbe alert, so the money went
-- into the shop's own till even when the same phone paid it - and on 2026-11-19 it threw away the owner's test payment to
-- their own till. Nothing uses the table any more.
DROP TABLE IF EXISTS payment_inbox_spent;
