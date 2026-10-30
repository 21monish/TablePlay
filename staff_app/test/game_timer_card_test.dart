import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_staff/features/staff_home.dart';
import 'package:tableplay_staff/theme/tableplay_theme.dart';

Map<String, dynamic> timer(String tableStatus, {bool expired = false}) => {
  'id': 1,
  'status': 'active',
  'expires_at': DateTime.now()
      .add(Duration(minutes: expired ? -1 : 43))
      .toUtc()
      .toIso8601String(),
  'table_session': {
    'status': tableStatus,
    'dining_table': {'table_code': 'T01'},
  },
};

Widget host(Map<String, dynamic> game) => MaterialApp(
  theme: tablePlayTheme(),
  home: Scaffold(
    body: GameTimerCard(
      game: game,
      onExtend: (_) async {},
      onCustom: () async {},
      onStop: () async {},
    ),
  ),
);

void main() {
  testWidgets(
    'billing timer shows locked access rather than remaining play time',
    (tester) async {
      await tester.pumpWidget(host(timer('billing')));
      expect(find.text('Locked during billing'), findsOneWidget);
      expect(find.text('BILLING'), findsOneWidget);
      expect(find.text('REMAINING'), findsNothing);
      for (final label in ['+15 min', '+30 min', 'Custom']) {
        final button = tester.widget<OutlinedButton>(
          find.ancestor(
            of: find.text(label),
            matching: find.byWidgetPredicate(
              (widget) => widget is OutlinedButton,
            ),
          ),
        );
        expect(button.onPressed, isNull);
      }
      expect(
        tester
            .widget<LinearProgressIndicator>(
              find.byType(LinearProgressIndicator),
            )
            .value,
        0,
      );
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets('open table keeps the timer and extension buttons available', (
    tester,
  ) async {
    await tester.pumpWidget(host(timer('open')));
    expect(find.text('REMAINING'), findsOneWidget);
    expect(find.text('Locked during billing'), findsNothing);
    expect(
      tester
          .widget<OutlinedButton>(
            find.widgetWithText(OutlinedButton, '+15 min'),
          )
          .onPressed,
      isNotNull,
    );
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets(
    'billing status change disables controls without changing expiry',
    (tester) async {
      final game = timer('open');
      await tester.pumpWidget(host(game));
      await tester.pumpWidget(
        host({
          ...game,
          'table_session': {
            'status': 'billing',
            'dining_table': {'table_code': 'T01'},
          },
        }),
      );
      expect(find.text('Locked during billing'), findsOneWidget);
      expect(find.text('REMAINING'), findsNothing);
      expect(
        tester
            .widget<OutlinedButton>(
              find.widgetWithText(OutlinedButton, '+15 min'),
            )
            .onPressed,
        isNull,
      );
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets('expired and closed timers cannot be extended', (tester) async {
    for (final game in [timer('closed'), timer('open', expired: true)]) {
      await tester.pumpWidget(host(game));
      expect(find.text('REMAINING'), findsNothing);
      expect(
        tester
            .widget<OutlinedButton>(
              find.widgetWithText(OutlinedButton, '+15 min'),
            )
            .onPressed,
        isNull,
      );
      await tester.pumpWidget(const SizedBox.shrink());
    }
  });
}
