# Customer 1.13.1+2017 — private candidate

- Menu sorting by effective price, name and preparation time; mobile layout fixes.
- Table service shows the server's bill totals/payment status and request history.
- Duplicate taps cannot submit concurrent orders. Writes are never auto-replayed.
- One bounded retry for transient read failures and clear network/server messages.
- Pairing and cart are retained on temporary failures; check orders after a timeout.

39 tests pass; analyzer is clean. Android package identity/certificate are unchanged.
Install the matching backend so ordering charges the displayed effective price.
Bill display is not an online payment checkout. Physical-device acceptance remains.
