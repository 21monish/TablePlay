import 'package:flutter/material.dart';
import '../theme/tableplay_theme.dart';

/// Display only the paired table's server snapshot. Never settle payments or
/// reconstruct a bill from cached menu prices on the customer device.
class TableSummary extends StatelessWidget {
  const TableSummary({super.key, required this.session});
  final Map<String, dynamic> session;

  String money(dynamic value) =>
      'INR ${(double.tryParse('$value') ?? 0).toStringAsFixed(2)}';

  @override
  Widget build(BuildContext context) {
    final bill = session['bill'] as Map?;
    final requests = (session['service_requests'] as List? ?? [])
        .whereType<Map>()
        .toList()
      ..sort(
          (a, b) => ((b['id'] as num?) ?? 0).compareTo((a['id'] as num?) ?? 0));
    Widget amount(String label, dynamic value) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 5),
          child: Row(children: [
            Expanded(child: Text(label)),
            Text(money(value),
                style: const TextStyle(fontWeight: FontWeight.w800))
          ]),
        );
    return Card(
        child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('Your bill',
                    style: Theme.of(context).textTheme.titleLarge),
                const SizedBox(height: 8),
                if (bill == null)
                  const Text(
                      'The counter has not generated your bill yet. Request it below when you are ready.',
                      style: TextStyle(color: TablePlayColors.muted))
                else ...[
                  Text('${bill['bill_number'] ?? 'Table bill'}',
                      style: const TextStyle(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 5),
                  Text('Payment: ${bill['payment_status'] ?? 'unpaid'}'),
                  const Divider(),
                  amount('Subtotal', bill['subtotal']),
                  amount('Tax', bill['tax_amount']),
                  amount('Discount', bill['discount_amount']),
                  const Divider(),
          amount('Bill total', bill['grand_total']),
                  const Text(
                      'Pay at the counter or with your waiter. This screen does not collect an online payment.',
                      style: TextStyle(
                          color: TablePlayColors.muted, fontSize: 12)),
                ],
                if (requests.isNotEmpty) ...[
                  const SizedBox(height: 20),
                  Text('Recent table requests',
                      style: Theme.of(context).textTheme.titleMedium),
                  ...requests.take(8).map((request) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(request['status'] == 'completed'
                            ? Icons.check_circle_outline
                            : Icons.hourglass_empty_rounded),
                        title: Text(switch (request['request_type']) {
                          'call_waiter' => 'Waiter assistance',
                          'water' => 'Water',
                          'bill' => 'Bill request',
                          _ => 'Table assistance',
                        }),
                        subtitle: Text('${request['status'] ?? 'pending'}'),
                      )),
                ],
              ],
            )));
  }
}
