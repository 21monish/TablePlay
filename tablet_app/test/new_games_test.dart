import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_tablet/core/api_client.dart';
import 'package:tableplay_tablet/features/game_center_v2.dart';
import 'package:tableplay_tablet/theme/tableplay_theme.dart';

void main() {
  testWidgets('Memory Match renders six pairs and starts ready to play',
      (tester) async {
    final api = ApiClient(baseUrl: 'http://127.0.0.1:1/api/v1');
    await tester.pumpWidget(MaterialApp(
      theme: tablePlayTheme(),
      home: MemoryMatchV2(
        api: api,
        game: const {'id': 4, 'name': 'Memory Match', 'slug': 'memory-match'},
      ),
    ));

    expect(find.text('MOVES'), findsOneWidget);
    expect(find.text('PAIRS'), findsOneWidget);
    expect(find.text('0/6'), findsOneWidget);
    expect(find.byIcon(Icons.question_mark_rounded), findsNWidgets(12));

    await tester.pumpWidget(const SizedBox.shrink());
    api.close();
  });

  testWidgets('Quick Math starts its timed challenge', (tester) async {
    final api = ApiClient(baseUrl: 'http://127.0.0.1:1/api/v1');
    await tester.pumpWidget(MaterialApp(
      theme: tablePlayTheme(),
      home: QuickMathV2(
        api: api,
        game: const {'id': 5, 'name': 'Quick Math', 'slug': 'quick-math'},
      ),
    ));

    expect(find.text('Start challenge'), findsOneWidget);
    await tester.tap(find.text('Start challenge'));
    await tester.pump();

    expect(find.text('Submit answer'), findsOneWidget);
    expect(find.text('45s'), findsOneWidget);
    expect(find.byType(TextField), findsOneWidget);

    await tester.pumpWidget(const SizedBox.shrink());
    api.close();
  });

  testWidgets('Connect Four accepts alternating two-player moves',
      (tester) async {
    final api = ApiClient(baseUrl: 'http://127.0.0.1:1/api/v1');
    await tester.pumpWidget(MaterialApp(
      theme: tablePlayTheme(),
      home: ConnectFourV2(api: api, game: const {
        'id': 6,
        'name': 'Connect Four',
        'slug': 'connect-four',
      }),
    ));

    expect(find.text('Player 1 · choose a column'), findsOneWidget);
    await tester.tap(find.byKey(const Key('connect-cell-0')));
    await tester.pump();
    expect(find.text('Player 2 · choose a column'), findsOneWidget);

    await tester.pumpWidget(const SizedBox.shrink());
    api.close();
  });

  testWidgets('Table Race renders four players and advances turns',
      (tester) async {
    final api = ApiClient(baseUrl: 'http://127.0.0.1:1/api/v1');
    await tester.pumpWidget(MaterialApp(
      theme: tablePlayTheme(),
      home: TableRaceV2(api: api, game: const {
        'id': 7,
        'name': 'Table Race',
        'slug': 'table-race',
      }),
    ));

    expect(find.byKey(const Key('race-score-0')), findsOneWidget);
    expect(find.byKey(const Key('race-score-3')), findsOneWidget);
    expect(find.text('Player 1 to roll'), findsOneWidget);
    await tester.tap(find.byKey(const Key('roll-dice')));
    await tester.pump();
    expect(find.text('Player 2 to roll'), findsOneWidget);

    await tester.pumpWidget(const SizedBox.shrink());
    api.close();
  });
}
