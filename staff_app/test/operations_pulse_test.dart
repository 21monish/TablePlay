import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_staff/theme/tableplay_theme.dart';
import 'package:tableplay_staff/widgets/common.dart';

void main() {
  testWidgets('operations pulse summarizes live attention and opens a queue', (
    tester,
  ) async {
    var opened = false;
    await tester.pumpWidget(
      MaterialApp(
        theme: tablePlayTheme(),
        home: Scaffold(
          body: OperationsPulse(
            title: 'Counter command strip',
            subtitle: 'Items need attention',
            metrics: [
              PulseMetric(
                label: 'Confirm orders',
                value: 2,
                icon: Icons.receipt_long_rounded,
                color: TablePlayColors.gold,
                onTap: () => opened = true,
              ),
              const PulseMetric(
                label: 'Guest requests',
                value: 1,
                icon: Icons.notifications_active_rounded,
                color: TablePlayColors.accent,
              ),
            ],
          ),
        ),
      ),
    );

    expect(find.text('3 ACTIVE'), findsOneWidget);
    expect(find.text('Confirm orders'), findsOneWidget);
    await tester.tap(find.text('Confirm orders'));
    expect(opened, isTrue);
  });

  testWidgets('operations pulse reports a clear service state', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: tablePlayTheme(),
        home: const Scaffold(
          body: OperationsPulse(
            title: 'Kitchen service pulse',
            subtitle: 'Tickets need attention',
            metrics: [
              PulseMetric(
                label: 'New tickets',
                value: 0,
                icon: Icons.soup_kitchen_rounded,
                color: TablePlayColors.success,
              ),
            ],
          ),
        ),
      ),
    );

    expect(find.text('CLEAR'), findsOneWidget);
    expect(find.text('Everything is under control'), findsOneWidget);
  });
}
