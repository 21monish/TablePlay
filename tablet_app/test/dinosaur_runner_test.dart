import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tableplay_tablet/core/api_client.dart';
import 'package:tableplay_tablet/features/dinosaur_runner.dart';
import 'package:tableplay_tablet/theme/tableplay_theme.dart';

void main() {
  test('runner jumps once, follows gravity, and lands', () {
    final engine = DinosaurRunnerEngine(random: Random(7))..start();
    final ground = engine.dinosaurY;

    expect(engine.jump(), isTrue);
    expect(engine.jump(), isFalse);
    engine.update(.1);
    expect(engine.dinosaurY, lessThan(ground));

    for (var frame = 0; frame < 20; frame++) {
      engine.update(.05);
    }

    expect(engine.dinosaurY, ground);
    expect(engine.isOnGround, isTrue);
    expect(engine.score, greaterThan(0));
  });

  test('runner stops immediately when its hitbox reaches an obstacle', () {
    final engine = DinosaurRunnerEngine(random: Random(3))..start();
    engine.obstacles
      ..clear()
      ..add(RunnerObstacle(
        x: DinosaurRunnerEngine.dinosaurX + 16,
        width: 40,
        height: 66,
        kind: RunnerObstacleKind.cactus,
      ));

    expect(engine.update(.016), isTrue);
    expect(engine.gameOver, isTrue);
    expect(engine.playing, isFalse);
  });

  testWidgets('Dinosaur Dash starts and exposes touch jump controls',
      (tester) async {
    SharedPreferences.setMockInitialValues({
      'game_high_score_dinosaur-dash': 42,
    });
    final api = ApiClient(baseUrl: 'http://127.0.0.1:1/api/v1');

    await tester.pumpWidget(MaterialApp(
      theme: tablePlayTheme(),
      home: DinosaurRunnerV2(
        api: api,
        game: const {
          'id': 4,
          'name': 'Dinosaur Dash',
          'slug': 'dinosaur-dash',
        },
      ),
    ));
    await tester.pump();

    expect(find.text('Ready to run?'), findsOneWidget);
    expect(find.text('Start run'), findsOneWidget);
    expect(find.text('Tap, Space, or Arrow Up'), findsOneWidget);

    await tester.tap(find.byKey(const Key('dinosaur-start')));
    await tester.pump(const Duration(milliseconds: 20));

    expect(find.text('Jump'), findsOneWidget);
    expect(find.byKey(const Key('dinosaur-jump')), findsOneWidget);
    await tester.tap(find.byKey(const Key('dinosaur-jump')));
    await tester.pump(const Duration(milliseconds: 20));

    await tester.pumpWidget(const SizedBox.shrink());
    api.close();
  });
}
