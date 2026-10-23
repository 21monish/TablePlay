import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_tablet/features/customer_menu.dart';
import 'package:tableplay_tablet/features/table_summary.dart';
import 'package:tableplay_tablet/theme/tableplay_theme.dart';

void main() {
  testWidgets('bill and request history use only server snapshot values', (tester) async {
    await tester.pumpWidget(MaterialApp(theme: tablePlayTheme(), home: Scaffold(body: SingleChildScrollView(child: TableSummary(session: {
      'bill': {'bill_number': 'BILL-123', 'subtotal': '100.00', 'tax_amount': '5.00', 'discount_amount': '10.00', 'grand_total': '95.00', 'payment_status': 'unpaid'},
      'service_requests': [{'id': 1, 'request_type': 'water', 'status': 'completed'}, {'id': 2, 'request_type': 'bill', 'status': 'pending'}],
    })))));
    expect(find.text('BILL-123'), findsOneWidget);
    expect(find.text('INR 95.00'), findsOneWidget);
    expect(find.text('Payment: unpaid'), findsOneWidget);
    expect(find.text('Water'), findsOneWidget);
    expect(find.text('Bill request'), findsOneWidget);
    expect(find.textContaining('does not collect an online payment'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('no bill is not presented as a zero or paid bill', (tester) async {
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: TableSummary(session: const {}))));
    expect(find.textContaining('has not generated your bill'), findsOneWidget);
    expect(find.textContaining('INR 0'), findsNothing);
    expect(find.textContaining('Payment:'), findsNothing);
  });

  for (final width in [360.0, 900.0]) {
    testWidgets('menu sorting and filtering fit width $width', (tester) async {
      tester.view.reset();
      tester.view.physicalSize = Size(width, 1100);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final categories = [{'id': 1, 'name': 'Mains', 'menu_items': [
        {'id': 1, 'name': 'Premium', 'price': '200', 'effective_price': '150', 'food_type': 'veg', 'preparation_minutes': 10},
        {'id': 2, 'name': 'Budget', 'price': '80', 'effective_price': '80', 'food_type': 'veg', 'preparation_minutes': 5},
        {'id': 3, 'name': 'Unavailable', 'price': '50', 'is_available': false},
      ]}];
      await tester.pumpWidget(MaterialApp(theme: tablePlayTheme(), home: Scaffold(body: CustomerMenuView(categories: categories, cartCount: 0, cartTotal: 0, onOpenCart: () {}, onAdd: (item, instruction) {}))));
      await tester.pumpAndSettle();
      expect(find.text('Unavailable'), findsNothing);
      final dropdown = find.byKey(const Key('customer-menu-sort'));
      await tester.tap(dropdown);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Price: low to high').last);
      await tester.pumpAndSettle();
      if (width < 620) expect(tester.getTopLeft(find.text('Budget')).dy, lessThan(tester.getTopLeft(find.text('Premium')).dy));
      await tester.enterText(find.byType(TextField).first, 'Budget');
      await tester.pumpAndSettle();
      expect(find.text('Premium'), findsNothing);
      expect(find.byWidgetPredicate((widget) => widget is Text && widget.data == 'Budget'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
