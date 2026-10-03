import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_tablet/core/api_client.dart';
import 'package:tableplay_tablet/features/game_center_v2.dart';
import 'package:tableplay_tablet/theme/tableplay_theme.dart';

void main() {
  testWidgets('Snake starts with touch controls and a zero score',
      (tester) async {
    final api = ApiClient(baseUrl: 'http://127.0.0.1:1/api/v1');
    await tester.pumpWidget(MaterialApp(
      theme: tablePlayTheme(),
      home: SnakeGameV2(
        api: api,
        game: const {'id': 3, 'name': 'TablePlay Snake', 'slug': 'snake'},
      ),
    ));

    expect(find.text('Start game'), findsOneWidget);
    expect(find.text('SCORE'), findsOneWidget);
    expect(find.text('0'), findsWidgets);

    await tester.ensureVisible(find.text('Start game'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Start game'));
    await tester.pump();

    expect(find.text('Swipe the board or use the controls'), findsOneWidget);
    expect(find.byIcon(Icons.keyboard_arrow_up_rounded), findsOneWidget);
    expect(find.byIcon(Icons.keyboard_arrow_down_rounded), findsOneWidget);

    await tester.pumpWidget(const SizedBox.shrink());
    api.close();
  });
}
